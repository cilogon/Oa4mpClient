---
title: An unconditional re-decode discarded the response-encoding branch above it, so a Latin-1 client read back as modified outside the Registry
date: 2026-08-24
category: logic-errors
module: Oa4mpClient plugin (OIDC client synchronization verification, server response decoding)
problem_type: logic_error
component: rails_model
severity: medium
status: resolved
symptoms:
  - "A client whose name or comment carries an accented character reads back as out of sync, or as a check that could not complete, when the OA4MP server answers in ISO-8859-1"
  - "The same client verifies cleanly when every field is plain ASCII, so the fault looks data-dependent rather than encoding-dependent"
  - "The plugin contains an ISO-8859-1 branch that appears to handle exactly this, and it has no effect"
root_cause: logic_error
resolution_type: fixed
related_components:
  - Model/Oa4mpClientOa4mpServer.php (oa4mpVerifyClient, decodeServerResponse)
  - Test/Case/Model/ServerResponseDecodingTest.php
tags:
  - oa4mp
  - oidc
  - sync-verification
  - character-encoding
  - json-decode
  - dead-code
  - untestable-seam
---

# A re-decode below the branch discarded what the branch computed

## The shape

Code that decides something in a branch, and then unconditionally redoes it
below the branch, has no branch. The second assignment wins on every path.

This is trivial to see when the two arms compute visibly different things. It
is very hard to see when they agree on the common input -- then the dead branch
is not merely harmless-looking, it is genuinely harmless right up until the day
the uncommon input arrives.

## The instance

`oa4mpVerifyClient()` fetched the OA4MP server's representation of a client and
decoded it:

```php
$contentType = $response->getHeader('Content-Type');

if(str_contains($contentType, 'ISO-8859-1')) {
  $oa4mpObject = json_decode(mb_convert_encoding($response->body(), 'UTF-8', 'ISO-8859-1'), true);
} else {
  $oa4mpObject = json_decode($response->body(), true);
}

$oa4mpObject = json_decode($response->body(), true);   // <- discards the above
```

`json_decode()` accepts only UTF-8 and returns `null` on anything else. So when
the server answered in ISO-8859-1 **and** the body carried a byte above 0x7F --
an accented character in a client name or comment -- the transcoded value was
computed correctly, thrown away, and replaced by `null`.

That `null` went into the comparison, which unmarshalled it. It either raised
(reported, after 2026-08-24, as a check that could not complete) or compared as
a difference and told the user their client had been modified outside the
Registry. Neither was true. The server had simply answered in Latin-1.

Blame says the branch and the stray line arrived in the same commit
(`c8d29ad8`, 2026-01-13) -- the shape of an edit that added the branch above an
existing decode and never deleted the original. It survived seven months.

## Why it survived

Two reasons, and the second is the one worth keeping.

**The arms agree on ASCII.** Every client whose fields are plain ASCII decodes
identically either way. The defect was invisible to every real client until an
accented one appeared on a Latin-1 server.

**No test could reach it.** The decode sat inside `oa4mpVerifyClient()`, which
constructs its own `HttpSocket` and reaches a real server. The hermetic tier
cannot make an HTTP request, so no test could observe which branch a given
response selected. This is the same wall that hid the misleading-failure defect
in `compareToServerObject()`, and it was fixed the same way: split the decision
into `decodeServerResponse()`, a seam a test subclass can call directly.

## The fix

`decodeServerResponse()` now owns the decision and returns from each arm, so
there is no line below the branch to discard anything. `getHeader()` is cast to
string, since it returns `false` for a header the response does not carry.

## Prevention

1. **A branch whose arms both assign to one variable should return, not
   assign.** Returning makes a stray line below it unreachable code rather than
   a silent override. This is the mechanical form of the whole defect.
2. **When two arms agree on the common input, the uncommon input is the only
   test that matters.** An ASCII-only fixture cannot distinguish these two
   implementations. The regression test carries a real byte above 0x7F, and it
   asserts the fixture carries one -- because the first version of that test
   did not, and passed against the defect.
3. **A decision buried behind a socket is a decision nobody can test.** When a
   method mixes I/O with a judgment, split the judgment out at the moment you
   need to reason about it, not after it breaks. Two defects in this one method
   have now been found this way.

## What this does not cover

`oa4mpNewClient()` decodes the create response with no encoding handling at
all. It reads only `client_id` and the secret, which are ASCII in practice, so
it is left alone -- but it is the same method shape and would fail the same
way on a non-ASCII field.
