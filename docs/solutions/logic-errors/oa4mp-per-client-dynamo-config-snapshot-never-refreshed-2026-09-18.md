---
title: A per-client DynamoDB configuration snapshot taken at client creation shadowed the admin client default forever, so rotated AWS credentials never reached the cfg
date: 2026-09-18
category: logic-errors
module: Oa4mpClient plugin (OIDC client edit, cfg marshalling)
problem_type: logic_error
component: rails_controller
related_components:
  - rails_model
severity: high
symptoms:
  - "An admin client's AWS Access Key ID and Secret Access Key were rotated; saving an OIDC client of that CO still sent the old credentials in the cfg"
  - "No error and no out-of-sync warning -- the save reported success"
root_cause: stale_cache
resolution_type: code_fix
tags: [cakephp, hasone, snapshot, staleness, dynamo-config, credential-rotation, cfg-marshalling, oa4mp]
---

# The per-client DynamoDB configuration was a snapshot nothing ever refreshed

## Problem

An operator rotated the AWS Access Key ID and Secret Access Key on a CO's admin
client, then opened an OIDC client of that CO and clicked SAVE with no other
change. The `cfg` written to OA4MP still carried the previous credentials, and
every later save carried them again.

## Root Cause

`Controller/Oa4mpClientCoOidcClientsController::add()` copies the admin client's
`DefaultDynamoConfig` into a per-client `oa4mp_client_dynamo_configs` row when a
client is created (`client_id` set, `admin_id` null) -- a snapshot, not a
reference. The comment there says as much: "For now we set the DynamoDB
configuration to the default."

`Oa4mpClientOa4mpServer::resolveDynamoConfig()` prefers that per-client row over
the admin default whenever its `aws_region` is populated, and
`oa4mpMarshallCfgQdl()` builds `dynamo_module_config.access_key_id` /
`secret_access_key` from whatever the resolver returns. Nothing else ever wrote
the per-client row: the only other writer is the legacy search-attribute
migration in `Oa4mpClientCoSearchAttribute::toClaim()`, which fires only for
unmigrated LDAP search attributes. So the credentials captured at creation were
re-sent forever.

Three things kept it quiet:

1. **No error.** The synchronization check resolves the configuration through
   the *same* resolver, so it compared the stale snapshot against an OA4MP
   server carrying those same stale values. They matched, the client was judged
   in sync, and the edit proceeded to a success flash.
2. **No visible state.** No view under `View/Oa4mpClientCoOidcClients/` renders
   the per-client DynamoDB configuration, so the row an operator was fighting
   was not on any page.
3. **A test that looked like coverage.** `ClaimCfgFallbackTest::
   testClientWithPerClientConfigEmitsItsOwnValues` asserted the per-client row
   wins over the default -- but seeded both rows with the *same* AWS key
   constants, so it could not tell them apart on the one field an operator
   actually rotates.

A client with no per-client row was never affected: it already fell back to the
admin default on every marshall.

## Solution

`Oa4mpClientDynamoConfig::refreshedFromAdminDefault()` brings the snapshot
forward from the admin client's default, and `edit()` uses it: the refreshed row
goes into the client data that is marshalled, and is persisted **after** the
OA4MP server has accepted the edit and **before** the client itself is saved.

Both ends of that ordering are load-bearing, and the synchronization check is
why. It compares the plugin's copy against the server's, so either copy moving
without the other reports the client as modified outside the Registry -- and the
next edit is gated by that same check, so the operator cannot correct anything
and recover.

- **Persist after the send.** Writing first would put the plugin ahead of the
  server whenever the server call failed.
- **Persist before the client save.** `saveAssociated()` can fail on its own
  validation -- an invalid home URL, a malformed contact address -- and that
  failure says nothing about what the server now holds. Persisting only on its
  success would leave the plugin behind the server on exactly that path.

The refresh declines in two cases, each of which would otherwise change behavior
rather than preserve it:

- **No real per-client row.** CakePHP's Containable returns the `hasOne`
  association as an array of null-valued fields rather than an empty array, so
  `aws_region` is the test, exactly as in `resolveDynamoConfig()` (see the
  related phantom-null document). Such a client already resolves to the admin
  default; creating a row for it would reintroduce the snapshot.
- **No admin default to copy.** Copying the phantom's nulls over a populated
  per-client row would empty the cfg the client is already being sent.

## Operational consequence

The fix is per-client and lazy: a client picks up a rotated credential the next
time it is saved, not when the admin client is saved. After a rotation, each
affected OIDC client must be saved once. Clients with no per-client row need
nothing.

Which clients are affected:

```sql
SELECT c.id, c.name, d.id AS config_id, d.aws_access_key_id
FROM cm_oa4mp_client_co_oidc_clients c
LEFT JOIN cm_oa4mp_client_dynamo_configs d ON d.client_id = c.id
WHERE c.admin_id = <admin id>;
```

A client with a row there carries a snapshot; a client without one already
tracks the default.

## Prevention

- **A copy of a configuration is a cache, and a cache needs a refresh path
  named at the time the copy is introduced.** The snapshot here was written as a
  deliberate "for now" and then behaved as authoritative for as long as the
  client existed.
- **A positive control that seeds both sides with the same value proves
  nothing about which side was read.** Where a test asserts "A wins over B",
  A and B must differ on every field the assertion could plausibly be about --
  especially the field an operator changes in production.
- **A comparison that resolves its inputs the same way the send path does
  cannot detect a stale input.** Symmetry between marshalling and the sync check
  is exactly what this plugin wants (see the comparator/marshaller asymmetry
  document), but it means staleness common to both sides is invisible. Freshness
  has to be established before the comparison, not by it.
- **Regression coverage:** `Test/Case/Model/DynamoDefaultRotationTest.php` in
  the hermetic suite (`Test/run.sh`) -- the rotated credential reaching the cfg,
  an unrefreshed control that still emits the snapshot, the in-place save, the
  two declined cases, and a source scan locking the controller wiring and its
  ordering. `ClaimCfgFallbackTest` now seeds the per-client row with different
  credentials from the default.
- **Known gap:** the suite has no harness for the OIDC clients controller (only
  the claims one, `Test/lib/Oa4mpClaimsControllerHarness.php`), so `edit()`
  itself is locked by source scan rather than driven. A harness for that
  controller would turn the wiring test into a behavioral one.

## Found alongside, not fixed here

`Oa4mpClientCoSearchAttribute::toClaim()` saves its per-client configuration with
`id` unset (`Model/Oa4mpClientCoSearchAttribute.php:649-656`), so it INSERTs
rather than updating whenever the client already has a row -- which, since
`add()` creates one for every client, is always. That is the duplicate-insert
shape of the admin-client document below, in the one call site its fix never
touched. It predates this change and is not reachable through the refresh (the
refreshed row keeps its id), but an edit that triggers the legacy migration can
now write the row twice in one request, from two different writers.

## Related Issues

- [oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30](./oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md)
  -- the phantom-null `hasOne` read, whose `aws_region` test this fix reuses to
  decide whether a per-client row is real.
- [oa4mp-admin-client-hasone-duplicate-insert-2026-06-30](./oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md)
  -- the write-side sibling. The refreshed row keeps its own id for the same
  reason: a save without it inserts a second configuration.
- [oa4mp-comparator-marshaller-asymmetry-2026-08-22](./oa4mp-comparator-marshaller-asymmetry-2026-08-22.md)
  -- the symmetry this bug hid behind.

Branch `fix/dynamo-config-refresh-from-admin-default`; merge pending.
