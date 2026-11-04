---
title: Whitespace around a URL drove the plugin and OA4MP out of sync (server-first write path)
date: 2026-11-02
category: logic-errors
module: Oa4mpClient plugin (callbacks and Authorization tab write paths, OIDC client home URL)
problem_type: logic_error
component: rails_controller
symptoms:
  - "A callback URL saved with a leading or trailing space reported Callback Added, but the callback never appeared in the Registry"
  - "Every later edit of that client was refused as modified outside the Registry (out of sync)"
  - "The comparator log listed different callback lists, with the server copy carrying the padded URL"
root_cause: missing_validation
resolution_type: code_fix
severity: high
tags: [sync-verification, out-of-sync, write-path, validation-order, whitespace, callbacks, authorization, trim]
---

# Whitespace around a URL drove the plugin and OA4MP out of sync (server-first write path)

## Problem

cilogon/Oa4mpClient#48. A subscriber typed or pasted a callback URL with a stray
space around it. The OA4MP server accepted it, the Registry did not, and the
page said it had succeeded. From then on the synchronization guard blocked every
edit of that client. Fixed by cilogon/Oa4mpClient#49.

## Root cause

The write actions talk to the server **before** they validate locally:

1. `Oa4mpClientCoCallbacksController::add()` / `edit()` and
   `Oa4mpClientAuthorizationsController::manage()` built the new client from
   the raw posted data and called `oa4mpEditClient()`.
2. The server stored the URL as sent.
3. Only then did the action call `save()`. `validCallbackUri()`
   (`Model/Oa4mpClientCoCallback.php`) and Cake's `url` rule
   (`Model/Oa4mpClientAuthorization.php`) both reject a leading or trailing
   space, so the save failed.
4. The save result was assigned to `$ret` and never read, so success was
   flashed.
5. `isClientDataSynchronized()` (`Model/Oa4mpClientOa4mpServer.php`, "Compare
   callbacks") then saw a callback on the server that the plugin had no row
   for, and every later edit was refused.

The whitespace was only the trigger. **Any** input the plugin's validators
reject goes through the same path: the server takes it, the plugin drops it,
and the two sides drift apart.

## Solution

- **Trim in the model.** `beforeValidate()` on `Oa4mpClientCoCallback`
  (`url`) and `Oa4mpClientAuthorization` (the three redirect URLs) trims
  surrounding whitespace.
- **Validate before the server call, and send what validation left behind.**
  The callbacks controller's `_validatePostedCallback()` and the same steps
  inline in `manage()` run `create(false)`, `set($this->request->data)`, and
  `validates()`. They then copy `$model->data[<alias>]` back into the request
  data, so the value sent to OA4MP and the value saved are the same trimmed
  string. A rejected post never reaches the server. It falls through to the
  GET logic, which keeps the posted input in the form.
- **Check the local save.** A `save()` that fails after the server accepted
  the change flashes `pl.oa4mp_client_co_callback.er.add.save`,
  `...er.edit.save`, or `pl.oa4mp_client_authorization.er.edit.save`, which
  say the two sides now disagree. It never flashes success.
- **The OIDC client home URL is trimmed in the controller.** In
  `Oa4mpClientCoOidcClientsController` `add()` and `edit()`, the trim runs at
  the top of the POST branch, before `newClientData()` marshals the data or
  `home_url` is copied into `$newClient`.

Regression tests: `Test/Case/Controller/UrlWhitespaceWritePathTest.php`, driven
through `Test/lib/Oa4mpWritePathHarnesses.php`. That harness reuses the claims
harness fakes, and the controllers' new `_oa4mpServer()` factories make it
possible.

## Why a model-side trim alone is not enough, and can make things worse

The obvious one-line fix is "trim in `beforeValidate()`". It is not enough by
itself, and on some paths it *creates* drift:

- `beforeValidate()` runs inside `save()`/`validates()`. On every write path
  above, the server payload was built from `$this->request->data` **before**
  any validation ran.
- Trimming only in the model would make the plugin store `https://x/cb` while
  the server kept ` https://x/cb`. A validation failure becomes a silent value
  mismatch, which is harder to see.
- This is why the OIDC client home URL is trimmed in the controller and not in
  the model. `add()` marshals with `newClientData()` before `saveAssociated()`
  ever validates.

**The rule:** whatever normalizes a value must run before the code that builds
the server payload. Then the payload must be built from the normalized data.
On a server-first write path, that means validating first.

## Relation to the claims write-path fix

The claims controller had the same ignored `save()` result. It was fixed
earlier (`ClaimsWritePathTest`; see
`oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`, prevention rule 1).
That fix **reports** the drift after it happens. It still calls the server
first, so a claim the plugin rejects still reaches the server. Validating first,
as done here, **prevents** that case. The after-the-fact report is still needed
for save failures that validation cannot predict.

## Prevention

- When adding or touching a controller action that calls `oa4mpEditClient()`,
  check the order:
  1. Normalize and validate.
  2. Build the payload from the validated data.
  3. Call the server.
  4. Check `save()`.

  Several controllers still have the server-first, unchecked-save shape and
  are follow-up work: scopes, refresh tokens, access tokens, access controls,
  and named configs. The OIDC client `edit()` also still sends to the server
  before validating, so a `home_url` that is invalid for a reason other than
  whitespace can still drift.
- A test for such an action should assert two things:
  - the fake server never receives input the model rejects;
  - the server payload and the saved row hold the same value.

  The tests in `UrlWhitespaceWritePathTest` follow this pattern.
- Diagnostic gap (proposed, not implemented): the comparator logs the two
  callback lists with `print_r`, which makes a leading space nearly invisible.
  Logging with `var_export` or `json_encode` would have shown the quoted
  `" https://..."` immediately.

## Related

- cilogon/Oa4mpClient#48 (issue of record), fixed by cilogon/Oa4mpClient#49
- `docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`
- `docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md`
- `docs/solutions/test-failures/oa4mp-green-run-does-not-prove-a-test-can-fail.md`
