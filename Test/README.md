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

Remaining regressions to add (each needs heavier fixtures; seams identified):

- **empty-type never serialized** (#3c) -- the `&&` guard in
  `Oa4mpClientOa4mpServer::oa4mpMarshallCfgQdl` (~line 876). Needs a full
  ldap-config + claim + `Oa4mpClientClaimConstraint` cfg fixture; assert a
  `{constraint_field: type, constraint_value: ''}` constraint is dropped.
- **foreach loop-variable leak** (#3b) -- `buildClaimFromLdapMapping`
  (line 1351) with a no-match attribute set; assert no leaked wrong `type`.
  Needs `adminClient` CoLdapProvisionerTarget attributes.
- **non-atomic save / orphan claims** (#3a) -- `Oa4mpClientCoSearchAttribute::toClaim`.
  DB-transactional: needs a forced middle-save failure (a `DefaultDynamoConfig`
  missing a notBlank field) and an assertion that no orphan claim row remains.
- **comparator drift** (#7) -- `isClientDataSynchronized` (~line 431), full
  round-trip of a marshalled cfg unmarshalled and compared in-sync across
  QDLv3/QDLv2/deprecated formats.
- **view double-encoding** (#9, U6) and **admin-client duplicate insert**
  (#1, U6); the **authorization matrix** (U3, `Oa4mpClientAuthzComponent::permissionSet`)
  which is coupled to Registry's Session/Role/CoGroupMember and needs the most
  scaffolding.

## Known wrinkle

On a fresh database, `./Console/cake database` currently emits a non-fatal
`relation "cm_oa4mp_client_dynamo_configs" already exists` warning ("Possibly
failed to update database schema") while still returning success and leaving the
plugin's tables queryable. This is a `cake database` reconciliation quirk in the
plugin's schema (KTD2 territory, related to the raw-SQL foreign-key note in
`Config/Schema/schema.xml`). It does not block the suite, but U3 should confirm
the overlaid plugin's schema fully applies for a schema-changing checkout (AE2).
