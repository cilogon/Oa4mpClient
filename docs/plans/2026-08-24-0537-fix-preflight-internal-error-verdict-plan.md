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

- **Objective:** A failure the sync comparison itself reports never tells a user their OIDC client was modified outside the Registry, on any of the thirteen pre-flight guards.
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

- R3. A failure to complete the check produces a message distinct from `er.bad_client`, saying the plugin could not verify the client rather than that the client was modified. It lives in `Lib/lang.php`. It directs the user to support the way `er.bad_client` does, so the internal-error path keeps a resolution route. The string is fixed: no interpolated exception text, class name, filesystem path, line number, or fragment of the server's response. Diagnostic detail stays in the model's existing log entry.
- R4. A real mismatch keeps `er.bad_client` unchanged, including its wording.

**The guards**

- R5. Each of the thirteen pre-flight guards distinguishes the two cases and flashes the matching message.
- R6. Each guard's existing redirect target is preserved. Targets are chosen per action and are not uniform -- the claims index redirects to the OIDC client list rather than to itself, because it re-verifies on every request and would loop.
- R7. A guard that cannot complete its check does not silently proceed as though the client were in sync.
- R8. A guard taking the internal-error branch logs the client identifier and the guarded action before redirecting, so a deployment-wide failure (every client) is distinguishable from repeated internal errors on one client.

### Key Decisions

- KD1. **Route the guards on a signal the model already computes rather than having each controller interpret an exception.** The sync check owns the distinction; thirteen controllers re-deriving it is how the two cases got conflated in the first place. Governs R1, R5.
- KD2. **Keep `er.bad_client` exactly as it is.** It is correct for the case it names, it is referenced in support correspondence, and changing it would make an unrelated message churn. Governs R4.

### Acceptance Examples

- AE1. **Covers R5, R6.** Given `cfg_contract.json` is unreadable, when a user opens a client's claims tab, then the flash says the plugin could not verify the client, and the redirect target is unchanged.
- AE2. **Covers R4.** Given a client genuinely differs from its server representation, when a user opens any guarded page, then the flash is still `er.bad_client`.
- AE3. **Covers R2.** Given `oa4mpVerifyClient()` is called in its two-argument (bare) form, when the check cannot complete, then its return value is distinguishable from both `true` and `false` under a strict comparison. Verified at the model tier by U1, not by U3 -- U3 removes the last production caller of that form.
- AE4. **Covers R7.** Given the check cannot complete, when a guard runs, then the page is not rendered as though the client were in sync.

### Scope Boundaries

- The write path is already fixed and is not revisited.
- Failures that occur before `compareToServerObject()` is reached -- an unreachable OA4MP server, a non-JSON or undecodable response body -- still produce `synchronized => false` with no `error`, and so still reach `er.bad_client`. Widening the internal-error signal to cover transport and decode failures is a separate concern.
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

- KTD1. **Add an `error` key to the array form and give the bare form a third state.** The array form already carries `error` from `compareToServerObject()`; surfacing it is a pass-through. The bare form returns a boolean today, so it needs a representation for "could not check" that a caller cannot mistake for either boolean -- returning null is the smallest such change. After U3 converts the last production caller to the array form, that third state is defensive rather than load-bearing: it has no production consumer, and its only coverage is U1's model-tier test. Because null is falsy in PHP, the contract is `true|false|null` and any bare-form caller must test `=== null` before boolean handling; U1 asserts that strictly. Governs R1, R2.
- KTD2. **Convert the bare call site to the array form instead of teaching callers about null.** The bare form has one production consumer -- `Oa4mpClientClaimsController::index` -- plus three live-tier test assertions at `Test/Case/LiveServer/LiveClientLifecycleTest.php:178`, `:202` and `:222`, each currently wrapped in `assertTrue()`. Converting the production site means the third state has exactly one consumer shape rather than two, and the bare form's null return becomes defensive rather than load-bearing. U1 must assert those three live-tier calls distinguish null from false rather than relying on `assertTrue()`. Governs R2.
- KTD3. **Derive each redirect target from the site it replaces, never from a shared default.** The targets are not uniform and the reasoning is written into per-site comments. A shared default would silently change where five or more actions send the user, and at least one -- the claims index -- would loop. Governs R6.

### Assumptions

- Callers outside `Controller/` do exist and must keep working unchanged. They are: `oa4mpEditClient()` at `Model/Oa4mpClientOa4mpServer.php:1285` (array form, already branches on `error` -- this is the write-path fix this plan declares out of scope); `Test/Case/LiveServer/LiveClientLifecycleTest.php:178`, `:202`, `:222` (bare form); and the hermetic harness `Test/lib/Oa4mpClaimsControllerHarness.php:107`. U1 must leave each of them working. Confirm the list is still complete by search before changing the signature's contract; the search in the originating review was grep-only over `*.php` and `*.inc`.

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
- Approach: The array form is a pass-through. Do not change what the comparison compares. Word the new string so it tells the user the plugin could not complete a check, not that anything is wrong with their client, and keep it ASCII. Keep it a fixed sentence -- no interpolated exception, path, or server-response detail; the diagnostic material stays in the model's existing log entry, which already redacts secrets.
- Test scenarios:
  1. A broken contract yields a result whose error is set and whose synchronized is false.
  2. A genuine mismatch yields a result whose error is unset and whose synchronized is false.
  3. An in-sync client yields synchronized true and no error.
  4. The bare form returns its third state when the check cannot complete, and a plain boolean otherwise; a strict `=== null` distinguishes it from `false`, and a loose truthiness check does not (covers AE3).
- Verification: `Test/run.sh` passes with the floor raised.

### U2. Route the twelve array-form guards

- Goal: Twelve pre-flight guards flash the matching message and keep their redirect targets.
- Requirements: R5, R6, R7, R8.
- Dependencies: U1.
- Files:
  - the twelve sites listed in Sources, excluding `Oa4mpClientClaimsController::index:537`.
  - `Test/lib/Oa4mpClaimsControllerHarness.php` -- `Oa4mpHarnessOa4mpServer` currently returns `array('synchronized' => ..., 'oa4mp_server_extra' => ...)` with no `error` key and no knob to set one, so no controller-level test can drive a guard down the broken-contract branch. Give it a `$verifyError = false` property, include `'error' => $this->verifyError` in its array return, and have the bare-form branch return the third state when it is set.
- Approach: At each site, branch on the error key before the synchronized check. Read that site's existing redirect target out of the code you are replacing and keep it; do not introduce a shared helper that picks one. Leave each site's existing comment about why its target was chosen. On the internal-error branch, log the client identifier and the guarded action before redirecting (R8).
- Test scenarios:
  1. For a representative guard, a broken contract flashes the internal-error message and redirects to that site's existing target (covers AE1).
  2. For the same guard, a genuine mismatch still flashes `er.bad_client` (covers AE2).
  3. A source scan asserts no guard flashes `er.bad_client` without first testing the error key, so a site added later cannot regress silently.
  4. The internal-error branch logs the client identifier and the guarded action (covers R8).
- Verification: `Test/run.sh` passes; every site's redirect target is unchanged from its pre-change value.

### U3. Convert the bare-form guard

- Goal: The last guard reads the same signal as the other twelve.
- Requirements: R2, R5, R6, R8.
- Dependencies: U1.
- Files:
  - `Controller/Oa4mpClientClaimsController.php` -- the `index` guard at `:537`.
  - `Test/lib/Oa4mpClaimsControllerHarness.php` -- the same `$verifyError` knob U2 adds, if U2 has not already landed it.
- Approach: Switch it to the array form and branch as U2 does, logging the client identifier and action on the internal-error branch (R8). Keep its redirect to the OIDC client list; its own comment records that redirecting to its own index would loop.
- Test scenarios:
  1. A broken contract on the claims index flashes the internal-error message and does not loop (covers AE4).
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

`min_tests_run` in `Test/run.sh` (currently 240) is raised once, at the end of U3, to the suite's new real count -- not per unit -- and its explanatory comment is updated in the same edit. Raising it in U1 and not again after U2 and U3 add tests leaves `ClaimsControllerHarnessTest::testRunShRequiresAPlausibleTestCount` red on a check unrelated to this fix.

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

---

## Deferred / Open Questions

### From 2026-08-24 review

- **Per-site evidence for all thirteen guards.** U2 tests one representative guard and adds a source scan that only forbids flashing `er.bad_client` without first testing the error key. That scan does not prove each guard flashes the *new* message on the internal-error branch, nor that each redirect target survived unchanged -- so eleven guards can be changed incorrectly with the suite still green, and the Definition of Done's per-site claims rest on inspection rather than evidence. Open question: require a thirteen-row verification matrix (internal-error branch, genuine-mismatch branch, pre-change redirect target) as a gate, or accept representative coverage plus the scan. Raised only by the cross-model reviewers (Codex).
- **Redirect versus read-only render on a failed check.** After this plan ships, an unreadable `cfg_contract.json` still bounces every user off every claims tab, callback list and scope page for every client; only the wording changes. The Problem Frame names the bouncing itself as part of the harm, and R7 forecloses the alternative -- rendering the page read-only with a warning banner -- without weighing it. Open question: record a Key Decision for redirect-over-degraded-render with its reasoning, or reopen the choice.
