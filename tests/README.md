# Tests

`tests/run.sh` runs every check a pull request to `registry-5` must pass. CI
runs the same script (`.github/workflows/hermetic-tests.yml`), so a green run
on your machine and a green gate mean the same thing.

```bash
tests/run.sh          # style check, fast suite, integration suite (~30 s)
tests/run.sh --unit   # fast suite only (a few seconds)
```

The last line of a passing run is

```
==> PASSED: style check, fast suite (N tests), integration suite (M tests).
```

Anything else, or a non-zero exit status, is a failure.

## Requirements

Docker with Compose v2, and the Registry 5 image built from the commit in
`workbench/registry.pin`. No public Registry 5 image exists. Build it once with

```bash
workbench/bin/build-image --registry /path/to/a/cilogon/registry/checkout
```

`run.sh` uses the base image that script builds,
`comanage-registry-base:wb-<first 12 characters of the pin>-1`, and prints its
tag, ID and the Registry commit it was built from. To run against another
local image, set `OA4MP_TEST_REGISTRY_IMAGE`; `run.sh` warns when that image
was not built from the pinned commit.

## The two suites

Both run inside the Registry image, with this checkout mounted read-only at
`/srv/comanage-registry/local/plugins/Oa4mpClient`, using the image's
PHPUnit. `phpunit.xml.dist` defines them; `tests/bootstrap.php` sets up each
one according to `OA4MP_TEST_SUITE`, which `run.sh` sets.

| Suite | Directory | Namespace | Runs with |
|---|---|---|---|
| Fast | `tests/Unit/` | `Oa4mpClient\Test\Unit` | no Registry application, no database, networking disabled |
| Integration | `tests/TestCase/` | `Oa4mpClient\Test\TestCase` | a bootstrapped Registry and a throwaway Postgres test database |

Put a test in the fast suite whenever the code under test does not need
Registry: the code that builds, reads and compares what goes to OA4MP is kept
framework-free for exactly this reason, and the Parity check runs here.
`BootstrapIsolationTest` fails if the fast suite ever loads the Registry
application.

Integration tests extend `Cake\TestSuite\TestCase`. The `test` connection
points at the stack's own database, and Cake's usual aliases send every
table there.

The bootstrap names the Registry root (`/srv/comanage-registry/app`) instead
of searching for it. Core plugins find it by walking up the directory tree
for `vendor/cakephp/cakephp`, which never succeeds from `local/plugins`.

## What `run.sh` does

1. Runs `phpcs` with `phpcs.xml` over `src/`, `tests/`, `bin/` and
   `workbench/`, with networking disabled. Errors fail the run; warnings (a
   line past 120 characters) are shown and do not. It only reports: never run
   `phpcbf` or another fixer over the tree.
2. Runs the fast suite in a container with networking disabled.
3. Starts the compose project `oa4mp-r5-tests` (`tests/docker/compose.yaml`):
   a digest-pinned Postgres with an empty `registry_test` database, and the
   Registry container, idle, on an internal network with nothing published on
   the host. Anything an interrupted earlier run left behind is removed
   first.
4. Inside the Registry container, writes the database configuration, runs
   `bin/cake database` and `bin/cake setup`, then activates the plugin with
   `bin/cake plugin --action activate --plugin Oa4mpClient`, which applies
   `config/plugin.json`. The output is shown only if a step fails.
5. Runs the integration suite.
6. Removes the containers, network and volume, pass or fail.

## How a run is judged

Each suite must pass all three of these, because none is enough alone:

- **Exit status.** PHPUnit exits non-zero on a failure or error.
- **The clean summary line.** The suite's last line must be PHPUnit's
  `OK (N tests, M assertions)`. A skipped, incomplete or risky test, a
  warning, a deprecation (`OK, but there were issues!`), output printed by a
  test, or a process that ended before its summary all fail the run.
- **A floor under the test count.** PHPUnit counts only the tests it found.
  A test file renamed off `*Test.php`, or moved out of its suite's directory,
  drops out of the run with everything else green. `min_unit_tests` and
  `min_integration_tests` in `run.sh` turn that into a failure.

Raise a floor in the pull request that adds tests. While a suite is small its
floor equals its size; once it has a few dozen tests, keep the floor three
below the count. Lower a floor only in a change that removes tests on
purpose, never to make a red run green.

## Tests that guard the gate itself

- `tests/TestCase/ActivationTest.php`: after activation on the empty
  database, Registry lists the plugin as active from `local/plugins`, and
  every table, column and named index `config/plugin.json` declares exists.
  A schema step can report success without creating anything; this is the
  check that it did.
- `tests/TestCase/Schema/PluginSchemaTest.php`: `config/plugin.json` declares
  no key twice (PHP's JSON decoder keeps the last of two equal keys without a
  word), every foreign key names a table declared earlier in the file or in
  Registry's core schema, and every foreign key column has a non-unique named
  index on that column alone (Registry refuses the schema otherwise). Columns
  declared as `{}` are resolved against Registry's column library first. Each
  check has a control test that feeds it a broken schema.
- `tests/Unit/GateSafeguardsTest.php`: the secret scan extends gitleaks'
  built-in rules rather than replacing them, its allowlist exempts literal
  values only, the workflow names the config explicitly, and every image the
  gate pulls is pinned by digest.

## In CI

`.github/workflows/hermetic-tests.yml` runs on every pull request and on
pushes to `registry-5`. It uses no secrets, so pull requests from forks run
it too, once a maintainer approves the run.

- **Test suites** checks out `cilogon/registry` at the commit in
  `workbench/registry.pin`, builds Registry's base image the way
  `workbench/bin/build-image` does (with a layer cache keyed on the pin), and
  runs `tests/run.sh`.
- **Secret scan** runs a digest-pinned gitleaks over the full history with
  `.gitleaks.toml`.

Changing `workbench/registry.pin` changes what both the workbench and the
gate run. Rebuild the image and run `tests/run.sh` before proposing it.

## Writing a new test

- Show it fail before relying on it: break the behaviour it guards (in a
  scratch copy for anything destructive), watch the test go red, restore.
- Every bug fix carries a regression test in the same pull request.
- Raise the suite's floor in the same pull request.
