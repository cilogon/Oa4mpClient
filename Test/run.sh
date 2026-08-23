#!/usr/bin/env bash
#
# Single entry command for the Oa4mpClient hermetic test suite (U2).
#
# Brings up a real COmanage Registry + Postgres with the plugin-under-test
# overlaid, creates the schema via Registry's native `cake database` (which
# applies the overlaid plugin's schema.xml), runs the thin-runner test suite,
# and exits with the suite's status. Usable by a developer, in CI, and by Claude.
#
# Usage: Test/run.sh
set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$TEST_DIR/docker"

# Captured suite output, so the sentinel check below has something to grep.
suite_log="$(mktemp)"

cleanup() {
  rm -f "$suite_log"
  docker compose down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Bringing up Registry + Postgres..."
docker compose up -d

echo "==> Creating schema (applies the overlaid plugin schema via cake database)..."
# `cake database`'s exit code is not trustworthy: Test/README.md's "Known
# wrinkle" records it returning success while emitting a non-fatal "Possibly
# failed to update database schema" warning. Capture and print the output
# instead of discarding it, and prove the plugin's tables actually exist below
# rather than trusting this exit code alone.
docker compose exec -T comanage-registry bash -c '
  mkdir -p /srv/comanage-registry/local/Config
  source /usr/local/lib/comanage_utils.sh
  comanage_utils::prepare_database_config
  cd /srv/comanage-registry/app && ./Console/cake database'

echo "==> Verifying the plugin's tables actually exist..."
# schema.xml declares 22 <table> elements but only 15 unique table names (some
# tables, e.g. oa4mp_client_dynamo_configs, are defined twice), so an exact
# count of <table> tags would be fragile; use the unique-name count as a floor
# instead. This is the post-condition `cake database`'s exit code cannot be
# trusted to provide (see the comment above).
min_plugin_tables=15
plugin_table_count="$(docker compose exec -T comanage-registry-database \
  psql -U registry_user -d registry -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'cm_oa4mp_client_%'")"
plugin_table_count="${plugin_table_count//[[:space:]]/}"
if [ -z "$plugin_table_count" ] || [ "$plugin_table_count" -lt "$min_plugin_tables" ]; then
  echo "==> ERROR: expected at least $min_plugin_tables cm_oa4mp_client_* tables," \
    "found ${plugin_table_count:-0}. The plugin schema likely failed to apply" \
    "-- see Test/README.md's Known wrinkle." >&2
  exit 1
fi

echo "==> Running the thin-runner test suite..."
# Two independent gates, because neither is sufficient alone:
#
#   1. The exec exit status, which catches a failing assertion.
#   2. The runner's ALL_TESTS_PASSED sentinel, which it prints only after every
#      discovered test has run and passed.
#
# The exit status alone is not a backstop: a test that reaches exit(0) -- its
# own, or one inside the code under test, for example Controller::redirect()'s
# _stop() -- ends the whole process mid-run with a success status, and a run
# that stopped after three of a hundred tests is indistinguishable from a
# completed one. Requiring the sentinel closes that hole mechanically.
suite_status=0
docker compose exec -T comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_test' 2>&1 \
  | tee "$suite_log" || suite_status=$?

if [ "$suite_status" -ne 0 ]; then
  echo "==> ERROR: the test suite exited with status $suite_status." >&2
  exit 1
fi

if ! grep -q 'ALL_TESTS_PASSED' "$suite_log"; then
  echo "==> ERROR: the suite exited 0 but never printed the runner's" \
    "ALL_TESTS_PASSED sentinel, so it ended early rather than passing." >&2
  exit 1
fi

echo "==> Suite passed (ALL_TESTS_PASSED)."
