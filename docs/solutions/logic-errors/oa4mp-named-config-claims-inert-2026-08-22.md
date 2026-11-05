---
title: Claims on a named-configuration client are silently inert and go live all at once when the named configuration is cleared
date: 2026-08-22
category: logic-errors
module: Oa4mpClient plugin (OIDC client claims, cfg marshalling, sync verification)
problem_type: logic_error
component: rails_model
severity: high
status: resolved
last_updated: 2026-11-05
symptoms:
  - "A claim created on a client that uses a named configuration is saved, is listed on the claims tab, and is never sent to the OA4MP server"
  - "Nothing in the interface indicates the claim is inert -- the claims tab is fully available on a named-configuration client, with no banner, no gate, and no warning on save"
  - "Sync verification reports the client in sync while it carries claims the server has never seen, so the usual drift signal never fires"
  - "Clearing the named configuration activates every previously created claim at once, on the next edit, with no review step in between"
  - "The relying party begins receiving identity attributes at the moment the named configuration is cleared, and the change that released them looks like an unrelated configuration edit"
root_cause: logic_error
resolution_type: code_fix
related_components:
  - Model/Oa4mpClientOa4mpServer.php (oa4mpMarshallCfgQdl named-configuration branch, isClientDataSynchronized named-configuration early return)
  - Controller/Oa4mpClientCoNamedConfigsController.php (confirmed apply and removal, delete on apply, reset to new-client defaults on removal)
  - Controller/Oa4mpClientNamedConfigActions.php (replaced tabs refused, never-sent leftovers deleted on touch)
  - Controller/Oa4mpClientClaimsController.php (refused while a Named configuration is applied)
  - Test/Case/Model/NamedConfigClaimSyncTest.php (locks the comparator half)
  - Test/Case/Model/ClaimCfgContractTest.php (cfg_shape/named_config -- locks the marshaller half)
tags:
  - oa4mp
  - oidc
  - named-configuration
  - claim
  - attribute-release
  - sync-verification
  - comparator
  - marshaller
  - inert-data
---

# Claims on a named-configuration client are silently inert

**Status: RESOLVED.** The remedy landed in cilogon/Oa4mpClient#75, which
closed cilogon/Oa4mpClient#58 and cilogon/Oa4mpClient#59. A Named configuration now
replaces the client's own claims and the other settings it supplies, and the
replaced rows are deleted rather than kept, so nothing stored can come back
into force when the Named configuration is removed. See Resolution. The
sections up to Current state describe the defect as it was.

## Problem

A client can be attached to a named configuration -- a stored cfg, shared
across clients, that the plugin sends to the OA4MP server verbatim instead of
building one from the client's own claims. Two places in
`Model/Oa4mpClientOa4mpServer.php` branch on that:

- `oa4mpMarshallCfgQdl()` merges the stored named configuration into the cfg
  and **returns before the claim loop runs**:

  ```php
  // If using a named configuration then just add the cfg for that
  // named configuration and then return the cfg.
  if(!empty($data['Oa4mpClientCoOidcClient']['named_config_id'])) {
    ...
    $cfg = array_merge_recursive($cfg, $namedCfg);

    return $cfg;
  }
  ```

- `isClientDataSynchronized()` **returns true before the claim comparison**:

  ```php
  // If this client uses a named configuration than return true here,
  // else continue with more detailed comparison.
  if($usesNamedConfig) {
    return true;
  }
  ```

The two are consistent with each other, and that consistency is the point: a
named-configuration client's claims are neither sent nor compared, so the
comparator does not report the drift that would otherwise be permanent. Taken
on their own, both branches are correct.

The defect is what sits above them. `Controller/Oa4mpClientClaimsController.php`
gates its `add`, `edit` and `delete` actions for **public** clients -- there is
a `_blockIfPublicClient()` helper, and it exists precisely because sending
claims for a public client is a mistake. There is no equivalent gate for a
named-configuration client. The claims tab is fully available: a user can
create, edit and list claims on such a client, they are persisted to
`cm_oa4mp_client_claims` like any other, they render on the tab like any other,
and they do nothing at all.

## Why it is worse than merely useless

Inert data would be a cosmetic problem if it stayed inert. It does not.

`named_config_id` is an ordinary editable field. Clear it, and on the next save
the marshaller stops taking the named-configuration branch and falls through to
the claim loop -- which now emits **every claim that was accumulated while the
client was exempt**, in one cfg, in one request. The comparator stops taking
its early return in the same moment and begins reporting the client in sync
with that new state.

The consequence is an attribute release. A relying party starts receiving
identity attributes -- group memberships, email addresses, entitlements,
whatever was staged on the tab -- at the instant an administrator edits an
apparently unrelated configuration field. No screen lists what is about to be
released, no confirmation names the claims, and the audit trail shows a
configuration edit rather than a change in attribute release. The people who
created those claims may not be the person who clears the field, and there may
be months between the two acts.

## Symptoms

- Claims created on a named-configuration client never reach the server, and
  nothing says so.
- Sync verification reports the client in sync throughout, so the plugin's own
  drift signal cannot surface the discrepancy.
- Clearing the named configuration releases all of the accumulated claims at
  once, silently, on the next edit.

## Resolution

Applying a Named configuration on the Named Configuration tab, including
switching from one to another, now asks for confirmation, and the server
checks that the confirmation was given. The confirmation says the Named
configuration takes the place of the client's own scopes, claims,
authorization settings and access token settings, that those will be
deleted, and that Require Active Status and Authorized User Group stop being
enforced, so the Named configuration's own script must enforce them if they
are still needed (cilogon/Oa4mpClient#59). On confirm the plugin sends only
the Named configuration, plus the client's callbacks and refresh token
setting, and once the server accepts it deletes the client's own scopes,
claims, authorization, access token, LDAP configuration and DynamoDB
configuration. While a Named configuration is applied the Scopes, Claims,
Access Tokens and Authorization tabs are hidden and refuse requests.

Removing a Named configuration also asks for confirmation and resets the
client to the settings a new client gets: the `openid` scope, Require Active
Status on, no claims, no JWT access tokens, and a copy of the Admin client's
default DynamoDB configuration. Nothing stored before or during the Named
configuration is revived.

A client that already had a Named configuration when this shipped has its
never-sent leftover rows deleted when any of its pages is next opened or
saved. Its access token row is the exception: it is still sent, so it stays
until the next confirmed apply or removal. Public clients never get the
Named Configuration tab and are refused; the maintainer clears any public
client that already has one by hand.

Against the remedy list below:

1. **Existing data.** Stored claims are deleted on a confirmed apply, and an
   older client's never-sent leftovers are deleted on its next page view or
   save.
2. **The clearing transition.** Removal is confirmed and resets the client to
   new-client defaults, so no stored claim can become live when the Named
   configuration is cleared.
3. **A visible state.** The Claims tab is hidden while a Named configuration
   is applied, and the client holds no claims of its own there, so the plugin
   never shows a claim that has no effect.

The cfg builder and the sync check are unchanged: the builder still never
emits a Named-configuration client's own claims or authorization args, and
the sync check still compares only scopes for such a client.
`NamedConfigClaimSyncTest`, the `cfg_shape/named_config` row of
`ClaimCfgContractTest` and `NamedConfigAuthorizationCfgTest` now lock that as
intended behavior rather than as a frozen defect.

## State before the remedy

The Claims tab was made read-only for a named-configuration client
(cilogon/Oa4mpClient#39, which closed cilogon/Oa4mpClient#28; plan
`docs/plans/2026-10-14-1946-feat-named-config-read-only-tabs-plan.md`). The
index lists the stored claims under a notice naming the named configuration and
saying they are ignored while it is applied; add, edit and delete are refused
before any server call (`ClaimsWritePathTest`). The Scopes and Authorization
tabs got the same treatment, and the client's authorization args are no longer
sent while a named configuration is applied. Against the remedy list below,
that covers point 3 (a visible state) and part of point 1 (stored claims are
kept and shown, not deleted or migrated). Point 2 is still open: removing the
named configuration sends every stored claim at once, with no review step, and
the read-only tab means stale claims cannot be pruned before removal.

The comparator and marshaller halves are unchanged and still locked with
characterization tests, so an incidental edit cannot change them unnoticed:

- `Test/Case/Model/NamedConfigClaimSyncTest.php` locks the comparator's early
  return. Its first method -- a named-configuration client carrying claims
  compared against a server representation with none -- is the one that binds:
  removing the early return turns it red. Its third method is a control on a
  client with no named configuration and cannot bind, by construction.
- `Test/Case/Model/ClaimCfgContractTest.php`, row `cfg_shape/named_config`,
  locks the marshaller's half: the named-configuration shape is returned and
  carries no claim mappings.

One thing has changed on the marshaller's side since this was written, and it
is not a remedy. The named-configuration branch now stamps the cfg capability
contract version at `metadata.Oa4mpClient.contract_version` before it returns,
so a named-configuration cfg is counted in the deployment census like every
other cfg the plugin sends. The stamp is a fact about the cfg, written after
the named configuration is merged; the branch still returns before the claim
loop, the comparator still never reads the cfg's metadata, and the claims are
as inert as they were. The row above asserts the stamp alongside the
named-configuration URL rather than dropping the whole metadata block
unexamined, and `Test/Case/Model/ContractVersionStampTest.php` covers the
stamp itself.

Both locks were held **provisionally** until the remedy above. They now
describe intended behavior: a Named configuration, not the client's own rows,
decides what the server holds.

## What a remedy has to cover

Recorded before the remedy; see Resolution for how each point is covered.

1. **Existing data, not only future input.** Gating the tab from today forward
   leaves every already-stored inert claim in place, still armed to fire when
   the named configuration is cleared. Any remedy has to say what happens to
   them -- deleted, migrated into the named configuration, retained but marked,
   or surfaced for review.
2. **The clearing transition specifically.** Even with the tab gated, clearing
   `named_config_id` remains the moment when stored claims become live. If any
   inert claim can survive the remedy, that transition needs its own review
   step.
3. **A visible state, not just a block.** A claim that exists and does nothing
   is the underlying problem. Whatever is chosen, the tab must not be able to
   show a claim that silently has no effect.

## Prevention

1. **A branch that skips work needs a gate on the input that feeds it.** Both
   early returns are individually correct; the bug lives in the gap between
   them and the tab that keeps producing data for a code path that will never
   read it. When adding a branch that makes some input irrelevant, ask what
   still lets a user create that input, and gate it in the same change.

2. **Symmetric skipping hides itself from the drift signal.** Because the
   marshaller and comparator skip claims together, the client reports in sync
   forever, and the plugin's own out-of-sync verdict -- the mechanism that
   surfaces every other discrepancy in this model -- cannot report this one.
   When both the writer and its verifier agree to ignore a field, nothing else
   is watching it.

3. **Ask what happens when a mode is turned off, not only while it is on.**
   The exemption is safe while the named configuration is set. All of the harm
   is in the transition out of it, where stored state that was inert becomes
   live in bulk. Any "this mode ignores X" branch deserves the follow-up
   question: what happens to the accumulated X when the mode ends?

4. **Attribute release is not an ordinary configuration change.** A field whose
   edit can start sending identity attributes to a relying party needs the
   change to be visible at the moment it is made. Deciding release as a side
   effect of clearing an unrelated-looking field is the shape to avoid.

5. **A guard that exists for one client type is a template, not a special
   case.** `_blockIfPublicClient()` was written because claims on a public
   client are meaningless. Claims on a named-configuration client are equally
   meaningless, and the same controller never grew the matching guard. When
   adding a guard for one variant, enumerate the other variants of the same
   field and check each.

6. **Record a deferred defect with a test, not just a note.** A comment or an
   issue does not stop the behavior from changing under a refactor. The
   characterization tests here mean that whoever moves the early return finds
   out immediately, and finds this document from the failure message.

## Related Issues

- cilogon/Oa4mpClient#28 (issue of record), fixed in part by
  cilogon/Oa4mpClient#39 (read-only Claims, Scopes and Authorization tabs).
- cilogon/Oa4mpClient#58 (replace and delete the client's own configuration
  when a Named configuration is applied) and cilogon/Oa4mpClient#59 (warn
  that Require Active Status and Authorized User Group stop being enforced),
  fixed by cilogon/Oa4mpClient#75.
- `docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md` -- its
  open question on this defect is answered by the remedy above, and unit U7 is the characterization work this document
  accompanies.
- `docs/solutions/integration-issues/oa4mp-public-client-cfg-rejected-2026-08-03.md`
  -- the public-client contract, and the controller guard that is the precedent
  a named-configuration guard would follow.
- `docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md`
  -- the opposite failure in the same pair of functions. There the marshaller
  and the comparator disagreed and produced permanent drift; here they agree
  and produce permanent silence. Both are consequences of the same one decision
  being encoded in two places.
