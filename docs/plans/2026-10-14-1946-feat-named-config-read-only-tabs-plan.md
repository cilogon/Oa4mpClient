---
title: Named Configuration Read-Only Tabs - Plan
type: feat
date: 2026-10-14
topic: named-config-read-only-tabs
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-brainstorm
execution: code
---

# Named Configuration Read-Only Tabs - Plan

## Goal Capsule

- **Objective:** An admin looking at an OIDC client that uses a Named configuration can see that the Named configuration, not the client's own Claims, Scopes and Authorization settings, decides what the OA4MP server does. The plugin no longer sends settings that the screen calls ignored.
- **Means:** one Named-configuration predicate shared by the cfg marshaller, the three tab controllers and their views (KTD1-KTD4).
- **Product authority:** GitHub issue cilogon/Oa4mpClient#28, widened in dialogue to include the Authorization tab and the cfg it produces. The Product Contract below wins on behavior; the Planning Contract wins on mechanism.
- **Execution profile:** plugin repository only, PHP and CakePHP views, hermetic tests. No QDL repository change and no live-tier run.
- **Stop conditions:** stop and report if a guarded write cannot be refused without blocking the GET that renders the read-only page, or if any existing test that locks Named-configuration behavior has to be weakened rather than updated to the new contract.
- **Who finishes:** `ce-work` implements; the developer reviews and merges the upstream pull request.
- **Open blockers:** none.
- **Product Contract preservation:** Product Contract unchanged except that Outstanding Questions is resolved in place by KTD1-KTD4 and now holds only implementation-time notes.

---

## Product Contract

### Summary

While a Named configuration is applied, the Claims, Scopes and Authorization tabs become read-only. Each names the Named configuration in force and says its settings are ignored. The plugin stops sending the client's authorization settings to the OA4MP server, so the cfg carries only the Named configuration and the access-token setting. The other tabs are unchanged.

### Problem Frame

A Named configuration replaces the client's own cfg with a stored one that points at a different QDL script and brings its own scopes. The plugin already ignores the client's claims and scopes when building the request. But the Claims and Scopes tabs still look live: an admin can add a claim, save, and see success, while the server never receives it.

The Authorization tab is worse. Its settings are still sent, merged into the Named configuration's cfg. Yet the Named configuration's script almost certainly never reads them. So an admin can see Require Active Status or an Authorized User Group on the tab and believe the client is gated when nothing enforces it. New confidential clients are created with Require Active Status saved by default (cilogon/Oa4mpClient#31), so nearly every client moved onto a Named configuration shows that false assurance.

### Key Decisions

- **Read-only with a notice, not hidden or greyed out.** Stored values stay visible because they come back into force when the Named configuration is removed. Governs R1, R2, R3. (session-settled: user-directed -- chosen over a greyed-out tab, a hidden tab, and a notice that still allows editing: the admin should see what is stored but must not edit settings that have no effect)
- **The Authorization tab is treated the same as Claims.** A Named configuration points at an entirely different QDL script that almost certainly ignores the Authorization settings. Governs R1, R2, R3, R7. (session-settled: user-directed -- chosen over a caution notice on a still-editable tab, and over leaving the tab unchanged: the settings should be presented as ignored, like Claims)
- **Stop sending authorization settings for Named-configuration clients.** What the server receives then matches what the screen says. The cost is accepted: applying a Named configuration removes the client's Require Active Status and group gate from the server, even where the Named configuration's script would have read them. Governs R7. (session-settled: user-directed -- chosen over continuing to send them: keeping them would make the "ignored" notice untrue for any script that reads them, and the server cfg should carry exactly the Named configuration)
- **The sync check keeps ignoring authorization settings for Named-configuration clients.** Clients already on a Named configuration are not blocked as out of sync by leftover args, and their next save drops them. This is the current behavior, so it is kept rather than built. Governs R8. (session-settled: user-directed -- chosen over reporting those clients out of sync and blocking edits until an operator reconciles each one)

### Requirements

**Tab behavior while a Named configuration is applied**

- R1. The Claims, Scopes and Authorization tabs show the client's stored settings read-only.
- R2. Each of those three tabs shows a notice that names the applied Named configuration and says the tab's settings are ignored while it is applied.
- R3. The plugin refuses any add, edit or delete on those three tabs, whether it arrives from the page, a direct URL or a form post. It tells the admin why, and saves nothing and sends nothing to the server.
- R4. The Properties, Callbacks, Refresh Tokens, Access Tokens, Access Control and Named Configuration tabs behave as they do today.

**Stored settings**

- R5. Applying a Named configuration leaves the client's stored claims, scopes and authorization settings unchanged.
- R6. Removing the Named configuration makes the three tabs editable again, and the stored settings are sent to the server again from that point.

**What the server receives**

- R7. For a client with a Named configuration applied, the plugin sends none of the client's authorization settings to the OA4MP server. Anything the Named configuration's own stored JSON contains is still sent as part of that configuration.
- R8. The sync check does not report a Named-configuration client out of sync because of authorization settings on either side.

**Documentation**

- R9. The manual's Named configuration section says which tabs are ignored while one is applied, and that applying one removes the client's authorization gates from the server. The CHANGELOG's Unreleased section records the behavior change.

### Acceptance Examples

Key Flows is omitted: each behavior is a single step on one tab, and the examples below cover the conditional paths.

- AE1. Read-only Claims tab
  - **Covers R1, R2, R3.**
  - **Given** a confidential client with Named configuration "X" applied and two stored claims,
  - **When** the admin opens the Claims tab,
  - **Then** the two claims are listed, a notice names "X" and says these claims are ignored, and no add, edit or delete control is offered. A form post to the claim add URL for this client is refused with that explanation, and no claim is saved and no request goes to the server.
- AE2. Applying a Named configuration drops the gates
  - **Covers R5, R7.**
  - **Given** a client with Require Active Status and an Authorized User Group saved,
  - **When** the admin applies Named configuration "X",
  - **Then** the cfg sent to the server contains no `require_active_status`, `authorization_group_id` or other authorization arg the plugin builds. The Authorization tab still shows the saved settings, read-only.
- AE3. Removing the Named configuration restores the gates
  - **Covers R6.**
  - **Given** the client from AE2 with "X" applied,
  - **When** the admin removes the Named configuration,
  - **Then** the cfg sent to the server again carries `require_active_status` and `authorization_group_id`, and the three tabs are editable.
- AE4. Leftover args on an existing client
  - **Covers R8, R7.**
  - **Given** a client that was on Named configuration "X" before this change, whose server cfg still carries authorization args from an earlier save,
  - **When** the admin opens and saves one of its still-editable tabs, such as Callbacks,
  - **Then** the client is not reported out of sync, and the cfg sent on that save has no plugin-built authorization args.

### Scope Boundaries

- The tabs do not show what the Named configuration itself provides, such as its scopes or claims. Deferred.
- No check of whether a Named configuration's script reads authorization args. The plugin cannot inspect the script.
- No bulk re-save to clear leftover authorization args from clients already on a Named configuration. They clear on each client's next save.
- Claim migration from deprecated Search attributes, which runs when a client's edit page loads, is unchanged.
- No QDL change in `cilogon-service-config-us` or `cilogon-service-config-au`.

### Dependencies / Assumptions

- Applying or removing a Named configuration already sends the updated client to the server when the change is made (`Controller/Oa4mpClientCoNamedConfigsController.php:260-283`), so R7 and R6 take effect at that moment, not only on a later save.
- Public clients carry no cfg at all, so R7 changes nothing for them. R1-R3 apply to any client with a Named configuration applied, public or confidential.

### Outstanding Questions

The three questions deferred to planning are resolved by KTD1 (one predicate), KTD3 and KTD4 (guards and per-tab rendering) and KTD2 (marshalling only; the unmarshaller and the sync check need no change).

**Deferred to Implementation**

- Exact names of the predicate, the guard helpers, the view variable and the language keys.
- Whether the Scopes and Authorization controller harnesses share a base with the existing Claims harness or stay as two small siblings; decide when writing the second one.

### Sources / Research

- Scopes built from the Named configuration's scopes, the client's own ignored: `Model/Oa4mpClientOa4mpServer.php:1944-1980`.
- cfg builder: authorization args added before the Named configuration branch (`Model/Oa4mpClientOa4mpServer.php:1641-1691`); the branch merges the stored JSON with `array_merge_recursive` and returns (`:1693-1725`) before claim mappings and the DynamoDB/LDAP claim-source args. The access-token setting is added before the branch (`:1624-1629`).
- Sync check returns early for Named-configuration clients, before the access-token, authorization and claim comparisons: `Model/Oa4mpClientOa4mpServer.php:1083-1086`.
- The tab nav is one shared file copied per controller: the nine `View/*/tabs.inc` files are byte-identical.
- Write actions with no Named-configuration check today, each re-sending the client to the server: Claims `add`/`edit`/`delete` (`Controller/Oa4mpClientClaimsController.php`), Scopes `edit_scopes` (`Controller/Oa4mpClientCoScopesController.php`), Authorizations `manage` (`Controller/Oa4mpClientAuthorizationsController.php`).
- Manual section to extend: `docs/oidc-client-plugin-manual.md:382-404`.
- Related learning on a tab showing a state the server does not have: `docs/solutions/logic-errors/oa4mp-require-active-status-shown-ticked-when-unsaved.md`.
- Open defect this work closes in part: `docs/solutions/logic-errors/oa4mp-named-config-claims-inert-2026-08-22.md` (claims on a Named-configuration client are inert and ungated).
- Guard precedent: `docs/solutions/integration-issues/oa4mp-public-client-cfg-rejected-2026-08-03.md` and `_blockIfPublicClient()` in `Controller/Oa4mpClientClaimsController.php`.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **One predicate decides "this client uses a Named configuration".** Add it on `Oa4mpClientOa4mpServer` beside `isPublicClient()` and `requiresActiveStatus()`, testing the `named_config_id` value, never the `Oa4mpClientCoNamedConfig` association (a missing hasOne comes back as an all-null array). The marshaller, the three controllers and the view variables all ask it. The existing inline `named_config_id` checks elsewhere in the model stay as they are. Rationale: `docs/solutions/logic-errors/oa4mp-require-active-status-shown-ticked-when-unsaved.md` and `oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md`. Governs R1, R3, R7.
- KTD2. **R7 is a marshalling change only.** In `oa4mpMarshallCfgQdl()` the authorization-args block runs before the Named-configuration branch; it is skipped when KTD1's predicate holds, and its "orthogonal" comment is rewritten to say why. The non-Named path is unchanged byte for byte. No change to the unmarshaller (it tolerates absent args) or to `isClientDataSynchronized()` (its Named-configuration early return already precedes the authorization comparison, which is what R8 needs). Every save path and Named-configuration apply/remove reaches the server through `oa4mpEditClient()`, which re-marshals the whole client, so this one change covers AE2, AE3 and AE4. Implements the Product Contract's "stop sending authorization settings" and "sync check keeps ignoring" decisions (Governs R7, R8).
- KTD3. **Writes are refused in each controller, not in the permission layer.** `Oa4mpClientAuthzComponent::permissionSet()` is keyed by action, so denying there would also refuse the GET that renders the read-only page. Claims `add`, `edit` and `delete` get a guard mirroring `_blockIfPublicClient()`: on GET or POST, flash an error naming the Named configuration and redirect to the Claims index, before any server call or row write. Scopes `edit_scopes` and Authorizations `manage` handle GET and POST in one action, so their guard refuses POST/PUT only and redirects to the same action, whose GET then renders read-only. Governs R3.
- KTD4. **Read-only rendering uses a separate flag, not `$e = false`.** In `View/Oa4mpClientClaims/fields.inc` and `View/Oa4mpClientCoScopes/fields.inc` a non-editable page with no `view` permission renders nothing, and no `view` permission exists. Follow the public-client pattern instead: the controller sets a view variable carrying the Named configuration's name (from `current()`, which already loads `Oa4mpClientCoNamedConfig`), and each view disables or hides its controls on that flag. The notice reuses the existing `ui-widget` notice block in `View/Oa4mpClientClaims/index.ctp`, with text from new `Lib/lang.php` keys and the name escaped exactly once (`docs/solutions/ui-bugs/oa4mp-view-title-double-html-encoding-2026-08-03.md`). When the client's `named_config_id` points at a configuration no longer in the admin client's list, there is no name to show, so the notice identifies the configuration by its id rather than printing an empty quote. Governs R1, R2.
- KTD5. **Scopes and Authorization controllers get a server seam and a test harness.** Only the Claims controller has the `_oa4mpServer()` seam and a harness today; the other two build `Oa4mpClientOa4mpServer` inline. Adding the same seam lets a hermetic test prove R3's "sends nothing to the server" by an empty recorded-call list, which a source scan cannot (`docs/solutions/test-failures/oa4mp-green-run-does-not-prove-a-test-can-fail.md`). The harness's fake server (`Oa4mpHarnessOa4mpServer` in `Test/lib/Oa4mpClaimsControllerHarness.php`) is a standalone class that implements only `oa4mpEditClient()` and `oa4mpVerifyClient()`. Once the guards ask the KTD1 predicate through the seam, and `manage()` asks `requiresActiveStatus()` through it, the fake must answer both by delegating to a real `Oa4mpClientOa4mpServer`, so existing harness tests keep passing and the guards exercise the production predicate. Governs R3.

### Assumptions

- In pipeline mode the plan-time scoping confirmation was skipped. The bets it would have shown: the claim name on the Claims index becomes plain text for a Named-configuration client (it links to an edit page that would now bounce); the Scopes and Authorization submit controls are hidden rather than shown disabled; the notice also appears on a public client that has a Named configuration.
- Claim migration from deprecated Search attributes on the Properties page can still create claim rows for a Named-configuration client. It is not one of the three tabs and stays out of scope per the Product Contract.

### Sequencing

U1 first, because U2-U4 use its predicate. U2, U3 and U4 are independent of each other. U5 last, so the docs describe what shipped.

---

## Implementation Units

### U1. Named-configuration predicate and authorization args withheld from the cfg

- **Goal:** One predicate for "uses a Named configuration", and a cfg that carries no plugin-built authorization args while one is applied.
- **Requirements:** R5, R7, R8; KTD1, KTD2. Covers AE2, AE3, AE4.
- **Dependencies:** none.
- **Files:**
  - `Model/Oa4mpClientOa4mpServer.php`
  - `Test/Case/Model/NamedConfigAuthorizationCfgTest.php` (new)
  - `Test/run.sh` (raise `min_tests_run`)
- **Approach:**
  1. Add the KTD1 predicate next to `isPublicClient()`.
  2. In `oa4mpMarshallCfgQdl()`, skip the authorization-args block when the predicate holds, and rewrite the "orthogonal" comment to state the new rule and point at R7.
  3. Leave `isClientDataSynchronized()` and the unmarshaller untouched (KTD2).
- **Patterns to follow:** `isPublicClient()` / `requiresActiveStatus()` shape; fixtures built through `Oa4mpClientCoOidcClient::current()` as in `Test/Case/Model/RequireActivePredicateTest.php`; the saved-shape rule in `docs/solutions/test-failures/oa4mp-sync-check-needs-the-saved-client-shape.md`.
- **Execution note:** Write the AE2 test first and watch it fail against the current marshaller (verified red), since today the args are emitted.
- **Test scenarios:**
  - Covers AE2. A confidential client with a Named configuration applied and a saved authorization row (`require_active` true, `authz_co_group_id` set, all three redirect URLs set) marshals a cfg whose `tokens.identity.qdl.args` contains none of `require_active_status`, `authorization_group_id`, `authorization_group_redirect_url`, `require_active_redirect_url`, `no_record_redirect_url`.
  - Same client: the Named configuration's own JSON is present in the cfg and the contract-version stamp is still written.
  - Named configuration whose stored JSON itself defines a `tokens.identity.qdl.args` key: that key and value reach the cfg unchanged (R7's second sentence), not merged into an array.
  - Covers AE3. Same saved client with `named_config_id` cleared marshals `require_active_status` and the integer `authorization_group_id` again.
  - Predicate: `named_config_id` set -> true; null, empty string, and a client whose `Oa4mpClientCoNamedConfig` is a phantom all-null array -> false.
  - Covers AE4. A saved Named-configuration client with a retained authorization row, compared against a server representation that still carries authorization args, is reported in sync; so is the same client against a server representation with none.
- **Verification:** the new tests pass, the existing cfg, contract and Named-configuration characterization tests (`ClaimCfgContractTest`, `NamedConfigClaimSyncTest`, `ContractVersionStampTest`, `CfgMarshallingTest`) pass unchanged, and the run count rises by the number of tests added.

### U2. Claims tab read-only while a Named configuration is applied

- **Goal:** The Claims tab lists stored claims with a notice and no write controls, and the controller refuses claim writes.
- **Requirements:** R1, R2, R3 for Claims; KTD3, KTD4. Covers AE1.
- **Dependencies:** U1.
- **Files:**
  - `Controller/Oa4mpClientClaimsController.php`
  - `View/Oa4mpClientClaims/index.ctp`
  - `View/Oa4mpClientClaims/fields.inc` (only if the add/edit form needs the flag)
  - `Lib/lang.php`
  - `Test/lib/Oa4mpClaimsControllerTestCase.php` (Named-configuration client fixture)
  - `Test/lib/Oa4mpClaimsControllerHarness.php` (fake server answers the predicates, per KTD5)
  - `Test/Case/Controller/ClaimsWritePathTest.php`
  - `Test/run.sh`
- **Approach:**
  1. Add a guard beside `_blockIfPublicClient()` and call it in `add`, `edit` and `delete` at the same point, before any server call.
  2. In `index`, set the KTD4 view variable.
  3. In `index.ctp`, show the notice, hide the Add link and the per-row edit and delete actions, and render the claim name as plain text when the flag is set.
- **Patterns to follow:** `_blockIfPublicClient()` and the `$is_public_client` handling in `index.ctp`; `ClaimsWritePathTest::testPublicClientIsBlockedFromAdd` / `...Edit...`.
- **Test scenarios:**
  - Covers AE1. POST to `add` for a Named-configuration client: no server call recorded, claim row count unchanged, error flashed, redirect to the Claims index, no success flash.
  - POST to `edit` for an existing claim on that client: same assertions, and the claim row is unchanged.
  - POST to `delete` for that claim: no server call, row still present, error flashed.
  - GET to `add` for that client is redirected to the index with the error.
  - Control: the same POST to `add` for an otherwise identical client without a Named configuration reaches the fake server, so the guard is shown to be the thing refusing.
  - The flashed message names the Named configuration, and a name containing an apostrophe appears escaped once.
- **Verification:** guard tests pass and fail when the guard call is removed from each action; the Claims index for a Named-configuration client shows the notice and no write controls when checked in a browser.

### U3. Scopes tab read-only while a Named configuration is applied

- **Goal:** The Scopes checklist shows the stored scopes disabled with a notice, and a POST is refused.
- **Requirements:** R1, R2, R3 for Scopes; KTD3, KTD4, KTD5.
- **Dependencies:** U1.
- **Files:**
  - `Controller/Oa4mpClientCoScopesController.php`
  - `View/Oa4mpClientCoScopes/fields.inc`
  - `Lib/lang.php`
  - `Test/lib/` (a Scopes controller harness following `Oa4mpClaimsControllerHarness.php`)
  - `Test/Case/Controller/ScopesNamedConfigGuardTest.php` (new)
  - `Test/run.sh`
- **Approach:**
  1. Add a `_oa4mpServer()` seam and use it where the server object is built today.
  2. In `edit_scopes`, refuse POST/PUT when the predicate holds, before `oa4mpEditClient()` and before any scope row is saved or deleted, and redirect back to `edit_scopes`.
  3. On GET, set the KTD4 view variable.
  4. In `fields.inc`, show the notice, disable every scope checkbox and any add-scope input with the same mechanism the public-client flag uses, and hide the submit control.
- **Patterns to follow:** the public-client disabling in `View/Oa4mpClientCoScopes/fields.inc`; the Claims harness's recorded flash, redirect and server calls.
- **Test scenarios:**
  - POST of a changed scope set for a Named-configuration client: no server call, the client's scope rows unchanged, error flashed, redirect to `edit_scopes`.
  - GET for the same client is not redirected and renders (the guard does not block the read-only view).
  - Control: the same POST for a client without a Named configuration reaches the fake server.
- **Verification:** tests pass and the POST test fails with the guard removed; `edit_scopes` for a Named-configuration client shows the notice with checkboxes disabled when checked in a browser.

### U4. Authorization tab read-only while a Named configuration is applied

- **Goal:** The Authorization form shows the saved settings disabled with a notice, and a POST is refused.
- **Requirements:** R1, R2, R3 for Authorization; KTD3, KTD4, KTD5. Covers AE2 (tab half).
- **Dependencies:** U1.
- **Files:**
  - `Controller/Oa4mpClientAuthorizationsController.php`
  - `View/Oa4mpClientAuthorizations/manage.ctp`
  - `Lib/lang.php`
  - `Test/lib/` (an Authorizations controller harness)
  - `Test/Case/Controller/AuthorizationsNamedConfigGuardTest.php` (new)
  - `Test/run.sh`
- **Approach:**
  1. Add a `_oa4mpServer()` seam.
  2. In `manage`, refuse POST/PUT when the predicate holds, before `oa4mpEditClient()` and before the authorization row is saved, and redirect back to `manage`.
  3. On GET, set the KTD4 view variable alongside the existing `vv_require_active`.
  4. In `manage.ctp`, show the notice, render each input disabled with its saved value, and hide the submit block. The page's JS that hides and clears redirect-URL fields must not clear a value on a disabled form.
- **Patterns to follow:** `vv_require_active` wiring from `docs/solutions/logic-errors/oa4mp-require-active-status-shown-ticked-when-unsaved.md`; existing source checks in `Test/Case/View/AuthorizationNoRecordFieldTest.php` for what the thin runner cannot render.
- **Test scenarios:**
  - POST changing `require_active` and the group for a Named-configuration client: no server call, the authorization row unchanged, error flashed, redirect to `manage`.
  - GET for the same client renders and is not redirected.
  - Control: the same POST for a client without a Named configuration reaches the fake server.
  - Source check on `manage.ctp`: every authorization input and the submit block are tied to the Named-configuration flag, with a floor on the number of inputs found so a newly added input that is not disabled fails the check.
- **Verification:** tests pass and the POST test fails with the guard removed; the tab for a Named-configuration client shows saved values disabled and the notice when checked in a browser.

### U5. Documentation and learnings

- **Goal:** Operators and future maintainers can read the new behavior.
- **Requirements:** R9.
- **Dependencies:** U1-U4.
- **Files:**
  - `docs/oidc-client-plugin-manual.md`
  - `CHANGELOG.md`
  - `CONCEPTS.md`
  - `docs/solutions/logic-errors/oa4mp-named-config-claims-inert-2026-08-22.md`
- **Approach:**
  1. Manual, Named configurations section: say which tabs become read-only, that their stored values return to force when the configuration is removed, and that applying one removes the client's Require Active Status and group gate from the server.
  2. CHANGELOG Unreleased: one entry, operator-facing, naming the gate removal on apply.
  3. `CONCEPTS.md`, Named configuration entry: extend the uncommitted sentence to cover authorization settings.
  4. The open learning: record that the tab is now gated and the inert state is visible (its remedy points 1 and 3), and that point 2, the bulk activation when the configuration is cleared, remains open.
- **Test expectation:** none -- documentation only.
- **Verification:** ASCII-only check passes on the changed docs; nothing in them contradicts R1-R9.

---

## Verification Contract

| Gate | Command | Applies to |
|---|---|---|
| Hermetic suite | `Test/run.sh` | every unit; must report the all-passed sentinel and a run count at or above the raised `min_tests_run` |
| Lint | `php -l` on each changed PHP file and `.ctp` | U1-U4 |
| Verified red | revert each guard or the marshaller skip locally and confirm its new test fails | U1-U4 |
| Floor | `min_tests_run` in `Test/run.sh` raised by the number of tests added, with a new explanatory paragraph at the top of the list; `ClaimsControllerHarnessTest::testRunShRequiresAPlausibleTestCount` passes | U1-U4 |

The live tier (`Test/run-live.sh`) is not run.

---

## Definition of Done

- R1-R9 hold, and AE1-AE4 are each exercised by a named test.
- The cfg for a client without a Named configuration is unchanged by this work.
- `Test/run.sh` is green with the raised floor; no existing test was weakened.
- No abandoned-attempt code, debug output or unused helper is left in the diff.
- Docs and the open learning are updated per U5.
