---
title: OIDC client edit saved the posted record ids, so a hand-built post could write another client
date: 2026-11-04
category: security-issues
module: Oa4mpClient plugin (OIDC client edit)
problem_type: security_issue
component: rails_controller
symptoms:
  - "Editing one OIDC client with another client's id in the post renamed the other client in the Registry"
  - "The edited client's contact email row moved to the other client"
  - "Both clients then read as out of sync with their OA4MP copies"
  - "A post carrying only another client's id ended in a unique-constraint database error"
root_cause: missing_validation
resolution_type: code_fix
severity: high
last_updated: 2026-11-05
tags: [authorization, mass-assignment, saveassociated, hidden-fields, oidc-client, out-of-sync, cakephp]
---

# OIDC client edit saved the posted record ids, so a hand-built post could write another client

## Problem

cilogon/Oa4mpClient#53. `Oa4mpClientCoOidcClientsController::edit($id)`
authorized and loaded the client named in the URL, and sent OA4MP that client
with the posted name, home URL and contact email. It then saved with
`saveAssociated($this->request->data)`. Fixed by cilogon/Oa4mpClient#64.

## Root cause

In CakePHP 2, `save()` and `saveAssociated()` write the rows the data names.
An `id` in `request->data` decides which row is updated, a `client_id` on an
associated row decides which parent it belongs to, and any other column in the
data is written too. Checking permission on the URL's id does nothing to bind
the save to that id.

The edit form renders the client's `id`, `admin_id` and `oa4mp_identifier`,
and the contact email row's `id` and `client_id`, as hidden fields
(`View/Oa4mpClientCoOidcClients/fields.inc`). Hidden fields are input like any
other. A post for client A carrying client B's `id` and `oa4mp_identifier`
edited A on the OA4MP server but renamed B in the Registry and moved A's
contact email row to B, so both clients drifted from their server copies and
the sync check blocked them. Columns the form never shows, such as
`public_client` or `named_config_id`, could be set the same way.

## Solution

`editDataForStoredClient()` rebuilds `request->data` as the first step of the
POST branch:

- the client's `id`, `admin_id` and `oa4mp_identifier` come from the stored
  client;
- only the fields the form edits are taken from the post: name, home URL, the
  deprecated refresh token lifetime when posted, and the contact email
  address;
- the contact email row's `id` and `client_id` come from the stored row (a new
  row is created for this client when it has none);
- every other posted column and section is dropped.

Everything after it, the up-front check, the server payload, the save, and a
rejected form shown again, works on the client in the URL. A posted client id
that differs from the URL's is logged.

Tests: the forged-identifier tests in the OIDC client edit section of
`Test/Case/Controller/ValidateBeforeServerWriteTest.php`.

## Prevention

- Never pass `request->data` straight to `save()` / `saveAssociated()` in an
  action authorized by a URL id. Build the save data from the stored record's
  identifiers and the editable fields only, or pass a `fieldList` and set the
  id yourself.
- Treat every hidden field, especially ids and foreign keys, as untrusted.
- A choice list a form renders is not a server-side check either. The Access
  Control tab offered only active groups in the client's CO, and for a
  delegated editor only groups they belong to, but saved any numeric group
  id, so a hand-built request could hand the client's editing to another
  CO's group; it also saved the row its posted hidden ids named
  (cilogon/Oa4mpClient#54, fixed by cilogon/Oa4mpClient#68). Check a posted choice against the same list the page
  offered, and take row ids from the stored record.
- Still open with the same shape when this was written: OIDC client `add()`
  (posted data flows through `newClientData()` into `saveAssociated()`), the
  callback edit (route client sent to OA4MP, posted data saved). The add and
  callback paths, including callback delete accepting another client's
  callback id, were fixed for cilogon/Oa4mpClient#65 on branch
  `fix/client-add-and-callbacks-stored-ids` (the merge is pending). Claim add, edit and
  delete had the same shape, and delete also removed any claim id whichever
  client owned it (cilogon/Oa4mpClient#56, fixed by
  cilogon/Oa4mpClient#70): a route id needs the same ownership check as a
  posted one.

## Related Issues

- cilogon/Oa4mpClient#53 (issue of record), fixed by cilogon/Oa4mpClient#64
- cilogon/Oa4mpClient#56 -- claim add, edit and delete not limited to the client in the URL; fixed by cilogon/Oa4mpClient#70
- cilogon/Oa4mpClient#65 -- OIDC client add and callback add/edit, the same pattern, plus callback delete
- cilogon/Oa4mpClient#54 -- the Access Control tab, an unchecked choice list plus the same posted-id pattern; fixed by cilogon/Oa4mpClient#68
- `docs/solutions/logic-errors/oa4mp-url-whitespace-server-first-write-drift-2026-11-02.md`
  -- the same edit path, and why the server and the Registry must store the
  same values.
