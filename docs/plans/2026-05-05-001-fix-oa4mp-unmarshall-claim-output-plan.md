---
title: "fix: Unmarshall legacy cfg formats to claim-shaped output and rename comparator keys"
type: fix
status: active
date: 2026-05-05
origin: docs/brainstorms/2026-05-05-oa4mp-unmarshall-claim-output-brainstorm.md
---

# fix: Unmarshall legacy cfg formats to claim-shaped output and rename comparator keys

## Summary

Implement the legacy-format claim conversion in `oa4mpUnMarshallContent` and the comparator-side key rename in six implementation units: rename comparator keys first to isolate the format-3 regression surface, plumb CO context from `oa4mpVerifyClient` down into `oa4mpUnMarshallContent`, build a private read-only helper that mirrors `Oa4mpClientCoSearchAttribute::toClaim()`, wire it into the QDLv2 and deprecated paths, and manually verify against a real OA4MP server.

---

## Problem Frame

`oa4mpVerifyClient`'s sync comparison reports format-1 and format-2 OIDC clients as out-of-sync regardless of actual server state, because the cfg-format-2 and cfg-format-1 unmarshall branches still write to `Oa4mpClientCoLdapConfig` while the comparator's OA4MP-server-side branch reads claim-shaped data. The full pain narrative — including the upstream data-model migration that turned `Oa4mpClientCoLdapConfig` into a dead key — lives in the origin doc.

---

## Requirements

- R1. When `oa4mpUnMarshallContent` parses a cfg in format 1 (deprecated) or format 2 (QDLv2), the returned array carries the equivalent claim representation under `Oa4mpClientClaim` (per R8). (origin R1)
- R2. The unmarshall result for legacy formats does not populate `Oa4mpClientCoLdapConfig`. (origin R2)
- R3. Format-1 unmarshalling continues to validate the `cfg.claims.preProcessing` block; the new conversion runs alongside that validation, not instead of it. (origin R3)
- R4. Claims produced for legacy formats match what `Oa4mpClientCoSearchAttribute::toClaim()` produces, including the `CoProvisioningTarget` → `CoLdapProvisionerTarget` → `CoLdapProvisionerAttribute` lookup. (origin R4)
- R5. LDAP attribute names that `toClaim()` does not handle are silently skipped during unmarshall conversion, matching `toClaim()`'s default-branch behavior. (origin R5)
- R6. Conversion logic for legacy formats is implemented in `Model/Oa4mpClientOa4mpServer.php`. The persisted-side `toClaim()` is left untouched. (origin R6)
- R7. `oa4mpUnMarshallContent` receives CO context — the `$adminClient` array, from which `co_id` and the LDAP serverurl are derivable — so the duplicated helper can run the `CoProvisioningTarget` find. The function's signature is extended; `oa4mpVerifyClient` passes the value through from its existing parameter. (origin R7)
- R8. The unmarshall path writes claims under `Oa4mpClientClaim` and constraints under nested `Oa4mpClientClaimConstraint`, applied to format-1, format-2, and the existing format-3 path. For format 3, both the outer-claim assignment and the nested-constraint assignment in `oa4mpUnMarshallCfgQdlv3` are renamed (not only the outer key). (origin R8)
- R9. `isClientDataSynchronized`'s OA4MP-server-side branch reads from `Oa4mpClientClaim` and `Oa4mpClientClaimConstraint`. The "note different key name from OA4MP server" comment is removed. (origin R9)

**Origin acceptance examples:** AE1 (covers R1, R2, R8), AE2 (covers R1, R3), AE3 (covers R4, R7, R8, R9), AE4 (covers R5).

---

## Scope Boundaries

- Refactoring `Oa4mpClientCoSearchAttribute::toClaim()` into a shared pure builder. The mapping table and provisioner lookup are duplicated locally and accepted as duplication; `toClaim()` is untouched.
- Any change to how plugin-side `Oa4mpClientClaim` records get *created* via the existing migration path. The unmarshall side mirrors `toClaim()`'s output shape.
- Cfg formats other than 1, 2, and 3.
- Automated test coverage for `oa4mpUnMarshallContent` or `isClientDataSynchronized`. Verification is manual against a real OA4MP server with one client per cfg format.
- Comparator-side relaxation for legacy formats (the provisioner-drift Open Question is logged in Risks and left to a separate change if the failure mode actually surfaces).

---

## Context & Research

### Relevant Code and Patterns

- `Model/Oa4mpClientOa4mpServer.php`:
  - `oa4mpUnMarshallContent` (line 946): the entry point; only caller is `oa4mpVerifyClient` line 1464.
  - `oa4mpUnMarshallCfgQdlv2` (line 1222): handles format-2 (and format-1.0.0 sub-format internally); appends to `$oa4mpClient['Oa4mpClientCoLdapConfig']` at line 1098.
  - `oa4mpUnMarshallCfgDeprecated` (line 1149): handles format-1 (the truly-deprecated cfg shape); appends at line 1110; carries the `claims.preProcessing` validation block at lines 1116–1127.
  - `oa4mpUnMarshallCfgQdlv3` (line 1312): writes outer key `Oa4mpClaim` at line 1413 and nested key `ClaimConstraint` at line 1408.
  - `isClientDataSynchronized` (line 44): reads `Oa4mpClaim` at line 302 and `ClaimConstraint` at line 374; the "note different key name" comment is at line 372.
  - `oa4mpVerifyClient` (line 1431): receives `$adminClient` and `$curClient`; `$adminClient['Oa4mpClientCoAdminClient']['co_id']` is available (matches the access pattern at line 861). The internal call at line 462 (`$verifyResult = $this->oa4mpVerifyClient(...)` from inside another flow) also has access.
- `Model/Oa4mpClientCoSearchAttribute.php`:
  - `toClaim()` (line 86): the canonical per-attribute conversion. Switch over LDAP attribute names lives at lines 98–253. Provisioner-target lookup (`CoProvisioningTarget` → `CoLdapProvisionerTarget` → `CoLdapProvisionerAttribute`) lives at lines 255–313. Save-side calls (`saveAssociated` / `saveField`) live at lines 319–351 — these are intentionally NOT mirrored.
- 13 external callers of `oa4mpVerifyClient` (across `Controller/`) all pass `$admin, $client[, $returnExtras]` — unchanged by this plan.

### Institutional Learnings

None. `docs/solutions/` does not exist in this repo yet.

### External References

None. The work is a localized fix in CakePHP 2.x model code; external best-practice research adds nothing here.

---

## Key Technical Decisions

- **Discovery strategy for `toClaim()` switch cases — enumerate all current cases up front.** The switch is small and visible; staged discovery risks production drift on attributes the cfg payload contains but the helper hasn't yet learned. R4 mandates full equivalence, so partial mirroring is a regression vector.
- **Lookup caching — memoize per unmarshall call.** The `CoProvisioningTarget` find depends only on `coId` and the LDAP serverurl, both stable for one unmarshall invocation. A typical client has multiple LDAP attributes; doing the find once per attribute is wasteful. Use a local in-function cache; no cross-call state.
- **Helper inputs — a normalized claim-mapping descriptor plus CO context.** The descriptor carries `ldap_attribute_name`, `return_name`, `return_as_list`. The format-2 and format-1 paths each construct this descriptor from their own intermediate cfg shape and pass it in. This avoids forcing both paths to assemble a heavyweight `Oa4mpClientCoSearchAttribute` array shape that toClaim()'s save side requires but the helper doesn't.
- **Helper location — private method on the `Oa4mpClientOa4mpServer` class.** Same class as the unmarshall sub-routines that call it; visibility matches the conversion's coupling to the surrounding flow.
- **Drift mitigation — cross-reference comments only.** A short PHPDoc note on `Oa4mpClientCoSearchAttribute::toClaim()` ("when changing the switch table, mirror in `Oa4mpClientOa4mpServer::buildClaimFromLdapMapping`") and the inverse on the new helper. Per origin R6, no extraction; cross-reference is the cheapest signal.
- **Implementation order — rename first, then plumb context, then helper, then wire-in.** Renaming first lands the format-3 regression surface as its own commit so it can be verified in isolation before the legacy-format work piles on top.
- **Comparator behavior unchanged for legacy formats.** The provisioner-state-drift concern (origin Open Question) is documented in Risks; not addressed here. The failure mode is "provisioner reconfiguration since migration triggers a one-time false out-of-sync, resolved by re-running the migration." Acceptable until evidence shows otherwise.

---

## Open Questions

### Resolved During Planning

- *Discovery strategy for `toClaim()` switch cases* (origin Q1): enumerate all current cases up front (see Key Technical Decisions).
- *Provisioner-target lookup caching* (origin Q2): memoize per unmarshall call (see Key Technical Decisions).
- *Helper location and inputs* (origin Q3): private method on `Oa4mpClientOa4mpServer`; inputs are a normalized descriptor plus CO context (see Key Technical Decisions).

### Deferred to Implementation

- *Whether the helper handles the `ldap_to_claim_mappings` empty-string `return_as_list` case* — `toClaim()` doesn't visibly branch on this but the cfg payload may carry it; implementer reads the QDLv2 path's `$listAttributes` handling at lines 1280–1284 for the source-of-truth interpretation.
- *Logging cadence for skipped unknown attributes* — `toClaim()` logs at line 250; the helper should log at the same verbosity, but the exact log string is implementer's call.

---

## Implementation Units

- U1. **Rename comparator keys to long-form (format-3 path)**

  **Goal:** rename the unmarshall-output keys on the format-3 writer and the comparator's OA4MP-server-side reader so all three format paths and the comparator agree on `Oa4mpClientClaim` / `Oa4mpClientClaimConstraint`.

  **Requirements:** R8, R9.

  **Dependencies:** None.

  **Files:**
  - Modify: `Model/Oa4mpClientOa4mpServer.php`

  **Approach:**
  - In `oa4mpUnMarshallCfgQdlv3`: change the outer key write at line 1413 (`$oa4mpClient['Oa4mpClaim']` → `$oa4mpClient['Oa4mpClientClaim']`) and the nested key write at line 1408 (`$claimMapping['ClaimConstraint']` → `$claimMapping['Oa4mpClientClaimConstraint']`).
  - In `isClientDataSynchronized`: change the outer key read at line 302 (`$oa4mpServerData['Oa4mpClaim']` → `$oa4mpServerData['Oa4mpClientClaim']`) and the nested key read at line 374 (`$claim['ClaimConstraint']` → `$claim['Oa4mpClientClaimConstraint']`).
  - Remove the now-stale `// Normalize constraints (note different key name from OA4MP server).` comment at line 372.
  - Leave the comparator's cur-data branch unchanged: lines 301 (`$curData['Oa4mpClientClaim']`) and 337 (`$claim['Oa4mpClientClaimConstraint']`) already use the long-form keys; only the OA4MP-server-side branch needs renaming.

  **Patterns to follow:**
  - The plugin's persisted-data side already uses `Oa4mpClientClaim` / `Oa4mpClientClaimConstraint` consistently — the rename brings the unmarshall-side into the same convention. Reference: `Model/Oa4mpClientClaim.php` (model name), `Model/Oa4mpClientCoOidcClient.php` line 219 (`'Oa4mpClientClaim' => array('Oa4mpClientClaimConstraint')`).

  **Test scenarios** (manual, since automated coverage is excluded by scope):
  - *Happy path — Covers AE3 (partial).* On a format-3 OIDC client whose persisted state matches the OA4MP-server state, `oa4mpVerifyClient` returns synchronized. Confirms the writer and reader still agree under the new key names.
  - *Regression watch.* On a format-3 client whose server-side cfg has been edited externally (one claim removed), `oa4mpVerifyClient` correctly reports out-of-sync. Confirms the comparator still detects real drift.

  **Verification:**
  - Manual sync check on a format-3 client returns the same in-sync verdict as before the rename.
  - **Verification gate.** This manual sync run must pass before starting U2. The Risks-table mitigation for format-3 rename typos depends on the rename being verified in isolation between commits, not just landing structurally separate.

- U2. **Plumb CO context through `oa4mpUnMarshallContent`**

  **Goal:** extend `oa4mpUnMarshallContent`'s signature to accept the calling `$adminClient` so downstream legacy-format conversion can run the provisioner-target lookup.

  **Requirements:** R7.

  **Dependencies:** None.

  **Files:**
  - Modify: `Model/Oa4mpClientOa4mpServer.php`

  **Approach:**
  - Add `$adminClient` as a **required** parameter to `oa4mpUnMarshallContent` (no default-null). The single in-tree caller is updated in lockstep, and a required parameter forces any future caller to confront the dependency rather than silently invoking with missing context.
  - Update the in-method `$this->oa4mpUnMarshallContent($oa4mpObject)` call at line 1464 to pass `$adminClient` (the parameter `oa4mpVerifyClient` already receives at line 1431).
  - The 13 external `oa4mpVerifyClient` callers in `Controller/` are unchanged — they already pass `$admin`. Confirm by grep before commit.

  **Patterns to follow:**
  - `oa4mpVerifyClient`'s existing signature `($adminClient, $curClient, $returnExtras = false)` shows the project's preferred shape for context-bearing model methods.

  **Test scenarios** (manual):
  - *Integration scenario.* On any cfg-format client, running `oa4mpVerifyClient` end-to-end returns the same result as before (no behavioral change at U2 — only the parameter is threaded through).

  **Verification:**
  - `grep -n "oa4mpUnMarshallContent" Model/ Controller/` shows the only caller is `Model/Oa4mpClientOa4mpServer.php:1464`, now passing `$adminClient`.
  - `grep -rn "oa4mpVerifyClient(" Controller/` shows all 13 callers still passing `$admin, $client[, $returnExtras]` — unchanged.

- U3. **Build the legacy-format claim-conversion helper**

  **Goal:** add a private read-only method on `Oa4mpClientOa4mpServer` that converts an LDAP-search-attribute mapping descriptor into a claim array equivalent to what `toClaim()` produces — including the provisioner-target lookup that derives constraint values for attributes like `mail`, `uid`, `voPersonExternalID`, etc.

  **Requirements:** R4, R5, R6.

  **Dependencies:** U2.

  **Files:**
  - Modify: `Model/Oa4mpClientOa4mpServer.php`
  - Modify: `Model/Oa4mpClientCoSearchAttribute.php` (PHPDoc cross-reference comment only — no logic change)

  **Approach:**
  - Add a private method (proposed name `buildClaimFromLdapMapping`) that takes:
    - `$mapping` — array with `ldap_attribute_name`, `return_name`, `return_as_list`
    - `$serverUrl` — the LDAP server URL from the calling cfg path's `$ldapConfig['serverurl']`. Required for matching the provisioner target. Different per cfg-format-1 entry and per cfg-format-2 entry, so it must thread per-call rather than living on `$adminClient`.
    - `$adminClient` — carries `co_id` for the `CoProvisioningTarget` filter
    - `&$lookupCache` — pass-by-reference array for per-unmarshall-call memoization of the `CoProvisioningTarget` find
  - Mirror the switch in `Model/Oa4mpClientCoSearchAttribute.php` lines 98–253 case-for-case. Every case `toClaim()` handles is ported. Default branch returns `null` (skip), matching `toClaim()`'s line 250 behavior.
  - Mirror the provisioner-target lookup at `Model/Oa4mpClientCoSearchAttribute.php` lines 255–313 in spirit, not call-for-call. `Oa4mpClientCoSearchAttribute` reaches `CoProvisioningTarget` through its parent association chain (`$this->Oa4mpClientCoLdapConfig->Oa4mpClientCoAdminClient->Co->CoProvisioningTarget`), but `Oa4mpClientOa4mpServer` does NOT have that chain (`useTable = false`, no model associations). Use `ClassRegistry::init('CoProvisioningTarget')` to obtain the model handle, then perform the same dynamic `bindModel` for `LdapProvisioner.CoLdapProvisionerTarget` (mirroring lines 262–270) and `find('all', $args)`. Filter by `co_id` and `plugin = 'LdapProvisioner'`, contain `CoLdapProvisionerTarget.CoLdapProvisionerAttribute`, match the target on `serverurl`, then look up the attribute by name and read its `type` for the constraint value. Do NOT call `$this->Oa4mpClientCoLdapConfig->...` directly from within `Oa4mpClientOa4mpServer` — that property is not bound on this class and would throw at runtime.
  - Memoize the `CoProvisioningTarget` find result in `$lookupCache` keyed by `coId|serverUrl`. The first attribute that triggers the lookup populates the cache; subsequent attributes reuse it.
  - **Helper returns `null` whenever the claim cannot be fully reconstructed** — unknown attribute (default branch), missing `$adminClient`, missing `$serverUrl`, no matching `CoProvisioningTarget`, no matching `CoLdapProvisionerAttribute`. The caller skips null returns. Do NOT return a partial claim with the constraint dropped; a partial claim normalizes differently from the persisted side and produces false out-of-sync. This matches `toClaim()`'s own behavior of `return;` when the lookup cannot complete (lines 273–277, 288–292, 304–308).
  - Return shape on success: an associative array with the claim fields (`claim_name`, `source_model`, `source_model_claim_value_field`, `claim_value_selection`, `claim_value_json_format`, optional `claim_multiple_value_serialization`, optional `claim_value_string_serialization_delimiter`) plus a nested `Oa4mpClientClaimConstraint` array of constraint maps with `constraint_field` and `constraint_value` keys.
  - Do NOT call `saveAssociated`, `saveField`, or any other DB-mutating method. The helper is read-only.
  - Add a PHPDoc note on the helper pointing to `Oa4mpClientCoSearchAttribute::toClaim()` as the source-of-truth that must move in lockstep. Add an inverse PHPDoc note on `toClaim()` pointing back to the helper.

  **Patterns to follow:**
  - The model-method shape in `Oa4mpClientOa4mpServer.php` uses `function name(...)` (CakePHP 2.x convention), not `private function`. PHP 5.4+ visibility keywords are supported but the file currently uses bare `function`. Match the file's existing style.
  - For the bindModel pattern needed for `CoLdapProvisionerTarget`, mirror lines 262–270 in `toClaim()` exactly.

  **Test scenarios** (manual + targeted code reading at review time):
  - *Happy path — known attribute.* Helper called with `ldap_attribute_name = 'sn'` returns a claim with `source_model = 'Name'`, `source_model_claim_value_field = 'family'`, two constraints (`type = 'all'`, `primary = 'true'`), matching `toClaim()` line 181–194 byte-for-byte.
  - *Edge case — unknown attribute.* Helper called with `ldap_attribute_name = 'somethingNotInTheSwitch'` returns `null`; the caller is expected to skip the entry.
  - *Edge case — `return_as_list = true`.* Helper output for a known attribute reflects the list-flag in whatever way `toClaim()` does (tracking the QDLv2 parser's existing handling at lines 1280–1284 — implementer confirms the exact field).
  - *Integration — provisioner lookup.* Helper called with `ldap_attribute_name = 'mail'` and a CO whose LDAP provisioner attribute `mail` has `type = 'official'` returns a claim whose constraint_value is `'official'` (matching what `toClaim()` would produce for the same provisioner state).
  - *Integration — memoization.* Helper called twice in the same unmarshall call (with the same `$adminClient` and a populated `$lookupCache`) hits the cache on the second call. Confirmed by reading-back: only one CakePHP `find('all')` call against `CoProvisioningTarget` per unmarshall.
  - *Edge case — missing provisioner.* Helper called with an attribute that requires the provisioner lookup but no `CoProvisioningTarget` matches the CO returns `null` (matching `toClaim()` lines 273–277). Caller skips.
  - *Edge case — null adminClient.* Helper called with `$adminClient = null` for a lookup-required attribute returns `null` cleanly rather than throwing.

  **Verification:**
  - Code-walk against `Oa4mpClientCoSearchAttribute::toClaim()` shows every switch case ported; differences (case keys, return shapes, constraint construction) are zero.
  - Helper output field names match what `isClientDataSynchronized` reads at the cur-data normalization (lines 324–358 of `Oa4mpClientOa4mpServer.php`): `claim_name`, `source_model`, `source_model_claim_value_field`, `claim_value_selection`, `claim_value_json_format`, `claim_multiple_value_serialization`, `claim_value_string_serialization_delimiter`, plus the nested `Oa4mpClientClaimConstraint` array with `constraint_field` and `constraint_value` keys.
  - PHPDoc cross-reference comments are present on both functions.

- U4. **Wire the helper into the format-2 (QDLv2) path**

  **Goal:** replace the `Oa4mpClientCoLdapConfig` write in the format-2 unmarshall path with claim-shaped output produced by U3's helper.

  **Requirements:** R1, R2, R5, R8 (writer side for format-2).

  **Dependencies:** U3.

  **Files:**
  - Modify: `Model/Oa4mpClientOa4mpServer.php`

  **Approach:**
  - **Update the initialization block (lines 952–959).** Add `$oa4mpClient['Oa4mpClientClaim'] = array();` alongside the other initialized keys, and remove the `$oa4mpClient['Oa4mpClientCoLdapConfig'] = array();` line at line 956. After this fix no path populates `Oa4mpClientCoLdapConfig`, so initializing it as an empty array would leave a dead key in the result. This change applies for both U4 and U5 — landing it as part of U4 since U4 wires the first writer.
  - In `oa4mpUnMarshallContent` around lines 1092–1102, after `oa4mpUnMarshallCfgQdlv2` returns:
    - Stop appending to `$oa4mpClient['Oa4mpClientCoLdapConfig']`.
    - For each `Oa4mpClientCoSearchAttribute` entry inside the returned `$ldapConfig` (see lines 1273–1287 of `oa4mpUnMarshallCfgQdlv2` for the shape), construct a mapping descriptor (`ldap_attribute_name = $sa['name']`, `return_name = $sa['return_name']`, `return_as_list = $sa['return_as_list']`).
    - Call `buildClaimFromLdapMapping($mapping, $ldapConfig['serverurl'], $adminClient, $lookupCache)`. Skip null returns. Append non-null returns to `$oa4mpClient['Oa4mpClientClaim']`.
  - The `oa4mpUnMarshallCfgQdlv2` helper itself does not need changes — its output remains the LDAP-config-shaped intermediate. The conversion happens in `oa4mpUnMarshallContent` at the integration seam.

  **Test scenarios** (manual):
  - *Happy path — Covers AE1.* A format-2 OIDC client whose cfg `ldap_to_claim_mappings` contains a known attribute, after `oa4mpUnMarshallContent` runs, has one `Oa4mpClientClaim` entry per recognized mapping and zero `Oa4mpClientCoLdapConfig` entries.
  - *Edge case — Covers AE4.* A format-2 cfg with an unrecognized LDAP attribute name in `ldap_to_claim_mappings` produces an unmarshall result that skips that mapping; the rest of the response is unmarshalled normally; no exception.
  - *Integration — Covers AE3 (partial).* Same client, persisted on the plugin side from a `toClaim()` migration, returns synchronized through `oa4mpVerifyClient` — confirming the helper output and the persisted records normalize to the same shape under the comparator.

  **Verification:**
  - Manual sync run on a format-2 client returns synchronized when the server is unchanged.
  - `print_r($oa4mpClient)` after unmarshalling a format-2 client shows `Oa4mpClientClaim` populated, `Oa4mpClientCoLdapConfig` absent.

- U5. **Wire the helper into the format-1 (deprecated) path**

  **Goal:** replace the `Oa4mpClientCoLdapConfig` write in the deprecated-format unmarshall path with claim-shaped output, while preserving the existing `claims.preProcessing` validation block.

  **Requirements:** R1, R2, R3, R5, R8 (writer side for format-1).

  **Dependencies:** U3.

  **Files:**
  - Modify: `Model/Oa4mpClientOa4mpServer.php`

  **Approach:**
  - In `oa4mpUnMarshallContent` around lines 1104–1128, after `oa4mpUnMarshallCfgDeprecated` returns:
    - Keep the `oa4mpUnMarshallCfgDeprecated` return value as a local variable for the gating check (`if(!empty($ldapConfigs))`); do not write it to `$oa4mpClient['Oa4mpClientCoLdapConfig']`.
    - Run the existing `claims.preProcessing` validation block (lines 1116–1127) unchanged. The validation reads from `$cfg['claims']['preProcessing']` directly and does not depend on the `Oa4mpClientCoLdapConfig` array.
    - For each `Oa4mpClientCoSearchAttribute` entry inside the returned `$ldapConfigs[i]` (the deprecated-cfg helper builds the same `Oa4mpClientCoSearchAttribute` shape — see `oa4mpUnMarshallCfgDeprecated` body around line 1149+), construct a mapping descriptor and call `buildClaimFromLdapMapping($mapping, $ldapConfigs[i]['serverurl'], $adminClient, $lookupCache)` — same call shape as U4. Skip nulls; append the rest to `$oa4mpClient['Oa4mpClientClaim']`.

  **Patterns to follow:**
  - U4's wire-in pattern at the QDLv2 integration seam — same descriptor construction, same skip-on-null, same `Oa4mpClientClaim` append. Aim for symmetric code at the two seams.

  **Test scenarios** (manual):
  - *Happy path — Covers AE2.* A format-1 (deprecated) cfg whose `claims.preProcessing` references an LDAP claim source matching `claims.sourceConfig`, after `oa4mpUnMarshallContent` runs, passes the preProcessing validation AND has `Oa4mpClientClaim` entries derived from the deprecated `claims.sourceConfig`.
  - *Error path — preProcessing validation still fires.* A format-1 cfg whose `claims.preProcessing` references a non-LDAP claim source (or a source that doesn't match `claims.sourceConfig`) raises `LogicException` with message `pl.oa4mp_client_co_oidc_client.er.preprocessing` — same behavior as before this change.
  - *Integration — Covers AE3 (full).* Format-1 client with persisted `Oa4mpClientClaim` records produced by `toClaim()` returns synchronized through `oa4mpVerifyClient`.

  **Verification:**
  - Manual sync run on a format-1 client returns synchronized when the server is unchanged.
  - Manual run with a deliberately broken `claims.preProcessing` block still raises the expected `LogicException`.

- U6. **Manual verification across all three cfg formats**

  **Goal:** sign-off step — confirm the change works against a real OA4MP server with one OIDC client of each cfg format.

  **Requirements:** All success criteria.

  **Dependencies:** U1, U2, U3, U4, U5.

  **Files:** None (no code change).

  **Approach:**
  - **Precondition.** Confirm the test environment can host one client per cfg format (1, 2, 3). For format 1 specifically, verify that an existing client's cfg can be reverted to the deprecated shape AND that the reverted state survives the round trip: edit the server-side cfg JSON, fetch it back through the OA4MP server's API, and confirm the deprecated shape is returned (i.e., the server does not normalize it on read or reject the deprecated signature, and `oa4mpUnMarshallCfgDeprecated`'s signature check at line 1151 still passes). If the precondition cannot be met for format 1, document the gap and decide whether to ship U5 unverified or invest in a synthetic fixture before this unit's verification proceeds.
  - Identify or provision one OIDC client per cfg format (1, 2, 3) registered with the test OA4MP server.
  - For each client: navigate to the client's view in the plugin UI; trigger the sync verification flow; confirm the UI reports synchronized.
  - For each client: deliberately edit the server-side cfg externally (e.g., remove one claim mapping); re-trigger sync; confirm the UI reports out-of-sync.
  - Restore the test clients' server-side cfgs to their original states.

  **Test scenarios** (manual):
  - *Happy path.* One client per format reports synchronized when server matches plugin.
  - *Regression watch.* One client per format reports out-of-sync after a real server-side edit. Especially important on format 3, where the rename had the largest blast radius.

  **Verification:**
  - All three formats: synchronized when expected, out-of-sync when expected.
  - **Log inspection required.** After each manual sync run, inspect the registry log for caught-exception entries from the unmarshall path. `oa4mpVerifyClient`'s broad `catch(Exception $e)` at line 1472 silently downgrades any unexpected throw to `synchronized=false`; without log inspection, a "synchronized" UI verdict and a "swallowed exception → false out-of-sync" verdict are visually identical. If exception entries are present, the helper threw and the synchronized verdict is unreliable until the underlying issue is fixed.

---

## System-Wide Impact

- **Interaction graph:** The change is contained to `Model/Oa4mpClientOa4mpServer.php` plus a documentation-only comment in `Model/Oa4mpClientCoSearchAttribute.php`. The 13 controller call sites of `oa4mpVerifyClient` are unchanged (signature preserved).
- **Error propagation:** The format-1 `claims.preProcessing` validation continues to raise `LogicException` with `pl.oa4mp_client_co_oidc_client.er.preprocessing`. The new helper's failure modes (missing provisioner, missing CO context, unknown attribute) all return `null` and are skipped — no exceptions escape into `oa4mpVerifyClient`.
- **State lifecycle risks:** None. The helper is read-only; no DB writes, no caches outside the per-call memoization.
- **API surface parity:** `oa4mpUnMarshallContent`'s signature changes (gains an optional `$adminClient` parameter). Default-null preserves any tooling/test invocations that don't pass it. The single in-tree caller is updated.
- **Integration coverage:** The provisioner-target lookup is the cross-layer behavior unit tests alone wouldn't prove. U6's manual run on a CO with a real LDAP provisioner is what confirms it.
- **Unchanged invariants:** `oa4mpVerifyClient`'s contract is unchanged (same parameters, same return shape). `Oa4mpClientCoSearchAttribute::toClaim()`'s behavior is unchanged (the only edit there is a PHPDoc cross-reference comment). The format-3 unmarshall semantics are unchanged — only the output key names differ. Plugin-side persisted-claim records are not migrated, modified, or re-keyed.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| Format-3 rename typo or missed call site silently breaks every existing client's sync check until manual verification surfaces it. | U1 lands the rename in isolation as a separate commit so it can be verified before legacy-format work piles on. U6 includes a regression-watch case for format-3 specifically. |
| The duplicated switch table drifts from `toClaim()` when a future maintainer edits one and forgets the other. | Cross-reference PHPDoc on both functions points each to the other; reviewers and future maintainers see the obligation immediately. (Long-term mitigation — extracting a shared builder — is explicitly deferred per origin R6 and the duplication-drift Open Question.) |
| Provisioner-target reconfiguration after migration changes the helper's computed constraint_value but the persisted claim's frozen constraint_value stays put, producing false out-of-sync until re-migration. | Documented behavior; no code mitigation in this plan. The proper fix is re-running the migration when provisioner config changes — owned by the migration path, not this fix. Surface in operator-facing notes if false out-of-sync becomes a recurring issue. |
| `toClaim()` uses `bindModel` to dynamically attach `CoLdapProvisionerTarget` to `CoProvisioningTarget`. The helper must do the same; missing the bind silently returns no provisioner targets. | U3's approach explicitly mirrors lines 262–270. Code-walk verification at review time. |
| No automated test catches a typo or signature mismatch; everything depends on manual verification. | Scope decision (origin), accepted. U6 is the explicit sign-off step. Reviewers should code-walk the rename sites in U1 with extra care. |
| Format-1 fixture may not be reachable in the test environment because the deprecated cfg shape may have been migrated away. | U6 includes the fallback of editing a server-side cfg JSON directly to revert one client to format-1 for the test. |

---

## Documentation / Operational Notes

- No user-facing documentation changes — the fix is internal to sync-comparison correctness.
- No migration. No feature flag. No rollout staging needed for a model-layer fix that only affects in-process sync-check behavior.
- Operators may want a brief note: "After provisioner-target reconfiguration (changing an LDAP provisioner attribute's `type`), re-run the OIDC-client migration to refresh persisted claim constraint values; otherwise `oa4mpVerifyClient` may report a one-time false out-of-sync." Add to operator runbook if such a runbook exists; otherwise skip.

---

## Sources & References

- **Origin document:** [docs/brainstorms/2026-05-05-oa4mp-unmarshall-claim-output-brainstorm.md](../brainstorms/2026-05-05-oa4mp-unmarshall-claim-output-brainstorm.md)
- Related code: `Model/Oa4mpClientOa4mpServer.php`, `Model/Oa4mpClientCoSearchAttribute.php`, `Model/Oa4mpClientClaim.php`
- Related PRs/issues: none yet.
