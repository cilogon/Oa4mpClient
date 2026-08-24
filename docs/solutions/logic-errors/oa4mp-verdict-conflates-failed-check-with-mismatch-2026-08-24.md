---
title: A boolean that means both "the answer is no" and "I could not compute an answer" is read as the more alarming one
date: 2026-08-24
category: logic-errors
module: Oa4mpClient plugin (OIDC client synchronization verification, controller pre-flight guards)
problem_type: logic_error
component: rails_model
severity: high
status: resolved
symptoms:
  - "An unreadable or malformed cfg_contract.json bounces every user off every claims tab, callback list and scope page, for every client"
  - "The message they are shown says the client has been modified outside the Registry and asks them to email support"
  - "Support is sent after client tampering that never happened, while the actual fault is a deployment file"
  - "The failure is deployment-wide but presents as client-specific, so nobody suspects the deploy"
root_cause: logic_error
resolution_type: fixed
related_components:
  - Model/Oa4mpClientOa4mpServer.php (oa4mpVerifyClient, compareToServerObject, verdictFromComparison)
  - Controller/ (thirteen pre-flight guards across nine controllers)
  - Lib/lang.php (er.bad_client, er.verify_failed)
  - Test/Case/Controller/PreflightVerdictTest.php
  - Test/Case/Model/UnmarshallFailureDiagnosticsTest.php
tags:
  - oa4mp
  - oidc
  - sync-verification
  - error-handling
  - conflated-verdict
  - two-valued-return
  - user-facing-message
  - guard
---

# A verdict that means two things is read as the more alarming one

## The shape

A check returns a boolean. The `false` branch has a message attached to it.
Then a failure inside the check itself is caught, and the catch leaves the
verdict at its initial `false`.

From that moment the boolean means two different things -- "I ran and the
answer is no" and "I could not run" -- and every caller reads it as whichever
one the message names. The message names the alarming one, because that is the
case the author was thinking about when they wrote it.

This is the general shape, and it is worth naming separately from this
instance: **a boolean that conflates "the answer is no" with "I could not
compute an answer" is a defect whenever the two have different remedies.**
Here one remedy is "email support about your client" and the other is "fix the
deploy" -- as far apart as two remedies get.

## The instance

`oa4mpVerifyClient()` compares a client against the OA4MP server's
representation of it. Thirteen guards across nine controllers call it at the
top of an action and, on a false verdict, flash
`pl.oa4mp_client_co_oidc_client.er.bad_client` -- "This client has been
modified outside of the Registry. Please email help@cilogon.org for
assistance." -- and redirect away.

The cfg capability contract made the second reading routinely reachable: the
contract is read while unmarshalling, so an unreadable or malformed
`cfg_contract.json` raises for every client on the tier. A bad deploy therefore
presented as thirteen different pages each accusing the user's client of
tampering.

The signal to tell them apart already existed. `compareToServerObject()` had
returned an `error` key since the contract work, and `oa4mpEditClient()` -- the
write path -- already read it. What had not happened was surfacing it to the
guards, which are the path a user actually meets: they run on the GET that
renders a form or a list, while the write path only covers the narrow window
between a form rendering and its submission.

## The fix

- The bare two-argument form of `oa4mpVerifyClient()` gained a third state.
  `null` is neither boolean and is falsy, so a caller that never tests for it
  fails closed rather than rendering the page as though the client were in
  sync. A caller that wants the distinction tests `=== null` first.
- A distinct message, `er.verify_failed`, says the Registry could not verify
  the client. It is a fixed sentence: the exception's class, file and line
  already go to the log, and none of that belongs in a flash. It keeps a
  support route, because a blocked user still needs one.
- Each guard tests the error key before the synchronized check, and logs the
  client and the guarded action on that branch -- the only thing that separates
  a deployment-wide fault from a client-specific one, since the user-facing
  message is deliberately the same for both.
- `er.bad_client` is unchanged. It is correct for the case it names.

## Prevention

1. **When a catch leaves a verdict at its default, the verdict has stopped
   being a verdict.** Either the failure gets its own value in the return, or
   the catch must not be reachable. Setting a flag beside the boolean and not
   reading it at the call sites is the state this defect lived in for one
   release.
2. **Ask what each branch of a user-facing message asserts about the user.**
   "Your client was modified outside the Registry" is an accusation. A message
   that can fire for a reason that has nothing to do with the user is the
   wrong message, however rare that reason looks at the time.
3. **Fixing one caller is not fixing the signal.** The write path was corrected
   first and the thirteen read-path guards were left reading the old
   conflation, which is how a fixed defect kept shipping. When a signal is
   wrong, enumerate its callers.
4. **Lock the shape, not just the case.** `PreflightVerdictTest` scans every
   controller and reddens if a guard reaches for the tampering message without
   testing the error key first, so a guard added later cannot silently
   reintroduce the conflation.

## What this does not cover

Failures that occur before the comparison is reached -- an unreachable OA4MP
server, a non-JSON or undecodable response body -- still produce a false
verdict with no error set, and so still reach `er.bad_client`. That is the same
shape one layer up, and it remains open.
