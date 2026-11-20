#!/usr/bin/env bash
#
# Run the Oa4mpClient checks for Registry 5: the style check, the fast suite
# and the integration suite. The same script runs locally and in CI, and its
# exit status is the pull request gate's verdict.
#
# Usage: tests/run.sh          style check, fast suite, integration suite
#        tests/run.sh --unit   fast suite only
#
# Everything runs inside a Registry 5 image built from the commit in
# workbench/registry.pin. By default that is the base image
# workbench/bin/build-image builds (and CI builds the same way); set
# OA4MP_TEST_REGISTRY_IMAGE to run another local image instead.
#
# The fast suite and the style check run with networking disabled. The
# integration suite runs in the compose project oa4mp-r5-tests, which starts
# from an empty database and is removed afterwards. See tests/README.md.
set -euo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$TESTS_DIR")"
source "$REPO_DIR/workbench/bin/lib.sh"
PLUGIN_DIR=/srv/comanage-registry/local/plugins/Oa4mpClient
APP_DIR=/srv/comanage-registry/app
COMPOSE=(docker compose --project-name oa4mp-r5-tests
  --file "$TESTS_DIR/docker/compose.yaml")

# Floors under the number of tests each suite runs. PHPUnit reports only the
# tests it found, so a test file renamed off *Test.php or moved out of its
# suite's directory vanishes from the run with everything still green; a
# floor turns that loss into a failure.
#
# While the suites are small each floor equals the suite's size. Once a suite
# has a few dozen tests, keep its floor three below the count. Raise a floor
# in the change that adds tests; lower it only in a change that removes tests
# on purpose, never to turn a red run green.
min_unit_tests=42
min_integration_tests=14

unit_only=false
case "${1:-}" in
  "") ;;
  --unit) unit_only=true ;;
  *) echo "Usage: tests/run.sh [--unit]" >&2; exit 2 ;;
esac

fail() {
  echo "==> ERROR: $*" >&2
  exit 1
}

pin="$(workbench_pin)"
image="${OA4MP_TEST_REGISTRY_IMAGE:-$(workbench_base_image_tag "$pin")}"
export OA4MP_TEST_REGISTRY_IMAGE="$image"

docker image inspect "$image" > /dev/null 2>&1 \
  || fail "no local image $image. Build it with workbench/bin/build-image" \
    "(the base image is enough), or set OA4MP_TEST_REGISTRY_IMAGE to an" \
    "image built from Registry commit $pin."

image_id="$(docker image inspect --format '{{.Id}}' "$image")"
image_commit="$(docker image inspect \
  --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' \
  "$image")"
echo "==> Registry image: $image"
echo "==> Registry image ID: $image_id"
echo "==> Built from Registry commit: ${image_commit:-UNKNOWN}"
if [ "$image_commit" != "$pin" ]; then
  echo "==> WARNING: workbench/registry.pin is $pin; this image was not built" \
    "from it."
fi

logs="$(mktemp -d)"
cleanup() {
  rm -rf "$logs"
  if [ "$unit_only" = false ]; then
    "${COMPOSE[@]}" down --volumes --remove-orphans > /dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

run_offline() {
  docker run --rm --network none \
    --volume "$REPO_DIR:$PLUGIN_DIR:ro" \
    --workdir "$APP_DIR" \
    "$@"
}

# Check a PHPUnit run's output and status against the suite's floor.
#
# A clean PHPUnit run ends with "OK (N tests, M assertions)". Any other last
# line is not a pass: failures, errors, risky or skipped tests, warnings,
# deprecations ("OK, but there were issues!"), or a process that ended before
# printing its summary at all, which is what a stray exit() inside a test or
# the code under test looks like.
check_suite() {
  local suite="$1" status="$2" log="$3" floor="$4" summary count

  [ "$status" -eq 0 ] || fail "the $suite suite exited with status $status."

  summary="$(tr -d '\r' < "$log" | sed '/^[[:space:]]*$/d' | tail -n 1)"
  if ! [[ "$summary" =~ ^OK\ \(([0-9]+)\ tests?,\ [0-9]+\ assertions?\)$ ]]; then
    fail "the $suite suite did not end with PHPUnit's clean summary" \
      "'OK (N tests, M assertions)'; its last line was: $summary"
  fi

  count="${BASH_REMATCH[1]}"
  if [ "$count" -lt "$floor" ]; then
    fail "the $suite suite ran $count tests, below its floor of $floor." \
      "Tests have gone missing from discovery, for example a file renamed" \
      "off *Test.php or moved out of the suite's directory."
  fi

  echo "==> The $suite suite passed: $count tests (floor $floor)."
  printf -v "${suite}_count" '%s' "$count"
}

unit_count=0
integration_count=0

if [ "$unit_only" = false ]; then
  echo "==> Checking style (phpcs, report only)..."
  style_status=0
  run_offline --entrypoint "$APP_DIR/vendor/bin/phpcs" "$image" \
    --standard="$PLUGIN_DIR/phpcs.xml" --basepath="$PLUGIN_DIR" \
    --no-colors --runtime-set ignore_warnings_on_exit 1 \
    || style_status=$?
  [ "$style_status" -eq 0 ] \
    || fail "the style check found errors (phpcs exited $style_status)."
  echo "==> Style check passed."
fi

echo "==> Running the fast suite with networking disabled..."
unit_status=0
run_offline --env OA4MP_TEST_SUITE=unit \
  --entrypoint "$APP_DIR/vendor/bin/phpunit" "$image" \
  --configuration "$PLUGIN_DIR/phpunit.xml.dist" --testsuite unit \
  2>&1 | tee "$logs/unit.log" || unit_status=$?
check_suite unit "$unit_status" "$logs/unit.log" "$min_unit_tests"

if [ "$unit_only" = true ]; then
  echo "==> PASSED: fast suite ($unit_count tests)."
  exit 0
fi

echo "==> Starting Registry and an empty test database..."
"${COMPOSE[@]}" down --volumes --remove-orphans > /dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait

echo "==> Setting up Registry and activating the plugin..."
# The steps the image's entrypoint runs at startup, minus the web server,
# then Registry's own plugin command. Its output is long; it is shown only if
# a step fails. ActivationTest checks the result, since a schema step can
# report success without having created anything.
setup_status=0
#
# The image's helper functions are not written for `set -e` (a `read -d ""`
# in them returns non-zero by design), so errexit starts after them, and the
# database configuration they write is checked instead.
"${COMPOSE[@]}" exec -T registry bash -c '
  source /usr/local/lib/comanage_utils.sh
  OUTPUT=/dev/stdout
  comanage_utils::prepare_database_config
  set -e
  test -s /srv/comanage-registry/local/config/database.php
  cd /srv/comanage-registry/app
  ./bin/cake database
  ./bin/cake setup --admin-given-name Test --admin-family-name Admin \
    --admin-username test.admin
  ./bin/cake plugin --action activate --plugin Oa4mpClient
' > "$logs/setup.log" 2>&1 || setup_status=$?
if [ "$setup_status" -ne 0 ]; then
  tail -n 60 "$logs/setup.log" >&2
  fail "setting up Registry or activating the plugin failed" \
    "(status $setup_status); the end of its output is above."
fi
grep -F 'Completed task activate for Plugin Oa4mpClient' "$logs/setup.log" \
  || fail "Registry's plugin command did not report activating Oa4mpClient."

echo "==> Running the integration suite..."
integration_status=0
"${COMPOSE[@]}" exec -T --env OA4MP_TEST_SUITE=integration \
  --workdir "$APP_DIR" registry \
  vendor/bin/phpunit --configuration "$PLUGIN_DIR/phpunit.xml.dist" \
  --testsuite integration \
  2>&1 | tee "$logs/integration.log" || integration_status=$?
check_suite integration "$integration_status" "$logs/integration.log" \
  "$min_integration_tests"

echo "==> PASSED: style check, fast suite ($unit_count tests)," \
  "integration suite ($integration_count tests)."
