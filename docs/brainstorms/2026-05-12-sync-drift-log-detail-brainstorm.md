---
date: 2026-05-12
topic: sync-drift-log-detail
---

# Sync Drift Log Detail

## Summary

Extend the per-side detail logging pattern already applied to the four `Oa4mpClientClaim` branches in `isClientDataSynchronized` to every other drift-log branch in the same function, so that an operator reading the log can see *how* the two sides differ, not just *that* they differ. Mask known-secret field values.

---

## Problem Frame

The `isClientDataSynchronized` function in `Model/Oa4mpClientOa4mpServer.php` runs roughly 25 separate equality checks between the plugin's view of an OIDC client and the OA4MP server's view. When any check fails, the function logs a short message of the form `"<Model> <field> is out of sync"` and returns false.

For most fields, this message reports the existence of drift but not its shape. The operator then has to attach a debugger, query the database by hand, or inspect the OA4MP server administratively to find out what each side actually contains — a time-consuming step that turns every sync failure into an investigation.

The prior workstream (May 2026) demonstrated the cost of this by hitting an `Oa4mpClientClaim` count-drift case where the message was uninformative; adding per-side dumps to the four claim branches let the operator immediately see the duplication that was happening. The remaining ~21 branches still have the same uninformativeness.

---

## Requirements

**Detail content**
- R1. Every drift-log branch in `isClientDataSynchronized` must include enough per-side detail in the log output that an operator can see what the two sides actually contained at the point of comparison.
- R2. Scalar-valued comparisons must include both values inline with the existing one-line drift message, in a form that identifies which side is which (e.g., something equivalent to `(plugin=<value>, oa4mp=<value>)`). When either value is long or contains line breaks (URLs, templates, multi-line strings), fall back to per-side dumps on subsequent lines rather than crowding the inline pair.
- R3. Array-valued and structure-valued comparisons must additionally emit per-side dumps of the compared values, matching the shape already used for the `Oa4mpClientClaim` branches.
- R4. Branches that report a presence mismatch (one side has a sub-structure, the other does not) must dump the contents of the present side. The empty side is implied by the message and does not need its own dump.

**Secrets**
- R5. Known-secret field values must be masked in the log output rather than written verbatim. The currently enumerated secrets in `Oa4mpClientDynamoConfig` are `aws_access_key_id` and `aws_secret_access_key` (both present in `Config/Schema/schema.xml`). Only `aws_access_key_id` is compared by an existing branch today; `aws_secret_access_key` is enumerated here so that if a future drift-check is added for it (or for any wholesale dump of the `Oa4mpClientDynamoConfig` structure) the masking rule applies without requiring a doc revision first.
- R6. Masking is applied inline at the affected branch, not via a generic allowlist, configuration, or framework. New secret-bearing fields added later will extend the same per-branch treatment.

**Scope of change**
- R7. The branches already updated in the prior session (the four `Oa4mpClientClaim` branches: count drift, plugin-has-server-doesn't, server-has-plugin-doesn't, normalized-claim-deep-compare) stay as they are; they are the reference pattern.
- R8. The existing comment-mismatch branch already logs the actual comment text and stays as-is.
- R9. The change is logging-only. No branch changes the value it returns, the order in which checks run, or whether a check fires.

---

## Success Criteria

- An operator reading the log file for any single drift case can identify the differing values without consulting the database or the OA4MP server administratively.
- No secret field value appears verbatim in the log file after the change.
- The function's behavior under unit-equivalent inputs is identical before and after: same return value, same call sequence, same side effects other than the expanded log lines.

---

## Scope Boundaries

- No restructuring of `isClientDataSynchronized` (helper extraction, table-driven comparisons, deduplication of the repeated `if(...) { log; return false; }` shape).
- No change to log level or destination — continues to use `$this->log()`.
- No configurable or pluggable secret-redaction allowlist; the one known case is handled inline.
- No special handling of email addresses, callback URLs, scope names, or other non-secret identifiers — they are logged verbatim, consistent with current posture elsewhere in the plugin.
- No change to which branches return false or to the comparison logic itself.
- No fix for the deeper "why does `Oa4mpClientCoSearchAttribute.claim_id` keep resetting between edits" question carried forward from the prior session — that follow-up is tracked in `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md`.

---

## Dependencies / Assumptions

- **Log destination and access control are inherited from the deployment.** `$this->log()` writes to whatever destination the deploying CakePHP / COmanage Registry configuration routes it to (typically a server-side log file readable by the web-server process user). This change does not modify that destination, retention, or access-control posture. The masking decisions in R5/R6 are sized against that inherited posture rather than against an explicit threat model. If a deployment routes plugin logs to a destination with broader readership (centralized aggregator, third-party logging service), the masking rule still applies; surrounding context (other already-logged structures in the same file, e.g., full HTTP response bodies elsewhere in `Model/Oa4mpClientOa4mpServer.php`) is out of scope for this change and is not made safer or less safe by it.

---

## Key Decisions

- **Detail shape tailored to field shape, not uniform.** Scalars get inline value pairs; arrays and structures get per-side dumps. *Rationale:* a `print_r` of two integers is noisy; an inline pair of two large arrays is unreadable. Matching the shape to the data keeps each branch as short as it can be while still informative.
- **Secrets masked inline, not via a generic mechanism.** *Rationale:* there is exactly one known case today. Building a redaction allowlist or framework now would be premature; extending the same per-branch treatment when a second case appears is cheap.
- **Logging-only change, no refactor.** *Rationale:* the function's repetitive shape is a known cost, but mixing a refactor into this change would obscure which lines are behaviorally inert and which are logging-only. Refactor, if wanted, is a separate decision.
- **Verbatim logging of non-secret identifiers is the recorded posture.** Email addresses, callback URLs, scope names, and similar non-secret identifiers are logged verbatim, consistent with the existing posture elsewhere in the plugin and in deployment infrastructure. *Rationale:* these values are routinely visible in OIDC client logs already; suppressing them here without suppressing them elsewhere would be cosmetic rather than meaningful. This change does not add a GDPR/CCPA-specific handling commitment — if a deployment is subject to regulatory regimes that require additional handling, that is a separate change outside this brainstorm's scope.

---

## Deferred / Open Questions

### From 2026-05-12 review

- **Inline per-branch masking vs. a small inline `redactSecrets` helper.** R6 explicitly chose inline masking at each affected branch, with the rationale that one known case does not warrant a helper. The 2026-05-12 ce-doc-review (adversarial reviewer) flagged this as a discipline trap: every future site that touches a secret-bearing field must independently remember to redact, and a single helper (e.g. `redactSecrets(array $row): array` that null-replaces a small hard-coded set of field names) would enforce the policy at one point without becoming a framework. With R5 now enumerating two secret fields (one compared, one not), the question gains weight — the helper would cover both fields uniformly. Resolve before implementation; the choice affects R5 enumeration and R6 mechanism together.
