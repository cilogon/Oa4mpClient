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

## Writing tests

A test case extends `Oa4mpTestCase` (`Test/lib/Oa4mpTestCase.php`) and defines
`test*` methods; put it under `Test/Case/` (one subdirectory deep is discovered).
The runner shell `Console/Command/Oa4mpTestShell.php` finds and runs them.
`Test/Case/HarnessSelfTest.php` is the reference example. Most core-logic tests
assert the marshalled cfg and database state directly and need no server; the
few that must simulate a server response use `Test/Stub/Oa4mpServerStub.php` with
a captured response under `Test/fixtures/oa4mp-responses/`.

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
- **comparator drift** (#7) -- `isClientDataSynchronized` reports in-sync for a
  matching pair and out-of-sync for a real difference (SyncVerificationTest).
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

All nine documented bugs are locked. Each regression was additionally verified
red by temporarily restoring the documented pre-fix code path (or, for the
authorization matrix, by mutating the rule under test) and observing only its
own test fail.

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

The Registry and database images are pinned by digest in
`Test/docker/docker-compose.yml`, so a pass or fail is attributable to the pull
request's code rather than image drift; bumping a pin is an explicit, reviewable
change. Set `OA4MP_TEST_REGISTRY_IMAGE` or `OA4MP_TEST_DATABASE_IMAGE` to try a
different image locally without editing the file.

## Known wrinkle

On a fresh database, `./Console/cake database` currently emits a non-fatal
`relation "cm_oa4mp_client_dynamo_configs" already exists` warning ("Possibly
failed to update database schema") while still returning success and leaving the
plugin's tables queryable. This is a `cake database` reconciliation quirk in the
plugin's schema (KTD2 territory, related to the raw-SQL foreign-key note in
`Config/Schema/schema.xml`). It does not block the suite, but U3 should confirm
the overlaid plugin's schema fully applies for a schema-changing checkout (AE2).
