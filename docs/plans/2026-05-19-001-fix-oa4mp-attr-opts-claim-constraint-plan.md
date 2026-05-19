---
title: Fix OA4MP voPersonApplicationUID Claim Constraint Under LdapProvisioner Attribute Options
type: fix
status: completed
date: 2026-05-19
origin: docs/brainstorms/2026-05-19-oa4mp-attr-opts-claim-constraint-brainstorm.md
---

# Fix: OA4MP voPersonApplicationUID Claim Constraint Under LdapProvisioner Attribute Options

## Summary

Introduce a single helper that computes the effective identifier-type set from CoService rows and encodes it as a uniform anchored regex (`^...$`). Both the writer (`toClaim()`) and the sync comparator (`buildClaimFromLdapMapping()`) call this helper with identical arguments — the architectural enforcement of the lockstep-mirror discipline — so the OA4MP server's DynamoDB-via-QDL claim path returns exactly the identifier-type set that the LdapProvisioner exports when `attr_opts` is enabled.

---

## Problem Frame

See origin (`docs/brainstorms/2026-05-19-oa4mp-attr-opts-claim-constraint-brainstorm.md`) for the full pain narrative. Plan-specific framing: the fix introduces no new behavior at the schema or API surface — it is a per-CO computation inserted into two existing emit sites, gated on `attr_opts` and the `voPersonApplicationUID` search attribute. The lockstep discipline established by prior commits (`f298ba0`, the empty-type → `'all'` normalization) is the precedent this plan extends.

---

## Requirements

All R-IDs trace to origin's requirements (`docs/brainstorms/2026-05-19-oa4mp-attr-opts-claim-constraint-brainstorm.md`):

- R1. `attr_opts` OFF: claim-constraint behavior unchanged from today (see origin R1).
- R2. `attr_opts` ON, voPersonApplicationUID: effective filter is the intersection of `LdapProvisionerAttribute.type` with `CoService.identifier_type` values in the CO (see origin R2).
- R2a. All non-deleted CoServices count regardless of status; mirrors the LdapProvisioner (see origin R2a).
- R3. Empty effective set ⇒ no claim row persisted; back-pointer stays NULL (see origin R3).
- R4. Non-empty effective set ⇒ uniform anchored regex `^...$` for both single and multi-type sets (see origin R4).
- R4a. Each identifier-type element is regex-escaped before inclusion (see origin R4a).
- R5. Comparator applies identical effective-filter logic; writer and comparator produce byte-identical strings (see origin R5).
- R6. Comparator surfaces divergence as value mismatch or presence/absence mismatch (see origin R6).
- R7. No auto-rebuild, no new UI affordance; comparator drift log is the operator's signal (see origin R7).
- R8. Scope limited to the voPersonApplicationUID branch in writer and comparator (see origin R8).

**Origin acceptance examples:** AE1-AE10 (see origin). Each implementation unit's test scenarios cite the AE-IDs they directly enforce.

---

## Scope Boundaries

See origin Scope Boundaries for the full list (no schema change, no auto-rebuild, no UI action, voPersonApplicationPassword out, group-membership filter out, no backfill, no validation rule changes). This plan additionally:

### Deferred to Follow-Up Work

- Refresh of related learning docs once the fix lands — separate `/ce-compound-refresh` pass, per the pattern established in prior commits in this area.
- Custom diff helper for the comparator's drift-log output if the regex-encoded constraint values prove hard to scan in operator logs. The per-side detail log added in commit `26d5ae3` renders values verbatim; the planning judgment is that `^(eppn|oidcsub)$` is readable enough today, and the follow-up is conditional on operator feedback.

---

## Context & Research

### Relevant Code and Patterns

- **`Model/Oa4mpClientCoSearchAttribute.php` — `toClaim()` voPersonApplicationUID branch (~line 224-234) + `useLdapProvisionerConfig` block (~line 263-332).** The writer site. The empty-string → `'all'` normalization at ~line 321-327 (commit `f298ba0`) is the precedent for this kind of mirror-the-provisioner work; the `attr_opts`-OFF branch keeps that normalization verbatim.
- **`Model/Oa4mpClientOa4mpServer.php` — `buildClaimFromLdapMapping()` (~line 1309-1536), with the `useLdapProvisionerConfig` block at ~line 1459-1532.** The comparator site. The `$lookupCache` pattern at ~line 1465-1483 is the cache the new CoService lookup must integrate with.
- **`Model/Oa4mpClientClaimConstraint.php` line 53-62.** `notBlank` + `allowEmpty: false` validation on both constraint fields — the reason "no claim at all" (R3) is the correct shape on zero effective set rather than an empty-value constraint row.
- **`Controller/Oa4mpClientCoOidcClientsController.php::edit` migration block (~line 455 post-1659690).** The caller that drives `toClaim()` per search attribute; uses the inner per-attribute `claim_id IS NULL` guard (post-commit `1659690`) to suppress reconversion. The U2 wiring must not regress this gate.
- **`docs/solutions/logic-errors/oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md` — DB-reset SQL block.** The per-client SQL pattern that operators use to clear `claim_constraints`, `claims`, and reset `search_attribute.claim_id` so re-edit triggers re-migration. This is the operator remediation path for surfaced drift (see Documentation / Operational Notes).
- **`repositories/cilogon-service-config-us/roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl::handle_identifier`** (~line 404-486 in the QDL reference numbering). The QDL consumer that evaluates `type_regex_pattern =~ v.'cm_type'`. The QDL operator `=~` is documented in the QDL reference as "Regular expression match" without an explicit anchoring semantic; the uniform `^...$` encoding chosen in this plan is safe under either possible interpretation.
- **External (COmanage Registry repo): `app/Plugin/LdapProvisioner/Model/CoLdapProvisionerTarget.php` line 692-718.** The authoritative LdapProvisioner behavior the helper mirrors: for each Identifier matching `targetType`, look up `CoService.identifier_type` matches via `CoService::mapIdentifierToLabels`; if no service matches the identifier's type, the export is suppressed.
- **External (COmanage Registry repo): `app/Model/CoService.php::mapIdentifierToLabels` line 403-411.** The CoService access pattern. The helper's CoService query mirrors the find-conditions (`co_id`, `identifier_type`) and default soft-delete handling; the helper does NOT call `mapIdentifierToLabels` directly (it needs unique `identifier_type` values, not the label map).

### Institutional Learnings

- **`docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md`** — the lockstep-mirror discipline between writer and comparator. The single-helper structure (U1) is the architectural enforcement of this discipline for the voPersonApplicationUID + `attr_opts` case; without a shared helper, the lockstep relies on programmer vigilance and has historically failed.
- **`docs/solutions/logic-errors/oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md`** — the empty-LdapProvisioner-type → `'all'` precedent. Establishes that the plugin must mirror the LdapProvisioner's runtime filtering semantics at writer/comparator emit time. The DB-reset SQL block in this doc is the operator remediation path documented in Operational Notes.
- **`docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`** — the atomic save discipline (claim row + back-pointer saved atomically in `toClaim()` per commit `1659690`) and the foreach-no-match accumulator pattern (per commit `c503465`). Both shapes must stay intact in U2; do not regress the save ordering, do not reintroduce a `foreach` self-assign for the CoService loop.

---

## Key Technical Decisions

- **Single helper, called from both writer and comparator.** Structural enforcement of R5's byte-identical contract — without the shared helper, the lockstep-mirror discipline lives only in comments and has historically slipped (`oa4mp-unmarshall-claim-comparator-drift-2026-05-05`). The helper accepts `$coId`, the `LdapProvisionerAttribute.type` value (string or empty/null), the `attr_opts` boolean, and an optional `$lookupCache` reference. It returns either a string (the encoded `constraint_value`) or null (the "suppress claim" signal).

- **Uniform anchored regex within `attr_opts`-ON.** Single-type sets emit `^<escaped>$`; multi-type sets emit `^(<escaped1>|<escaped2>|...)$` with alternation parts in deterministic order. `attr_opts`-OFF stays bare literal per R1. Rationale: (a) anchoring is safe against future `cm_type` strings that share prefixes with existing identifier types — current production code works only because the type vocabulary has no substring collisions, which the plugin cannot guarantee going forward; (b) uniform shape between N=1 and N>1 eliminates the encoding-shape transition that creates phantom comparator drift when CoServices are added or removed; (c) the alternative ("literal for single, alternation for multi") preserves the original AE shape at the cost of a latent 1↔N comparator bug that has to be paid for elsewhere.

- **Deterministic alternation order: lexicographic sort on the escaped form.** Sorting on the escaped form means escape rules don't perturb order — writer and comparator compute the same string when they apply the same escape and the same sort. Lexicographic on the platform's default string comparison is the cheapest enforcement.

- **`preg_quote($x, '/')` for regex escape.** PHP's framework-native escape. The QDL/Java regex flavor accepts the same escape vocabulary for the metacharacters identifier types could realistically contain. No hand-rolled escape table.

- **CoService lookup uses `ClassRegistry::init('CoService')` with a distinct-on-identifier_type query.** Mirrors how `CoProvisioningTarget` is reached in the existing comparator. Conditions: `co_id` + `identifier_type IS NOT NULL`. CakePHP default `find` excludes soft-deleted rows. No status or group filter (R2a). The helper extracts unique `identifier_type` values from the result.

- **CoService lookup cached in the comparator's existing `$lookupCache`.** A new cache key parallel to the CoProvisioningTarget entry (e.g., `coService|{coId}`). A sync run over a client with many search attributes would otherwise repeat the CoService query per call; the existing cache pattern is the right home.

- **Comparator-first staging considered and rejected.** The product-lens reviewer asked whether shipping only the comparator side first (to convert latent into observed) would de-risk the writer change. Rejected because comparator-only drift without a remediation path produces alert fatigue: operators see drift, have no plugin-supported action to take, and learn to ignore the signal. Single-step delivery means new clients are correct on landing and existing-client drift surfaces alongside the documented remediation SQL.

---

## Open Questions

### Resolved During Planning

- **Encoding shape**: Uniform anchored regex (`^...$`) within `attr_opts`-ON for both single and multi-type sets; `attr_opts`-OFF unchanged (bare literal per R1). User-confirmed 2026-05-19; origin R4 and AE3/AE5/AE6/AE9/AE10 updated.
- **Suspended-status CoService**: Counts toward the effective filter (origin R2a).
- **Regex escape mechanism**: `preg_quote($x, '/')`.
- **Alternation order**: Lexicographic on the escaped form.
- **CoService cache integration**: Joins the existing `$lookupCache` in `buildClaimFromLdapMapping`.

### Deferred to Implementation

- **QDL `=~` empirical anchor semantics.** The QDL reference documents `=~` as "Regular expression match" without specifying anchor behavior. The uniform `^...$` encoding is safe under either interpretation (anchored full-match: explicit anchors redundant but harmless; unanchored find: explicit anchors mandatory and load-bearing). The U3 verification step exercises a real `cm_type` value against the encoded constraint on an OA4MP server to confirm behavior; if the empirical result reveals that anchors are redundant, no code change is needed.
- **Class home for the helper.** Whether the helper lives as a public static method on `Oa4mpClientCoSearchAttribute`, a public method on `Oa4mpClientOa4mpServer`, or a new dedicated utility class is left to implementer judgment. The constraint is that BOTH the writer (`Model/Oa4mpClientCoSearchAttribute.php`) and the comparator (`Model/Oa4mpClientOa4mpServer.php`) call the same method with identical arguments. A public static method on `Oa4mpClientCoSearchAttribute` is the lowest-friction home given the writer already lives there; the comparator can reach it via the model.

### Surfaced by 2026-05-19 brainstorm review, addressed here

- **R4-vs-OQ contradiction (review item 1)**: resolved by the Encoding shape decision above; the origin's OQ entry on regex encoding is reduced to anchoring/ordering verification, both of which this plan settles.
- **1→N transition trap (review item 5)**: dissolved by the uniform anchored regex form; encoding shape no longer changes when CoServices are added or removed.
- **Suppress-claim-on-zero-matches token-emission trace (review item 3)**: the OA4MP server's QDL handler iterates `claim_mappings.`; absence of a mapping means the claim does not appear in the token. The U3 verification step includes a real-token check to confirm "no claim row → claim absent from token" rather than "claim emitted with empty value or downstream error".
- **Preemptive premise (review item 4)**: accepted as planning input. Mitigation: comparator drift signal after U2+U3 land converts the latent-fleet problem into observed, per-client drift entries the operator can remediate.
- **Comparator-first alternative (review item 6)**: rejected; rationale in Key Technical Decisions above.
- **Drift remediation path (review item 2)**: documented in Documentation / Operational Notes — per-client DB-reset SQL plus edit-and-save the OIDC client is the operator's remediation; the existing inner `claim_id IS NULL` migration gate (post-`1659690`) means edit-and-save re-runs `toClaim()` for reset search attributes.
- **Cross-plugin LdapProvisioner coupling (review item 7)**: acknowledged as a long-term maintenance cost. Mitigation: U1's helper docblock cites the authoritative LdapProvisioner block (file + line range) so future LdapProvisioner changes to the voPersonApplicationUID filtering rule surface via cross-file grep, and the lockstep-mirror learning doc carries the discipline pattern for future similar work.

---

## High-Level Technical Design

> *This sketch illustrates the intended approach and is directional guidance for review, not implementation specification. The implementing agent should treat it as context, not code to reproduce.*

```
toClaim() (writer, U2)              buildClaimFromLdapMapping() (comparator, U3)
       \                                            /
        \                                          /
         +-->  computeEffectiveConstraint(  <-----+
                  coId,
                  ldapProvisionerAttributeType,   // string or empty
                  attrOpts,                       // bool
                  &lookupCache                    // optional; comparator passes its existing cache
               )
                       |
                       +--  attrOpts OFF
                       |       --> return existing-literal-with-empty->'all'-normalization (per R1)
                       |
                       +--  attrOpts ON
                              |
                              +--  query DISTINCT CoService.identifier_type WHERE co_id = $coId
                              |    (CakePHP default find; excludes soft-deleted; no status/group filter)
                              |
                              +--  if ldapProvisionerAttributeType is specific:
                              |        intersect with { ldapProvisionerAttributeType }
                              |
                              +--  if effective set empty:
                              |        --> return null  (caller suppresses the claim per R3)
                              |
                              +--  else:
                                       preg_quote each element with '/' delimiter
                                       sort lexicographically on escaped form
                                       join with '|' if more than one
                                       wrap in '^(...)$'  (single-element: '^X$', multi: '^(A|B|...)$')
                                       --> return encoded string
```

The writer (U2) calls the helper, then either short-circuits without saving (helper returned null) or proceeds with the existing `saveAssociated` path using the returned string as `constraint_value`. The comparator (U3) calls the helper, then either returns null from `buildClaimFromLdapMapping()` (helper returned null → comparator expects no claim) or builds the expected claim shape with the returned string as `constraint_value`. The two paths share the helper's output, which is the R5 byte-identical contract.

---

## Implementation Units

### U1. Add effective-filter + encoding helper

**Goal:** Introduce the single helper described in High-Level Technical Design. The helper is the canonical site where writer (U2) and comparator (U3) agree on what the encoded `constraint_value` should be (or whether the claim should exist at all).

**Requirements:** R2, R2a, R3, R4, R4a, R5 (the helper's determinism is the structural enforcement of R5).

**Dependencies:** None.

**Files:**
- Modify: `Model/Oa4mpClientCoSearchAttribute.php` (add a public static method, e.g., `computeVoPersonApplicationUidConstraint($coId, $ldapProvisionerAttributeType, $attrOpts, &$lookupCache = null)`).

The class home is the lowest-friction option (writer already lives here; comparator can reach it via the model). If implementation discovers a better home, the planning constraint is only that both call sites share one method.

**Approach:**
- attr_opts OFF branch: return the existing literal encoding — `$ldapProvisionerAttributeType` with empty/null normalized to `'all'`. Reuse the exact normalization pattern from `Model/Oa4mpClientCoSearchAttribute.php` ~line 321-327.
- attr_opts ON branch:
  1. If `$lookupCache !== null` and contains a `coService|{coId}` entry, use that; otherwise query `ClassRegistry::init('CoService')` for distinct `identifier_type` values where `co_id == $coId` AND `identifier_type IS NOT NULL`. Use the resulting unique-values list. Cache it in `$lookupCache['coService|' . $coId]` when a cache was passed.
  2. If `$ldapProvisionerAttributeType` is a specific (non-empty, non-null) value: intersect against `{$ldapProvisionerAttributeType}`. Otherwise keep the full distinct set (the "All Types" branch).
  3. If the resulting set is empty: return null.
  4. Otherwise: `preg_quote` each element with `/` delimiter; sort lexicographically on the escaped form; if exactly one element, return `'^' . $escaped . '$'`; if more than one, return `'^(' . implode('|', $sortedEscaped) . ')$'`.
- Docblock cites the authoritative LdapProvisioner block (external repo path + line range 692-718 in `app/Plugin/LdapProvisioner/Model/CoLdapProvisionerTarget.php`) and the lockstep-mirror learning doc.

**Patterns to follow:**
- Empty-string → `'all'` normalization at `Model/Oa4mpClientCoSearchAttribute.php` ~line 321-327 (commit `f298ba0`).
- `$lookupCache` pattern at `Model/Oa4mpClientOa4mpServer.php` ~line 1465-1483 (the CoProvisioningTarget cache shape).
- Accumulator pattern from commit `c503465` (no `foreach` self-assign on the CoService loop).
- AGENTS.md: PHP 8.3, double-slash comments, CakePHP 2.x conventions.

**Test scenarios:**

Note: the plugin's `Test/Case/Model/` directory contains only placeholder `empty` files; no PHPUnit infrastructure for model tests exists today. U1 has no standalone automated test step. Its verification happens at U2 and U3 via manual reproduction; the scenarios below are the verification checklist the implementer applies when exercising the helper through its callers.

- Covers AE1. Happy path: attr_opts=false, `ldapProvisionerAttributeType='eppn'` → `'eppn'` (literal, unchanged).
- Covers AE2. Edge case: attr_opts=true, `ldapProvisionerAttributeType=''`, CO has zero CoServices → null.
- Covers AE3. Happy path: attr_opts=true, `ldapProvisionerAttributeType=''`, CO has CoServices with identifier_types {eppn, oidcsub} → `^(eppn|oidcsub)$` (alphabetical).
- Covers AE4. Edge case: attr_opts=true, `ldapProvisionerAttributeType='eppn'`, CO has no CoService with identifier_type='eppn' → null.
- Covers AE5. Happy path: attr_opts=true, `ldapProvisionerAttributeType='eppn'`, CO has one or more CoServices with identifier_type='eppn' → `^eppn$`.
- Covers AE9. Edge case: attr_opts=true, `ldapProvisionerAttributeType=''`, CO has a CoService with identifier_type='eppn.legacy' → `^eppn\.legacy$` (metacharacter escaped).
- Covers AE10. Happy path: attr_opts=true, CO has only a Suspended CoService with identifier_type='eppn' → `^eppn$` (status does not exclude).
- Edge case: attr_opts=true, multiple CoServices with the same identifier_type='eppn' → `^eppn$` (deduplication of identifier types).
- Edge case: attr_opts=true, CO has only soft-deleted CoService rows → null (CakePHP default find excludes them).
- Determinism: same inputs called twice → byte-identical output (sort stable, escape stable).

**Verification:**
- Helper is callable from both U2 and U3 paths with the documented signature.
- The scenarios above hold when exercised via U2 (writer) and U3 (comparator) integration reproduction.

---

### U2. Wire helper into `toClaim()` for the voPersonApplicationUID branch

**Goal:** Make the writer use the U1 helper for the voPersonApplicationUID + attr_opts-ON case, and suppress claim persistence when the helper returns null.

**Requirements:** R1, R2, R3, R4, R4a, R8.

**Dependencies:** U1.

**Files:**
- Modify: `Model/Oa4mpClientCoSearchAttribute.php` (`toClaim()` — the voPersonApplicationUID switch branch at ~line 224-234 and the `useLdapProvisionerConfig` block at ~line 263-332).

**Approach:**
- In the existing `useLdapProvisionerConfig` block, after `$ldapProvisionerTarget` and `$ldapProvisionerAttribute` are resolved (~line 296-318), branch:
  - If the search attribute name is `voPersonApplicationUID` AND `$ldapProvisionerTarget['attr_opts']` is truthy → call the U1 helper with `$coId`, `$ldapProvisionerAttribute['type']`, `true`. Do not pass `$lookupCache` (the writer is a single-OIDC-client edit, no cache).
    - Helper returns null → log a diagnostic line in the existing "did not convert" log shape (e.g., `voPersonApplicationUID claim suppressed: effective filter is empty for co_id=$coId, ldapProvisionerAttributeType='$type', attr_opts=on`) and `return;` from `toClaim()` without persisting. Match the early-return shape at ~line 282-285 / 297-299 / 315-317.
    - Helper returns string → build `$claimConstraints[] = array('constraint_field' => 'type', 'constraint_value' => $encoded)` and let the existing `saveAssociated` path continue.
  - Otherwise (attr_opts OFF, or search attribute is not voPersonApplicationUID) → existing literal-with-empty→'all' normalization unchanged. The block at ~line 321-332 keeps its current behavior.

**Patterns to follow:**
- Early-return diagnostic log shape at ~line 282-285, 297-299, 315-317.
- Atomic save discipline from commit `1659690`: do NOT introduce any save between `saveAssociated` and `saveField('claim_id', $newId)`.
- Accumulator-based attribute lookup from commit `c503465` at ~line 307-313: preserve when modifying this block.

**Test scenarios:**
- Covers AE1. Happy path: attr_opts OFF, voPersonApplicationUID + type='eppn'. Run `toClaim()` end-to-end via OIDC client edit → one claim row in `cm_oa4mp_client_claims`, one row in `cm_oa4mp_client_claim_constraints` with `constraint_value='eppn'`, `cm_oa4mp_client_co_search_attributes.claim_id` set.
- Covers AE2. Edge case: attr_opts ON, voPersonApplicationUID + type='', CO has zero CoServices. Run `toClaim()` → no row in `cm_oa4mp_client_claims`, `search_attribute.claim_id` stays NULL, suppression diagnostic line appears in the plugin log.
- Covers AE4. Edge case: attr_opts ON, voPersonApplicationUID + type='eppn', CO has CoServices but none with identifier_type='eppn'. Run `toClaim()` → no row persisted, diagnostic line emitted.
- Covers AE5. Happy path: attr_opts ON, voPersonApplicationUID + type='eppn', CO has at least one CoService with identifier_type='eppn'. Run `toClaim()` → one claim row, constraint_value=`^eppn$`, back-pointer set.
- Covers AE3. Happy path: attr_opts ON, voPersonApplicationUID + type='', CO has CoServices with identifier_types {eppn, oidcsub}. Run `toClaim()` → one claim row, constraint_value=`^(eppn|oidcsub)$`, back-pointer set.
- Covers AE9. Edge case: attr_opts ON, voPersonApplicationUID + type='', CO has a CoService with identifier_type='eppn.legacy'. Run `toClaim()` → constraint_value=`^eppn\.legacy$`.
- Covers AE10. Happy path: attr_opts ON, voPersonApplicationUID + type='', only a Suspended CoService with identifier_type='eppn'. Run `toClaim()` → constraint_value=`^eppn$`.
- Covers AE8. Integration: OIDC client with both voPersonApplicationUID and uid as search attributes, attr_opts ON. Run migration → voPersonApplicationUID claim follows new behavior; uid claim follows existing `useLdapProvisionerConfig` path with literal value, no `^...$` form.
- Regression: existing attr_opts-OFF clients edit-and-save → no diff in `cm_oa4mp_client_claim_constraints` rows.

Verification approach: per-scenario, run the per-client DB-reset SQL from `docs/solutions/logic-errors/oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md` to clear claim state, edit the OIDC client to trigger the migration block, inspect `cm_oa4mp_client_claim_constraints` rows and the plugin log. Configure the test CO's CoServices and the LdapProvisionerTarget's `attr_opts` flag as the scenario describes via the Registry UI.

**Verification:**
- Manual reproduction of AE1, AE2, AE3, AE4, AE5, AE8, AE9, AE10 against a real OIDC client + LdapProvisioner config with DB state and log lines matching expectations.
- Existing attr_opts-OFF clients show no row-level diff after edit-and-save (regression check).

---

### U3. Wire helper into `buildClaimFromLdapMapping()` for comparator parity

**Goal:** Make the sync comparator compute its expected constraint shape via the same U1 helper, including the suppress-claim case when the effective set is empty. Reuse the existing `$lookupCache` for the new CoService query.

**Requirements:** R5, R6, R8.

**Dependencies:** U1.

**Files:**
- Modify: `Model/Oa4mpClientOa4mpServer.php` (`buildClaimFromLdapMapping()` ~line 1309-1536, specifically the voPersonApplicationUID branch and the `useLdapProvisionerConfig` block at ~line 1459-1532).

**Approach:**
- After `$matchedAttribute` is resolved (~line 1504-1517), branch:
  - If the search attribute is `voPersonApplicationUID` AND `$ldapProvisionerTarget['attr_opts']` is truthy → call the U1 helper with `$coId`, `$matchedAttribute['type']`, `true`, AND pass `$lookupCache` through.
    - Helper returns null → `return null` from `buildClaimFromLdapMapping()`. The comparator's caller (`isClientDataSynchronized`) treats null returns as "no expected claim" — drift is reported if a persisted claim exists for this search attribute. Log a `buildClaimFromLdapMapping: voPersonApplicationUID effective filter empty for co_id=$coId; expecting no claim` diagnostic to match the existing log shape at ~line 1455, 1461, 1486, 1500, 1515.
    - Helper returns string → use it as the `constraint_value` in `$claimConstraints`; let the rest of the comparator-side claim assembly run.
  - Otherwise → existing literal-with-empty→'all' normalization unchanged (the block at ~line 1519-1531 keeps its current behavior).

**Patterns to follow:**
- Cache key shape parallel to the existing CoProvisioningTarget cache at ~line 1465-1483 (e.g., `coService|{coId}`).
- Diagnostic log shape consistent with the existing returns at ~line 1455, 1461, 1486, 1500, 1515.

**Test scenarios:**
- Covers AE6. Integration: persisted claim has `^eppn$` (from earlier sync when only the eppn CoService existed). Add a CoService with identifier_type='oidcsub'. Run `isClientDataSynchronized` → comparator computes `^(eppn|oidcsub)$`; drift reported (per-side log shows current=`^(eppn|oidcsub)$`, persisted=`^eppn$`).
- Covers AE7. Integration: persisted claim has `^eppn$`. Delete the eppn CoService (no other matching CoServices). Run `isClientDataSynchronized` → comparator returns null for this search attribute's expected claim; drift reported as "expected no claim but persisted claim present".
- Edge case: attr_opts OFF, persisted claim has bare literal `eppn`. Run sync → no drift (OFF path unchanged on both sides).
- Edge case: attr_opts ON, persisted claim absent (`search_attribute.claim_id` is NULL), CoService set is empty → no drift (comparator returns null; persisted absence matches).
- Integration (cross-pair): write a fresh attr_opts-ON OIDC client via U2 (post-migration constraint_value=`^(eppn|oidcsub)$`). Immediately run comparator via U3 against the same DB state. Expected: comparator computes byte-identical `^(eppn|oidcsub)$`; "in sync".
- Edge case: cache reuse — run sync over a client with multiple voPersonApplicationUID-flavored claims (hypothetical, since the schema is 1-to-1, but the cache must still behave correctly for any future N>1 case). Inspect that only one CoService query fires per CO per sync run.
- Covers AE10 (regression). Integration: attr_opts ON, CO has only a Suspended CoService with identifier_type='eppn'. After writer-side migration (U2) persists `^eppn$`, run comparator → "in sync" (Suspended counts equally on both sides per R2a).

Verification approach: trigger sync via the existing comparator entry point (or call `isClientDataSynchronized` directly on a test client), inspect the per-side drift log added in commit `26d5ae3`. Manipulate the CO's CoService rows directly in the database to drive AE6 and AE7 scenarios.

**Verification:**
- AE6 and AE7 reproduction shows the documented drift signals.
- A freshly-U2-migrated attr_opts-ON client whose CoServices have not changed since migration reports "in sync".
- Existing attr_opts-OFF clients show no drift (regression).
- The cross-pair scenario confirms writer and comparator produce byte-identical strings.

---

## System-Wide Impact

- **Interaction graph:** The voPersonApplicationUID branch in `toClaim()` is reached during the OIDC client edit migration block (`Controller/Oa4mpClientCoOidcClientsController.php` `edit()` ~line 455, post-commit `1659690`) when a search attribute's `claim_id` is NULL. The `buildClaimFromLdapMapping()` site is called once per search attribute by `isClientDataSynchronized`. The new CoService lookup adds one query per CO per sync run (cached afterward).
- **Error propagation:** U1's null return is the "suppress claim" signal. U2 must early-return cleanly (no orphan rows, no half-saved state); the existing pre-saveAssociated early-return pattern is the shape. U3 must early-return null so the comparator's caller (`isClientDataSynchronized`) treats it as "no expected claim" — that code path already exists for unsupported search attributes (~line 1455).
- **State lifecycle risks:** Suppressed-claim case must leave `search_attribute.claim_id` NULL (matching existing "did not convert" exits). Re-running migration on the same client after a CoService change must NOT create a duplicate claim — the inner `claim_id IS NULL` guard added in `1659690` handles this. The atomic save discipline (claim row + `saveField('claim_id', …)` adjacency, per `1659690`) must stay intact in U2.
- **API surface parity:** No external API surface changes. The cfg-writer (`oa4mpMarshallCfgQdl` at ~line 836) reads from `cm_oa4mp_client_claim_constraints` rows — its `&&`-guard (post-commit `7684cbb`) continues to skip half-populated rows; new uniform anchored regex values pass through unchanged.
- **Integration coverage:** Writer ↔ comparator byte-identical equality is the R5 contract. The cross-pair scenario in U3 exercises this directly; unit-level helper testing alone cannot prove the contract (the contract is about the call sites' shared use of the helper).
- **Unchanged invariants:** attr_opts-OFF behavior (R1); all other search-attribute branches in `toClaim()` and `buildClaimFromLdapMapping()`; the database schema; the OA4MP server cfg shape; `Oa4mpClientClaimConstraint` validation rules; the foreach accumulator pattern from `c503465`; the atomic-save discipline from `1659690`; the `&&`-guard from `7684cbb`.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| QDL `=~` semantics turn out to be substring/find (unanchored), making the `^...$` form mandatory rather than belt-and-suspenders. | U3 verification exercises a real `cm_type` value via the sync comparator on the OA4MP server. If `=~` is unanchored, the explicit anchors do the work. If `=~` is anchored-by-default, anchors are redundant but not harmful. The uniform form is safe under either interpretation. |
| Existing attr_opts-ON OIDC clients have persisted constraint values from the pre-fix code path (bare literals). After U2+U3 land, every such client reports drift. | Expected and documented (Operational Notes below). Operator remediation: per-client DB-reset SQL + edit-and-save the OIDC client. No bulk-migration tool in scope. |
| The helper's CoService query is repeated N+1 across many OIDC clients in a single sync run if cache integration is sloppy. | `$lookupCache` is passed through from `buildClaimFromLdapMapping()` to the helper; cache key is per-CO so identical CO context across clients reuses the result. The U3 cache-reuse test scenario asserts this. |
| Future LdapProvisioner change to voPersonApplicationUID filtering rule silently diverges from the plugin's helper. | U1's docblock cites the LdapProvisioner block (file + line range) so cross-file grep surfaces the link; the lockstep-mirror learning doc captures the discipline pattern. Any future LdapProvisioner change in that block should re-read this plan as related context. |
| Implementer skips the AE9 (regex-metacharacter) scenario because today's identifier types contain no metacharacters. | R4a is a hard origin requirement and AE9 is enumerated explicitly in U1 and U2's test scenarios. Code review should flag U1 as incomplete if no scenario exercises a metacharacter-containing identifier type. |
| Implementer regresses the atomic-save discipline (commit `1659690`) by adding a save between `saveAssociated` and `saveField`. | U2's "Patterns to follow" explicitly calls this out; the related learning doc (`oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`) captures the bug shape. |

---

## Documentation / Operational Notes

- **Operator remediation for surfaced drift.** After U2+U3 land, the sync comparator will report drift for any pre-existing attr_opts-ON OIDC client whose persisted `voPersonApplicationUID` constraint is a bare literal (the pre-fix shape) rather than the new `^...$` form. Per-client remediation:
  1. Run the per-client reset SQL pattern from `docs/solutions/logic-errors/oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md`: delete `cm_oa4mp_client_claim_constraints` rows for the affected claim, delete the `cm_oa4mp_client_claims` row, reset `cm_oa4mp_client_co_search_attributes.claim_id` to NULL for the affected client.
  2. Edit-and-save the OIDC client in the Registry UI. The inner `claim_id IS NULL` migration gate (post-`1659690`) re-runs `toClaim()` for the reset search attribute, which now hits U2's new logic and persists the encoded `^...$` value.
  3. Re-run the sync comparator on that client; expect "in sync".

  No bulk-migration tool is in scope; remediation is per-client and operator-driven, matching the precedent established by the empty-type fix and the migration-gate fix.

- **Learning-doc refresh** after the fix lands — separate `/ce-compound-refresh` pass, scoped to update the three sibling learning docs (`oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md`, `oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md`, `oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`) with cross-references to this fix and a new learning doc capturing the helper + CoService-coupling pattern.

---

## Sources & References

- **Origin document:** `docs/brainstorms/2026-05-19-oa4mp-attr-opts-claim-constraint-brainstorm.md`
- Related code (this plugin):
  - `Model/Oa4mpClientCoSearchAttribute.php` (`toClaim()`)
  - `Model/Oa4mpClientOa4mpServer.php` (`buildClaimFromLdapMapping()`, `oa4mpMarshallCfgQdl()`)
  - `Model/Oa4mpClientClaimConstraint.php` (validation rules)
  - `Controller/Oa4mpClientCoOidcClientsController.php` (`edit()` migration block)
- Related code (external, COmanage Registry repo):
  - `app/Plugin/LdapProvisioner/Model/CoLdapProvisionerTarget.php` line 692-718 (voPersonApplicationUID + `attr_opts` filter)
  - `app/Model/CoService.php` line 403-411 (`mapIdentifierToLabels`)
- Related code (external, OA4MP server config repo):
  - `repositories/cilogon-service-config-us/roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl` (`handle_identifier`)
- Related learning docs:
  - `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` (lockstep-mirror discipline)
  - `docs/solutions/logic-errors/oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md` (empty-type precedent + DB-reset SQL)
  - `docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md` (atomic save discipline, accumulator pattern, `&&`-guard alignment)
- Related commits:
  - `f298ba0` — empty LdapProvisioner type → `'all'` normalization
  - `1659690` — atomic claim + back-pointer save; remove coarse migration gate
  - `7684cbb` — cfg-writer `&&`-guard alignment
  - `c503465` — `foreach` accumulator pattern for provisioner-attribute lookup
  - `26d5ae3` — sync comparator per-side detail log
