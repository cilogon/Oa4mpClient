---
title: CakePHP hasOne Containable association returns a phantom all-null array, not empty — bare !empty() guard sent null DynamoDB config and blocked OIDC client edits
date: 2026-06-30
category: logic-errors
module: Oa4mpClient plugin (OIDC client cfg marshalling and sync verification)
problem_type: logic_error
component: rails_model
related_components:
  - rails_controller
severity: high
symptoms:
  - "PUT cfg dynamo_module_config serialized with every key null (region/access_key_id/secret_access_key/table_name/partition_key) despite a populated admin DefaultDynamoConfig"
  - "Registry log: Oa4mpClientDynamoConfig aws_region is out of sync (plugin=NULL, oa4mp=us-east-2), blocking the client edit"
root_cause: wrong_api
resolution_type: code_fix
tags: [cakephp, containable, hasone, association, phantom-null-array, dynamo-config, sync-verification, oa4mp]
---

# CakePHP hasOne Containable association returns a phantom all-null array, not empty

## Problem

When editing an OIDC client that had no per-client `oa4mp_client_dynamo_configs` row, the plugin both serialized an all-null `dynamo_module_config` into the PUT sent to the OA4MP server and reported the client perpetually out of sync — because a missing CakePHP `hasOne` association is read by Containable as an array of null-valued fields, not as an empty array. A bare `!empty()` guard on the association therefore selected the phantom config and skipped the `DefaultDynamoConfig` fallback.

## Symptoms

- Editing such a client produced a PUT `cfg` whose `dynamo_module_config` had every key null (`region`, `access_key_id`, `secret_access_key`, `table_name`, `partition_key`), even though a populated admin `DefaultDynamoConfig` existed and should have been used.
- The Registry log emitted `Oa4mpClientDynamoConfig aws_region is out of sync (plugin=NULL, oa4mp='us-east-2')`, and `isClientDataSynchronized()` returned false, blocking the client edit entirely.

## What Didn't Work

The first fix (commit `edda3fb`) tightened only the marshalling guard in `oa4mpMarshallCfgQdl()` from `!empty($data['Oa4mpClientDynamoConfig'])` to `!empty($data['Oa4mpClientDynamoConfig']['aws_region'])`. That was correct but **incomplete**: marshalling now sent the admin defaults, but `isClientDataSynchronized()` still independently read the same raw phantom array (`$curData['Oa4mpClientDynamoConfig']`), so it compared `plugin=NULL` against the server's now-real values and reported out-of-sync. Fixing one read site surfaced the second bug on the comparison path.

To confirm the root state, `cm_oa4mp_client_dynamo_configs` was queried directly: no per-client row existed for `client_id=175`, while the admin `DefaultDynamoConfig` (`admin_id=4`) was fully populated — proving the null fields came from CakePHP's LEFT JOIN, not from real data. (The same query also surfaced a duplicate `DefaultDynamoConfig` for `admin_id=4`, harmless here but worth deduping.)

## Solution

Commit `d354872` extracts one shared resolver and routes both paths through it.

The original bad guard:

```php
if(!empty($data['Oa4mpClientDynamoConfig'])) {      // phantom all-null array is non-empty -> always true
  $dynamoConfig = $data['Oa4mpClientDynamoConfig'];
} else {
  $dynamoConfig = $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'];  // never reached
}
```

The shared helper:

```php
function resolveDynamoConfig($data) {
  if(!empty($data['Oa4mpClientDynamoConfig']['aws_region'])) {
    return $data['Oa4mpClientDynamoConfig'];
  }
  return $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'] ?? array();
}
```

Both call sites now use it. In `oa4mpMarshallCfgQdl()` (the send path):

```php
$dynamoConfig = $this->resolveDynamoConfig($data);
```

And in `isClientDataSynchronized()` (the compare path):

```php
$curDynamo = $this->resolveDynamoConfig($curData);
if(!empty($curDynamo) && !empty($oa4mpServerData['Oa4mpClientDynamoConfig'])) {
  if($curDynamo['aws_region'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['aws_region']) {
    // ...out of sync
  }
}
```

## Why This Works

CakePHP 2.x Containable fetches a `hasOne`/`belongsTo` association via a LEFT JOIN, so a client with no matching related row yields an array whose keys are all present but null — not an empty array as `hasMany` would. A bare `!empty()` on that association array is therefore always truthy and silently selects the phantom config.

`aws_region` is `required` + `notBlank` in the `Oa4mpClientDynamoConfig` model validation, so it is guaranteed non-empty on any genuinely persisted row and reliably distinguishes a real config from the phantom.

The deeper invariant: "what we send" (marshalling) and "what we compare" (sync verification) must derive from one shared resolver. When they read the association independently, they drift — which is exactly the second bug, where the send path used defaults but the compare path used nulls.

## Prevention

- **Never guard a CakePHP `hasOne`/`belongsTo` Containable association with a bare `!empty()` on the association array.** It is always non-empty because the LEFT JOIN fills every column with null when no related row exists. Check a known `required`/`notBlank` field instead (here, `aws_region`), or filter out null values before testing. This is the array-shape sibling of the scalar `!empty(null)` pitfall noted in [oa4mp-cfg-unmarshall-swallowed-typeerror-2026-05-12](./oa4mp-cfg-unmarshall-swallowed-typeerror-2026-05-12.md).
- **Centralize any value two code paths must agree on.** The effective Dynamo config is computed both when marshalling the PUT and when comparing against the server; a single shared `resolveDynamoConfig()` makes divergence impossible. This is the same writer-vs-comparator-symmetry lesson as [oa4mp-unmarshall-claim-comparator-drift-2026-05-05](./oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md) and [oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18](./oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md).
- **Test gap:** this repo has no runnable suite (`Test/` is empty scaffolding), so the fix could not be covered by an automated test. A future test should assert that, given a `$data` array with an all-null `Oa4mpClientDynamoConfig` and a populated `Oa4mpClientCoAdminClient.DefaultDynamoConfig`, `resolveDynamoConfig()` returns the `DefaultDynamoConfig` values — and that `oa4mpMarshallCfgQdl()` and `isClientDataSynchronized()` consequently agree (no spurious out-of-sync) for a client with no per-client row.

## Related Issues

- [oa4mp-claim-migration-three-latent-bugs-2026-05-18](./oa4mp-claim-migration-three-latent-bugs-2026-05-18.md) — same file and DynamoConfig domain; its "Bug 1" blames a `DefaultDynamoConfig` missing `aws_region`. The fallback introduced here partially mitigates that failure model (refresh candidate).
- [oa4mp-unmarshall-claim-comparator-drift-2026-05-05](./oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md) — canonical writer/comparator phantom-drift doc; this is the successor lesson for the `hasOne` association shape.
- [oa4mp-legacy-orphan-claim-recovery-2026-05-19](./oa4mp-legacy-orphan-claim-recovery-2026-05-19.md) — operational runbook premised on `DynamoConfig->save()` failing when `DefaultDynamoConfig` lacks a `notBlank` field; same symptom surface, different mechanism (refresh candidate).
- [oa4mp-admin-client-hasone-duplicate-insert-2026-06-30](./oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md) — write-side counterpart of this read-side bug: the duplicate `DefaultDynamoConfig` rows noted in "What Didn't Work" (admin 4) are caused by the admin-client edit form omitting the hidden `DefaultDynamoConfig.id`, making CakePHP's associated-save INSERT instead of UPDATE.
- Commits: `edda3fb` (marshalling guard), `d354872` (shared `resolveDynamoConfig` across marshalling and sync), branch `fix/dynamo-config-fallback-hasone-phantom`.
