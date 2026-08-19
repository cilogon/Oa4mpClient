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
docker compose exec -T comanage-registry bash -c '
  mkdir -p /srv/comanage-registry/local/Config
  source /usr/local/lib/comanage_utils.sh
  comanage_utils::prepare_database_config
  cd /srv/comanage-registry/app && ./Console/cake database' >/dev/null

echo "==> Running the thin-runner test suite..."
# The exec exit status propagates via `set -e`, so a failing suite fails run.sh.
docker compose exec -T comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_test'
