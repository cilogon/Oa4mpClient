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

cleanup() { docker compose down -v >/dev/null 2>&1 || true; }
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
# The exec exit status propagates via `set -e`, so a failing suite fails run.sh.
docker compose exec -T comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_test'
