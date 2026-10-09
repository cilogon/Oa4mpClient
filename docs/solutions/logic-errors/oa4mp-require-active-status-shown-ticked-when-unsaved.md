---
title: Require Active Status showed ticked on the Authorization tab for clients that never saved it, while OA4MP did not enforce it
date: 2026-09-29
category: logic-errors
module: Oa4mpClient plugin (OIDC client Authorization tab and client creation)
problem_type: logic_error
component: rails_view
related_components:
  - rails_controller
  - rails_model
severity: high
symptoms:
  - "The Authorization tab showed Require Active Status ticked for every client with no saved authorization row and every row saved with require_active NULL"
  - "OA4MP did not enforce Require Active Status for those clients; users without an active CO Person record could log in"
  - "New confidential clients were created without the setting saved or sent to OA4MP"
  - "Sync verification reported those clients in sync, so nothing flagged the mismatch"
root_cause: wrong_api
resolution_type: code_fix
tags: [cakephp, containable, hasone, phantom-null-array, require-active-status, checkbox-default, authorization, oa4mp]
---

# Require Active Status showed ticked on the Authorization tab for clients that never saved it

## Problem

The Authorization tab showed **Require Active Status** ticked for every OIDC client that had never saved it. The OA4MP server did not enforce it for those clients. The default existed only on screen. It became real only when someone saved the Authorization tab, often to change some other setting. Until then the client admitted users whose CO Person record was not active, while the tab and the manual both said it did not.

## Symptoms

- A newly created confidential client opened its Authorization tab with Require Active Status ticked, but the OA4MP server's cfg for the client had no `require_active_status` arg, so users without active status could still log in.
- The same thing happened for any client whose `cm_oa4mp_client_authorizations` row stored `require_active` as NULL.
- Sync verification reported these clients as in sync, so no out-of-sync banner or log line flagged the mismatch.
- The manual described the checkbox as "when checked (the default)" (pre-fix `docs/oidc-client-plugin-manual.md:436` at `79db001`), which matched the screen but not the server.
- Saving the Authorization tab for an unrelated reason, such as picking an authorization group, silently turned enforcement on, because the ticked box was posted as `require_active = 1`.

## What Didn't Work

No fix attempt failed here. The problem is that the defect stayed hidden, and the reasons are worth recording:

1. **A view default that "recommends" a setting looks exactly like the saved setting.** The template ticked the box whenever `require_active` was unset. An admin cannot tell a recommended default from a stored value, so a screenshot or a manual check of the tab showed nothing wrong. The default was never written to the database or sent to the server.
2. **The sync check cannot catch a default that exists only in the UI.** `isClientDataSynchronized()` compares the plugin's rows with the server's cfg. A client with no authorization row and no server authz config matches on both sides: the plugin says nothing, the server says nothing. The check only fires when one side has an authorization row or the server has authz config and the other does not. The plugin side counts as having a row only when it carries a row `id`, which is how the check tells a stored row from the phantom (`Model/Oa4mpClientOa4mpServer.php:1117-1128`; pre-fix around `79db001` line 1046-1060). The ticked checkbox appears in neither place, so the check had nothing to compare.
3. **`isset()` on a Containable hasOne array is not a presence test.** This is the same trap as the DynamoDB config phantom-array bug (see Related Issues). A missing `Oa4mpClientAuthorization` row comes back from Containable as an array of null-valued fields, so `isset($authorization['require_active'])` is false. That is exactly the case where the template fell through to `true`.

## Solution

The fix landed in cilogon/Oa4mpClient#31. It has four parts.

**1. One predicate for "does this client require Active Status".** `Oa4mpClientOa4mpServer::requiresActiveStatus()` (`Model/Oa4mpClientOa4mpServer.php:740-742`) tests the `require_active` value, never whether the association is present:

```php
function requiresActiveStatus($data) {
  return !empty($data['Oa4mpClientAuthorization']['require_active']);
}
```

**2. The marshaller uses the predicate** (`Model/Oa4mpClientOa4mpServer.php:1646-1648`). Its behavior is unchanged. The old guard, `!empty($data['Oa4mpClientAuthorization']) && $data['Oa4mpClientAuthorization']['require_active']` (`79db001` line 1576), already sent `require_active_status` only when the value was truthy. The change is that the tab now asks the same question:

```php
if($this->requiresActiveStatus($data)) {
  $authzArgs['require_active_status'] = $data['Oa4mpClientAuthorization']['require_active'];
}
```

**3. The Authorization tab renders the saved value.** `Oa4mpClientAuthorizationsController::manage()` sets a view variable from the predicate (`Controller/Oa4mpClientAuthorizationsController.php:160`), and the template reads it (`View/Oa4mpClientAuthorizations/manage.ctp:156`):

```php
// Before (79db001: View/Oa4mpClientAuthorizations/manage.ctp:156-157)
// $authorization = $this->request->data['Oa4mpClientAuthorization'];  (line 49)
$checked = isset($authorization['require_active']) ? $authorization['require_active'] : true;
print $this->Form->input('require_active', array('type' => 'checkbox', 'value' => 1, 'checked' => $checked));

// After
// Controller: $this->set('vv_require_active', $oa4mpServer->requiresActiveStatus($client));
print $this->Form->input('require_active', array('type' => 'checkbox', 'value' => 1, 'checked' => $vv_require_active));
```

**4. The default is saved and sent at creation.** Before the fix, `Oa4mpClientCoOidcClientsController::add()` built the client with no `Oa4mpClientAuthorization` row, so nothing was saved and nothing was sent. Now `newClientData()` (`Model/Oa4mpClientOa4mpServer.php:755-781`) gives every confidential client `Oa4mpClientAuthorization.require_active = true` (line 767-769). The same data goes into `saveAssociated()` and into the marshalled create request, so the stored row and the server cfg agree from the start. Public clients get no row, because the OA4MP server accepts no custom cfg on a public client. Saving the row together with a new client required relaxing `Oa4mpClientAuthorization`'s `client_id` rule to `'required' => 'update'` (`Model/Oa4mpClientAuthorization.php:50-54`): `saveAssociated()` validates the hasOne row before the parent id exists.

The manual now describes the box as showing the saved setting, and says that older clients that never saved it show it unchecked (`docs/oidc-client-plugin-manual.md:436-442`).

**Side effect worth knowing.** A row saved with `require_active` NULL now renders unchecked. On load, the tab's JS runs `toggleRequireActiveRedirectUrlField()` (`View/Oa4mpClientAuthorizations/manage.ctp:63`, `91-104`). With the box unchecked, that function hides the Active Status Redirect URL field and clears its value. So the next save of such a client drops any stored redirect URL unless the admin ticks the box again. The Unreleased section of `CHANGELOG.md` records this.

## Why This Works

There were three readers of one stored value, and they disagreed about what a missing value means:

| Reader | Missing row or NULL meant |
|---|---|
| Template (pre-fix) | ticked (`isset` false -> `true`) |
| Marshaller | not sent (falsy -> not required) |
| Sync check | in sync (neither side has anything) |

The server enforces what the marshaller sends, so the marshaller's answer is the truth. Making the template call the same predicate removes the only reader that disagreed. The tab can no longer show a state the server does not have. `!empty()` on the value treats a phantom all-null hasOne array, a NULL column, `false`, and the posted string `'0'` all as "not required", and treats `true`/`'1'` as "required". This matches exactly what the marshaller sends.

Once the display is honest, a "default on" policy only holds if it is saved. `newClientData()` puts that default in the database and in the create request together. The sync check then has a real row to compare against the server's authz cfg, instead of two matching absences.

## Prevention

- **A UI default for a setting that has an effect must be saved, not rendered.** If a form ticks a box that the database does not hold, the screen is lying. Write the default when the record is created, and render only stored values. Never use `isset($x) ? $x : true` for a checkbox that controls server behavior.
- **Route every reader of a policy flag through one predicate.** When the marshaller, the view, and any other reader ask the same question, give it one function (`requiresActiveStatus()`, like `isPublicClient()` beside it) and forbid inline re-derivations. Drift between two hand-written checks is how the screen and the server came to disagree.
- **Never test a Containable hasOne association with `isset()`/`!empty()` on the association.** Test a real field value. A missing row is an all-null array.
- **Sync verification does not cover settings that exist only in the UI.** It compares stored rows against server state. If the thing you care about is neither stored nor sent, the check passes. Test the rendered state against the stored state directly.
- **Regression coverage:** `Test/Case/Model/RequireActivePredicateTest.php` builds each client through `Oa4mpClientCoOidcClient::current()`, the same read path the tab uses. For each, it asserts both the predicate and whether the marshaller emits `require_active_status`:
  - `testNoAuthorizationRowIsNotRequired`: no row (phantom all-null array) -> false, nothing sent.
  - `testSavedOffIsNotRequired`: row saved `false` -> false, nothing sent.
  - `testSavedNullIsNotRequired`: row saved NULL -> false, nothing sent.
  - `testSavedOnIsRequired`: row saved `true` -> true, `require_active_status` sent.
  - `testPostedValuesReadAsSaved`: posted `'1'` -> true, `'0'` -> false.
  - `testAuthorizationTemplateUsesTheSavedValue`: `manage.ctp` binds `'checked' => $vv_require_active` and no longer contains `: true;`. The controller sets `vv_require_active` from `requiresActiveStatus(`. This is a source check, because the thin test runner cannot render the full page.

  Creation-time behavior (confidential clients saved and sent with `require_active`, public clients without) is covered in `Test/Case/Model/CreateTimeCfgTest.php` and `Test/Case/Model/AuthorizationCreateSaveTest.php`. Run with `Test/run.sh`. That the real OA4MP server accepts the create cfg and stores `require_active_status` is covered by `LiveClientLifecycleTest::testConfidentialClientCreatedRequiringActiveStatusStaysInSync` in the live-server tier (`Test/run-live.sh`); it failed on its first nightly run because it verified unsaved create data, which the row-`id` rule above reads as no row (fixed in cilogon/Oa4mpClient#34).

## Related Issues

- [oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30](oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md): the same Containable phantom all-null hasOne array. There a bare `!empty()` on the association picked up a phantom config. Here `isset()` on the phantom's field fell through to a ticked default. In both cases the fix is to test a real field value.
- [oa4mp-comparator-marshaller-asymmetry-2026-08-22](oa4mp-comparator-marshaller-asymmetry-2026-08-22.md): the same lesson from the other side. Two readers of the same data must share one rule, or they drift apart.
- [oa4mp-sync-check-needs-the-saved-client-shape](../test-failures/oa4mp-sync-check-needs-the-saved-client-shape.md): why the live test for this feature first reported a correctly created client out of sync. The sync check needs the saved client shape, not the create data.
- Fixed in cilogon/Oa4mpClient#31. Plan: `docs/plans/2026-09-28-1104-feat-require-active-status-default-plan.md`.
