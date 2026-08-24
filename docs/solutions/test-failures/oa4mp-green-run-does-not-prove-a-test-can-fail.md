---
title: "A green run cannot tell a real test from one that is structurally unable to fail"
date: 2026-08-24
category: test-failures
module: Oa4mpClient plugin (regression test authoring, red-proof discipline)
problem_type: test_failure
component: testing_framework
symptoms:
  - "A source-scan test asserted only that a read of the error key preceded the tampering-message string in each guard's source region, so a guard that read the key and still flashed the tampering message on the failed branch satisfied the ordering check and passed"
  - "A regression fixture built its Latin-1 test body with json_encode(), which escapes non-ASCII by default, so the fixture carried no byte above 0x7F and the fixed and unfixed decode implementations agreed on it"
  - "Both artifacts passed in a green run against the exact defect they were written to catch, with nothing in the passing output distinguishing them from a real test"
root_cause: missing_validation
resolution_type: test_fix
severity: high
related_components:
  - Test/Case/Controller/PreflightVerdictTest.php
  - Test/Case/Model/ServerResponseDecodingTest.php
  - "Test/README.md:234-237 (mandatory red-proof step)"
tags: [test-authoring, red-proof, false-positive-coverage, source-scan-test, fixture-construction, json-encode, code-review, regression-test]
---

# A test that cannot fail is indistinguishable from a test that passes

## Problem

Two regression artifacts written in one session (2026-08-24) were structurally
incapable of failing against the defect each existed to catch. Both sat in a
green run and looked like ordinary coverage. Neither had a symptom: a test that
cannot fail and a test that passes emit the same bytes.

This is the sibling case of
`docs/solutions/test-failures/oa4mp-test-runner-silent-pass-count-gate.md`,
which states the principle for merge gates -- *a gate's disarmed state must be
distinguishable from its passing state*. The new angle here is that the same
property is required of **ordinary tests**, not only of gates, and that the
repo already owns the operational check for it: the mandatory red proof at
`Test/README.md:234-237`.

The two underlying bugs are documented separately and are not restated here:

- `docs/solutions/logic-errors/oa4mp-verdict-conflates-failed-check-with-mismatch-2026-08-24.md`
- `docs/solutions/logic-errors/oa4mp-verify-response-encoding-discarded-2026-08-24.md`

This learning is about the *verification artifacts* for those two fixes.

## Instance 1 -- the structural lock that checked word order

`Test/Case/Controller/PreflightVerdictTest.php:201` --
`testNoGuardFlashesTheTamperingMessageWithoutFirstTestingTheErrorKey`. It
carries real weight: only two of the thirteen pre-flight verify call sites are
reachable by the hermetic claims harness (the file's own header says so,
`PreflightVerdictTest.php:16-26`), so the other eleven guards are covered by
this source scan and nothing else.

The first version scanned each controller source file, cut it into regions
between successive verify call sites, and asserted, for any region mentioning
`er.bad_client`, only this:

```php
$bad    = strpos($region, 'er.bad_client');
$tested = strpos($region, "\$verifyResult['error']");
$this->assertTrue($tested !== false && $tested < $bad, ...);
```

That is an ordering assertion over source text. A guard that *reads* the error
key and then ignores it -- flashing the tampering message on the failed-check
branch anyway -- satisfies it. It also counted sites with the bare substring
`oa4mpVerifyClient(`, which a comment or docblock mentioning the method
satisfies, so the site-count floor could be held up by prose after a guard was
deleted.

The current version keeps the ordering assertion and adds two more per region,
plus a stricter site match (`PreflightVerdictTest.php:212-256`):

```php
// '->oa4mpVerifyClient(' -- the call, not the name.
while (($at = strpos($source, '->oa4mpVerifyClient(', $at)) !== false) { ... }

$failedMessage = strpos($region, 'er.verify_failed');
$this->assertTrue($failedMessage !== false && $failedMessage < $bad, ...);

$this->assertTrue(strpos($region, '$verifyFailed') !== false, ...);
```

with the floor at `$sites >= 13` (`PreflightVerdictTest.php:260`). The flag the
third assertion requires is the one production guards actually branch on --
`Controller/Oa4mpClientClaimsController.php:187-195` sets `$verifyFailed` from
`!empty($verifyResult['error'])` and selects the message with it.

**Found by:** the in-process testing reviewer and, independently, the
cross-model adversarial pass (Codex) during `ce-code-review`; the two findings
merged into one. Not found by the author, and not by any mechanical check --
the weak assertion passes and the strong one passes, on correct code, alike.

## Instance 2 -- the fixture that carried no test data

`Test/Case/Model/ServerResponseDecodingTest.php:121` --
`testALatin1ResponseDecodesRatherThanCollapsingToNull`, the regression test for
the discarded encoding branch at
`Model/Oa4mpClientOa4mpServer.php:2822-2832`, where a body declaring
`ISO-8859-1` is transcoded before `json_decode()` and everything else is
decoded raw.

The first version built the body with `json_encode(array(...))` and no flags.
`json_encode()` escapes non-ASCII to an ASCII `\uXXXX` sequence by default, so
the "Latin-1" fixture contained no byte above `0x7F`. Both the fixed and the
unfixed implementation agree on an ASCII body -- that agreement is precisely
why the underlying bug hid from 2026-01-13 to 2026-08-24, and the test file
says so in its own control case (`ServerResponseDecodingTest.php:141-161`). The
test therefore passed against the defect it existed to catch.

The fix is two-part. The fixture now forces a real non-ASCII byte
(`ServerResponseDecodingTest.php:92-107`):

```php
private function utf8Body() {
  return json_encode(array('client_id' => 'cilogon:/client_id/abc',
                           'client_name' => $this->accentedName()),
                     JSON_UNESCAPED_UNICODE);
}
private function latin1Body() {
  return mb_convert_encoding($this->utf8Body(), 'ISO-8859-1', 'UTF-8');
}
```

and the test asserts its own fixture before asserting anything about the code
(`ServerResponseDecodingTest.php:126-130`):

```php
$this->assertTrue(preg_match('/[\x80-\xFF]/', $this->latin1Body()) === 1,
  'the fixture body actually carries a non-ASCII byte, without which this'
  . ' test cannot fail against the defect');
```

**Found by:** the repo's mandatory red-proof step. Reinstating the buggy decode
produced **0 failures** -- which exposed the test, not the code. Review had
already looked at this file and passed it.

## Why This Works -- and why one mechanism could not have covered both

The two instances failed the same property for different reasons, and were
caught by mechanisms that do not substitute for each other.

- Instance 1 asserted the **wrong property**. It ran, it could fail, and it did
  fail on some broken inputs -- just not on the input that matters. A red proof
  against the actual pre-fix code would have gone red, because the pre-fix
  guards had no error-key read at all: the ordering assertion catches *that*
  shape fine. What it misses is a *different* wrong implementation nobody wrote
  yet, which is exactly what a structural lock is for. Only a reader reasoning
  about what the assertion permits finds that. Review found it; mechanism could
  not.
- Instance 2 asserted the **right property against empty data**. No amount of
  reading the assertions reveals it, because the assertions are correct -- the
  defect is one flag missing from a helper twenty lines away. Only executing
  the test against the broken code reveals it. The red proof found it; review
  had looked and passed.

So: **the red proof is what catches a test that cannot fail. Review is what
catches a test that checks the wrong property.** Running both is not
belt-and-braces; each is the only instrument for its own failure mode.

Underneath, this extends the count-gate learning one step. That doc's rule was
about gates: when a gate's answer is a verdict rather than a measurement, ask
what it prints when it is doing nothing. A regression test is a gate too -- a
one-bit gate on one behavior -- and its disarmed states are the same family:
data that exercises no branch (instance 2), an assertion that admits the defect
(instance 1), a fixture that constructs the wrong thing. In all of them "I
checked and it is fine" and "I checked nothing" are the same green line.

The count-gate work had already reached the same idea from the other end, and
its phrasing is the sharpest this repo has produced. Building the test that
locks `min_tests_run`, the count was deliberately derived by scanning source
for `public function test*` rather than by calling `get_class_methods()` --
because `get_class_methods()` is the very mechanism whose silent subtraction
the gate exists to catch, so a test counting with it "would agree with the
runner by construction and prove nothing." **Independent derivation** is the
generalization: a check must not compute its expectation through the machinery
it is checking. Instance 2 is that rule violated at the data layer -- the
fixture was built by the same escaping the code under test would have to
survive. (session history)

That work also supplies the precedent for expecting a partial defect after a
total one is closed. Commit `07f448f` closed zero-discovery (an empty
`Test/Case` exits 1, a class-name mismatch fails loudly), and the partial case
-- a suite that loses four tests and still passes -- stayed open until
`0dd72aa`. A test that cannot fail is the partial case of a test that does not
run. (session history)

## Prevention

**1. Never write a regression test without executing the red proof, even when
the assertion is obviously right.** Instance 2's assertions were correct and
its fixture was empty; nothing short of running it against the bug would have
shown that. `Test/README.md:234-237` already requires this -- reinstate the
pre-fix path, confirm the new test *and only it* fails, restore, and say so in
the commit message. Treat "0 failures" from a red proof as a **failed proof**,
not as a surprising pass.

**2. Assert the fixture before asserting the code, whenever the fixture is
constructed rather than literal.** The `preg_match('/[\x80-\xFF]/', ...)`
guard at `ServerResponseDecodingTest.php:126-130` is the shape: state the
property the test data must have for the test to be capable of failing, and
assert it first. Encoders that silently normalize -- `json_encode()` escaping
non-ASCII, an ORM coercing a type, a serializer dropping a null -- are where
this bites.

**3. Derive the expectation independently of the machinery under test.** A
check that computes its expected value through the same mechanism it is
checking agrees with it by construction. This is the count gate's rule
(source-grep for `test*` rather than `get_class_methods()`) restated at the
data layer: instance 2's fixture was produced by the same encoder whose
behavior the test was meant to survive. (session history)

**4. For a structural or source-scanning lock, write down the wrong
implementation that would still pass, then close it.** Ask it of every
assertion, not of the test as a whole. Instance 1's ordering check was
satisfied by a dead read; the fix was to require the *effect*
(`er.verify_failed` before `er.bad_client`) and the *mechanism*
(`$verifyFailed` present), not just the presence and order of a symbol.

**5. Match the call, not the name.** Any scan whose floor rests on a substring
count must match something a comment cannot produce. `oa4mpVerifyClient(`
appears in prose; `->oa4mpVerifyClient(` does not. A count floor satisfiable by
a docblock is a floor a deletion can step over.

**6. A source scan needs a floor on how much it found, and the floor needs the
same scrutiny as the assertions.** `$sites >= 13`
(`PreflightVerdictTest.php:260`) is the direct descendant of `min_tests_run` in
`Test/run.sh`, and inherits its rules: a floor, not an equality; raised
deliberately as guards are added; never lowered to make a red run green.

**7. Cross-model adversarial review earns its cost on assertion strength
specifically.** Instance 1 was found by two reviewers independently and by no
mechanism. That is the class of finding to spend a second model on -- not
"is this correct", but "what would still pass this".

## Verification

All claims below were re-checked against the current tree (HEAD `9a30adb`).

- **Instance 1's fix works, and the original did not.** Simulating the scan
  logic on a synthetic guard region containing a dead
  `$verifyResult['error']` read followed by an unconditional `er.bad_client`
  flash: the original ordering-only assertion evaluates PASS, while both new
  assertions (`er.verify_failed` before `er.bad_client`; `$verifyFailed`
  present) evaluate FAIL. On a comment reading
  `// see oa4mpVerifyClient( in the model`, the bare substring matches and
  `->oa4mpVerifyClient(` does not. Run at PHP 8.4 in this checkout against the
  assertion text now at `PreflightVerdictTest.php:227-255`. The stronger
  claim reported during the session -- that one real guard was temporarily
  edited to read the key and still flash unconditionally, and the strengthened
  scan caught it -- comes from the session record and was not re-executed here.
- **Instance 2's guard is in place and the encoding is real.** The fixture
  passes `JSON_UNESCAPED_UNICODE` (`ServerResponseDecodingTest.php:98-102`),
  transcodes to ISO-8859-1 (`:105-107`), and the non-ASCII guard runs before
  any other assertion in the test (`:126-130`). The decode it exercises is
  `Model/Oa4mpClientOa4mpServer.php:2822-2832`, whose docblock (`:2811-2814`)
  names the unconditional re-decode that sat below the branch from 2026-01-13
  to 2026-08-24. The red-proof result (reinstating the pre-fix decode fails
  exactly that one test) is reported from the session; it was not re-run while
  writing this.
- **The red-proof rule exists as cited.** `Test/README.md:234-237`.
- **Merge state.** Both fixes are merged and reachable from HEAD:
  `git log --merges` shows `69272ef Merge pull request #19` and
  `9a30adb Merge pull request #20`, the latter being HEAD.

### A citation trap in this repo

Cite **cilogon/Oa4mpClient#19** (pre-flight verdict) and
**cilogon/Oa4mpClient#20** (response encoding) -- the upstream numbers, which
are the ones the merge commit subjects carry. The fork's own pull request
numbers for this work were #17 and #18, which are *not* the landing record and
collide confusingly with unrelated upstream PRs of the same numbers already in
this history (`32884c6 Merge pull request #17 from skoranda/docs/changelog-7.0.0-rc6`,
`3cdc666 Merge pull request #18 from skoranda/feat/cfg-qdl-contract`). Read the
landing number out of `git log --merges main`, never out of the fork. See
`docs/solutions/conventions/oa4mp-fork-pr-is-never-the-landing-record-2026-08-22.md`.

## Related Issues

- `docs/solutions/test-failures/oa4mp-test-runner-silent-pass-count-gate.md` --
  the nearest sibling and the doc this one extends. It establishes "a gate's
  disarmed state must be distinguishable from its passing state" for merge
  gates; this learning carries the property down to individual tests and names
  the red proof as the operational check.
- `docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md`
  -- the same family at the tool-configuration layer: a scan that exits 0 while
  its rule set is disarmed.
- `docs/solutions/logic-errors/oa4mp-verdict-conflates-failed-check-with-mismatch-2026-08-24.md`
  -- the bug instance 1's lock protects. Its Prevention item 4 describes that
  lock as reddening when a guard skips the error key; true of the current tree,
  and true only after the strengthening documented here.
- `docs/solutions/logic-errors/oa4mp-verify-response-encoding-discarded-2026-08-24.md`
  -- the bug instance 2 regresses. Its Prevention item 2 states the
  fixture-quality lesson in instance-specific terms; this doc is its
  generalization.
- `Test/README.md` -- the compounding norm and the red-proof rule (`:234-237`).
- `CONCEPTS.md` -- **Silent pass** and **Verified red** are the vocabulary this
  learning instantiates at the level of a single test.
- No related GitHub issues: four searches against the tracker returned nothing;
  it carries only the long-closed RFC 7591/7592 migration issue.
