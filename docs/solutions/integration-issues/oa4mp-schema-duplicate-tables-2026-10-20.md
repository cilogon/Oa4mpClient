---
title: "Seven tables declared twice in schema.xml made cake database warn on every fresh install"
date: 2026-10-20
category: integration-issues
module: Oa4mpClient plugin (Config/Schema/schema.xml)
problem_type: integration_issue
component: database
symptoms:
  - "On a fresh database `./Console/cake database` prints \"Possibly failed to update database schema\" and `relation \"cm_oa4mp_client_dynamo_configs\" already exists`, yet exits 0 and every plugin table exists"
  - "A column added to oa4mp_client_authorizations has to be added at two places in schema.xml"
  - "schema.xml holds 22 <table> elements but only 15 distinct table names"
root_cause: config_error
resolution_type: code_fix
severity: low
related_components:
  - Config/Schema/schema.xml
  - Test/run.sh
  - Test/run-live.sh
  - Test/README.md
tags: [schema, adodb, cake-database, duplicate-table, table-order, foreign-key]
---

# Seven tables declared twice in schema.xml made cake database warn on every fresh install

## Problem

`Config/Schema/schema.xml` declared seven tables twice: `oa4mp_client_dynamo_configs`,
`oa4mp_client_claims`, `oa4mp_client_claim_constraints`, `oa4mp_client_refresh_tokens`,
`oa4mp_client_access_tokens`, `oa4mp_client_authorizations` and
`oa4mp_client_access_controls`. Commit `c8d29ad` ("Use DynamoDB to resolve claims")
appended a byte-identical second copy of the block after the first. Reported as
cilogon/Oa4mpClient#44, which listed six of the seven and missed
`oa4mp_client_dynamo_configs`.

## Root Cause

Registry's `cake database` (AdoDB xmlschema) builds its SQL by comparing each `<table>`
against the database as it was before the run. On a fresh database both copies of a table
look new, so it issues `CREATE TABLE` twice. The second create fails, Registry prints
"Possibly failed to update database schema", and the command still exits 0. The tables
exist because the first copy created them.

On an existing database the second copy is compared against a table that already matches,
so it is a no-op. That is why upgrades never showed the warning, and why nothing visibly
broke.

## Why it survived

The symptom was seen twice and explained away both times:

- `Test/README.md` recorded the warning as a "Known wrinkle" and put it down to a
  `cake database` reconciliation quirk.
- `Test/run.sh` and `Test/run-live.sh` noted that the schema had "22 <table> elements but
  only 15 unique table names" and counted unique names to work around it.
- `Test/Case/Model/ContractDeclarationTest.php` checked that the duplicate
  `oa4mp_client_claims` blocks agreed with each other rather than that there was only one.

Each workaround was reasonable where it was written. Together they kept the duplication
from failing anything. Meanwhile commit `14a725b` (no-record redirect) had to add
`no_record_redirect_url` to both copies of `oa4mp_client_authorizations`.

## Solution

Delete the second copy. The first copy stays because it is already in the right order:
`cake database` creates tables in file order, so every plugin table that a `REFERENCES`
constraint names must be declared before the table that references it, or a fresh install
fails to create the foreign key. No migration is needed, since an existing database
already matches the single remaining definition.

`Test/Case/Config/SchemaDeclarationTest.php` reads the schema text and fails when:

- any table name is declared more than once, or
- a table references a plugin table (`cm_oa4mp_client_*`) declared later in the file.

Each check has a positive control that alters the schema text (a repeated table, a
referenced table moved to the end) to prove the check fires.

## Prevention

- Treat "Possibly failed to update database schema" from the plugin's schema as a
  defect, not noise. Every other plugin in the Registry image applies its schema
  cleanly, so a warning that appears only for this plugin points at this plugin's
  schema.
- When a test or script has to work around something odd in a source file, fix the
  file or open an issue for it rather than writing the oddity down as expected.

## Related Issues

- cilogon/Oa4mpClient#44, fixed on branch `fix/schema-duplicate-tables` (merge pending).
- `docs/solutions/test-failures/oa4mp-test-runner-silent-pass-count-gate.md`, which
  quotes the same warning in its account of why `Test/run.sh` checks that the plugin's
  tables exist.
