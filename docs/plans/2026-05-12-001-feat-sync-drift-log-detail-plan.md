---
title: Sync Drift Log Detail
type: feat
status: completed
date: 2026-05-12
origin: docs/brainstorms/2026-05-12-sync-drift-log-detail-brainstorm.md
---

# Sync Drift Log Detail

## Summary

Add a small private `redactSecrets` helper on `Oa4mpClientOa4mpServer` and update the remaining ~25 drift-log branches in `isClientDataSynchronized` (18 scalar + 3 array-valued + 4 presence-mismatch) to emit per-side detail following the brainstorm's R2/R3/R4 conventions. The four `Oa4mpClientClaim` branches (R7) and the comment-mismatch branch (R8) are left as-is.

---

## Problem Frame

Origin: `docs/brainstorms/2026-05-12-sync-drift-log-detail-brainstorm.md`. Operators today see "X is out of sync" with no per-side detail across roughly 21 branches of `isClientDataSynchronized`, turning every drift case into a debugger or database investigation.

---

## Requirements

- R1. Every drift-log branch in `isClientDataSynchronized` includes enough per-side detail that an operator can see what the two sides contained without external tools (origin R1).
- R2. Scalar comparisons include both values inline; long or multi-line values fall back to per-side dumps on subsequent lines (origin R2).
- R3. Array-valued and structure-valued comparisons emit per-side dumps matching the `Oa4mpClientClaim` shape (origin R3).
- R4. Presence-mismatch branches dump the contents of the present side (origin R4).
- R5. Both `aws_access_key_id` and `aws_secret_access_key` are masked in any log output produced by this function. Only `aws_access_key_id` is compared today; the helper enumerates both so future drift-checks inherit masking (origin R5).
- R6. Masking is implemented via a small private `redactSecrets` helper on `Oa4mpClientOa4mpServer`. The helper accepts a row and returns a copy with the enumerated secret field names null-replaced or sentinel-replaced. Callers pass the row through the helper before any `print_r` of a structure that may contain a secret. *(Planning-time resolution of origin R6's open question — see Key Technical Decisions and Open Questions.)*
- R7. The four already-updated `Oa4mpClientClaim` branches and the existing comment-mismatch branch are not modified (origin R7, R8).
- R8. The change is logging-only. No branch changes its return value, the order of checks, or the structural flow of the function (origin R9).

---

## Scope Boundaries

- No restructuring of `isClientDataSynchronized` (no helper extraction of the comparison logic, no table-driven rewrite, no deduplication of the repeated `if(...) { log; return false; }` shape).
- No change to log level or destination — continues to use `$this->log()`.
- No automated tests added for `isClientDataSynchronized`. The model currently has no test harness; introducing one is out of scope.
- No configurable, externalized, or per-deployment secret-redaction allowlist — the helper's secret-field-name list is a hard-coded array inside the helper.
- No special handling of email addresses, callback URLs, scope names, or other non-secret identifiers.
- No change to the comparison logic itself or to which branches return false.

---

## Context & Research

### Relevant Code and Patterns

- `Model/Oa4mpClientOa4mpServer.php` — the file. `isClientDataSynchronized` starts at line 44. Reference pattern for per-side dumps is at lines 305-326 (claim count drift, one-side-only x2) and 411-417 (normalized-claim deep-compare). Both use `print_r(..., true)` on the in-memory arrays.
- `Config/Schema/schema.xml` lines 231-233 — confirms `aws_access_key_id` and `aws_secret_access_key` exist in the `oa4mp_client_dynamo_config` table. Only `aws_access_key_id` is compared in `isClientDataSynchronized` today.
- `Lib/lang.php` — text localization. No new lang strings needed for this change (log lines are operator-facing diagnostic text, not localized user-facing text per the existing convention in the function).

### Institutional Learnings

- `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` — the prior workstream's durable lessons on this function. Relevant Prevention rules: keep set-level guards (count drift) ahead of per-row guards; log both sides at the moment of detection so operators see the data, not just the verdict.

### External References

- None. The change is internal to the plugin and follows existing in-repo conventions.

---

## Key Technical Decisions

- **`redactSecrets($row)` helper (resolves origin R6).** A `private function` on `Oa4mpClientOa4mpServer` that returns a shallow copy of `$row` with each enumerated secret field name replaced by a redaction sentinel (e.g., `'[REDACTED]'`) when present. Hard-coded secret-name list inside the helper: `aws_access_key_id`, `aws_secret_access_key`. Helper is null-safe: missing fields are left absent (no key creation). *Rationale:* the helper enforces masking at a single point so that any future site logging a row that may contain a secret can apply masking via one method call. The brainstorm's original "inline at every branch" wording is superseded by this planning-time decision (see Open Questions → Resolved During Planning).
- **Use `[REDACTED]` as the sentinel string.** *Rationale:* visible in log output, doesn't collide with any plausible legitimate value, and signals to the log reader that the field was deliberately masked rather than empty.
- **For scalar branches, mask is applied inline at the call site, not via the helper.** The helper operates on row arrays. A scalar branch like `aws_access_key_id` is comparing two scalar values directly, not two rows; the inline form `(plugin=[REDACTED], oa4mp=[REDACTED])` is appropriate. The helper covers row-shaped dumps; the inline literal covers scalar-pair logging. Both use the same sentinel. *Rationale:* a helper called with one scalar would be a function-call ceremony with no enforcement benefit — the scalar branches are by definition pinpoint sites where the field name is already in the source line.
- **Long-scalar fallback threshold is left to implementer judgment.** R2's "long or contains line breaks" rule is enforced by reading the source values: if either value contains `"\n"` or exceeds ~80 characters when stringified, fall back to per-side dumps on subsequent lines. The implementer picks the specific threshold. *Rationale:* a hard numeric threshold codified in the plan would be a guess. The implementer can see each field's plausible values when writing each branch and choose the cleanest local treatment.
- **Verification of R8 (logging-only) is mechanical, not test-based.** Diff the non-log lines between the pre- and post-change function bodies; the diff must be empty except for the helper-method addition and the explicit logging-expression changes. *Rationale:* the model has no test harness, so a test-based verification would itself require building scaffolding, which is out of scope. The mechanical diff is what a careful reviewer would do anyway.

---

## Open Questions

### Resolved During Planning

- **Inline per-branch masking vs. small `redactSecrets` helper.** Resolved at planning time: helper approach. Origin R6's "inline, not via a generic mechanism" wording is superseded by this plan's R6 + Key Technical Decision. The helper is a private method on `Oa4mpClientOa4mpServer` with a hard-coded internal allowlist of two secret field names — still not a configurable framework, but a single enforcement point.

### Deferred to Implementation

- **Exact long-scalar threshold for the R2 fallback.** Left to the implementer to choose per branch based on the field's plausible value range. No central constant.

---

## Implementation Units

### U1. Add `redactSecrets` private helper to `Oa4mpClientOa4mpServer`

**Goal:** Introduce the one place that knows the names of secret-bearing fields and produces a redacted copy of a row.

**Requirements:** R5, R6.

**Dependencies:** None.

**Files:**
- Modify: `Model/Oa4mpClientOa4mpServer.php`

**Approach:**
- Add a `private function redactSecrets($row)` method on the model. Hard-coded internal array of secret field names: `aws_access_key_id`, `aws_secret_access_key`. For each name, if the key is present in `$row`, replace its value with the string `'[REDACTED]'`. Return the copy.
- Place the helper near other private utility methods in the same model file (locate by reading the file's existing internal-helper conventions; the function `setOa4mpClientId` and similar internal helpers are reasonable neighbors).
- Helper must not mutate its argument — work on a copy.
- Helper must be null-safe: a missing key stays missing; an explicit `null` value is left as `null` (no need to redact what is already empty).

**Patterns to follow:**
- Existing `private function` declarations in the same model (look for the `private` access modifier and naming conventions).
- The repeated `print_r(..., true)` use throughout `isClientDataSynchronized` for the four `Oa4mpClientClaim` branches around lines 305-326 and 411-417.

**Test scenarios:**
- Test expectation: none — the helper has no existing test harness in this model. Verification at U3 covers usage of the helper indirectly via the DynamoConfig structure-dump path. The helper is small enough (single loop over a 2-element array) that visual review of the diff is sufficient.

**Verification:**
- Method is `private`, takes one array argument, returns a new array.
- Both secret field names are present in the helper's internal list.
- Helper does not mutate the input array (would be reviewed by reading the implementation).
- The function `isClientDataSynchronized` still parses and runs after the addition (no syntax error in the file).

---

### U2. Update scalar drift-log branches in `isClientDataSynchronized`

**Goal:** Replace the existing one-line `"X is out of sync"` log calls in scalar comparison branches with the R2 inline-pair form, with masking at the `aws_access_key_id` branch and the long-scalar fallback ready for fields that can hold long or multi-line values.

**Requirements:** R1, R2, R5, R6, R7, R8.

**Dependencies:** U1 (the redaction sentinel `'[REDACTED]'` is established by U1's helper and used inline here for parity).

**Files:**
- Modify: `Model/Oa4mpClientOa4mpServer.php`

**Approach:**
- Eighteen scalar branches need updating. Group by which Model they belong to:
  - `Oa4mpClientCoOidcClient` (lines ~49-67): `oa4mp_identifier`, `name`, `proxy_limited`, `public_client`.
  - `Oa4mpClientRefreshToken` (lines ~78-83): `token_lifetime`.
  - `Oa4mpClientAccessToken` (lines ~201-206): `is_jwt`.
  - `Oa4mpClientAuthorization` (lines ~221-247): `require_active`, `authz_co_group_id`, `authz_group_redirect_url`, `require_active_redirect_url`.
  - `Oa4mpClientDynamoConfig` (lines ~251-297): `aws_region`, `aws_access_key_id` (secret), `table_name`, `partition_key`, `partition_key_template`, `partition_key_claim_name`, `sort_key` (normalized scalar), `sort_key_template` (normalized scalar).
- For each branch, replace `$this->log("X is out of sync")` with `$this->log("X is out of sync (plugin=<curVal>, oa4mp=<oa4mpVal>)")` where `<curVal>` and `<oa4mpVal>` are stringified values from the existing comparison variables.
- For the `aws_access_key_id` branch specifically: write the inline form with `'[REDACTED]'` substituted for both values. Do not interpolate the actual value into the log string.
- For the `authz_group_redirect_url`, `require_active_redirect_url`, `partition_key_template`, `sort_key_template` branches (fields whose values are URLs or templates and may be long or multi-line): when either value contains a newline or exceeds the implementer's chosen length threshold, emit per-side dumps on subsequent lines instead of the inline pair (the R2 long-scalar fallback). The choice is per-branch: if a field's plausible values are always short, the inline pair suffices.
- Do not change which branches return false. Do not change the comparison logic. The only changes are inside the body of each `if(...) { ... }` block, on the `$this->log(...)` call.

**Patterns to follow:**
- The existing `Oa4mpClientClaim` count-drift branch at lines ~320-326 — shape of the inline `(plugin=..., oa4mp=...)` suffix on a one-line log call (the count case uses `count(...)` inline; scalars use the value directly).
- The brainstorm's R2 wording — `(plugin=<value>, oa4mp=<value>)` as the canonical form.

**Test scenarios:**
- Test expectation: none. No existing test harness for this function. Verification is via manual diff inspection (see Verification below).

**Verification:**
- Every modified scalar branch's log call carries both sides' values, either inline or as per-side dumps for the long-scalar cases.
- The `aws_access_key_id` branch produces a log line in which neither value appears verbatim — both are replaced by `'[REDACTED]'`.
- A diff of the function's non-log lines between pre- and post-change shows zero changes (no condition flips, no early return additions or removals, no comparison operator changes).
- The file still parses (no PHP syntax error) and the function still runs end-to-end without altering return values for any test case the implementer happens to exercise.

---

### U3. Update array-valued and presence-mismatch drift-log branches

**Goal:** Add per-side detail to the seven non-scalar drift-log branches following the `Oa4mpClientClaim` reference pattern.

**Requirements:** R1, R3, R4, R5, R6, R7, R8.

**Dependencies:** U1 (`redactSecrets` is called from any branch that may dump a row containing a secret field — currently the `Oa4mpClientDynamoConfig` presence-mismatch case if such a branch existed; defensively, any future dump that includes DynamoConfig structure data routes through the helper).

**Files:**
- Modify: `Model/Oa4mpClientOa4mpServer.php`

**Approach:**
- Three array-valued branches and four presence-mismatch branches. Locations:
  - `Oa4mpClientCoEmailAddress` array (lines ~100-103): emit `print_r($curEmails, true)` and `print_r($oa4mpEmails, true)` on the two lines following the existing one-liner.
  - `Oa4mpClientCoCallback` array (lines ~120-123): same shape, on `$curCallbacks` / `$oa4mpCallbacks`.
  - `Oa4mpClientCoScope` array (lines ~165-168): same shape, on `$curScopes` / `$oa4mpScopes`.
  - `Oa4mpClientAccessToken` plugin-has-server-doesn't (lines ~191-194): dump the present side via `print_r($curData['Oa4mpClientAccessToken'], true)`. The empty side is implied by the message.
  - `Oa4mpClientAccessToken` server-has-plugin-doesn't (lines ~196-199): dump `print_r($oa4mpServerData['Oa4mpClientAccessToken'], true)`.
  - `Oa4mpClientAuthorization` plugin-has-server-doesn't (lines ~209-214): dump `print_r($curData['Oa4mpClientAuthorization'], true)`.
  - `Oa4mpClientAuthorization` server-has-plugin-doesn't (lines ~216-219): dump `print_r($oa4mpServerData['Oa4mpClientAuthorization'], true)`.
- None of these arrays/rows currently contain secret-bearing fields (verified against `Config/Schema/schema.xml` for each model). The `redactSecrets` helper is not invoked at any of these sites. However, if the implementer notices any field that should be considered secret while doing this work, add it to the helper's internal list and route the relevant dump through `redactSecrets`.
- Do not change the comparison logic, ordering, or return values. Add the per-side dump lines inside the existing `if(...) { ... }` body, before `return false`.

**Patterns to follow:**
- `Oa4mpClientClaim` count-drift at lines ~321-326: shape of two `$this->log("name: " . print_r(..., true));` calls immediately following the one-line summary.
- `Oa4mpClientClaim` plugin-has-server-doesn't at lines ~305-309 and server-has-plugin-doesn't at lines ~311-315: shape of a single per-side dump when the other side is empty.
- `Oa4mpClientClaim` normalized deep-compare at lines ~411-417: shape of dumping the normalized arrays after the existing one-liner.

**Test scenarios:**
- Test expectation: none. Manual diff verification.

**Verification:**
- Every modified array-valued branch logs both sides via `print_r`.
- Every modified presence-mismatch branch logs the present side and does not attempt to log the empty side.
- No drift-log branch in this set leaks a value from a secret-bearing field. Spot-check by reading the log expressions and confirming none reference a `*_secret_*` or `*_key_id` field.
- Mechanical diff: non-log lines (conditions, variable construction, `return false` calls) are unchanged from pre-change.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| The "logging-only" promise is violated by accidental edit (a `return false` deleted, a condition flipped) while making ~20 changes in one function. | The implementer or reviewer performs a non-log-line diff against the pre-change function. R8's verification expects the diff to be empty except for the helper addition and the explicit log expression changes. |
| A new secret-bearing field is added to a model in the future and the implementer forgets to extend `redactSecrets`'s internal list. | Helper is at one location; a comment inside the helper enumerates the fields and explains the convention. Future changes to the schema for OIDC client-related tables should prompt a review of `redactSecrets`. Not enforced by code; this is a discipline rule, but localized to one place. |
| The R2 long-scalar fallback is applied unevenly across branches (some long-string scalars get inline pairs that are unreadable). | The per-branch implementer judgment is the mitigation. If a particular field's value range turns out to break the inline pair in practice, a focused follow-up adjustment is cheap. |

---

## Documentation / Operational Notes

- No external-facing documentation changes. The change is operator-visible only in the form of more informative log output when drift is detected.
- The brainstorm doc at `docs/brainstorms/2026-05-12-sync-drift-log-detail-brainstorm.md` records the originating intent. Its Open Questions entry for the inline-vs-helper masking question is resolved by this plan (helper chosen); the brainstorm itself is left as a historical record of the workstream.

---

## Sources & References

- **Origin document:** `docs/brainstorms/2026-05-12-sync-drift-log-detail-brainstorm.md`
- Function under change: `Model/Oa4mpClientOa4mpServer.php` (`isClientDataSynchronized`, starts at line 44)
- Schema reference: `Config/Schema/schema.xml` (lines 231-239 for `oa4mp_client_dynamo_config`)
- Prior workstream solution doc: `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md`
