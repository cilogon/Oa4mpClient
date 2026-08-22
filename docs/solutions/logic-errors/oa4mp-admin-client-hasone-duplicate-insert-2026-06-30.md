---
title: Admin-client edit form omitted hidden DefaultDynamoConfig.id, so CakePHP associated-save inserted a duplicate row on every SAVE
date: 2026-06-30
category: logic-errors
module: Oa4mpClient plugin (admin client edit form)
problem_type: logic_error
component: rails_view
related_components:
  - rails_controller
  - rails_model
severity: medium
symptoms:
  - "Each SAVE on the admin-client edit page created a new oa4mp_client_dynamo_configs row instead of updating in place (admin 4 accumulated ids 5,41,42,43; admin 10 got 1,30)"
  - "Duplicate admin-default DynamoDB config rows (client_id IS NULL, same admin_id) with identical values"
root_cause: wrong_api
resolution_type: code_fix
tags: [cakephp, hasone, associated-save, saveall, hidden-id, insert-vs-update, dynamo-config, oa4mp, duplicate-rows]
---

# Admin-client edit form omitted hidden DefaultDynamoConfig.id, so associated-save inserted a duplicate row

## Problem

Editing an admin client (`oa4mp_client_co_admin_clients/edit/<id>`) and clicking SAVE created a new `oa4mp_client_dynamo_configs` row on every save instead of updating the existing `DefaultDynamoConfig` in place, accumulating duplicate admin-default rows.

## Symptoms

- Repeated SAVEs grew the duplicate set: admin 4 went `5` → `5,41` → `5,41,42` → `5,41,42,43`; admin 10 had `1,30`.
- All duplicates were identical in content (`client_id IS NULL`, same `admin_id`, same AWS region/table/partition values) — the form was prefilled with the existing values and re-inserted them.
- Detection query:
  ```sql
  SELECT admin_id, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
  FROM cm_oa4mp_client_dynamo_configs
  WHERE client_id IS NULL AND admin_id IS NOT NULL
  GROUP BY admin_id
  HAVING COUNT(*) > 1;
  ```

## What Didn't Work

Two traps slowed this down:

1. **First fix appeared insufficient — but it was a deploy gap, not a wrong fix.** After adding the hidden `id` field, a re-test still produced a new row (id 43). The fix had not actually been copied into the deployed registry-test checkout. Once the edited file was deployed, saving updated in place and no new row was created. Lesson: before concluding "the fix didn't work," confirm the changed file is the one actually running — a local commit on an unmerged/undeployed branch does not change a running server.
2. **A wrong source variable would have rendered an empty id.** The hidden field must read the id from the same data the form binds to. The view shows current Dynamo values via one variable while the hidden id mirrors the sibling `DefaultLdapConfig.id`, which reads from `$oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['id']`. Using a different/absent source would render an empty default → no id in the POST → still an INSERT.

## Solution

`View/Oa4mpClientCoAdminClients/fields.inc` rendered hidden primary-key fields for `DefaultLdapConfig` and the email address, but not for `DefaultDynamoConfig`. Add the missing hidden field inside the edit-mode (`$e`) guard, mirroring the `DefaultLdapConfig.id` sibling exactly:

```php
if(isset($oa4mp_client_co_admin_clients) && $e) {
  print $this->Form->hidden('DefaultLdapConfig.id', array('default' => $oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['id'])) . "\n";
  // Without the existing DefaultDynamoConfig.id in the POST, CakePHP's
  // associated save inserts a new oa4mp_client_dynamo_configs row on every
  // edit instead of updating in place (one duplicate per SAVE). Mirror the
  // DefaultLdapConfig.id hidden field above so the hasOne save is an UPDATE.
  print $this->Form->hidden('DefaultDynamoConfig.id', array('default' => $oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['id'])) . "\n";
  print $this->Form->hidden('Oa4mpClientCoEmailAddress.0.id', ...) . "\n";
  ...
}
```

Pre-existing duplicates were then removed by the developer with a one-time, transaction-wrapped, keep-lowest-id dedup (all rows confirmed identical first; `DefaultDynamoConfig` is looked up by `admin_id`, and no row stores a reference to a specific config id, so deleting by id is referentially safe).

## Why This Works

`edit()` defers to `parent::edit()` (COmanage `StandardController`), which persists `$this->request->data` via CakePHP's associated save (`saveAll`/`saveAssociated`). For a `hasOne`/`belongsTo` child, that save UPDATEs the existing row only when the submitted child data carries its primary key. With no `id` in the POST, CakePHP treats the child as a new record and INSERTs it, setting the foreign key (`admin_id`) from the parent — a fresh row per save.

A CakePHP form only submits fields it actually renders. The visible `DefaultDynamoConfig.*` inputs were rendered (so each duplicate is fully populated), but the `id` was never rendered, so it never reached the POST. The sibling `DefaultLdapConfig`, which *does* render its hidden `id`, UPDATEd correctly and never duplicated — the exact behavioral contrast that confirms the mechanism.

## Prevention

- **For any CakePHP 2.x `hasOne`/`belongsTo` child you want an associated save to UPDATE, render a hidden field for the child's primary key** (`Model.id`, or `Model.N.id` for `hasMany`) in the edit form. A missing id silently becomes an INSERT — no error, no validation failure, just a duplicate row. When adding a new associated model to an existing edit form, check the hidden-id block, not just the visible inputs.
- **Rendering the hidden id is necessary but not sufficient — the value it binds to must be a real, non-empty id.** FormHelper does not omit a hidden field whose bound value is missing; it submits `DefaultDynamoConfig.id=""`. CakePHP's `Model::getID()` treats an empty id as no id (`empty('')` is true), so `exists()` is false and the associated save INSERTs a duplicate anyway. A phantom-null `hasOne` read (see the related read-side doc) is one way the bound value goes empty while the markup still looks correct. Locked by `AdminClientEditSaveTest::testSaveWithEmptyStringDynamoConfigIdInsertsDuplicate`.
- **Read the hidden id from the same data the rest of that association binds to.** Mirror an existing working sibling (here `DefaultLdapConfig.id`) rather than inventing a new source variable.
- **Confirm deploy before judging a fix.** A persistent symptom after a fix may mean the changed file isn't the one running, not that the diagnosis is wrong.
- **Regression coverage:** locked by `Test/Case/Controller/AdminClientEditSaveTest.php` in the hermetic suite (`Test/run.sh`, gated by `.github/workflows/hermetic-tests.yml`). `testSaveWithDynamoConfigIdUpdatesInPlace` saves twice with the id present and asserts exactly one `cm_oa4mp_client_dynamo_configs` row for the `admin_id` remains, updated in place; `testSaveWithoutDynamoConfigIdInsertsDuplicate` characterizes the pre-fix behaviour; `testEditFormRendersHiddenDynamoConfigId` is the lock proper — it asserts the `if(isset($oa4mp_client_co_admin_clients) && $e)` guard in `fields.inc` still contains `Form->hidden('DefaultDynamoConfig.id'`. `Test/README.md` tracks this as bug #1.

## Related Issues

- [oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30](./oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md) — Sibling CakePHP 2.x `hasOne` DynamoConfig pitfall. That doc is the **read-side** bug (Containable returns a phantom all-null association array, fooling a bare `!empty()` guard); this is the **write-side** bug (missing hidden `id` → duplicate INSERT). That doc noticed the duplicate `DefaultDynamoConfig` rows for admin 4; this doc fixes their cause.
- [oa4mp-claim-migration-three-latent-bugs-2026-05-18](./oa4mp-claim-migration-three-latent-bugs-2026-05-18.md) — Same `DefaultDynamoConfig` persistence domain (its "Bug 1" concerns a DynamoConfig save), though the mechanism there is a non-atomic model save, not a view field omission.
- Commit `f48a68e` (branch `fix/admin-client-dynamo-config-duplicate-insert`), now on `main` and on `upstream/main`. It predates this repository's pull-request workflow, so there is no pull request to cite.
