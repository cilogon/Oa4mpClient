---
title: A configured claim named like the DynamoDB key claim refused every user at the token step
date: 2026-11-11
category: logic-errors
module: dynamodb_claims.qdl (OA4MP server, cilogon-service-config-us) and the Oa4mpClient Claims tab
problem_type: logic_error
component: qdl_script
symptoms:
  - "The token request failed with qdl_error 'User has no CO Person record' for a client whose sub claim was configured from an Identifier"
  - "The same user was found at post_auth and not found at post_token"
  - "Server logs showed the partition key built from the configured value (an ePPN) instead of the CILogon subject"
root_cause: logic_error
resolution_type: code_fix
severity: high
tags: [qdl, dynamodb, partition-key, claims, exec-phase, sub, oa4mp, claim-persistence]
---

# A configured claim named like the DynamoDB key claim refused every user at the token step

## Problem

cilogon/Oa4mpClient#67. A subscriber configured a claim named `sub`, sourced
from the CO Person's ePPN identifier. Every user of that client was then
refused at the token endpoint with "User has no CO Person record". The client
was asking for something supported (populate `sub` from the Registry), not
misconfigured.

## Root cause

`dynamodb_claims.qdl` finds the user's DynamoDB record by a key built from the
claim the DynamoDB configuration names in `partition_key_claim_name`
(conventionally `sub`). The plugin runs the script at `post_auth`,
`post_token`, `post_refresh` and `post_user_info`.

The script reads the key claim, looks the user up, then writes the configured
claims. At `post_auth` the key claim is still the authenticated subject, so
the lookup works, and then the configured `sub` overwrites it with the ePPN.
OA4MP saves the claim set at the end of `post_auth`, and every later phase
starts from that saved copy, not from the authenticated username. So at
`post_token` the script built the key from the ePPN and found nothing.

**A claim a QDL script writes at `post_auth` is the next phase's input.** Any
value the script later reads back, especially one it looks a user up by, must
not be overwritten by the same script.

## Why the obvious fixes do not work

- **Refuse the claim in the plugin.** That forbids the supported case.
- **Read the original subject from somewhere else.** OA4MP does not expose the
  transaction username to scripts. It never saves `xas.` back from a script,
  and `xas.` is client-supplied request input anyway, so it must not hold a
  lookup key. `proxy_claims.` is empty without proxying.
- **Stash it in the access-token payload.** Possible in principle with an extra
  access-token handler running at `pre_auth`, but it changes the cfg contract
  and is untested.

Of what a script can write, only claims survive across phases. The fix
therefore keeps the original value in a claim: a released copy claim.

## Solution

In `dynamodb_claims.qdl` (us-east-2-dev, delivered as a drop-in):

1. A client has a collision when one of its configured claim mappings has the
   same name as its `partition_key_claim_name`.
2. For such a client the script sets `cilogon_<key claim>` from the key claim
   before the lookup. At `post_auth` it always does so, replacing any existing
   value, because only that phase starts from the authenticated subject. In
   later phases it does so only when the copy is missing, which covers flows
   that began before the claim was configured.
3. It builds the key from the copy.
4. It skips any configured claim named `cilogon_<key claim>`, so configuration
   can never overwrite the copy.

Clients without a collision are unchanged, so the change is additive for every
deployed plugin version.

In the plugin, the Claims tab refuses a claim named `cilogon_<key claim>`
before the OA4MP write. The key claim comes from the same resolver the cfg
builder uses, so the plugin and the script agree. The form also warns and
disables Save while the reserved name is typed.

Accepted behavior:

- The copy claim is released like any other non-standard claim. A
  strict-scopes client receives it only with `org.cilogon.userinfo`.
- Deleting the colliding claim refuses users in flows that began while it
  existed, until they log in again.

## Testing the QDL locally

`roles/oa4mp-server/tests/qdl/test_partition_key_copy.qdl` lifts the code
between `// BEGIN partition key copy` / `// END partition key copy` and
`// BEGIN claim dispatch` / `// END claim dispatch` and runs it under
`/opt/qdl/bin/qdl-run`, in the manner of `test_authorization_gates.qdl`.

**The local `qdl-run` scopes functions differently from OA4MP's embedded
interpreter.** Under `qdl-run`:

- a `define[]` body cannot read script-level variables, and writing a stem
  inside one changes a local copy;
- a variable first assigned inside an `if[]` block is local to that block;
- an inline lambda, such as an extraction filter, does see the enclosing
  variables.

The production handlers rely on OA4MP behaving otherwise. Logic a local test
must prove therefore belongs at the top level of the script, assigns new
variables outside `if[]` blocks, and is lifted into a wrapper function that
takes its inputs as parameters.

The harness was checked red three ways: against the unmodified script (markers
missing), against the old lookup with markers added (nine collision cases
fail, no-collision cases pass), and against two single-line mutations.

## Prevention

- When a script both reads a claim and may write a claim of the same name,
  decide which value later phases must see, and keep that value under a name
  configuration cannot write.
- Remember that `post_auth` is the only phase that starts from the
  authenticated subject. Every later phase starts from what `post_auth` saved.
- Fixture QDL tests with the types OA4MP supplies (strings for claim values
  and `exec_phase`). See
  `docs/solutions/integration-issues/oa4mp-group-gate-string-group-id-never-matches.md`.
- On a server-first write path, a new refusal must run before the server call.
  See `docs/solutions/logic-errors/oa4mp-url-whitespace-server-first-write-drift-2026-11-02.md`.
