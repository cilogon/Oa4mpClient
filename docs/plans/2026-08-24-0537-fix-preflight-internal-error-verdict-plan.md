---
title: Pre-flight Internal Error Verdict - Plan
type: fix
date: 2026-08-24
topic: preflight-internal-error-verdict
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-plan-bootstrap
execution: code
---

# Pre-flight Internal Error Verdict - Plan

## Goal Capsule

- **Objective:** A plugin-internal failure never tells a user their OIDC client was modified outside the Registry, on any path.
- **Means:** Surface the internal-error signal the sync check already computes, and route the thirteen controller pre-flight guards on it (KTD1).
- **Product authority:** The repository owner.
- **Open blockers:** None.

---

## Product Contract

### Summary

Give the thirteen controller pre-flight guards a way to tell "the plugin could not complete its check" from "this client was modified outside the Registry", and a distinct message for the first.

### Problem Frame

Thirteen call sites across nine controllers verify that the plugin and the OA4MP server agree before rendering a page. Each is a guard at the top of an action: on a false verdict it flashes `pl.oa4mp_client_co_oidc_client.er.bad_client` -- "This client has been modified outside of the Registry. Please email help@cilogon.org for assistance." -- and redirects away. All thirteen use that one message.

The verdict is false for two unrelated reasons. One is a real mismatch. The other is any failure inside the check itself, which lands in a catch that leaves the verdict at its initial false. `docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md` made the second reason routinely reachable: the cfg capability contract is read while unmarshalling, and an unreadable or malformed `cfg_contract.json` -- a bad deploy, a permissions change -- raises there for every client.

That work fixed the write path. `oa4mpEditClient()` now returns the generic edit error rather than the tampering code when the comparison could not run. It did not fix these guards, and they are the path a user actually meets: they run on the GET that renders a form or a list, so a broken deployment file bounces a user off every claims tab, every callback list and every scope page for every client, with a message telling them their client was tampered with. The write-path fix only covers the narrow window where the contract breaks between a form rendering and its submission.

The signal already exists. `compareToServerObject()` returns an `error` key, and `oa4mpEditClient()` reads it. These guards read only `synchronized`.

### Requirements

**The signal**

- R1. `oa4mpVerifyClient()` exposes, to every caller, whether the verdict is a real mismatch or a failure to complete the check.
- R2. A caller that does not ask for the detailed result still gets a usable answer. One call site uses the two-argument form that returns a bare boolean.

**The message**

- R3. A failure to complete the check produces a message distinct from `er.bad_client`, saying the plugin could not verify the client rather than that the client was modified. It lives in `Lib/lang.php`.
- R4. A real mismatch keeps `er.bad_client` unchanged, including its wording.

**The guards**

- R5. Each of the thirteen pre-flight guards distinguishes the two cases and flashes the matching message.
- R6. Each guard's existing redirect target is preserved. Targets are chosen per action and are not uniform -- the claims index redirects to the OIDC client list rather than to itself, because it re-verifies on every request and would loop.
- R7. A guard that cannot complete its check does not silently proceed as though the client were in sync.

### Key Decisions

- KD1. **Route the guards on a signal the model already computes rather than having each controller interpret an exception.** The sync check owns the distinction; thirteen controllers re-deriving it is how the two cases got conflated in the first place. Governs R1, R5.
- KD2. **Keep `er.bad_client` exactly as it is.** It is correct for the case it names, it is referenced in support correspondence, and changing it would make an unrelated message churn. Governs R4.

### Acceptance Examples

- AE1. **Covers R5, R6.** Given `cfg_contract.json` is unreadable, when a user opens a client's claims tab, then the flash says the plugin could not verify the client, and the redirect target is unchanged.
- AE2. **Covers R4.** Given a client genuinely differs from its server representation, when a user opens any guarded page, then the flash is still `er.bad_client`.
- AE3. **Covers R2.** Given the caller uses the two-argument form, when the check cannot complete, then the caller can still tell that case from a mismatch.
- AE4. **Covers R7.** Given the check cannot complete, when a guard runs, then the page is not rendered as though the client were in sync.

### Scope Boundaries

- The write path is already fixed and is not revisited.
- No change to what the sync comparison actually compares.
- No change to `er.bad_client`'s wording.
- The other gaps recorded in the contract plan's Scope Boundaries -- named configurations, `cfg_schema.json` drift, the LDAP redaction vocabulary -- are separate.

### Dependencies / Assumptions

- `compareToServerObject()` and its `error` key are on `main` via the cfg contract work; this plan builds on them.
- Twelve of the thirteen sites already receive the array form and can read a new key without a signature change. The thirteenth, `Oa4mpClientClaimsController::index`, uses the bare form.

### Sources / Research

- The thirteen sites, with call shape:
  `Oa4mpClientCoScopesController::edit_scopes:140`, `Oa4mpClientAuthorizationsController::manage:119`,
  `Oa4mpClientRefreshTokensController::manage:101`, `Oa4mpClientCoCallbacksController::add:106`,
  `::edit:246`, `::index:294`, `Oa4mpClientAccessTokensController::manage:100`,
  `Oa4mpClientAccessControlsController::manage:136`, `Oa4mpClientClaimsController::add:179`,
  `::edit:434`, `::index:537` (bare boolean form), `Oa4mpClientCoNamedConfigsController::manage:316`,
  `Oa4mpClientCoOidcClientsController::edit:495`.
- `Lib/lang.php:525` -- the `er.bad_client` string.
- `Model/Oa4mpClientOa4mpServer.php` -- `oa4mpVerifyClient()` and `compareToServerObject()`.
- The originating work and its own record of this gap: `docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md`, Scope Boundaries.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **Add an `error` key to the array form and give the bare form a third state.** The array form already carries `error` from `compareToServerObject()`; surfacing it is a pass-through. The bare form returns a boolean today, so it needs a representation for "could not check" that a caller cannot mistake for either boolean -- returning null is the smallest such change and is what `Oa4mpClientClaimsController::index` will test against. Governs R1, R2.
- KTD2. **Convert the bare call site to the array form instead of teaching callers about null.** Only one site uses it. Converting it means the third state has exactly one consumer shape rather than two, and the bare form's null return becomes defensive rather than load-bearing. Governs R2.
- KTD3. **Derive each redirect target from the site it replaces, never from a shared default.** The targets are not uniform and the reasoning is written into per-site comments. A shared default would silently change where five or more actions send the user, and at least one -- the claims index -- would loop. Governs R6.

### Assumptions

- No caller outside `Controller/` consumes `oa4mpVerifyClient()`. Confirm by search before changing the signature's contract; the search in the originating review was grep-only over `*.php` and `*.inc`.

### Sequencing

U1, then U2 and U3 in either order, then U4.

---

## Implementation Units

### U1. Surface the internal-error signal and its message

- Goal: Callers can tell a failed check from a real mismatch, and there is a string that says so.
- Requirements: R1, R2, R3, R4.
- Dependencies: none.
- Files:
  - `Model/Oa4mpClientOa4mpServer.php` -- pass `compareToServerObject()`'s `error` through `oa4mpVerifyClient()`'s array return; give the bare form a distinct third state.
  - `Lib/lang.php` -- add the internal-error string near `er.bad_client:525`.
  - `Test/Case/Model/UnmarshallFailureDiagnosticsTest.php` -- extend; it already drives the broken-contract path.
- Approach: The array form is a pass-through. Do not change what the comparison compares. Word the new string so it tells the user the plugin could not complete a check, not that anything is wrong with their client, and keep it ASCII.
- Test scenarios:
  1. A broken contract yields a result whose error is set and whose synchronized is false.
  2. A genuine mismatch yields a result whose error is unset and whose synchronized is false.
  3. An in-sync client yields synchronized true and no error.
  4. The bare form returns its third state when the check cannot complete, and a plain boolean otherwise.
- Verification: `Test/run.sh` passes with the floor raised.

### U2. Route the twelve array-form guards

- Goal: Twelve pre-flight guards flash the matching message and keep their redirect targets.
- Requirements: R5, R6, R7.
- Dependencies: U1.
- Files: the twelve sites listed in Sources, excluding `Oa4mpClientClaimsController::index:537`.
- Approach: At each site, branch on the error key before the synchronized check. Read that site's existing redirect target out of the code you are replacing and keep it; do not introduce a shared helper that picks one. Leave each site's existing comment about why its target was chosen.
- Test scenarios:
  1. For a representative guard, a broken contract flashes the internal-error message and redirects to that site's existing target (covers AE1).
  2. For the same guard, a genuine mismatch still flashes `er.bad_client` (covers AE2).
  3. A source scan asserts no guard flashes `er.bad_client` without first testing the error key, so a site added later cannot regress silently.
- Verification: `Test/run.sh` passes; every site's redirect target is unchanged from its pre-change value.

### U3. Convert the bare-form guard

- Goal: The last guard reads the same signal as the other twelve.
- Requirements: R2, R5, R6.
- Dependencies: U1.
- Files: `Controller/Oa4mpClientClaimsController.php` -- the `index` guard at `:537`.
- Approach: Switch it to the array form and branch as U2 does. Keep its redirect to the OIDC client list; its own comment records that redirecting to its own index would loop.
- Test scenarios:
  1. A broken contract on the claims index flashes the internal-error message and does not loop (covers AE3, AE4).
  2. A genuine mismatch there still flashes `er.bad_client`.
- Verification: `Test/run.sh` passes.

### U4. Record the behaviour

- Goal: The next person reading a bad-client report can tell which of the two cases it was.
- Requirements: R3.
- Dependencies: U2, U3.
- Files:
  - `CHANGELOG.md`
  - `docs/solutions/logic-errors/` -- a learning: a verdict that means two things, one of which is the checker's own failure, will be read as the more alarming one.
- Approach: The learning is the durable half. Name the shape rather than this instance: a boolean that conflates "the answer is no" with "I could not compute an answer" is a defect whenever the two have different remedies.
- Test scenarios: `Test expectation: none -- documentation only; U2 and U3 carry the behavioural coverage.`
- Verification: `Test/run.sh` passes.

---

## Verification Contract

| Gate | Command | Applies to | Signal |
|---|---|---|---|
| Hermetic suite | `Test/run.sh` | U1-U4 | `ALL_TESTS_PASSED` at or above `min_tests_run` |
| Syntax | `php -l <file>` | every changed PHP file | Clean parse |
| Secret scan | gitleaks, via the `secret-scan` CI job | all units | No finding |

Prove the new branch red before accepting it green, per `Test/README.md:234-237`.

Behaviour that synchronizes OIDC clients cannot be verified from this repository alone. Before release, confirm manually in a running Registry that a client which genuinely differs still reports as modified outside the Registry, so the fix has not swallowed the real case.

---

## Definition of Done

- All thirteen guards distinguish the two cases; none flashes `er.bad_client` for a failure to complete the check.
- Every redirect target is unchanged from its pre-change value.
- `er.bad_client`'s wording is unchanged.
- `Test/run.sh` passes with the floor raised, and every changed PHP file passes `php -l`.
- The learning in `docs/solutions/` names the general shape, not only this instance.
- Work is recorded against the branch while unmerged; the upstream pull request is cited only once it exists, owner-qualified.
