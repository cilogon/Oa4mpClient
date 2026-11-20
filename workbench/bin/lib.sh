# Shared helpers for the workbench scripts. Source it; do not run it.

WORKBENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKBENCH_LOCAL="$WORKBENCH_DIR/local"
WORKBENCH_COMPOSE=(docker compose --project-name oa4mp-workbench
  --file "$WORKBENCH_DIR/compose.yaml")

# A machine-local override, for example to mount another plugin under
# local/plugins. It lives in the git-ignored local directory.
if [ -f "$WORKBENCH_LOCAL/compose.override.yaml" ]; then
  WORKBENCH_COMPOSE+=(--file "$WORKBENCH_LOCAL/compose.override.yaml")
fi

# The Registry commit the workbench image is built from unless overridden.
workbench_pin() {
  tr -d '[:space:]' < "$WORKBENCH_DIR/registry.pin"
}

# The image tag for a given Registry commit.
workbench_image_tag() {
  echo "oa4mp-workbench-registry:${1:0:12}"
}

# Test-login usernames, one for each kind of person the plugin treats
# differently. Every one except the last has a Registry record after seeding.
WORKBENCH_TEST_LOGINS=(
  wb-platform-admin
  wb-platform-admin-unlisted
  wb-co-admin
  wb-manager
  wb-editor
  wb-member
  wb-inactive
  wb-no-record
)

die() {
  echo "ERROR: $*" >&2
  exit 1
}

require_local_config() {
  [ -f "$WORKBENCH_LOCAL/workbench.env" ] \
    || die "no local configuration; run workbench/bin/make-local-config first"
}

# Read one KEY=value line from the local environment file without sourcing
# it, so a value is never evaluated by the shell.
local_value() {
  sed -n "s/^$1=//p" "$WORKBENCH_LOCAL/workbench.env" | tail -n 1
}
