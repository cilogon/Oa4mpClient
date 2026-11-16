---
title: The OA4MP-admin flag was true for every logged-in user once admin users were configured
date: 2026-11-16
category: security-issues
module: Oa4mpClient plugin (Oa4mpClientAuthzComponent::isOa4mpAdmin)
problem_type: security_issue
component: rails_controller
symptoms:
  - "With COMANAGE_REGISTRY_OA4MP_ADMIN_USERS set, permissionSet() returned oa4mp_admin = true for a logged-in user who was not on the list"
  - "No visible effect, because nothing in the plugin reads $p['oa4mp_admin'] yet"
root_cause: wrong_api
resolution_type: code_fix
severity: medium
tags: [authorization, allow-list, cakephp, session, in-array, loose-comparison, php8, oa4mp]
---

# The OA4MP-admin flag was true for every logged-in user once admin users were configured

## Problem

cilogon/Oa4mpClient#80. `Oa4mpClientAuthzComponent::isOa4mpAdmin()` decides whether the logged-in user is on the `COMANAGE_REGISTRY_OA4MP_ADMIN_USERS` list. Once that list held any name, it answered yes for every logged-in user. The fix is on branch `fix/oa4mp-admin-session-username`, merge pending.

## Root cause

Two mistakes, each harmless alone, combined into an allow-list that admitted everyone:

```php
$username = $this->Session->check('Auth.User.username') ?? null;

if(in_array($username, $oa4mpAdmins)) {
```

- **`check()` instead of `read()`.** CakePHP 2's `SessionComponent::check()` reports whether a key is present and returns a boolean. So `$username` was `true` for any logged-in user, never the username. The `?? null` fallback hid this: it only replaces `null`, and `true` is not null.
- **A loose `in_array()`.** Without its third argument, `in_array()` compares with `==`. In PHP 8, `true == 'alice@example.org'` is true for any non-empty string. A boolean reaching an allow-list check therefore matches the first listed name.

Nothing reads `$p['oa4mp_admin']` today, so the flag was wrong but inert. It would have granted every user whatever it came to gate.

The only test of the flag skipped itself whenever the environment variable was set, so the configured-list path, the one where the bug lived, had no coverage.

## Solution

Read the value, and compare strictly so that a missing username (`null`) or any non-string never matches:

```php
$username = $this->Session->read('Auth.User.username');

if(in_array($username, $oa4mpAdmins, true)) {
```

`Oa4mpClientCoAdminClientsController::isAuthorized()` already reads the username with `Session->read()`.

`testOa4mpAdminOnlyForListedUsers` in `Test/Case/Controller/Component/Oa4mpClientAuthzComponentTest.php` sets the list with `putenv()` and the username with `CakeSession::write()`. It then checks that an unlisted user is not an admin and a listed one is, and restores both values in a `finally` block. `CakeSession` writes work in the thin console runner, so session-dependent component code can be tested through the real component.

## Prevention

- Use `Session->read()` for a session value. `check()` answers only whether the key exists.
- Pass `true` as the third argument to `in_array()` in any allow-list or permission check. A boolean or `null` that slips in then matches nothing, instead of matching a listed name.
- When a test skips itself under some configuration, check that another test covers that configuration. Here the skipped case was exactly where the bug lived.
