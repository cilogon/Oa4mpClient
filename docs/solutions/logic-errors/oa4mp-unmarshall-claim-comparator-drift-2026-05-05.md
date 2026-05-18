---
title: OA4MP unmarshall and claim sync comparator drift on legacy cfg formats
date: 2026-05-05
category: logic-errors
module: Oa4mpClient plugin (OIDC client sync verification)
problem_type: logic_error
component: rails_model
related_components:
  - rails_controller
severity: high
symptoms:
  - Sync verification reports false drift on every pass for legacy-cfg (QDLv2 and deprecated format-1) OIDC clients
  - QDLv3 cfg clients also report false drift due to mismatched key names between unmarshaller and comparator
  - Database accumulates duplicate Oa4mpClientClaim rows on every edit of a deprecated-cfg client (typically two new rows per edit)
  - Comparator log message names the symptom but prints no per-side data, blocking root-cause investigation
  - Oa4mpClientCoSearchAttribute.claim_id reads back as null after edit even when toClaim saveField apparently succeeded
root_cause: logic_error
resolution_type: code_fix
tags:
  - oa4mp
  - oidc
  - sync-verification
  - comparator
  - claim
  - legacy-cfg
  - idempotency
  - cakephp
---

# OA4MP unmarshall and claim sync comparator drift on legacy cfg formats

## Problem

The COmanage Registry OA4MP Client plugin's sync-verification flow (`oa4mpVerifyClient()`) produced spurious "Oa4mpClientClaim out of sync" warnings for OIDC clients whose cfg was stored in legacy formats (QDLv2 or deprecated format 1). Even clients using the modern QDLv3 format triggered false positives due to mismatched array key names. A separate but related bug caused duplicate `Oa4mpClientClaim` rows to accumulate in the database on every edit of a deprecated-cfg client, compounding the false-positive rate over time.

## Symptoms

- Log lines reading `Number of claims is out of sync` (or similar) appearing on every sync-verify pass for affected clients, with no additional context identifying which side had what.
- "Plugin-empty" and "server-empty" out-of-sync branches firing even when both the OA4MP server and the plugin database clearly had claim data configured.
- Clients using QDLv3 cfg triggering out-of-sync warnings despite the unmarshaller running the correct code path.
- Clients using QDLv2 or deprecated format-1 cfg triggering out-of-sync warnings on every verify run, regardless of whether the actual OIDC registration was correct.
- Database inspection showing duplicate `Oa4mpClientClaim` rows for deprecated-cfg clients — typically 2 rows per canonical claim after 1 edit cycle, 10 rows after 5 edit cycles, all pointing to the same logical attribute.
- `Oa4mpClientCoSearchAttribute` rows for deprecated clients showing `claim_id` remaining null even after a migration block had ostensibly written a value via `saveField('claim_id', $newId)`.
- No actionable data from the existing log output: the comparator emitted only a count or branch label, not the actual arrays being compared.

## What Didn't Work

- **Using the per-attribute `claim_id` guard to prevent duplicate migration.** The controller's migration block was gated on `if($searchAttr['claim_id'] == null)`. The intent was that `toClaim()` would call `saveField('claim_id', $newId)` after creating the claim row, and subsequent edit loads would see a non-null `claim_id` and skip re-migration. In practice, `claim_id` read back as null on every subsequent load despite the `saveField` call apparently succeeding. Root cause not fully investigated; the leading hypothesis is an interaction between the OIDC-client save flow and dependent-association recreation that resets the search-attribute record. Because the guard never held, every edit pass triggered the migration loop again.

- **Assuming the unmarshaller simply wasn't producing any claims for QDLv3.** An initial read of the out-of-sync log suggested the server-side array was empty. On closer inspection the QDLv3 code path DID populate claim data — but wrote it to `$oa4mpClient['Oa4mpClaim']` and `$claimMapping['ClaimConstraint']` while the comparator read from `Oa4mpClientClaim` and `Oa4mpClientClaimConstraint`. The arrays existed; they were just invisible to the comparator because it looked at a different key.

- **Assuming the legacy-format unmarshaller just needed the same key rename as QDLv3.** After fixing the QDLv3 key names, legacy-format clients still produced mismatches. The QDLv2 and format-1 paths were writing `Oa4mpClientCoLdapConfig` array entries into the returned `$oa4mpClient` — the raw LDAP configuration descriptors, not the claim rows. The persisted side stores `Oa4mpClientClaim` rows (written at migration time via `toClaim()`). There was no common representation to compare. No key rename would fix this; a full translation layer was required.

- **Logging only the out-of-sync branch label.** The original comparator emitted a single log line identifying which out-of-sync branch was reached (count mismatch, plugin-empty, server-empty) but did not dump the arrays. Without per-side data, distinguishing "wrong keys" from "missing translation" from "extra rows" required code tracing rather than log analysis, significantly slowing diagnosis.

## Solution

The fix spans seven commits addressing three distinct bugs. The commits are described in logical dependency order.

### Step 1 — Add diagnostic logging to compare() (f33ed4e)

Before anything else, instrument the comparator so every out-of-sync branch prints both sides:

```php
// Before: opaque branch label only
$this->log("Number of claims is out of sync");

// After: dump both sides on every branch
$this->log("Oa4mpClientClaim: Number of claims is out of sync"
           . " (plugin=" . count($curClaims)
           . ", oa4mp=" . count($oa4mpClaims) . ")");
$this->log("curClaims: " . print_r($curClaims, true));
$this->log("oa4mpClaims: " . print_r($oa4mpClaims, true));
```

All three branches (count drift, plugin-empty, server-empty) were updated. This step unblocked root-cause investigation.

### Step 2 — Fix QDLv3 key names (U1, c6cb404)

The QDLv3 unmarshall path and the comparator used mismatched key names.

```php
// Before (unmarshaller output — wrong keys):
$oa4mpClient['Oa4mpClaim'] = $claimMappings;
$claimMapping['ClaimConstraint'] = $claimConstraints;

// After (correct keys matching persisted side):
$oa4mpClient['Oa4mpClientClaim'] = $claimMappings;
$claimMapping['Oa4mpClientClaimConstraint'] = $claimConstraints;
```

The comparator's read side was updated to match:

```php
// Before:
$oa4mpClaims = $oa4mpServerData['Oa4mpClaim'] ?? array();

// After:
$oa4mpClaims = $oa4mpServerData['Oa4mpClientClaim'] ?? array();
```

### Step 3 — Plumb $adminClient through oa4mpUnMarshallContent (U2, 1d9fdf5)

The legacy-cfg translation needs `co_id` (available on the admin client record) to resolve `CoProvisioningTarget` rows for constraint-value lookups. The helper signature was extended:

```php
// Before:
function oa4mpUnMarshallContent($oa4mpObject)

// After:
function oa4mpUnMarshallContent($oa4mpObject, $adminClient)
```

The single call site in `oa4mpVerifyClient()` was updated to pass `$adminClient` through.

### Step 4 — Add buildClaimFromLdapMapping helper (U3, e27123a)

A read-only translation helper was added that mirrors the switch table in `Oa4mpClientCoSearchAttribute::toClaim()` without the DB-write side effects. The full switch covers 14 cases; the structural pattern is:

```php
/**
 * Build a single Oa4mpClientClaim array from an LDAP mapping descriptor.
 *
 * IMPORTANT: this switch table is a read-only mirror of the table in
 * Oa4mpClientCoSearchAttribute::toClaim(). When changing cases here,
 * mirror the change there to avoid silent sync drift on legacy-cfg
 * clients.
 *
 * @param array  $mapping     Keys: ldap_attribute_name, return_name
 * @param string $serverUrl   LDAP server URL from cfg
 * @param array  $adminClient Admin client carrying CO context (co_id)
 * @param array  &$lookupCache Pass-by-ref memoization, keyed coId|serverUrl
 * @return array|null         Claim array, or null when not reconstructable
 */
function buildClaimFromLdapMapping($mapping, $serverUrl, $adminClient, &$lookupCache) {
  // ... validate $mapping['ldap_attribute_name'] and adminClient co_id ...

  switch($searchAttributeName) {
    case 'isMemberOf':
    case 'gecos':
    case 'givenName':
    case 'sn':
    case 'gidNumber':
    case 'uidNumber':
    case 'eduPersonOrcid':
      // No provisioner-config lookup needed; constraint values are static.
      // Build $claim directly with source_model, claim_value_selection, etc.
      break;

    case 'employeeNumber':
    case 'mail':
    case 'uid':
    case 'voPersonApplicationUID':
    case 'voPersonExternalID':
    case 'voPersonID':
      $useLdapProvisionerConfig = true;
      // ... build base $claim shape ...
      break;

    default:
      $this->log("buildClaimFromLdapMapping: did not convert " . $searchAttributeName);
      return null;
  }

  if($useLdapProvisionerConfig) {
    if(empty($serverUrl)) { return null; }    // serverUrl only required here
    $cacheKey = $coId . '|' . $serverUrl;
    if(!isset($lookupCache[$cacheKey])) {
      $coProvisioningTargetModel = ClassRegistry::init('CoProvisioningTarget');
      $coProvisioningTargetModel->bindModel(array(
        'hasOne' => array(
          'CoLdapProvisionerTarget' => array(
            'className' => 'LdapProvisioner.CoLdapProvisionerTarget',
            'foreignKey' => 'co_provisioning_target_id'
          )
        )
      ));
      $lookupCache[$cacheKey] = $coProvisioningTargetModel->find('all', array(
        'conditions' => array(
          'CoProvisioningTarget.co_id' => $coId,
          'CoProvisioningTarget.plugin' => 'LdapProvisioner'
        ),
        'contain' => array('CoLdapProvisionerTarget' => array('CoLdapProvisionerAttribute'))
      ));
    }

    // Use a fresh $matchedAttribute (not the loop variable) to avoid the
    // variable-shadowing bug present in toClaim().
    $matchedAttribute = null;
    foreach($ldapProvisionerTarget['CoLdapProvisionerAttribute'] as $attr) {
      if($attr['attribute'] == $searchAttributeName) {
        $matchedAttribute = $attr;
        break;
      }
    }
    if(empty($matchedAttribute)) { return null; }

    $claimConstraints[] = array(
      'constraint_field' => 'type',
      'constraint_value' => $matchedAttribute['type']
    );
  }

  $claim['Oa4mpClientClaimConstraint'] = $claimConstraints;
  return $claim;
}
```

The `$lookupCache` is initialized once per `oa4mpUnMarshallContent()` call and passed by reference into each `buildClaimFromLdapMapping()` invocation, giving within-request memoization without cross-request state.

### Step 5 — Wire the helper into QDLv2 and format-1 paths (U4 de5b25a, U5 c4fd85b)

Both legacy paths were reworked to produce `Oa4mpClientClaim` arrays instead of `Oa4mpClientCoLdapConfig` arrays.

```php
// Before (QDLv2 path, producing wrong type):
$oa4mpClient['Oa4mpClientCoLdapConfig'] = array();
foreach($ldapConfigs as $ldapConfig) {
  $oa4mpClient['Oa4mpClientCoLdapConfig'][] = $ldapConfig;
}

// After (QDLv2 path, producing correct type):
// Per-call memoization for the CoProvisioningTarget lookup; shared
// across the QDLv2 and deprecated paths.
$lookupCache = array();

$ldapConfigs = $this->oa4mpUnMarshallCfgQdlv2($cfg);
if(!empty($ldapConfigs)) {
  foreach($ldapConfigs as $ldapConfig) {
    if(empty($ldapConfig['Oa4mpClientCoSearchAttribute'])) { continue; }
    foreach($ldapConfig['Oa4mpClientCoSearchAttribute'] as $sa) {
      $mapping = array(
        'ldap_attribute_name' => $sa['name'],
        'return_name'         => $sa['return_name'],
        'return_as_list'      => !empty($sa['return_as_list']),
      );
      $claim = $this->buildClaimFromLdapMapping($mapping, $ldapConfig['serverurl'], $adminClient, $lookupCache);
      if($claim !== null) {
        $oa4mpClient['Oa4mpClientClaim'][] = $claim;
      }
    }
  }
  return $oa4mpClient;
}
```

The format-1 (deprecated) path follows the same shape, preserving its existing `claims.preProcessing` validation block.

### Step 6 — $alreadyMigrated gate in controller edit() (d6ffbe1)

Replace the per-attribute `claim_id` guard with a coarser set-level check:

```php
// Before: per-row guard that did not hold
$hasSearchAttr = !empty($client['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute']);
if($hasSearchAttr) {
  foreach($client['Oa4mpClientCoLdapConfig'] as $ldapConfig) {
    foreach($ldapConfig['Oa4mpClientCoSearchAttribute'] as $searchAttr) {
      if($searchAttr['claim_id'] == null) {
        // toClaim() runs every edit; claim_id never sticks
      }
    }
  }
}

// After: set-level guard that does hold
$hasSearchAttr = !empty($client['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute']);
$alreadyMigrated = !empty($client['Oa4mpClientClaim']);
if($hasSearchAttr && !$alreadyMigrated) {
  // ... migration loop runs once ...
}
```

This is explicitly a stop-the-bleed measure. It does not fix the underlying reason `claim_id` stays null; it prevents the migration loop from firing again as long as any `Oa4mpClientClaim` row exists for the client.

**Update 2026-05-18:** the underlying reason `claim_id` stayed null has been root-caused — `toClaim()`'s save sequence was non-atomic, with an `Oa4mpClientDynamoConfig::save()` between `saveAssociated()` and `saveField('claim_id', ...)`. A failure in that middle save aborted the function before the back-pointer was set, leaving an orphan claim row. With the save reordered so the claim row and its back-pointer land atomically (commit `1659690`), the per-row guard is once again reliable; the `$alreadyMigrated` gate has been removed and partial-migration recovery is restored. See `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md` for the full investigation and the inversion of Prevention rule 4 below.

### Step 7 — Move serverUrl guard inside provisioner branch (3d05b48)

An early return in `buildClaimFromLdapMapping()` rejected any mapping with an empty `$serverUrl` before the switch, silently dropping non-provisioner-backed claims (e.g., `isMemberOf`, `givenName`, `sn`) when cfg lacked a `serverurl` field. The guard was moved inside the provisioner-lookup cases only:

```php
// Before: gate at function entry, blocks all attributes
if(empty($serverUrl)) {
  $this->log("...");
  return null;
}
switch($searchAttributeName) { ... }

// After: gate only inside the branch that actually needs serverUrl
if($useLdapProvisionerConfig) {
  if(empty($serverUrl)) {
    $this->log("buildClaimFromLdapMapping: missing serverUrl; skipping " . $searchAttributeName);
    return null;
  }
  // ... provisioner lookup ...
}
```

## Why This Works

**Bug 1 (legacy-format comparator drift)** arose because the two representations being compared were never in the same shape. The persisted side stores `Oa4mpClientClaim` rows (written by `toClaim()` during the one-time migration). The unmarshaller's legacy-format paths were returning raw LDAP configuration descriptors (`Oa4mpClientCoLdapConfig`) — a structurally different type with different field names. The comparator had no chance of matching them. The fix (U3–U5) makes the unmarshaller translate LDAP descriptors into the same `Oa4mpClientClaim` shape as the persisted side, using the same switch logic as `toClaim()`. Now both sides of the comparator carry identically structured arrays.

**Bug 2 (QDLv3 wrong keys)** arose because the unmarshaller used abbreviated key names (`Oa4mpClaim`, `ClaimConstraint`) that do not match the CakePHP model names (`Oa4mpClientClaim`, `Oa4mpClientClaimConstraint`) used everywhere else in the plugin. The fix (U1) is a rename: both the writer (unmarshaller) and the reader (comparator) now use the canonical model-name keys.

**Bug 3 (duplicate claim rows)** arose because the per-row idempotency guard relied on `claim_id` being durably written per search-attribute row, but `claim_id` was not persisting across edit cycles. The coarser `$alreadyMigrated` gate (d6ffbe1) does not depend on per-row state — it asks "does this client have ANY claim rows?" If yes, skip the entire migration block. This survives whatever is resetting the per-row `claim_id` field.

**Update 2026-05-18:** "whatever is resetting the per-row `claim_id` field" was a non-atomic save sequence in `toClaim()` — the `Oa4mpClientDynamoConfig::save()` between the claim insert and the back-pointer save could fail and abort the function before the back-pointer landed. Reordering the saves so claim row + back-pointer are atomic (commit `1659690`) made the per-row guard reliable on its own; the `$alreadyMigrated` gate has been removed and partial-migration recovery has been restored. See `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`.

The `$lookupCache` pass-by-reference pattern works because it is initialized fresh on each `oa4mpUnMarshallContent()` call (not as a class property or static variable), so there is no cross-request contamination. Multiple calls within a single request share the cache (avoiding redundant DB queries), but each new verify call starts clean.

The deliberate duplication of `toClaim()`'s switch table into `buildClaimFromLdapMapping()` is correct because `toClaim()` has DB-write side effects (`saveAssociated`, `saveField`) that must not be triggered during a read-only verify pass. The two functions are cross-referenced in their docblocks to enforce lockstep maintenance.

## Prevention

**1. Add per-side diagnostic logging on every out-of-sync branch at design time.**

A comparator that emits only "out of sync" without showing both sides is undebuggable in production. Template for any sync-comparator branch:

```php
if(count($curClaims) !== count($oa4mpClaims)) {
  $this->log(__METHOD__ . ": claim count mismatch."
    . " plugin(" . count($curClaims) . "): " . print_r($curClaims, true)
    . " server(" . count($oa4mpClaims) . "): " . print_r($oa4mpClaims, true));
  return false;
}
```

Do this when the comparator is first written, not after a bug is reported.

**2. Use canonical model-name keys throughout the unmarshall layer.**

Whenever an unmarshaller writes into an array that will later be compared to CakePHP model results, use the model class name as the array key — not an abbreviation or alias. A consistent rule ("the key IS the model name") means the comparator does not need to map names:

```php
// Wrong — abbreviation diverges from model name:
$oa4mpClient['Oa4mpClaim'] = $claimMappings;

// Right — key == model name, matches find() result shape:
$oa4mpClient['Oa4mpClientClaim'] = $claimMappings;
```

**3. When a write-side and read-side helper are deliberate duplicates, put lockstep warnings in both docblocks.**

```php
/**
 * Convert an Oa4mpClientCoSearchAttribute row to an Oa4mpClientClaim row (DB write).
 *
 * IMPORTANT: the read-only output shape of this function is mirrored by
 * Oa4mpClientOa4mpServer::buildClaimFromLdapMapping() so the legacy-cfg
 * unmarshall paths can produce comparable claims for sync verification.
 * When changing cases here, mirror the change there to avoid silent sync
 * drift on legacy-cfg clients.
 */
public function toClaim(...) { ... }

/**
 * Build a read-only Oa4mpClientClaim array from an LDAP mapping descriptor.
 *
 * IMPORTANT: this switch table is a read-only mirror of the table in
 * Oa4mpClientCoSearchAttribute::toClaim(). When changing cases here,
 * mirror the change there.
 */
function buildClaimFromLdapMapping(...) { ... }
```

The duplication is intentional (the write-side helper has DB side effects that must not run during a read-only verify), but the lockstep requirement must be explicit in both places.

**4. Make per-row migration markers atomic with their primary entity; coarse set-level gates are a last resort.**

This rule was originally written as "prefer coarse set-level guards over per-row guards" based on the observation that per-row `claim_id` markers were not surviving save cycles. The 2026-05-18 investigation root-caused that observation: `toClaim()`'s save sequence was non-atomic, with an `Oa4mpClientDynamoConfig::save()` between the claim insert and the back-pointer save. When the middle save failed validation, the back-pointer was never written, leaving an orphan claim row with `claim_id` NULL — which the per-row guard then read as "not yet migrated" on the next pass, producing duplicates.

The correct discipline is to make the claim row and its back-pointer atomic, then trust the per-row guard:

```php
// Fragile: claim insert and back-pointer save are not adjacent; any
// failure between them strands the claim without a back-pointer.
$this->Claim->saveAssociated($claim);
$this->OtherThing->save($otherData);                  // can fail
$this->SearchAttr->saveField('claim_id', $this->Claim->id);

// Robust: back-pointer save runs immediately after the claim insert,
// before any other save that can fail. Independent saves run as
// separately-failing tail steps.
$this->Claim->saveAssociated($claim);
$this->SearchAttr->saveField('claim_id', $this->Claim->id);
$this->OtherThing->save($otherData);                  // can fail, doesn't strand the back-pointer
```

A coarse set-level guard remains a useful last-resort fallback when atomicity genuinely cannot be guaranteed (cross-service writes, no transaction support, etc.) — but it carries a significant cost: it blocks partial-migration recovery, since a client with one failed search-attribute migration can never retry that one attribute as long as any sibling claim row exists. See `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md` for the full reasoning and the removal of the `$alreadyMigrated` gate this doc originally added (commit `1659690`).

**5. Scope pass-by-reference caches at the entry point, not inside the helper.**

The `$lookupCache` pattern is:

```php
// Entry point (oa4mpUnMarshallContent): initialize fresh
$lookupCache = array();

// Call helper with reference:
$claim = $this->buildClaimFromLdapMapping($descriptor, $serverUrl, $adminClient, $lookupCache);

// Helper signature: receives by reference, reads and writes
function buildClaimFromLdapMapping(..., &$lookupCache) { ... }
```

If the cache were a static variable inside the helper, it would persist across requests in long-lived PHP processes. If it were a class property initialized in the constructor, a test that calls `oa4mpUnMarshallContent` twice would reuse stale cache entries from the first call. The entry-point initialization gives per-call isolation and within-call sharing.

**6. When extending a function signature to pass new context, check ALL call sites immediately.**

PHP does not produce a compile error when a call site omits a newly required parameter. Add a grep step to the change checklist:

```sh
# After changing oa4mpUnMarshallContent signature:
grep -rn 'oa4mpUnMarshallContent(' .
```

If the function has only one call site now, it may grow more later. Documenting the required parameter clearly in the docblock reduces the chance of a future call site being added without the new argument.

**7. Avoid variable shadowing in inner match loops.**

`Oa4mpClientCoSearchAttribute::toClaim()` originally contained a loop that reused the outer loop variable name for the inner match variable, so a no-match case silently left the outer variable's last value in scope. The fix pattern (used in `buildClaimFromLdapMapping`, and as of commit `c503465` also in `toClaim()`) is to use a distinct name and initialize to null before the inner loop:

```php
// Anti-pattern (the original toClaim() code, fixed in commit c503465):
foreach($ldapProvisionerAttributes as $ldapProvisionerAttribute) {
  if($ldapProvisionerAttribute['attribute'] === $attrName) {
    $ldapProvisionerAttribute = $ldapProvisionerAttribute; // no-op, masks no-match
  }
}

// Correct pattern (used in buildClaimFromLdapMapping and now in toClaim):
$matchedAttribute = null;
foreach($ldapProvisionerAttributes as $attr) {
  if($attr['attribute'] === $attrName) {
    $matchedAttribute = $attr;
    break;
  }
}
if($matchedAttribute === null) { return null; }
```

This pattern was backported to `toClaim()` in commit `c503465` (see `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`).

## Related Issues

- Origin requirements doc: `docs/brainstorms/2026-05-05-oa4mp-unmarshall-claim-output-brainstorm.md` — full problem frame, requirements R1–R9, acceptance examples, and the open/deferred questions including the duplication-drift and provisioner-state-drift risks.
- Implementation plan: `docs/plans/2026-05-05-001-fix-oa4mp-unmarshall-claim-output-plan.md` — the six implementation units (U1–U6) with exact line-number call sites, key technical decisions (memoization strategy, helper location, discovery approach, implementation order), risks table, and the manual U6 verification gate.
- Future migration cross-reference: `docs/plans/2026-02-04-feat-cakephp5-migration-plan.md` — when the CakePHP 5.x migration proceeds, the long-form key naming convention (`Oa4mpClientClaim` / `Oa4mpClientClaimConstraint`) established by this fix is the canonical name to preserve. Old short names (`Oa4mpClaim`, `ClaimConstraint`) must not be reintroduced as part of any class-renaming sweep.
- Resolved 2026-05-18: the `claim_id` non-persistence was root-caused as a non-atomic save sequence in `toClaim()` — `Oa4mpClientDynamoConfig::save()` between `saveAssociated()` and `saveField('claim_id', ...)` could fail validation and abort the function before the back-pointer was written. Fixed by reordering the saves so claim row + back-pointer land atomically; the `$alreadyMigrated` gate has been removed and partial-migration recovery is restored (commit `1659690`). See `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`.
- Resolved 2026-05-18: the variable-shadowing bug in `Oa4mpClientCoSearchAttribute::toClaim()` (Prevention rule 7) has been fixed by mirroring the read-only twin's accumulator pattern (commit `c503465`).
