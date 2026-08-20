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

cleanup() { docker compose down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "==> Bringing up Registry + Postgres..."
docker compose up -d

echo "==> Creating schema..."
docker compose exec -T comanage-registry bash -c '
  mkdir -p /srv/comanage-registry/local/Config
  source /usr/local/lib/comanage_utils.sh
  comanage_utils::prepare_database_config
  cd /srv/comanage-registry/app && ./Console/cake database' >/dev/null

echo "==> Running the live-server tier against $OA4MP_LIVE_SERVER_URL ..."
# The credential is passed to the container's environment only for this command.
docker compose exec -T \
  -e OA4MP_LIVE_SERVER_URL \
  -e OA4MP_LIVE_ADMIN_IDENTIFIER \
  -e OA4MP_LIVE_ADMIN_SECRET \
  -e OA4MP_LIVE_CO_ID \
  comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_test live'
