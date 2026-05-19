---
date: 2026-05-19
topic: oa4mp-attr-opts-claim-constraint
---

# OA4MP voPersonApplicationUID Claim Constraint Under LdapProvisioner Attribute Options

## Summary

When the COmanage LdapProvisioner has `attr_opts` enabled and the OIDC client uses `voPersonApplicationUID` as a search attribute, the plugin's claim-constraint logic must inspect the CoService model and emit a constraint that mirrors the LdapProvisioner's CoService-derived identifier-type filtering — so the OA4MP server's DynamoDB-via-QDL claim path stops returning identifiers that LDAP would have suppressed.

---

## Problem Frame

The plugin currently builds the `'type'` claim constraint for `voPersonApplicationUID` solely from `CoLdapProvisionerTarget` and the corresponding `CoLdapProvisionerAttribute`. It never inspects whether `attr_opts` is enabled, and never consults the CoService model.

When `attr_opts` is enabled, the LdapProvisioner's export behavior changes materially: an identifier is exported only when at least one CoService with matching `identifier_type` exists in the CO, and each matching service yields a separate `voPersonApplicationUID;app-{short_label}` attribute. If no matching service exists, the identifier is suppressed from LDAP entirely.

The OA4MP server runs two different data-source paths for claims. The LDAP path queries LDAP directly and inherits the LdapProvisioner's CoService-based filtering. The DynamoDB path runs the plugin-generated claim mapping (with its `'type'` constraint) through a QDL handler that filters identifier rows from DynamoDB by `cm_type` using the constraint value as a regex. Because the plugin's current constraint is computed without consulting CoService, the DynamoDB path can return identifiers the LDAP path would have suppressed.

This is a latent failure mode rather than a triaged symptom: the divergence has not been observed in a specific OIDC client yet, but the wiki-described LdapProvisioner behavior and the plugin's existing logic confirm it must exist anywhere `attr_opts` is enabled and `voPersonApplicationUID` is in use.

---

## Key Flows

- F1. OIDC client claim conversion / migration for `voPersonApplicationUID`
  - **Trigger:** Operator edits an OIDC client; the migration block (or any path that calls `toClaim()`) processes a `voPersonApplicationUID` search attribute.
  - **Actors:** Operator, plugin writer.
  - **Steps:** (a) Resolve the OIDC client's CoLdapProvisionerTarget and the matching CoLdapProvisionerAttribute. (b) If `attr_opts` is OFF, use the existing behavior (constraint value = the attribute's `type`, normalized empty → `'all'`). (c) If `attr_opts` is ON, compute the effective identifier-type set: intersection of the attribute's `type` (or any type if "All Types") with the set of `CoService.identifier_type` values in the CO. (d) Emit one `'type'` constraint encoding that set; if the set is empty, suppress the claim entirely (no claim row, no back-pointer).
  - **Outcome:** The persisted claim either matches the LdapProvisioner's effective LDAP export filter, or it does not exist (mirroring "no matching service → no LDAP export").
  - **Covered by:** R1, R2, R3, R4, R5.

- F2. Sync comparator detects drift after CoService change
  - **Trigger:** A CoService is added, deleted, or has its `identifier_type` changed after the OIDC client's claim was already persisted.
  - **Actors:** Plugin comparator, operator.
  - **Steps:** (a) On the next `isClientDataSynchronized` run, the comparator recomputes the effective filter from current CoService state. (b) If the result differs from the persisted claim constraint — either in value or in claim-existence — the comparator reports drift with per-side detail in the log. (c) The operator decides on remediation.
  - **Outcome:** Drift is visible in the comparator output, not silently absorbed.
  - **Covered by:** R6, R7.

---

## Requirements

**Writer behavior**
- R1. When `attr_opts` is OFF on the CoLdapProvisionerTarget, the plugin's `voPersonApplicationUID` claim-constraint behavior is unchanged from today (current `LdapProvisionerAttribute.type` → constraint value, with empty normalized to `'all'`).
- R2. When `attr_opts` is ON and the search attribute is `voPersonApplicationUID`, the plugin computes the effective identifier-type set as the intersection of (`LdapProvisionerAttribute.type` if specific, else every identifier type) with the set of `CoService.identifier_type` values in the CO.
- R2a. The CoService rows counted toward the effective identifier-type set are all non-deleted CoServices in the CO, regardless of operational status (Active, Suspended, or any other state). This mirrors the LdapProvisioner's `voPersonApplicationUID` export path, which applies no status filter. If the LdapProvisioner ever introduces a status filter on this path, R2a must be revisited in lockstep so the plugin and provisioner stay aligned.
- R3. If the effective identifier-type set is empty, no claim is persisted for this search attribute. The search attribute's `claim_id` back-pointer stays NULL, matching the existing "did not convert" exit paths in the writer.
- R4. If the effective identifier-type set is non-empty within the attr_opts-ON branch, exactly one `'type'` constraint is persisted on the claim, with its value encoded as a **uniform anchored regex** regardless of set size. Single-type sets emit `^<escaped_type>$`; multi-type sets emit `^(<escaped_type1>|<escaped_type2>|...)$` with alternation parts in deterministic order. The uniform shape (anchored at both ends, same form for N=1 and N>1) keeps writer and comparator byte-identical and avoids the encoding-shape transition that would otherwise occur when the matching CoService set grows or shrinks. attr_opts-OFF behavior is unchanged from today (bare literal value per R1).
- R4a. Each identifier-type element MUST be regex-escaped before inclusion in the constraint value. This applies to both single-type and multi-type sets — a single identifier type that contains regex metacharacters must not be emitted verbatim, since the OA4MP QDL evaluates the constraint as a regex against `cm_type`. Acceptance verification must include at least one identifier type carrying a metacharacter so the escape behavior is exercised at AE level.

**Comparator parity and drift**
- R5. The sync comparator (the path that builds the expected claim shape for `isClientDataSynchronized`) applies the same effective-filter logic as the writer, including the suppress-claim case when the effective set is empty. Writer and comparator must produce byte-identical constraint values for the comparator to report "no drift".
- R6. When CoService state changes after the OIDC client's claim was persisted, the next comparator run surfaces the divergence — either as a value mismatch on the `'type'` constraint or as a presence/absence mismatch when one side expects no claim. No code path silently absorbs the change.
- R7. The plugin does not auto-rebuild claim constraints in response to CoService save/delete events, and no new UI affordance is added to trigger a rebuild. The comparator's drift log is the operator's signal.

**Scope of the change**
- R8. The change applies only to the `voPersonApplicationUID` branch in the writer's switch (and the same branch in the comparator). Other dynamic search attributes (`uid`, `eduPersonPrincipalName`, `voPersonExternalID`, `voPersonID`) keep current behavior — the LdapProvisioner does not treat them as CoService-filtered.

---

## Acceptance Examples

- AE1. **Covers R1.** Given a CoLdapProvisionerTarget with `attr_opts` disabled and a `voPersonApplicationUID` attribute configured for identifier type "eppn", when the plugin migrates the search attribute, then the persisted claim has exactly one `'type'` constraint with value `eppn` — same as today.
- AE2. **Covers R2, R3.** Given `attr_opts` enabled, the attribute configured for "All Types", and a CO with **no** CoServices, when the plugin processes the search attribute, then no `Oa4mpClientClaim` is persisted and the search attribute's `claim_id` remains NULL.
- AE3. **Covers R2, R4.** Given `attr_opts` enabled, attribute configured for "All Types", and a CO with two CoServices having `identifier_type` values "eppn" and "oidcsub", when the plugin processes the search attribute, then exactly one claim is persisted with exactly one `'type'` constraint whose value is `^(eppn|oidcsub)$` (alternation parts in deterministic — e.g., alphabetical — order).
- AE4. **Covers R2, R3.** Given `attr_opts` enabled, attribute configured for "eppn" specifically, and a CO with no CoService having `identifier_type` = "eppn", when the plugin processes the search attribute, then no claim is persisted (matching the LdapProvisioner suppressing the export).
- AE5. **Covers R2, R4.** Given `attr_opts` enabled, attribute configured for "eppn" specifically, and a CO with one or more CoServices having `identifier_type` = "eppn", when the plugin processes the search attribute, then exactly one claim is persisted with one `'type'` constraint whose value is `^eppn$` (single-element set still encoded as the uniform anchored regex shape, not as a bare literal — preserves shape symmetry with the multi-type case).
- AE6. **Covers R5, R6.** Given a persisted claim with constraint value `^eppn$` (from an earlier migration when only an "eppn" CoService existed), when a new CoService with `identifier_type` "oidcsub" is added and the comparator runs, then the comparator reports drift because the recomputed effective set encodes as `^(eppn|oidcsub)$` and the persisted value is `^eppn$`.
- AE7. **Covers R5, R6.** Given a persisted claim from a CO that had a single CoService with `identifier_type` "eppn", when that CoService is deleted and the comparator runs, then the comparator reports drift because the recomputed effective set is empty (writer would now suppress the claim entirely).
- AE8. **Covers R8.** Given an OIDC client with both `voPersonApplicationUID` and `uid` as search attributes and `attr_opts` enabled, when the plugin processes them, then the `voPersonApplicationUID` claim is computed per R2-R4 but the `uid` claim continues to use the current LdapProvisionerAttribute-only logic.
- AE9. **Covers R4, R4a.** Given `attr_opts` enabled and a CoService whose `identifier_type` contains a regex metacharacter (e.g., the literal string `eppn.legacy`), when the plugin processes the search attribute, then the persisted constraint value is `^eppn\.legacy$` — the metacharacter is escaped so the constraint matches identifiers of type `eppn.legacy` only, and does not over-match strings like `eppnXlegacy` that the unescaped form would accept.
- AE10. **Covers R2, R2a.** Given `attr_opts` enabled, the attribute configured for "All Types", and a CO with one CoService whose `identifier_type` is "eppn" but whose status is **Suspended** (and no other CoServices), when the plugin processes the search attribute, then exactly one claim is persisted with a `'type'` constraint value `^eppn$` — Suspended status does not exclude the service from the effective filter.

---

## Success Criteria

- An OIDC client configured with `attr_opts` enabled and `voPersonApplicationUID` no longer produces silent identifier-set divergence between the LDAP path and the DynamoDB-via-QDL path on the OA4MP server: both return the same set of identifier types.
- The sync comparator reports a drift signal (not "in sync") in every scenario where CoService state changed after the claim was persisted such that the LDAP-exported set differs from what the persisted constraint encodes.
- `ce-plan` can begin work from this brainstorm without needing to revisit which attribute is in scope, what the effective-filter rule is, how zero matches are handled, or how drift remediation works.
- A future learning doc capturing this fix can describe the CoService-vs-LdapProvisionerAttribute coupling concretely without reverse-engineering the requirements from the diff.

---

## Scope Boundaries

- Schema or model change to support multiple `Oa4mpClientClaim` rows per `Oa4mpClientCoSearchAttribute`. The existing 1-to-1 search-attribute → claim relationship stays.
- Auto-rebuild of claim constraints in response to CoService save/delete events. No plugin hooks added.
- A manual "resync constraints" UI action on the OIDC client config. No new operator-facing action.
- `voPersonApplicationPassword`. The LdapProvisioner has analogous CoService logic for it, but `toClaim()` does not currently treat it as a search attribute.
- Group-membership filtering of CoServices. The LdapProvisioner does not apply it to the `voPersonApplicationUID` export path, so neither does the plugin.
- Backfill of existing OIDC client configs where this drift exists silently. The comparator surfaces drift on next sync check; operators decide remediation.
- Refresh of related learning docs once the fix lands (handled separately via `/ce-compound-refresh`, as established in prior cycles).
- Validation rule changes on `Oa4mpClientClaimConstraint`. The `notBlank` discipline on `constraint_field` and `constraint_value` remains in force; an empty effective set produces no constraint row, not a row with empty value.

---

## Key Decisions

- **Effective filter from CoService, not existence-check only.** The simpler "existence check only" variant (keep the current constraint value if at least one CoService matches) was rejected because it still leaves drift in the "All Types" + selective-services case: LDAP exports only the subset of identifier types backed by services, but the constraint value `'all'` still matches every identifier type in DynamoDB.
- **Single regex constraint, not multiple type-constraint rows.** The OA4MP server's QDL handler honors a single `'type'` constraint per claim (anything else falls back to `'all'`). A single-string regex encoding is the only shape that survives the round trip through the server's QDL.
- **One claim per search attribute, not one-per-service.** A multi-claim shape would require schema work (1-to-many search-attribute → claims) and would break the round-trip integrity of the existing comparator. The QDL accepts regex matching against `cm_type`, so a single-claim multi-type-regex constraint is sufficient.
- **Suppress the claim when zero matches, do not emit an empty-value constraint.** The plugin's `notBlank` validation would reject an empty `constraint_value` anyway; "no claim at all" is the semantically correct result, matching the LdapProvisioner's "no service → no export" behavior.
- **Comparator-only drift detection.** No auto-rebuild, no UI affordance. The plugin's established pattern is to surface drift via `isClientDataSynchronized`; operator-driven remediation matches how prior empty-type and migration-gate fixes were handled.
- **Snapshot semantics at writer-call time.** The constraint encodes CoService state at the moment `toClaim()` ran; later CoService changes do not retroactively rewrite the persisted value. The comparator's job is to surface that the snapshot is stale.

---

## Dependencies / Assumptions

- The OA4MP server's QDL handler for identifier claims (`dynamodb_claims.qdl:handle_identifier`) interprets the `'type'` constraint value as a regex applied via `=~` against `cm_type`. Verified by reading `repositories/cilogon-service-config-us/roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl`. If that contract changes (e.g., the server moves to exact-match or list semantics), the regex encoding here would need to be revisited.
- Identifier type values in COmanage are simple lowercase identifiers in practice (`eppn`, `eptid`, `oidcsub`, `uid`, etc.) without regex metacharacters. Regex-escaping of each element is **required by R4a** — defensive in the present (today's identifier types contain no metacharacters) but load-bearing if operators ever introduce identifier types containing regex syntax.
- The `CoService` model is reachable from the plugin via `ClassRegistry::init('CoService')`, mirroring how `CoProvisioningTarget` is reached today. No new plugin dependency or association is required.
- CakePHP's default `find` behavior excludes soft-deleted CoService rows; no explicit filter is needed to match what `CoLdapProvisionerTarget::assembleAttributes` queries via `CoService::mapIdentifierToLabels`.
- Both emit sites (`Oa4mpClientCoSearchAttribute::toClaim()` and `Oa4mpClientOa4mpServer::buildClaimFromLdapMapping()`) must share a single helper for the effective-filter computation, or replicate logic identically. The lockstep-mirror discipline between writer and comparator documented in `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` continues to apply.

---

## Outstanding Questions

### Deferred to Planning

- [Affects R4][Technical] How to encode a multi-type effective set as a single regex `constraint_value` that the QDL's `=~` operator matches against `cm_type` correctly. Specifically: (a) anchoring (full-match vs find/contains) and (b) deterministic ordering across writer and comparator. (The regex-escape rule for each element is settled by R4a — escape is required; the exact mechanism, e.g., `preg_quote` with which delimiter set, is a planning detail.) The QDL handler is the authority; planning should reproduce its matching semantics in a small fixture before settling on the encoding.
- [Affects R2, R5][Technical] Whether the existing `$lookupCache` in `buildClaimFromLdapMapping` should gain a CoService cache entry to avoid repeating the CoService lookup per search attribute when multiple claims in the same client refer to CoService state.
- [Affects R6][Needs research] Whether the comparator's per-side drift-log detail (added in commit `26d5ae3`) already renders constraint values verbatim, or whether the regex-encoded value needs a custom diff helper to keep operator-facing drift output human-readable.

---

## Deferred / Open Questions

### From 2026-05-19 review

- **R4 prescribes deterministic regex encoding; Outstanding Questions defers encoding design** — Requirements R4 / Outstanding Questions (P1, coherence (with adversarial), confidence 100)

  R4 explicitly states multi-type sets use "a deterministic regex encoding" as a requirement for this brainstorm, but the Outstanding Questions section defers the regex encoding design (anchoring, ordering) to the planning phase. Implementers reading R4 would begin coding a deterministic regex, while planners reading Outstanding Questions would expect to design the encoding. This creates divergent interpretation of whether the encoding decision is closed or open. Resolve either by loosening R4's commitment or by promoting the OQ items to requirements before planning starts.

  <!-- dedup-key: section="requirements r4 outstanding questions" title="r4 prescribes deterministic regex encoding outstanding questions defers encoding design" evidence="R4 line 50: a multi-type set uses a deterministic regex encoding so the OA4MP QDLs single-type-constraint contract" -->

- **Drift detection without remediation path leaves operators stuck** — R6 / R7 / Scope Boundaries (P1, product-lens (with adversarial), confidence 100)

  R6 surfaces drift via comparator, R7 forbids auto-rebuild, and Scope Boundaries explicitly rule out a "resync constraints" UI action. The doc declares "operator-driven remediation matches how prior empty-type and migration-gate fixes were handled" but never says what remediation an operator actually performs when the drift log fires. Without a documented operator workflow (re-save the OIDC client to re-run toClaim? manually edit the claim row?), the comparator output becomes noise the operator can see but cannot act on, which undermines the whole success criterion that drift signals are visible.

  <!-- dedup-key: section="r6 r7 scope boundaries" title="drift detection without remediation path leaves operators stuck" evidence="R7 The plugin does not auto-rebuild claim constraints in response to CoService save/delete events" -->

- **Suppress-claim-on-zero-matches has no story for token-emission side effects** — R3 / Key Decisions (P1, adversarial, confidence 75)

  The decision to "persist no claim at all" when the effective set is empty is justified solely on validation grounds (notBlank would reject it) and on mirroring LDAP. But the consumer is the OA4MP server emitting OIDC tokens. With no claim row present, the server's behavior is not "return an empty identifier set" — it's "no mapping configured for this search attribute at all," which may cause a different error path: a token issued without the claim, an authorization failure, or a fallback to a different attribute. The LDAP path with zero matching services returns an empty result for that attribute — semantically very different from "attribute is not mapped." The brainstorm never inspects what the OA4MP server does when a search-attribute-mapped claim is entirely absent versus when its DynamoDB query returns zero rows.

  <!-- dedup-key: section="r3 key decisions" title="suppress-claim-on-zero-matches has no story for token-emission side effects" evidence="R3 If the effective identifier-type set is empty no claim is persisted for this search attribute" -->

- **Preemptive-fix premise rests on inference, not observation** — Problem Frame / Success Criteria (P2, product-lens (with adversarial), confidence 100)

  The document is forthright that this is preemptive ("latent failure mode rather than a triaged symptom", "has not been observed in a specific OIDC client yet"). The success criteria assert that LDAP and DynamoDB paths "return the same set of identifier types" — but never trace forward to what an OIDC client consuming the divergent set actually does wrong (authorization decision based on wrong identifier? service routing? logging?). Without naming the downstream consequence, the priority of this fix versus other work is hard to defend, and the bar for "is this regex-encoding complexity worth it" cannot be calibrated. A targeted production audit (which COs currently run attr_opts ON with voPersonApplicationUID search?) would falsify or confirm the premise cheaply.

  <!-- dedup-key: section="problem frame success criteria" title="preemptive-fix premise rests on inference not observation" evidence="Line 22 This is a latent failure mode rather than a triaged symptom the divergence has not been observed" -->

- **Single-type encoding rule creates an irreversible 1-to-N transition trap** — R4 / AE5 / AE6 (P2, adversarial, confidence 75)

  R4 mandates "a single-type set uses the literal type as the value; a multi-type set uses a deterministic regex encoding." But this means adding a second CoService to a CO that previously had one doesn't just change the constraint's value — it changes the encoding shape from literal to regex. The comparator must now treat "eppn" and the single-element regex form as equivalent on the persisted side, OR force operators to manually re-migrate. The asymmetry also means a regression that drops back to one type leaves the persisted value as a now-degenerate regex even though the new effective set is a single type — the comparator will flag drift even when the effective behavior is identical.

  <!-- dedup-key: section="r4 ae5 ae6" title="single-type encoding rule creates an irreversible 1-to-n transition trap" evidence="R4 A single-type set uses the literal type as the value a multi-type set uses a deterministic regex encoding" -->

- **Alternative not considered: comparator-only fix, defer writer change** — Key Decisions / Requirements R1-R4 (P2, product-lens, confidence 75)

  The Key Decisions section evaluates and rejects "existence check only" and "multi-claim shape" alternatives, but never considers the lighter alternative of shipping only the comparator side (R5-R6) first — surface drift without changing the writer or introducing regex-encoded constraint values. Since the failure is latent and unobserved, the comparator alone would convert the "silent" problem into a "visible" one across the existing fleet, at which point the writer-side complexity (regex encoding, deterministic ordering, escape rules, CoService snapshot semantics) could be designed against actual observed cases rather than speculated ones. A staged path also de-risks the QDL regex-semantics assumption.

  <!-- dedup-key: section="key decisions requirements r1-r4" title="alternative not considered comparator-only fix defer writer change" evidence="Line 98-104 Key Decisions enumerate rejected alternatives but only writer-shape variants no consideration of" -->

- **Cross-plugin coupling to LdapProvisioner business logic compounds maintenance surface** — Dependencies / Problem Frame (P2, product-lens, confidence 75)

  The fix requires this plugin to faithfully mirror a specific subset of `CoLdapProvisionerTarget::assembleAttributes` / `CoService::mapIdentifierToLabels` behavior, and Outstanding Question on Suspended CoServices (now R2a) confirms the mirror must track LdapProvisioner edge cases the doc cannot yet enumerate. This creates a silent breakage class: any future LdapProvisioner change to the voPersonApplicationUID filtering rule (status filter, group filter, new attr_opts semantics) will not break the plugin loudly — it will reintroduce the exact silent divergence this fix exists to close. The doc should at minimum acknowledge this coupling as a long-term cost and consider whether a shared helper / contract test on the LdapProvisioner side belongs in scope.

  <!-- dedup-key: section="dependencies problem frame" title="cross-plugin coupling to ldapprovisioner business logic compounds maintenance surface" evidence="Line 113 CakePHP default find behavior excludes soft-deleted CoService rows no explicit filter is needed" -->
