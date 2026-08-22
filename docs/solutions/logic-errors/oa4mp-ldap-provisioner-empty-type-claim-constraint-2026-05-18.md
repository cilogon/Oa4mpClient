---
title: OA4MP claim type-constraint drift from LdapProvisioner "All Types" empty-string sentinel
date: 2026-05-18
category: logic-errors
module: Oa4mpClient plugin (OIDC client sync verification)
problem_type: logic_error
component: rails_model
severity: high
symptoms:
  - "Error: Oa4mpClientClaim: Number of claims is out of sync (plugin=2, oa4mp=3)"
  - "saveAssociated failed for claim with validation error: constraint_value This field cannot be left blank"
  - LDAP search attribute voPersonApplicationUID retains claim_id NULL despite being present in the OIDC client LDAP config
  - Search attribute silently fails to migrate to a claim-constraint row, breaking later sync verification
  - In-memory claim from buildClaimFromLdapMapping never matches server-side stored required_type
root_cause: logic_error
resolution_type: code_fix
related_components:
  - Model/Oa4mpClientCoSearchAttribute.php
  - Model/Oa4mpClientOa4mpServer.php
  - Oa4mpClientClaimConstraint validation
  - COmanage LdapProvisioner plugin
  - OA4MP server QDL
tags:
  - oa4mp
  - ldap-provisioner
  - claim-constraint
  - all-types-sentinel
  - empty-string-normalization
  - silent-drift
  - comparator
  - notblank-validation
---

# OA4MP claim type-constraint drift from LdapProvisioner "All Types" empty-string sentinel

## Problem

The COmanage Registry OA4MP Client plugin copied an LdapProvisioner attribute's `type` value verbatim into the `'type'` claim-constraint, but LdapProvisioner persists the "All Types" UI choice as an empty string, while the OA4MP server's QDL expects the literal sentinel `'all'`. The plugin emitted empty-string `'type'` constraints on both the writer path and the sync-comparator path, producing validation failures, save aborts, and silent count drift between the plugin's view of claims and the OA4MP server's view.

## Symptoms

- `Error: Oa4mpClientClaim: Number of claims is out of sync (plugin=2, oa4mp=3)` — silent drift surfaced as a count mismatch, not an exception.
- `Error: saveAssociated failed for claim preferred_username Array(...)` during the LDAP-config-to-claim migration in `Oa4mpClientCoOidcClientsController::edit`.
- `Error: Validation errors are Array([Oa4mpClientClaimConstraint] => Array([0] => Array([constraint_value] => Array([0] => This field cannot be left blank))))` — the `notBlank` rule in `Model/Oa4mpClientClaimConstraint.php:58-62` rejected the empty `constraint_value`.
- `cm_oa4mp_client_co_search_attributes` row for `voPersonApplicationUID` had `claim_id = NULL` even though the OIDC client's LDAP config referenced it (saveAssociated bailed before the search-attribute back-pointer could be written).

## What Didn't Work

Two earlier hypotheses (carried in from a paused prior session) were wrong:

1. **Omitting the `'type'` constraint entirely when the provisioner type was empty.** Semantically this matches "no type filter", but it would have caused drift on the sync-comparator path: the plugin side would emit a claim with one fewer constraint than the OA4MP side. It also contradicts the convention already established in the same files, which always emits an explicit `'type'` constraint and uses the sentinel `'all'` for the catch-all case (6 hardcoded occurrences across `Model/Oa4mpClientCoSearchAttribute.php` and `Model/Oa4mpClientOa4mpServer.php` for `gecos`, `givenName`, `sn`).

2. **Treating the cfg-writer empty-guard at `Model/Oa4mpClientOa4mpServer.php:836-838` (today `:876-878`) as the primary fix site.** That block used `||` instead of `&&` in its guard — `if(!empty($constraintMapping['constraint_field']) || !empty($constraintMapping['constraint_value']))` — which *would* emit a degenerate `{type: ""}` to the OA4MP server if a row ever reached it. But it was a red herring for this bug: nothing reached it, because the `notBlank` validation on `constraint_value` blocked the writer at site 1 before any row was ever persisted, let alone serialized to cfg. (The `||`-vs-`&&` guard audit has since been completed: the operator was aligned to `&&` to match the comment's stated AND-intent, and the guard documented as defense-in-depth, in commit `7684cbb`. See `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`. The change was invisible against today's data — the guard is unreachable on current code paths — but the operator now matches the comment's stated intent.)

A secondary observation also surfaced after the fix landed: the controller migration block at `Controller/Oa4mpClientCoOidcClientsController.php:455` used a coarse `$alreadyMigrated = !empty($client['Oa4mpClientClaim'])` gate that prevented re-running migration on partially-migrated clients. To confirm today's fix end-to-end we had to reset the DB with SQL to re-trigger migration. That coarse-gate issue has since been resolved: the underlying non-atomicity in `toClaim()` was root-caused (a `DynamoConfig::save()` between the claim insert and the back-pointer save could fail and strand the back-pointer), the saves were reordered to land atomically, and the coarse gate was removed (commit `1659690`). Partial-migration recovery now works without DB reset. See `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`.

## Solution

Normalize empty-string `type` to the sentinel `'all'` at **both** the writer and the sync-comparator, so the two sides of the wire stay in lockstep and the existing `'all'` convention is honored. Fixed in commit `f298ba0` on branch `fix/ldap-provisioner-attributes-no-type`.

### Site 1 — Writer/migration path

`Model/Oa4mpClientCoSearchAttribute.php`. Originally applied inline in `toClaim()`; since the `attr_opts` work landed, the normalization lives in the shared helper `computeVoPersonApplicationUidConstraint()` (`Model/Oa4mpClientCoSearchAttribute.php:119`), which `toClaim()` (now at `:235`) calls at `:471`. The "After" block below is the shape as originally applied; the helper's current bare-literal branch is quoted under "Later extension".

Before:

```php
$claimConstraints[] = array(
  'constraint_field' => 'type',
  'constraint_value' => $ldapProvisionerAttribute['type']
);
```

After:

```php
// The LdapProvisioner attribute config allows an "All Types" choice, which
// is persisted as an empty string in cm_co_ldap_provisioner_attributes.type.
// The OA4MP server's QDL expects the literal 'all' in that case.
$provisionerType = $ldapProvisionerAttribute['type'];
if($provisionerType === '' || $provisionerType === null) {
  $provisionerType = 'all';
}

$claimConstraints[] = array(
  'constraint_field' => 'type',
  'constraint_value' => $provisionerType
);
```

### Site 2 — Sync comparator path

`Model/Oa4mpClientOa4mpServer.php`, `buildClaimFromLdapMapping()` (function at `:1351`). Originally a mirrored copy of the writer's normalization; it now calls the *same* helper at `:1574`, so writer and comparator cannot drift by construction. Both sites must produce identical structures so the comparison in `isClientDataSynchronized` lines up exactly.

### Later extension — CoService-derived effective filter

For `voPersonApplicationUID` on a `CoLdapProvisionerTarget` with `attr_opts` enabled, the helper returns a uniform anchored regex over the CO's `CoService.identifier_type` set (`^X$` / `^(A|B)$`), or `null` meaning "suppress the claim entirely". The empty → `'all'` rule documented here is the bare-literal branch (`attr_opts` off, or any search attribute other than `voPersonApplicationUID`):

```php
// Model/Oa4mpClientCoSearchAttribute.php:126-131
if(!$useCoServiceFilter) {
  if($ldapProvisionerAttributeType === '' || $ldapProvisionerAttributeType === null) {
    return 'all';
  }
  return $ldapProvisionerAttributeType;
}
```

See `docs/plans/2026-05-19-001-fix-oa4mp-attr-opts-claim-constraint-plan.md`.

### Validation rule that exposed the bug

`Model/Oa4mpClientClaimConstraint.php:58-62`:

```php
'constraint_value' => array(
  'rule' => 'notBlank',
  'required' => true,
  'allowEmpty' => false
)
```

This rule is correct and stays as-is — it is what made the silent-drift bug *loud* on the write path. Without it, the empty-string constraint would have been silently persisted and only surfaced later as a count drift against the OA4MP server.

Regression coverage: `Test/Case/Model/ClaimMigrationTest.php::testEmptyTypeNormalizesToAll` and `::testRealTypeIsPreserved` lock this normalization (commit `1600944`).

### Reproduction-reset SQL

This SQL was originally used to work around the coarse `$alreadyMigrated` migration gate at `Controller/Oa4mpClientCoOidcClientsController.php:455` (`!empty($client['Oa4mpClientClaim'])`), which prevented re-running migration on a partially-migrated client. With the gate removed in commit `1659690` (see `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`), this reset is no longer required for partial-migration recovery — but remains a useful debugging aid for forcing re-exercise of the write path on a fresh client state. Substitute the actual claim IDs from `cm_oa4mp_client_claims WHERE client_id = <id>`:

```sql
START TRANSACTION;
DELETE FROM cm_oa4mp_client_claim_constraints WHERE claim_id IN (<claim_ids>);
DELETE FROM cm_oa4mp_client_claims WHERE client_id = <client_id>;
UPDATE cm_oa4mp_client_co_search_attributes sa
  JOIN cm_oa4mp_client_co_ldap_configs lc ON lc.id = sa.ldap_id
  SET sa.claim_id = NULL
  WHERE lc.client_id = <client_id>;
COMMIT;
```

## Why This Works

The root cause is an **empty-string-as-sentinel mismatch between two systems on opposite sides of a wire**:

- **LdapProvisioner side (storage):** the `cm_co_ldap_provisioner_attributes.type` column uses `''` (empty string) to encode the "All Types" choice from the UI dropdown.
- **OA4MP QDL side (consumer):** the server's QDL expects a non-empty string and treats the literal `'all'` as the "no specific type" sentinel.

The plugin sits in the middle and was passing the empty string through unmodified. Normalizing empty → `'all'` at every emit site makes the plugin's output conform to what the QDL expects, and `'all'` is the right normalization target for three reasons:

1. **Convention is already established.** The same two files hardcode `'all'` in 6 places when constructing constraints for `gecos`, `givenName`, and `sn`. The fix extends that convention to the dynamic case instead of inventing a new one.
2. **The QDL contract requires a non-empty string.** Omitting the constraint or sending `''` would both be wrong; only an explicit sentinel satisfies the consumer's contract.
3. **Symmetry across writer and comparator.** Applying the same normalization at both sites means the structure produced by `toClaim()` and the structure produced by `buildClaimFromLdapMapping()` agree, so `isClientDataSynchronized` no longer reports phantom drift.

The plugin's own `notBlank` validation on `constraint_value` was load-bearing here: it converted what would otherwise be silent drift into a loud `saveAssociated` failure, which is what made the bug diagnosable in the first place.

## Prevention

1. **Audit every emit-site when a UI "All / Any / None" dropdown is backed by an empty string in the DB.** Whenever a column's domain includes a sentinel-as-empty-string value, grep for every read of that column and confirm that downstream consumers either (a) accept empty string or (b) get a normalized value. Don't assume the empty string is harmless — it almost always means "pick a sentinel" somewhere downstream.

2. **Sync comparators must apply the same normalization as writers.** Any time you have a `buildX()` helper that constructs an expected shape for comparison against a peer system, it must mirror the writer that produced the shape originally. Cross-file sentinel handling is a classic drift source. A grep for the sentinel value (`'all'` here) should hit both files in lockstep. This extends the lockstep-mirror discipline already documented in `oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` from "switch-case parity" to "per-field value-normalization parity."

3. **Treat repeated hardcoded sentinels as a convention worth honoring (and possibly centralizing).** When you see the same magic string (`'all'`) appear 6 times across two files for the same purpose, that is a convention. New code paths feeding the same consumer must use the same sentinel. Consider extracting a class constant like `Oa4mpClientCoSearchAttribute::TYPE_ANY = 'all'` so future emit-sites can't drift.

4. **Silent-drift bugs (count mismatch with no exception) need per-side diagnostic detail.** A `claims out of sync (plugin=2, oa4mp=3)` message tells you *that* something is wrong but not *what*. This plugin's `isClientDataSynchronized` was upgraded in commit `26d5ae3` to log per-side detail, which shortened the diagnostic loop substantially. When adding cross-system sync checks, always include per-side dumps in the drift log — the marginal cost is small and the diagnostic payoff is large.

5. **Validation that rejects malformed sentinels is a feature, not friction.** The `notBlank` rule on `constraint_value` is what made this bug loud on the write path. Keep that kind of guard in place even when you "know" callers handle the sentinel correctly — it's the trip-wire that turns silent drift into a stack trace.

6. **When investigating a multi-site bug, walk the call chain in order of execution.** The cfg-writer guard at `Oa4mpClientOa4mpServer.php:836-838` (today `:876-878`) looked suspicious but was downstream of the actual failure. Validation blocked at site 1 (the writer) before site 2 (cfg serialization) could ever run. Always confirm which guard fires first before optimizing the wrong one.

## Related Issues

- `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` — introduces the `buildClaimFromLdapMapping` comparator helper this bug lives inside and documents the writer/comparator lockstep-mirror discipline that this fix demonstrates needs to extend from switch-case parity to value-normalization parity.
- `docs/solutions/logic-errors/oa4mp-cfg-unmarshall-swallowed-typeerror-2026-05-12.md` — same model file (`Oa4mpClientOa4mpServer.php`), unrelated function and unrelated angle (exception swallowing in cfg-format detection). Listed for navigation only.
