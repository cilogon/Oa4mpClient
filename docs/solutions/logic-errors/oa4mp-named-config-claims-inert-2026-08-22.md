---
title: Claims on a named-configuration client are silently inert and go live all at once when the named configuration is cleared
date: 2026-08-22
category: logic-errors
module: Oa4mpClient plugin (OIDC client claims, cfg marshalling, sync verification)
problem_type: logic_error
component: rails_model
severity: high
status: open
symptoms:
  - "A claim created on a client that uses a named configuration is saved, is listed on the claims tab, and is never sent to the OA4MP server"
  - "Nothing in the interface indicates the claim is inert -- the claims tab is fully available on a named-configuration client, with no banner, no gate, and no warning on save"
  - "Sync verification reports the client in sync while it carries claims the server has never seen, so the usual drift signal never fires"
  - "Clearing the named configuration activates every previously created claim at once, on the next edit, with no review step in between"
  - "The relying party begins receiving identity attributes at the moment the named configuration is cleared, and the change that released them looks like an unrelated configuration edit"
root_cause: logic_error
resolution_type: deferred_open
related_components:
  - Model/Oa4mpClientOa4mpServer.php (oa4mpMarshallCfgQdl named-configuration branch, isClientDataSynchronized named-configuration early return)
  - Controller/Oa4mpClientClaimsController.php (add, edit, delete -- guarded for public clients, ungated for named-configuration clients)
  - Test/Case/Model/NamedConfigClaimSyncTest.php (characterization lock for the comparator half)
  - Test/Case/Model/ClaimCfgContractTest.php (cfg_shape/named_config -- characterization lock for the marshaller half)
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
  - open-defect
  - deferred
---

# Claims on a named-configuration client are silently inert

**Status: OPEN. Not fixed.** This document records a defect that is understood,
characterized by tests, and deliberately left in place. The remedy is a product
decision, tracked as open question Q1 in
`docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md`.

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

## Current state

No fix. The behavior is frozen and locked with characterization tests, so an
incidental edit cannot change it unnoticed:

- `Test/Case/Model/NamedConfigClaimSyncTest.php` locks the comparator's early
  return. Its first method -- a named-configuration client carrying claims
  compared against a server representation with none -- is the one that binds:
  removing the early return turns it red. Its third method is a control on a
  client with no named configuration and cannot bind, by construction.
- `Test/Case/Model/ClaimCfgContractTest.php`, row `cfg_shape/named_config`,
  locks the marshaller's half: the named-configuration shape is returned and
  carries no claim mappings.

Both are locked **provisionally**. They describe the defect, not a decision
that the defect is correct. A Q1 remedy that changes how the marshaller or the
comparator treats named-configuration clients is expected to change these
tests; a maintainer who hits them red while fixing Q1 should update them and
this document, not work around them.

## What a remedy has to cover

Recorded here so the scope is not rediscovered from scratch:

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

- `docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md` -- Q1 is
  the open decision, and unit U7 is the characterization work this document
  accompanies.
- `docs/solutions/integration-issues/oa4mp-public-client-cfg-rejected-2026-08-03.md`
  -- the public-client contract, and the controller guard that is the precedent
  a named-configuration guard would follow.
- `docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md`
  -- the opposite failure in the same pair of functions. There the marshaller
  and the comparator disagreed and produced permanent drift; here they agree
  and produce permanent silence. Both are consequences of the same one decision
  being encoded in two places.
