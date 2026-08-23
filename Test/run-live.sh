#!/usr/bin/env bash
#
# Entry command for the LIVE-SERVER test tier (U9).
#
# Unlike Test/run.sh this tier talks to a real OA4MP server and creates, edits,
# and deletes real OIDC clients using a dedicated dev.cilogon.org test admin
# client. It never gates a pull request: CI runs it from
# .github/workflows/live-server-tests.yml on a schedule or on demand, on main
# only, where the credential is available.
#
# Credentials come from the environment. Locally, put them in Test/.env (copy
# Test/.env.example); that file is gitignored and must never be committed.
#
# Usage: Test/run-live.sh
set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -f "$TEST_DIR/.env" ]; then
  echo "==> Loading credentials from Test/.env"
  set -a
  # shellcheck disable=SC1091
  . "$TEST_DIR/.env"
  set +a
fi

: "${OA4MP_LIVE_SERVER_URL:?set it in Test/.env or the environment}"
: "${OA4MP_LIVE_ADMIN_IDENTIFIER:?set it in Test/.env or the environment}"
: "${OA4MP_LIVE_ADMIN_SECRET:?set it in Test/.env or the environment}"
: "${OA4MP_LIVE_CO_ID:?set it in Test/.env or the environment}"

cd "$TEST_DIR/docker"

# Captured so the gates below can read the runner's verdict block. Defined
# before the trap so cleanup can always remove it under `set -u`.
live_log="$(mktemp)"

cleanup() {
  docker compose down -v >/dev/null 2>&1 || true
  rm -f "$live_log"
}
trap cleanup EXIT

echo "==> Bringing up Registry + Postgres..."
docker compose up -d

echo "==> Creating schema..."
# `cake database`'s exit code is not trustworthy: Test/README.md's "Known
# wrinkle" records it returning success while emitting a non-fatal "Possibly
# failed to update database schema" warning. Capture and print the output
# instead of discarding it, and prove the plugin's tables actually exist below
# rather than trusting this exit code alone. (Mirrors Test/run.sh.)
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

echo "==> Running the live-server tier against $OA4MP_LIVE_SERVER_URL ..."
# Two gates, as in Test/run.sh: the exit status, and the runner's sentinel.
#
# The exit status alone is not a backstop here either. A test that reaches
# exit(0) -- its own, or one inside the code under test such as
# Controller::redirect()'s _stop() -- ends the process mid-run with a success
# status, and a tier that stopped after its first case is indistinguishable
# from one that finished. That matters more here than in the hermetic tier,
# because this tier creates real clients on a real server: a run that stops
# early can strand one, and reporting success would hide it.
#
# The count floor Test/run.sh applies as its third gate is deliberately not
# mirrored. This tier discovers only Test/Case/LiveServer, so the number is
# small enough that a floor would be edited on nearly every change and would
# stop meaning anything. The sentinel is the gate that carries here.
live_status=0
docker compose exec -T \
  -e OA4MP_LIVE_SERVER_URL \
  -e OA4MP_LIVE_ADMIN_IDENTIFIER \
  -e OA4MP_LIVE_ADMIN_SECRET \
  -e OA4MP_LIVE_CO_ID \
  comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_test live' \
  2>&1 | tee "$live_log" || live_status=$?

if [ "$live_status" -ne 0 ]; then
  echo "==> ERROR: the live-server tier exited with status $live_status." >&2
  exit 1
fi

# Same verdict-block anchoring as Test/run.sh, for the same reason: the
# sentinel is matched as a line of its own inside the region the runner prints
# only after the last test, which no test's own output can reach.
live_tail="$(tr -d '\r' < "$live_log" \
  | sed -n '/^[0-9][0-9]* tests run, [0-9][0-9]* failed\.$/,$p')"
if ! grep -q '^ALL_TESTS_PASSED$' <<< "$live_tail"; then
  echo "==> ERROR: the live-server tier exited 0 but never reached the" \
    "runner's ALL_TESTS_PASSED verdict, so it ended early rather than" \
    "passing. A client it created may be stranded on the server." >&2
  exit 1
fi

echo "==> Live-server tier passed (ALL_TESTS_PASSED)."
