# Oa4mpClient plugin test harness

This directory holds the automated test suite for the plugin. See the plan at
`docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md`.

## U1 spike findings (validated environment)

The hermetic environment is a real COmanage Registry plus a Postgres database,
with the plugin-under-test overlaid over the image's bundled release. Validated
facts:

- **Image:** `public.ecr.aws/cilogon/comanage-registry:latest` (~1.07 GB),
  PHP 8.4.24, CakePHP 2.10.24, app root `/srv/comanage-registry/app`.
- **Overlay target:** the released plugin lives at
  `app/Plugin/Oa4mpClient`; the compose file bind-mounts this checkout there.
- **Database:** Postgres (the image's default `COMANAGE_REGISTRY_DATASOURCE`),
  a companion container named `comanage-registry-database`.
- **Schema (KTD2):** Registry's own `./Console/cake database` loads each
  plugin's `Config/Schema/schema.xml`. Against a fresh database it creates the
  checkout's tables directly (15 `cm_oa4mp_client_*` tables observed), so
  reconciliation rides Registry's native mechanism — no raw-SQL FK re-apply.
- **Test runner:** the image ships the CakePHP test shell but **no PHPUnit**, and
  CakePHP 2.x's PHPUnit-based `cake test` does not run on PHP 8.4. The suite
  therefore uses a **thin runner**: CakePHP console shells (or plain scripts
  bootstrapped through the console) that load plugin models/components and assert
  directly. `Console/Command/Oa4mpSmokeShell.php` is the smoke proof.

## Running the suite

One command brings up the environment, creates the schema, runs the thin-runner
suite, and tears everything down (exiting non-zero if any test fails):

```bash
Test/run.sh
```

## The three gates

`Test/run.sh` reports success only when all three of these hold. None is
sufficient alone, and each covers a failure the others cannot see:

1. **The exec exit status**, which catches a failing assertion. It is not a
   backstop on its own: a test that reaches `exit(0)` -- its own, or one inside
   the code under test such as `Controller::redirect()`'s `_stop()` -- ends the
   process mid-run with a success status, and a run that stopped after three of
   a hundred tests looks exactly like a completed one.
2. **The runner's `ALL_TESTS_PASSED` sentinel**, which it prints only after
   every discovered test has run and passed, so a mid-run `exit(0)` cannot
   reach it. It is matched as a line of its own inside the runner's verdict
   block -- everything from the `N tests run, M failed.` summary onward -- and
   not as a substring of the whole log. Test cases now read `run.sh` and assert
   on its text, so the literal lives in the suite's own test data; an
   unanchored search would be satisfiable by a test that echoed the file. The
   verdict block is the one region no test can write into, because the runner
   prints it only after the last test has run.
3. **A floor on how many tests actually ran** (`min_tests_run` in `run.sh`),
   because gate 2 says nothing about how many tests discovery found in the
   first place. `ALL_TESTS_PASSED` means "everything I was given passed"; it
   cannot speak to what it was not given. A `test*` method that acquires a
   `private` keyword drops out of `get_class_methods()`, and a file renamed off
   the `*Test.php` glob drops out of the scan -- either one retires a
   regression test while gates 1 and 2 stay green. The runner's own floors are
   both zero cases, so only a floor above zero catches a partial loss.

The floor sits a few tests below the suite's current size, so consolidating a
case or two never forces an edit while losing four or more goes red. **Raise it
deliberately as the suite grows; lower it only alongside a deliberate removal,
never to make a red run green.**
`ClaimsControllerHarnessTest::testRunShRequiresAPlausibleTestCount` counts the
tree independently of the runner and reddens if the floor falls materially
behind, because the floor is a hand-maintained number and has drifted before.

Background and the full failure analysis:
`docs/solutions/test-failures/oa4mp-test-runner-silent-pass-count-gate.md`.

The live tier (`Test/run-live.sh`) applies gates 1 and 2, but not 3: it
discovers only `Test/Case/LiveServer`, where the count is small enough that a
floor would need editing on nearly every change. Gate 2 matters more there than
in the hermetic tier, because a run that stops early can strand a real client
on the server.

## Writing tests

A test case extends `Oa4mpTestCase` (`Test/lib/Oa4mpTestCase.php`) and defines
`test*` methods; put it under `Test/Case/` at any depth. The runner shell
`Console/Command/Oa4mpTestShell.php` finds and runs them, with one exception:
`Test/Case/LiveServer/` is skipped, because that tier needs a real credential
and must never run on the merge gate (see The live-server tier).

The class name must match the filename -- a file whose class is named
differently is reported as a failure, not skipped, so a whole test file cannot
retire unnoticed. `Test/Case/HarnessSelfTest.php` is the reference example.

Most core-logic tests assert the marshalled cfg and database state directly and
need no server. `Test/Stub/Oa4mpServerStub.php` and the captured response under
`Test/fixtures/oa4mp-responses/` exist for tests that must simulate a server
reply; **no test consumes them yet**, so treat the stub as the pattern to follow
when the first such test is written rather than as covered ground.

## Regression coverage status

Locked with passing tests (`Test/Case/Model/`):

- **dynamo phantom-null** (#4) -- `resolveDynamoConfig` fallback (CfgMarshallingTest).
- **public-client cfg-rejected** (#8) -- `oa4mpMarshallContent` emits no cfg for a
  public client (CfgMarshallingTest).
- **unmarshall swallowed TypeError** (#2) -- `oa4mpUnMarshallCfgQdlv2` with no
  `list_attributes` is not swallowed (CfgMarshallingTest).
- **ldap empty-type constraint** (#5) -- `computeVoPersonApplicationUidConstraint`
  normalizes empty/null to `all` (ClaimMigrationTest).
- **empty-type never serialized** (#3c) -- `oa4mpMarshallCfgQdl` drops a
  `{constraint_field: type, constraint_value: ''}` constraint (CfgMarshallingTest).
- **comparator drift** (#7) -- a cfg unmarshalled through
  `oa4mpUnMarshallContent` publishes its claims under the keys the comparator
  reads, for both the QDLv3 and the legacy format-1 path, and
  `isClientDataSynchronized` then reports in-sync against the equivalent
  persisted claim rows (SyncVerificationTest). The defect lived in the
  unmarshall translation, not in the comparator, so the test drives that path
  rather than comparing two hand-built arrays.
- **non-atomic save / orphan claims** (#3a) -- a failing `DefaultDynamoConfig`
  save no longer strands a claim without its `claim_id` back-pointer
  (ClaimMigrationPersistenceTest).
- **foreach loop-variable leak** (#3b) -- a provisioner-attribute list with no
  matching entry produces no claim instead of leaking the last entry's type
  into a constraint (ClaimMigrationPersistenceTest).
- **legacy orphan-claim recovery** (#6) -- re-running migration over an orphan
  rewires the search attribute to the existing claim rather than duplicating it
  (ClaimMigrationPersistenceTest).

- **admin-client duplicate insert** (#1) -- the admin-client edit form renders
  the hidden `DefaultDynamoConfig.id`, so the hasOne save updates in place
  instead of inserting a row per save (AdminClientEditSaveTest).
- **view title double-encoding** (#9) -- no controller pre-encodes a value it
  hands to `title_for_layout`; the core `pageTitleAndButtons` element is the
  single escape point (ViewTitleEncodingTest).

- **comparator/marshaller asymmetry** -- the sync comparator applies the same
  emptiness rule the marshaller does, for half-populated claim constraints and
  for a string-zero `source_model_claim_value_field`. Either previously made a
  client report out of sync on every verify pass with no edit able to repair it
  (ClaimConstraintSymmetryTest).
- **claims write-path ordering** -- add, edit and delete check the local write's
  result instead of discarding it, so a write that fails after the OA4MP server
  accepted reports the drift and the repair rather than success
  (ClaimsWritePathTest).

All nine documented bugs are locked, plus the two above found while building the
claims contract matrix. Each regression was additionally verified red by
temporarily restoring the documented pre-fix code path (or, for the
authorization matrix, by mutating the rule under test) and observing only its
own test fail.

## Claims contract coverage

`Test/Case/Model/ClaimCfgContractTest.php` is a table-driven matrix over what
actually changes the emitted cfg -- each claim field populated, empty and set to
a string zero, plus the cfg shape a client resolves to -- with one `test*` method
per row so a change that reddens several shows every one. Rows are declared in
`Test/lib/Oa4mpClaimRows.php`.

Three checks guard the matrix itself. `ClaimCfgDriftTest` derives the emitted
field set from the claim table's declared columns and fails when a field the
marshaller emits reaches no comparator list -- the case a new claim column would
otherwise slip through silently. `ClaimCfgFallbackTest` is the one
database-backed row, covering the configuration-fallback read the in-memory rows
bypass. `ClaimCfgFixtureHygieneTest` checks every checked-in expected value and
seeded credential in those files against the subset of the secret scanner's
rules the guard models -- it is not a guarantee against the scanner's full rule
set.

`NamedConfigClaimSyncTest` locks, provisionally, the exemption that stops a
named-configuration client being compared on its claims. The behaviour it pins
is a known open defect -- see the learning it links.

## Driving a controller action

`Test/lib/Oa4mpClaimsControllerHarness.php` drives a claims controller action
from the thin runner. It overrides the server-object factory to substitute a
fake, and overrides `redirect()` to record its target and then throw, because a
redirect that returned would let the public-client guard fall through into the
server call. It never calls `constructClasses()`. `Test/run.sh` additionally
requires the runner's `ALL_TESTS_PASSED` sentinel, so a mid-run `exit(0)`
reddens the gate instead of reporting green (see **The three gates** below).

The two test cases that drive it -- `ClaimsControllerHarnessTest` and
`ClaimsWritePathTest` -- extend `Test/lib/Oa4mpClaimsControllerTestCase.php`,
which seeds the client graph both need (CO, admin client with a default Dynamo
configuration, an ordinary and a public OIDC client, a claim and its
constraint), purges it in `tearDown()`, and supplies the shared
harness/count/source helpers. A subclass supplies only its fixture tag through
`fixtureTagPrefix()`.

The two view-layer bugs (#1, #9) are locked at the seam the thin runner can
reach: the real render of the core escaping element plus a statement-scoped
source check for the exact pattern each fix removed or added. Full page
rendering is not available here; the live-server tier (U9) is where a rendered
edit page could assert the same invariants end to end.

## Core-logic coverage

Beyond the bug regressions, the authorization matrix
(`Oa4mpClientAuthzComponent::permissionSet`) is covered by
`Test/Case/Controller/Component/Oa4mpClientAuthzComponentTest.php`: admin,
manager, and editor roles across add/delegate/edit/delete/manage/index,
including the hand-off where a manager loses per-client rights once the client
has an authorization group they are not in. Group membership resolves through
the real Registry `RoleComponent` against real `cm_co_group_members` rows.

## Fixtures

DB-backed tests seed the rows they need with `Test/lib/Oa4mpFixtures.php` and
drop them in `tearDown()`; CakePHP 2.x's PHPUnit fixture machinery does not run
on this stack. `Test/Case/Model/ClaimMigrationPersistenceTest.php` is the
reference example.

**Read-after-write trap.** CakePHP 2's `DboSource` caches every `SELECT` result
in-process, keyed by the literal SQL text, and nothing in application code
flushes it. Calling `Oa4mpFixtures::count()` or `scalar()` twice with an
identical query in one test method returns the *first* result even if a write
happened in between. Issue one such query per test, or vary the query, or assert
through a different path -- do not read the same count twice and expect it to
have moved.

## The compounding norm

Every bug fixed in this plugin gets a regression test here, in the same pull
request as the fix, linked to its `docs/solutions/` learning. That is what keeps
the suite growing where the bugs actually are rather than where coverage is easy.

The norm is backed by a checklist item in `.github/pull_request_template.md` and
by the reviewer expectation that a bug-fix pull request without a regression
test is asked for one before approval.

A good regression test is verified red: temporarily restore the pre-fix code
path (or mutate the rule under test), confirm the new test -- and only it --
fails, then restore. Every test in this suite was checked that way; say so in
the commit message so the next reader does not have to redo it.

## Continuous integration

`.github/workflows/hermetic-tests.yml` runs `Test/run.sh` on every pull request
and on pushes to `main`. It uses no repository secrets and never contacts
`dev.cilogon.org`, so it also runs on pull requests from forks, where GitHub
withholds secrets. A second job runs gitleaks over the full history as a
backstop against a committed credential.

That backstop is config-dependent, which is easy to miss because a disarmed
scanner and a clean repository produce identical output. `.gitleaks.toml` at
the repository root supplies the configuration, and the workflow names it with
`--config` rather than relying on discovery under the bind mount. Three
properties of that file are load-bearing:

- `[extend] useDefault = true` keeps the built-in ruleset armed. A gitleaks
  config with no `[extend]` block *replaces* the built-in rules instead of
  adding to them, so the scanner loads nothing, reports `no leaks found`, and
  exits 0 while detecting nothing at all.
- The allowlist exempts one masked AWS key id that has been in
  `cfg_example.json` since January 2026, matched as a literal value. Exempting
  it by `paths` would clear every rule across that whole file, because gitleaks
  ORs the allowlist conditions together.
- The workflow passes `--config`, so a config the container cannot read fails
  loudly instead of silently falling back to the default ruleset.

`Test/Case/CiWorkflowTest.php` locks all three from inside the gate, so
breaking any of them red-lights the merge. The full account is in
`docs/solutions/integration-issues/`.

The Registry and database images are pinned by digest in
`Test/docker/docker-compose.yml`, so a pass or fail is attributable to the pull
request's code rather than image drift; bumping a pin is an explicit, reviewable
change. Set `OA4MP_TEST_REGISTRY_IMAGE` or `OA4MP_TEST_DATABASE_IMAGE` to try a
different image locally without editing the file.

## The live-server tier

A second, non-gating tier exercises a real admin client on `dev.cilogon.org`:
it creates, edits, verifies, and deletes real OIDC clients. It exists for the
half the hermetic tier cannot reach -- whether the server *accepts* what the
plugin sends. The hermetic public-client test asserts the marshalled cfg; only
the live tier proves the server takes it.

```bash
cp Test/.env.example Test/.env   # fill in the dedicated test admin client
Test/run-live.sh
```

`Test/.env` is gitignored and must never be committed. Use an admin client
provisioned solely for testing, scoped to the minimum privileges it needs and
distinct from any staff or production credential.

In CI the tier runs from `.github/workflows/live-server-tests.yml` on a schedule
(07:00 UTC daily) or on demand, on `main` only. The credential belongs in the
`live-server` GitHub Environment, as four environment secrets
(`OA4MP_LIVE_SERVER_URL`, `OA4MP_LIVE_ADMIN_IDENTIFIER`,
`OA4MP_LIVE_ADMIN_SECRET`, `OA4MP_LIVE_CO_ID`), with the environment's
deployment-branch policy restricted to `main`; the job additionally guards on
`github.ref` and on `github.repository`, and the workflow has no
`pull_request`, `pull_request_target`, or `workflow_run` trigger.
`Test/Case/CiWorkflowTest.php` runs in the hermetic gate and fails if any of
that wiring changes.

The `github.repository` guard is what keeps the tier off forks. A fork's
schedule fires from the fork's own default branch, and a fork must never hold
the credential, so a scheduled run there can only fail -- one did, nightly,
from the day this workflow reached `main`. The job is skipped on a fork
instead. Run the tier on a fork with `Test/run-live.sh` and your own test admin
client, which is where iterating on it belongs anyway.

**Not configured in CI yet.** The environment exists in name only -- GitHub
created it empty the first time the scheduled job referenced it -- so it holds
no secrets and carries no branch policy. Until both are set, the scheduled run
fails at `Test/run-live.sh`'s first credential check, and the `github.ref` guard
is the only thing keeping the (absent) credential off other refs, not the two
independent gates described above. A test asserting the workflow's YAML cannot
see any of that; it is repository configuration, not code.

The hermetic runner skips `Test/Case/LiveServer` entirely; the live tier runs
only via `./Console/cake Oa4mpClient.Oa4mp_test live`.

**First verified run: 2026-08-23**, against `dev.cilogon.org` from a developer
workstation with a dedicated test admin client -- all three tests passed, and
every client created was deleted. It confirmed the two things only a real server
can: that the server accepts a confidential client and issues it a secret, and
that it accepts a public client (`token_endpoint_auth_method: none`, scope
`openid`) without issuing one, which is the server-acceptance half of the
public-client cfg bug. It also exercised the extra-keys round trip for real: the
server returns roughly a dozen fields the plugin does not model, and they
survive an edit.

That run found four defects, each fixed with hermetic coverage: real client
secrets were printed in the clear by the server model's request/response
logging; `_txt()` was never bootstrapped in the console context, so the client
comment was an unresolved translation key *and* the sync comparison agreed with
itself about it; the sync comparison read two optional sections through missing
keys; and `registration_client_uri` was captured as an extra and echoed back to
the server. Expect the same pattern from any future first contact with a server
behaviour this tier has not seen.

One cosmetic artifact remains: `Router::url()` has no HTTP host in a console
context, so the URL after the signature in a client's comment reads
`http://localhost/oa4mp_client/...` rather than the Registry's own base URL.
The sync comparison checks the signature prefix only, so this does not affect
any verdict; it is visible on clients this tier creates.

**Known gap:** each test deletes the clients it created, including when it
fails, but a process killed mid-run can still leave a `oa4mp-live-test-` client
on the server. Sweeping those needs a way to list the admin client's clients,
which the plugin's server model does not expose today (it addresses clients only
by `client_id`). Until it does, orphan cleanup is manual.

## Known wrinkle

On a fresh database, `./Console/cake database` currently emits a non-fatal
`relation "cm_oa4mp_client_dynamo_configs" already exists` warning ("Possibly
failed to update database schema") while still returning success and leaving the
plugin's tables queryable. This is a `cake database` reconciliation quirk in the
plugin's schema (KTD2 territory, related to the raw-SQL foreign-key note in
`Config/Schema/schema.xml`).

Because that exit code cannot be trusted, `Test/run.sh` no longer relies on it:
it prints the step's output and then verifies the plugin's tables actually exist
in the database, failing the run if fewer than the schema's 15 distinct
`cm_oa4mp_client_*` tables are present. Without that post-condition a checkout
whose schema silently failed to apply would run its tests against the image's
baked-in released tables and still report success.
