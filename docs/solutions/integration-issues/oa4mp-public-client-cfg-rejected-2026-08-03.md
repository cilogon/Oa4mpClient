---
title: Editing a public OIDC client failed because the marshaller sent a cfg OA4MP forbids on public clients
date: 2026-08-03
category: integration-issues
module: Oa4mpClient plugin (OA4MP server marshalling)
problem_type: integration_issue
component: service_object
related_components:
  - rails_controller
severity: medium
symptoms:
  - "Adding a Callback URL to a public OIDC client failed with the red flash Unable to edit OIDC client"
  - "OA4MP server returned HTTP 400 invalid_request_object: custom configurations not permitted in public clients"
  - "Registry log then showed No cfg object found in oa4mpObject when re-reading the server representation"
root_cause: logic_error
resolution_type: code_fix
tags: [oa4mp, public-client, cfg, marshalling, integration, comanage, oidc, token-endpoint-auth-method]
---

# Editing a public OIDC client failed because the marshaller sent a cfg OA4MP forbids

## Problem

Editing a **public** OIDC client (for example adding a Callback URL) failed with the red flash "Unable to edit OIDC client". The plugin marshalled a `cfg` (custom claims/QDL configuration) into the PUT it sends to the OA4MP server, and OA4MP rejects any custom configuration on a public client.

## Symptoms

- Red flash: **Unable to edit OIDC client** after adding a Callback URL to a public client.
- OA4MP server response was HTTP 400: `{"error":"invalid_request_object","error_description":"custom configurations not permitted in public clients."}`.
- The outbound PUT body contained a `cfg` object (the `dynamodb_claims.qdl` load with the admin's default DynamoDB config) even though the client was public (`token_endpoint_auth_method: none`).
- A later log line `No cfg object found in oa4mpObject` appeared — a red herring (see below).

## What Didn't Work

The tempting misread is the last error line, `No cfg object found in oa4mpObject`. That is **not** the cause. After the PUT is rejected, the plugin re-reads the server's representation of the client (a GET) and unmarshals it; a public client legitimately has no `cfg` on the server, so that log line is a benign downstream symptom of the failed edit, not the failure. Chasing it leads away from the real cause, which is one step earlier: the `cfg` the plugin *sent* in the PUT.

## Solution

`oa4mpMarshallContent` in `Model/Oa4mpClientOa4mpServer.php` built and attached a `cfg` whenever the client resolved any claim/config data, with no check for client type. Guard the cfg block so a `cfg` is only marshalled for confidential (non-public) clients:

```php
// Before — attaches cfg regardless of client type
if(!empty($data['Oa4mpClientCoLdapConfig']) ||
   !empty($data['Oa4mpClientCoOidcClient']['named_config_id']) ||
   !empty($data['Oa4mpClientAccessToken']) ||
   !empty($data['Oa4mpClientAuthorization']) ||
   !empty($data['Oa4mpClientClaim'])) {
  $cfg = $this->oa4mpMarshallCfgQdl($data);
  if(!empty($cfg)) {
    $content['cfg'] = $cfg;
  }
}

// After — OA4MP rejects cfg on public clients, so only confidential clients carry one
$isPublicClient = !empty($data['Oa4mpClientCoOidcClient']['public_client'])
                  && $data['Oa4mpClientCoOidcClient']['public_client'];

if(!$isPublicClient &&
   (!empty($data['Oa4mpClientCoLdapConfig']) ||
    !empty($data['Oa4mpClientCoOidcClient']['named_config_id']) ||
    !empty($data['Oa4mpClientAccessToken']) ||
    !empty($data['Oa4mpClientAuthorization']) ||
    !empty($data['Oa4mpClientClaim']))) {
  $cfg = $this->oa4mpMarshallCfgQdl($data);
  if(!empty($cfg)) {
    $content['cfg'] = $cfg;
  }
}
```

`oa4mpMarshallContent` is the single outbound choke point (both `oa4mpNewClient` and `oa4mpEditClient` call it), so guarding it there covers create and edit in one place.

## Why This Works

The causal chain:

1. A public client is created with `token_endpoint_auth_method: none`. At create time it has no claim/config associations, so no `cfg` is sent and OA4MP stores it fine.
2. Adding a Callback triggers an edit. `oa4mpEditClient` marshals the current client (now resolving the admin's default DynamoDB claim config) via `oa4mpMarshallContent`, which attaches `$content['cfg']` unconditionally.
3. The PUT carries a `cfg` for a public client. OA4MP enforces "no custom configuration on public clients" and returns 400.
4. `oa4mpEditClient` only returns success on HTTP 200, so it returns an error and the controller flashes "Unable to edit OIDC client". The callback is never saved.

The guard makes the plugin honor OA4MP's contract: public clients (no client secret, `openid` scope only) never carry a `cfg`.

**The contract is now enforced at three layers.** The marshaller guard above is the backstop; commit `fc2c9e9` ("feat(claims): reject claim write paths for public clients") added the two in front of it, so a public client cannot accumulate the claim data that produced the offending `cfg` in the first place:

- View — `View/Oa4mpClientClaims/index.ctp` hides the add/edit/delete controls for a public client and prints `pl.oa4mp_client_co_oidc_client.claims.public_client.description` instead.
- Controller — `Oa4mpClientClaimsController::_blockIfPublicClient()` guards `add()`, `edit()` (GET render and POST) and `delete()`, flashing `pl.oa4mp_client_claim.er.public_client` and redirecting; `index()` is deliberately left unguarded so the CLAIMS tab still renders the explanatory message.
- Marshaller — the `$isPublicClient` guard in `oa4mpMarshallContent`, which still covers clients whose claim data predates the UI guard.

**Verified the fix does not trade one bug for another.** The sync comparator `isClientDataSynchronized` only compares the cfg-derived config (DynamoDB, access token, authorization) when *both* the plugin side and the server side have it — the DynamoDB block is guarded by `!empty($curDynamo) && !empty($oa4mpServerData['Oa4mpClientDynamoConfig'])`. So a public client with plugin-side config but no server `cfg` still verifies as synchronized; omitting the cfg does not create a perpetual "out of sync" state on the next edit. (This is also why `oa4mpVerifyClient` passed before the failing PUT in the original report.)

## Prevention

- **Encode a server's per-client-type contract at the marshalling boundary, not downstream.** When an external service accepts or rejects a field based on an object's type/mode (here: OA4MP forbids `cfg` on public clients), the code that builds the outbound request must branch on that type. Do not rely on the server's rejection as the enforcement point — that surfaces to the user as an opaque failure.
- **When a marshalling change adds or removes a field, re-check the sync/verify comparator that reads it back.** In this plugin, `oa4mpMarshallContent` (what we send) and `isClientDataSynchronized` (what we compare on the next edit) are a matched pair; changing one without checking the other can turn a fixed edit into a permanent "out of sync" block. The comparator's both-sides-present guards are what make omitting the cfg safe here.
- **Read the server's own error string first.** OA4MP's `error_description` ("custom configurations not permitted in public clients") named the exact cause; the fix followed directly from it. The later `No cfg object found` line was a downstream symptom, not the cause.
- **Secret hygiene (follow-up):** `oa4mpEditClient` logs the full request body — including the AWS `secret_access_key` inside the cfg — at error level (and the full response). This model already has a `redactSecrets()` helper used by the sync comparator; routing the logged request/response bodies through it would stop leaking live credentials into the Registry log. When this bug fired, real AWS credentials for the DynamoDB table were written to the log and had to be rotated.
- **Regression coverage:** locked by `Test/Case/Model/CfgMarshallingTest.php::testMarshalledContentHasNoCfgForPublicClient`, which asserts `oa4mpMarshallContent` emits no `cfg` key for a `public_client` that resolves claim data and — flipping only `public_client` on the same fixture — that a confidential client still carries one. The server-acceptance half is `Test/Case/LiveServer/LiveClientLifecycleTest.php::testPublicClientIsAcceptedWithoutCustomConfiguration`. The captured OA4MP 400 lives at `Test/fixtures/oa4mp-responses/public-client-cfg-rejected.json`. Run with `Test/run.sh` (hermetic) or `Test/run-live.sh` (live server). The `_blockIfPublicClient` controller guard below is now covered from three angles: `Test/Case/Controller/ClaimsControllerHarnessTest.php::testRedirectRecordsItsTargetInsteadOfExiting` drives the guard on a public client and asserts the redirect target and the flashed message, and `Test/Case/Controller/ClaimsWritePathTest.php::testPublicClientIsBlockedFromAddBeforeAnyServerCall` and `::testPublicClientIsBlockedFromEditBeforeAnyServerCall` each assert the guard stops the action before any OA4MP call is made.

## Related Issues

- [oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30](../logic-errors/oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md) and [oa4mp-admin-client-hasone-duplicate-insert-2026-06-30](../logic-errors/oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md) — same DynamoDB `cfg` marshalling/sync domain. The `cfg` this bug wrongly sent is built from the same DefaultDynamoConfig those docs concern, and the `isClientDataSynchronized` / `resolveDynamoConfig` comparator that makes this fix safe is the one hardened in those learnings.
- [oa4mp-named-config-claims-inert-2026-08-22](../logic-errors/oa4mp-named-config-claims-inert-2026-08-22.md) -- names this doc's `_blockIfPublicClient()` controller guard as the precedent a matching named-configuration guard would follow; that doc's defect is the same claims tab left ungated, for a different client variant.
- The broader OA4MP plugin corpus under [docs/solutions/logic-errors/](../logic-errors/).
- Commit `6cd9065` ("fix(oa4mp): omit cfg for public clients on server marshalling"), on `main`. The follow-on that blocks claim writes for public clients is `fc2c9e9`, planned in `docs/plans/2026-08-03-001-feat-prevent-public-client-claims-plan.md`.
