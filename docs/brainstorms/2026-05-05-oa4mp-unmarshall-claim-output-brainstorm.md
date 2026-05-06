---
date: 2026-05-05
topic: oa4mp-unmarshall-claim-output
---

# Oa4mpClientOa4mpServer Unmarshall Claim Output

## Summary

Make `oa4mpUnMarshallContent` produce claim-shaped output for legacy cfg formats 1 and 2 so `oa4mpVerifyClient`'s sync comparison stops misfiring on legacy clients, and rename the unmarshall-output keys so the OA4MP-server-side of the comparator is consistent with the rest of the plugin.

## Problem Frame

The plugin's data model has migrated. Claim sources are now represented as `Oa4mpClientClaim` records (with `Oa4mpClientClaimConstraint` children), produced from earlier `Oa4mpClientCoLdapConfig` + `Oa4mpClientCoSearchAttribute` records by `Oa4mpClientCoSearchAttribute::toClaim()`. The plugin-side stored representation, all controllers, and one branch of `isClientDataSynchronized` have moved to the claim-shape.

The OA4MP server-side of `oa4mpUnMarshallContent` only kept up for cfg format 3 (QDLv3): `oa4mpUnMarshallCfgQdlv3` writes its result under `Oa4mpClaim`, and `isClientDataSynchronized` reads `Oa4mpClaim` to compare. For cfg format 2 (QDLv2) and format 1 (deprecated), the unmarshall path still appends to `Oa4mpClientCoLdapConfig`. That key is no longer read anywhere downstream, so legacy clients always fall into the "OA4MP server has no claims, plugin has claims" branch and report as out-of-sync regardless of actual server state.

Compounding the legibility problem, format-3's claim output uses short keys (`Oa4mpClaim`, `ClaimConstraint`) that disagree with every other `Oa4mpClientClaim` / `Oa4mpClientClaimConstraint` reference in the plugin. The two sides currently agree internally, so format-3 sync works, but the names are surprising next to a model class named `Oa4mpClientClaim` and will mislead future readers.

## Requirements

**Claim-shaped output for legacy formats**
- R1. When `oa4mpUnMarshallContent` parses a cfg in format 1 (deprecated) or format 2 (QDLv2), the returned `oa4mpClient` array carries the equivalent claim representation under the top-level key `Oa4mpClientClaim` (per R8), so the existing comparator branch consumes legacy and current cfg the same way.
- R2. The unmarshall result for legacy formats does not populate `Oa4mpClientCoLdapConfig`, since no downstream consumer reads it from this code path. Removing it leaves no zombie field in the result.
- R3. The format-1 unmarshalling continues to validate the `cfg.claims.preProcessing` block as it does today (consistency check between the configured claim source and `cfg.claims.sourceConfig`). The new claim conversion runs alongside that validation, not instead of it.

**Conversion fidelity**
- R4. The claims produced for legacy formats match what `Oa4mpClientCoSearchAttribute::toClaim()` produces for the same LDAP attributes — including the `CoProvisioningTarget` → `CoLdapProvisionerTarget` → `CoLdapProvisionerAttribute` lookup that derives constraint values for attributes such as `mail`, `uid`, `employeeNumber`, `voPersonApplicationUID`, `voPersonExternalID`, and `voPersonID`. Equivalent input must yield equivalent output between the persisted-claim side and the unmarshalled-server-side, so `isClientDataSynchronized`'s normalized comparison can return in-sync.
- R5. LDAP attribute names that `toClaim()` does not handle (its `default` branch) are silently skipped during unmarshall conversion, matching `toClaim()`'s "did not convert" behavior. Unknown attributes do not cause unmarshalling to fail.
- R6. The conversion logic for legacy formats is implemented in `Oa4mpClientOa4mpServer.php` and does not refactor `Oa4mpClientCoSearchAttribute::toClaim()`. The mapping table and provisioner-target lookup are duplicated in this file; the persisted-side `toClaim()` is left untouched.
- R7. `oa4mpUnMarshallContent` receives CO context — at minimum the calling client's `co_id` — so the duplicated conversion helper can run the `CoProvisioningTarget` find query that `toClaim()` uses. The function's signature is extended to accept this context, and `oa4mpVerifyClient` passes it through from the curClient/adminClient already in scope at the call site.

**Naming consistency on the OA4MP-server-side**
- R8. The unmarshall path writes claims under the key `Oa4mpClientClaim` and constraints under the nested key `Oa4mpClientClaimConstraint`. This applies to both the new legacy-format conversion and the existing format-3 path; for format 3, both the outer-claim assignment and the nested-constraint assignment in `oa4mpUnMarshallCfgQdlv3` must be renamed (not only the outer key).
- R9. `isClientDataSynchronized`'s OA4MP-server-side branch reads from `Oa4mpClientClaim` and `Oa4mpClientClaimConstraint`, kept in lockstep with the writer. The "note different key name from OA4MP server" comment becomes inaccurate after the rename and is removed.

## Acceptance Examples

- AE1. **Covers R1, R2, R8.** Given an OIDC client whose OA4MP-server cfg is in format 2 (QDLv2 with `ldap_to_claim_mappings`), when `oa4mpUnMarshallContent` runs on the server response, the returned array contains an `Oa4mpClientClaim` entry per claim mapping and no `Oa4mpClientCoLdapConfig` entries.
- AE2. **Covers R1, R3.** Given a format-1 (deprecated) cfg whose `claims.preProcessing` references an LDAP claim source matching `claims.sourceConfig`, when `oa4mpUnMarshallContent` runs, the preProcessing validation succeeds and the returned array contains `Oa4mpClientClaim` entries derived from the deprecated source config.
- AE3. **Covers R4, R7, R8, R9.** Given a CO with an LDAP provisioner target whose attributes determine constraint values for `mail`, and an OIDC client persisted with a corresponding `Oa4mpClientClaim` produced by `toClaim()`, when `oa4mpVerifyClient` runs against an unchanged OA4MP server using format-1 or format-2 cfg, `isClientDataSynchronized` returns true.
- AE4. **Covers R5.** Given a format-2 cfg containing an `ldap_to_claim_mappings` entry whose LDAP attribute name is unknown to the conversion table, when `oa4mpUnMarshallContent` runs, that mapping is skipped (not raised as an error) and the rest of the response is unmarshalled normally.

## Success Criteria

- Format-1 and format-2 OIDC clients that have not been changed externally on the OA4MP server stop reporting as out-of-sync from the plugin's UI.
- Format-3 OIDC clients continue to compare correctly after the rename — no regression on the existing path.
- A maintainer reading the unmarshall result or the OA4MP-server-side of `isClientDataSynchronized` finds `Oa4mpClientClaim` / `Oa4mpClientClaimConstraint` keys consistent with the rest of the plugin.

## Scope Boundaries

- Refactoring `Oa4mpClientCoSearchAttribute::toClaim()` into a pure builder shared between the persisted-side and the unmarshall-side. The mapping table and provisioner lookup are duplicated locally in `Oa4mpClientOa4mpServer.php` and accepted as duplication.
- Any change to how plugin-side `Oa4mpClientClaim` records get *created* via the existing migration path. The unmarshall side mirrors `toClaim()`'s output shape; `toClaim()` itself is untouched.
- Cfg formats other than 1, 2, and 3.
- Adding automated test coverage for `oa4mpUnMarshallContent` or `isClientDataSynchronized`. Verification is manual against a real OA4MP server with format-1, format-2, and format-3 clients.

## Key Decisions

- Both legacy formats (1 and 2) get fixed in the same change. They share the bug shape — both populate the unread `Oa4mpClientCoLdapConfig` key — and bundling avoids leaving a known-broken sync path on format-1 clients.
- Conversion fidelity must include the provisioner-target constraint lookup, not just the LDAP-attribute switch. Without the lookup, `isClientDataSynchronized`'s strict normalized comparison would still report "claims out of sync" on legacy clients even when the server is unchanged, making the fix only half-effective.
- Conversion logic is duplicated in `Oa4mpClientOa4mpServer.php` rather than factored out of `toClaim()`. Smaller blast radius for this fix; the duplication can be consolidated later if a third caller appears.
- The rename to `Oa4mpClientClaim` / `Oa4mpClientClaimConstraint` is bundled into this change rather than deferred. Three call sites (format 1, 2, 3) will all use the consistent names from this fix forward, and the comparator is already being touched as part of the rename.

## Outstanding Questions

### Deferred to Planning

- [Affects R4][Technical] R4 mandates full conversion fidelity, so the implementation must mirror every switch case `toClaim()` handles. The planning question is the discovery strategy: enumerate all current `toClaim()` cases up front, or stage discovery — start from the LDAP attributes observed in real-world cfg payloads and extend the mirror as additional cases surface in production.
- [Affects R4][Technical] Whether the provisioner-target lookup needs caching across multiple claims in one unmarshall call. `toClaim()` does the lookup per attribute; if the unmarshall path hits the same lookup repeatedly for one client, planning should decide whether to memoize within the function.
- [Affects R6][Technical] Where in `Oa4mpClientOa4mpServer.php` the duplicated conversion helper lives — a private method on the class versus a free helper, plus what its inputs are (raw `ldap_to_claim_mappings` entry vs. an already-shaped `Oa4mpClientCoSearchAttribute` array).

## Deferred / Open Questions

### From 2026-05-05 review

- **R6 mandates duplication that conflicts with R4's fidelity goal** — Requirements / Conversion fidelity (P1, scope-guardian, confidence 75)

  Implementers who must satisfy R4's strict equivalence guarantee ("equivalent input must yield equivalent output") while obeying R6's prohibition on refactoring toClaim() are left to maintain two independent copies of a non-trivial lookup path. Any future change to toClaim()'s switch cases or constraint derivation will silently diverge from the duplicated copy, regenerating the sync misfires the document exists to fix. The document acknowledges this tension ("the duplication can be consolidated later") but does not bound when divergence would become a problem or what process prevents it. The stated goal is a reliable sync comparison; accepted duplication of the fidelity-critical path undermines that goal from day one.

  <!-- dedup-key: section="requirements conversion fidelity" title="r6 mandates duplication that conflicts with r4s fidelity goal" evidence="R4. The claims produced for legacy formats match what Oa4mpClientCoSearchAttributetoClaim produces for the same" -->

- **Provisioner state drift creates false out-of-sync** — Requirements / Conversion fidelity (R4) (P1, adversarial, confidence 75)

  After this fix ships, legacy clients can still report as out-of-sync whenever the CO's CoLdapProvisionerAttribute type for an attribute like mail or uid has been changed since migration. toClaim() ran once at migration and froze constraint_value into the persisted claim. The duplicated unmarshall lookup recomputes constraint_value from current provisioner config every verify call. If the two diverge, isClientDataSynchronized's normalized comparison fails, partially defeating the stated goal of stopping the comparator from misfiring on legacy clients.

  <!-- dedup-key: section="requirements conversion fidelity r4" title="provisioner state drift creates false outofsync" evidence="The claims produced for legacy formats match what Oa4mpClientCoSearchAttributetoClaim produces for the same LDAP" -->

- **Duplication invites silent drift from toClaim** — Key Decisions / Scope Boundaries (P2, adversarial, confidence 75)

  The first time someone adds a new LDAP attribute case to toClaim(), changes its source_model_claim_value_field, or adjusts how an existing constraint is constructed and forgets to update the duplicated unmarshall mapping, legacy clients will silently start reporting as out-of-sync again. The plan justifies duplication on "smaller blast radius for this fix" but never names the long-term drift cost or compares it to extracting the read-only shape-the-claim portion of toClaim(). Inspection of toClaim() shows nearly all of lines 86-313 are read-only; only the saveAssociated/saveField calls at the end are write-side, so the extraction blast radius is modest.

  <!-- dedup-key: section="key decisions scope boundaries" title="duplication invites silent drift from toclaim" evidence="Conversion logic is duplicated in Oa4mpClientOa4mpServerphp rather than factored out of toClaim Smaller blast" -->

- **No tests for a three-path key rename** — Scope Boundaries / Success Criteria (P2, adversarial, confidence 75)

  The change renames the unmarshall write-key in three call sites (format 1, 2, 3) and the comparator read-key in two places, with no automated test catching a typo or missed call site. Format-3 is currently the only working sync path, so a typo on the format-3 writer or the comparator reader silently breaks every existing client's sync check until manual verification surfaces it. The plan defers to "manual against a real OA4MP server with format-1, format-2, and format-3 clients" but never confirms a format-1 (deprecated) fixture is reachable in the manual-test environment.

  <!-- dedup-key: section="scope boundaries success criteria" title="no tests for a threepath key rename" evidence="Adding automated test coverage for oa4mpUnMarshallContent or isClientDataSynchronized Verification is manual" -->
