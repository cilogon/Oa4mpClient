---
title: The claim migration saved the per-client DynamoDB configuration with the id unset, inserting one duplicate row per migrated search attribute
date: 2026-09-18
category: logic-errors
module: Oa4mpClient plugin (legacy LDAP search-attribute migration)
problem_type: logic_error
component: rails_model
related_components:
  - rails_controller
severity: medium
symptoms:
  - "Duplicate rows in cm_oa4mp_client_dynamo_configs for the same client_id, one per migrated LDAP search attribute"
  - "A client that already had a per-client configuration row gained another one when its edit page migrated legacy search attributes"
root_cause: wrong_api
resolution_type: code_fix
tags: [cakephp, hasone, insert-vs-update, dynamo-config, claim-migration, duplicate-rows, oa4mp]
---

# The claim migration inserted a per-client DynamoDB configuration instead of updating one

## Problem

Opening the edit page for an OIDC client with unmigrated LDAP search attributes
migrated each one to a claim -- and wrote a new `cm_oa4mp_client_dynamo_configs`
row for every attribute it migrated, beside any row the client already had.

## Root Cause

`Oa4mpClientCoOidcClientsController::edit()` calls
`Oa4mpClientCoSearchAttribute::toClaim()` once per unmigrated search attribute,
passing the admin client's `DefaultDynamoConfig`. `toClaim()` set `client_id` on
that array and unset `admin_id`, `id`, `created` and `modified` before saving.

With no `id`, `Model::getID()` is empty, so `exists()` is false and CakePHP's
save is an INSERT -- the same mechanism as the admin-client edit form's missing
hidden id field, in the one call site that fix never touched. Three unmigrated
search attributes therefore produced three identical configuration rows.

Dropping the incoming `id` was not itself wrong: the id that arrives belongs to
the **admin client's default row**, and saving with it would update the default,
reconfiguring every client of that admin client from one client's migration.
What was missing is the step after dropping it -- finding the client's own row
and carrying *that* id in.

## Why duplicates are worse here than clutter

`Oa4mpClientDynamoConfig` is a `hasOne`. A read returned one row with no
`ORDER BY`, and which one was unspecified. Both
`Oa4mpClientOa4mpServer::resolveDynamoConfig()` (marshalling and the
synchronization check) and
`Oa4mpClientDynamoConfig::refreshedFromAdminDefault()` act on whichever row
comes back, so a credential refresh could update one duplicate while the client
kept being marshalled from another.

This is not theoretical. With the read left unordered,
`testLegacyDuplicatesAreWrittenAndReadOnTheSameRow` fails on PostgreSQL with the
write landing on the lower id and the read returning the higher one -- the
migration reports success, and nothing an operator can see has changed.

## Solution

Two halves, because a write-side rule is meaningless if the read disagrees.

**The write.** `toClaim()` looks up the client's existing configuration row,
lowest id first, and carries its id into the save, so the save is an UPDATE. It
inserts only when the client genuinely has none, which is the legacy case the
migration exists to serve.

**The read.** `Oa4mpClientCoOidcClient::current()` orders its
`Oa4mpClientDynamoConfig` contain by `id ASC`, so a client carrying duplicates
is read from the same row every writer targets -- and the row a keep-lowest-id
dedup retains.

The order sits in the `contain` rather than on the `hasOne` association because
an association-level order is carried into `find('count')`, where PostgreSQL
rejects it: `column "Oa4mpClientDynamoConfig.id" must appear in the GROUP BY
clause`. That failure is how the association-level version was caught, in
`HarnessSelfTest`.

Pre-existing duplicates are not cleaned up by this change. The detection query
from the admin-client document applies with `client_id` in place of `admin_id`:

```sql
SELECT client_id, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
FROM cm_oa4mp_client_dynamo_configs
WHERE client_id IS NOT NULL
GROUP BY client_id
HAVING COUNT(*) > 1;
```

## Prevention

- **For a CakePHP 2.x `hasOne`/`belongsTo` child you want to UPDATE, the save
  needs the child's own primary key** -- in a form that means rendering the
  hidden id, and in model code it means looking the row up rather than assuming
  the id you were handed is the right one. An id from the wrong row is not
  safer than no id; it is a different bug.
- **Asserting that a failed write persists nothing does not tell you what a
  successful one writes.** The migration's configuration save already had a
  test, and it only covered the validation-failure path, so the insert-versus-
  update question was never asked. When a test asserts a row count after a
  failure, ask what the same count is after two successes.
- **Regression coverage:** `Test/Case/Model/ClaimMigrationPersistenceTest.php` --
  `testMigratingTwoSearchAttributesWritesOneDynamoConfig` (two migrations, one
  row), `testMigrationUpdatesAnExistingDynamoConfigInPlace` (the row a client
  created through `add()` already has), and
  `testMigrationDoesNotWriteThroughToTheAdminDefault`, which passed before this
  fix and pins the guard the fix must not break.

## Known limits

- **Concurrency.** The lookup and the save are two unguarded statements, and the
  schema has no unique constraint on `client_id`. Two simultaneous migrations of
  a client that has *no* row yet can both find none and both insert. The window
  is narrow and reachable only for a client that legitimately lacks a row (its
  admin client had no default when it was created, or the row was cleaned up),
  since `add()` gives every normally created client one. A unique index on
  `client_id` would close it, and would also have prevented this bug outright,
  but it cannot be added while duplicate rows exist -- the dedup comes first.
- **Existing duplicates are not repaired**, only rendered harmless: every reader
  and writer now agrees on the lowest id, so the extra rows are inert until an
  operator removes them.

## Related Issues

- [oa4mp-admin-client-hasone-duplicate-insert-2026-06-30](./oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md)
  -- the same insert-instead-of-update mechanism on the admin-client edit form.
  That document's Prevention section is where this call site should have been
  caught.
- [oa4mp-per-client-dynamo-config-snapshot-never-refreshed-2026-09-18](./oa4mp-per-client-dynamo-config-snapshot-never-refreshed-2026-09-18.md)
  -- found this bug. Its review asked what happens when the refresh and the
  migration both write the per-client row in one request; with this fix they
  write the same row.
- [oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30](./oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md)
  -- the read side of the same hasOne.
