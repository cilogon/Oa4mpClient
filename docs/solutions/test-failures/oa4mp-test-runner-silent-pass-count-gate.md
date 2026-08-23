---
title: "Exit status and the ALL_TESTS_PASSED sentinel proved the runner finished, not that discovery found the suite"
date: 2026-08-23
category: test-failures
module: Oa4mpClient plugin (hermetic test suite gating)
problem_type: test_failure
component: testing_framework
symptoms:
  - "A test* method that acquires a private keyword drops out of get_class_methods() and is never run, while the suite still exits 0 and prints ALL_TESTS_PASSED"
  - "A file renamed off the recursive *Test.php glob drops out of discovery with no runner-side signal"
  - "The ALL_TESTS_PASSED sentinel was matched anywhere in the log, so a test that echoes run.sh's own text can manufacture a pass"
  - "The hermetic CI job finishes in under a minute against a suite that takes several minutes locally, with exit 0 and the sentinel present"
root_cause: missing_validation
resolution_type: code_fix
severity: high
related_components:
  - Test/run.sh
  - Console/Command/Oa4mpTestShell.php
  - ".github/workflows/hermetic-tests.yml"
tags: [test-runner, silent-pass, test-count-gate, sentinel-matching, hermetic-tests, ci, discovery]
---

# Exit status and the ALL_TESTS_PASSED sentinel proved the runner finished, not that discovery found the suite

## Problem

The plugin's merge gate could report success while some or all of its tests had silently
dropped out of the run. Both gates `Test/run.sh` applied -- a zero exit status and a grep
for the runner's `ALL_TESTS_PASSED` sentinel -- proved only that the runner finished the
tests *discovery handed it*, never that discovery found the suite, so a regression test
could be retired from the effective suite with no signal anywhere.

## Symptoms

The whole difficulty is that there is nothing to see. A silent pass is not a degraded
green; it is an ordinary green.

A note on the citations below. Any line reference prefixed `0dd72aa^` points at the commit
*before* the fix, so those lines no longer exist in the working tree -- read them with
`git show 0dd72aa^:Test/run.sh`. Line references with no such prefix are the current tree.

- `Test/run.sh` exits 0. Its last line reads `==> Suite passed (ALL_TESTS_PASSED).` --
  byte-identical to the line a full run prints (`0dd72aa^:Test/run.sh:86`).
- The GitHub Actions `Hermetic suite` job concludes `success` and blocks nothing. Past the
  checkout, its only step is `Test/run.sh` (`.github/workflows/hermetic-tests.yml:39-44`), so
  the job's verdict is exactly the script's verdict.
- The runner itself prints its sentinel as usual: `Oa4mpTestShell.php:109` emits
  `ALL_TESTS_PASSED` whenever `$failed === 0`, and a test that never ran cannot fail.
- The only place the loss is visible at all is one line of log body -- the
  `%d tests run, %d failed.` summary at `Oa4mpTestShell.php:94` -- which nothing was reading.
  A run of 12 tests and a run of 159 differ in that line and in nothing a gate looked at.

The near miss that prompted the check was a timing smell, not a failure. The hermetic CI job
finishes in well under a minute -- the run on `main` immediately before the fix took 46
seconds -- against a local suite that takes several minutes. Reading the actual CI log
settled it: the log said `159 tests run, 0 failed.`, so CI was in fact running everything,
and the workflow wiring supports that (it fires on every `pull_request` with no path filter,
`.github/workflows/hermetic-tests.yml:13-17`, and discovery is a recursive glob, so new test
files are picked up by existing ones without a workflow edit). The point is not that CI was
broken. It is that a sub-minute green was **indistinguishable from the silent-pass shape**,
and only the count in the log could tell the two apart.

## What Didn't Work

Two gates were already in place, and the pre-fix comment above them stated their division of
labour honestly -- `# Two independent gates, because neither is sufficient alone:`
(`0dd72aa^:Test/run.sh:59`). Both were sound about the failure they were designed for. Both
were trusted for a proposition neither could support.

**Gate 1: the exec exit status** (`0dd72aa^:Test/run.sh:70-78`). What it proves: no test
raised a failing assertion that reached the runner's `_stop(1)`. What it cannot prove: that
the process ran to the end. The script's own comment says why -- a test that reaches
`exit(0)`, its own or one inside the code under test such as `Controller::redirect()`'s
`_stop()`, ends the whole process mid-run with a success status, and "a run that stopped
after three of a hundred tests is indistinguishable from a completed one."

**Gate 2: the sentinel grep** (`0dd72aa^:Test/run.sh:80-84`):

```bash
if ! grep -q 'ALL_TESTS_PASSED' "$suite_log"; then
  echo "==> ERROR: the suite exited 0 but never printed the runner's" \
    "ALL_TESTS_PASSED sentinel, so it ended early rather than passing." >&2
  exit 1
fi
```

This closes gate 1's hole mechanically: the sentinel is printed at `Oa4mpTestShell.php:109`,
past the failure check, so a mid-run `exit(0)` cannot reach it. But read what the sentinel
actually certifies. The runner counts `$total` inside the method loop at
`Oa4mpTestShell.php:71-75`, prints its summary, and emits the sentinel. Every one of those
numbers is downstream of discovery. **`ALL_TESTS_PASSED` means "everything I was given
passed", and says nothing about what I was given.**

Two ordinary edits shrink what discovery gives it, both silently:

- *A `test*` method acquires a `private` keyword.* The runner enumerates methods with
  `get_class_methods($case)` (`Oa4mpTestShell.php:71`), called from `Oa4mpTestShell`, an
  unrelated class -- so it returns public methods only. Confirmed directly on this stack
  (PHP 8.4.24): a class with `testA` public, `testB` private, `testC` protected, `testD`
  public yields exactly `[testA, testD]`. The private and protected methods do not error,
  do not warn, and are not counted; they simply are not there.
- *A file is renamed off the glob.* `_discover()` globs `*Test.php` and recurses
  (`Oa4mpTestShell.php:117-127`). A file renamed to anything else -- or moved outside
  `Test/Case` -- is not scanned, not loaded, and not reported.

The runner does defend the *zero* case, twice: an empty discovery exits 1 with
`No test cases found.` (`Oa4mpTestShell.php:48-53`), and a run that executes no method exits
1 with `No tests were executed.` (`:101-104`). It also refuses to skip a file whose class
name does not match the filename, failing it instead (`:62-69`). Those defences were added
deliberately, under adversarial review, when the suite was first built -- commit `07f448f`
("close silent-pass holes in the gate") made zero-discovery exit non-zero where it had
exited 0, and made a class-name mismatch fail rather than skip in silence.

**That is exactly why this bug is interesting: the zero case was already guarded, and the
partial case was not.** What the runner has nowhere is a floor *above* zero. Losing four
tests, or forty, or all but one, is a clean pass on both gates. (The fix's own comment says
"the runner's only floor of its own is `$total === 0`"; strictly there are two zero-floors,
not one, but both are zero-floors, so the argument stands unchanged.)

**A third weakness in gate 2, in the same area.** The grep matched the sentinel *anywhere in
the log*, as an unanchored substring. That became forgeable the moment the suite grew a test
that reads `Test/run.sh` and asserts on its text --
`ClaimsControllerHarnessTest::testRunShRequiresTheAllTestsPassedSentinel`
(`Test/Case/Controller/ClaimsControllerHarnessTest.php:325-356`) does exactly that,
asserting the script contains `ALL_TESTS_PASSED` and `grep -q`. The literal now lives in the
suite's own test data, so a failing assertion that dumped its haystack -- or any test that
echoed the file -- would put the sentinel in the log without the runner ever reaching it.

**A stricter-looking check that would have been worse.** Requiring the sentinel to be the
log's *last line* was considered and rejected: CakePHP shutdown warnings sometimes flush
after the sentinel, so that check would have been flaky -- stricter in appearance and less
reliable in practice than the one shipped. (session history)

## Solution

Commit `0dd72aa`, "fix(test): require a plausible test count, not just a clean finish",
touching `Test/run.sh` only (+52/-5). It landed on `main` in **cilogon/Oa4mpClient#11**,
merged 2026-08-23 (merge commit `ce6ef88`); local `main` equals `upstream/main` and `0dd72aa`
is reachable from it, so this is the current behaviour of the tree, not a proposal. It arose
from a reviewer finding on the claims-regression-coverage plan -- "nothing makes
`Test/run.sh` require the all-passed sentinel, so a stray exit still greens CI" -- rather
than from a planned requirement. (session history)

The header comment was rewritten to name the third proposition (`Test/run.sh:59-65`):

```bash
# Three independent gates, because none is sufficient alone:
#
#   1. The exec exit status, which catches a failing assertion.
#   2. The runner's ALL_TESTS_PASSED sentinel, which it prints only after every
#      discovered test has run and passed.
#   3. A floor on how many tests actually ran, because 2 says nothing about how
#      many tests discovery found in the first place.
```

### Anchoring the sentinel to the runner's verdict block

Instead of grepping the whole log, the script first cuts the log down to the runner's
verdict -- everything from the `N tests run, M failed.` summary onward -- and reads both
remaining gates out of that slice (`Test/run.sh:92-101`):

```bash
suite_tail="$(tr -d '\r' < "$suite_log" \
  | sed -n '/^[0-9][0-9]* tests run, [0-9][0-9]* failed\.$/,$p')"

# Gate 2: the sentinel as a line of its own inside that block, which the runner
# prints only after every discovered test has run and passed.
if ! grep -q '^ALL_TESTS_PASSED$' <<< "$suite_tail"; then
  echo "==> ERROR: the suite exited 0 but never reached the runner's" \
    "ALL_TESTS_PASSED verdict, so it ended early rather than passing." >&2
  exit 1
fi
```

Three deliberate choices here. The match is **line-anchored** (`^...$`), so the string
embedded in a quoted line does not count. The haystack is the **verdict block**, which no
test can write into: `Oa4mpTestShell.php:93-97` prints the summary only after the file loop
has finished, so nothing a test emits can land after it. And the block is anchored on the
**summary rather than the last line**, for the shutdown-warning reason above.

### The count gate

```bash
min_tests_run=157
tests_run="$(sed -n 's/^\([0-9][0-9]*\) tests run, [0-9][0-9]* failed\.$/\1/p' \
  <<< "$suite_tail" | head -n 1)"
if [ -z "$tests_run" ]; then
  echo "==> ERROR: the suite printed no 'N tests run, M failed.' line, so how" \
    "many tests actually ran cannot be established." >&2
  exit 1
fi
if [ "$tests_run" -lt "$min_tests_run" ]; then
  echo "==> ERROR: only $tests_run tests ran, expected at least" \
    "$min_tests_run. Tests have gone missing from discovery -- e.g. a test*" \
    "method turned private, or a test file renamed off the *Test.php glob." >&2
  exit 1
fi
```

(`Test/run.sh:125-138`; the success line at `:140` now reports the number it checked:
`==> Suite passed (ALL_TESTS_PASSED, $tests_run tests).`)

Note the empty case is failed explicitly rather than left to arithmetic. A missing summary
line means the count cannot be established, which is a red result, not a zero.

**Deriving the threshold.** The rule the script states is: put the floor a few tests below
the suite's current size, so consolidating a case or two never forces an edit here, while
losing four or more goes red. Raise it deliberately as the suite grows; lower it only
together with a deliberate removal, never to make a red run green.

**The worked example under that rule went stale, and that is itself the lesson.** As shipped,
the comment read "the hermetic tier runs 146 tests today, so 143 leaves three tests of
headroom" -- the numbers `0dd72aa` introduced. The constant on the very next line was then
raised from 143 to `min_tests_run=155` by `34e5503`, later in the same pull request, as the
suite grew; the prose was not touched. The derivation *rule* was unchanged and still correct.
Only its worked example drifted, within a single pull request, because the number and the
prose justifying it were maintained by hand and separately -- the constant stayed true because
the suite's red/green outcome depends on it, and the prose did not because nothing depends on
prose.

The drift also quietly weakened the gate. At 155 against 159 the headroom was four, not the
three the rule intends, so a whole test file could go missing without reddening anything: a
loss of N tests only trips the gate when `159 - N < 155`, that is when N is five or more, and
four files sat at or under four tests. The comment's own "every test file except the two
smallest" claim was therefore false at 155, not merely out of date.

Both are corrected on branch `fix/test-lock-the-count-gate` (merge pending): the floor moves
to **157** against the current **160** hermetic `test*` methods across 17 files, restoring
three tests of headroom, and the comment is rewritten to those numbers. At 160/157 the "two
smallest" claim is true again -- a loss trips the gate when N is four or more, and the only
files under that are `ClaimMigrationTest` (2) and `NamedConfigClaimSyncTest` (3).

### The precedent it extends

This is not a new idea in the file, and the commit says so. One step earlier, `Test/run.sh`
already refuses to trust a success status it knows to be unreliable: `cake database` returns
success while warning "Possibly failed to update database schema", so the script counts the
plugin's tables instead of believing the exit code (`Test/run.sh:40-56`,
`min_plugin_tables=15` -- the count of *unique* names among `Config/Schema/schema.xml`'s 22
`<table>` elements). The schema step had a post-condition floor; the suite step did not. The
fix extends one discipline by one step; it does not introduce one.

## Why This Works

The root cause is that two propositions were treated as one:

1. *The runner finished the tests it was given.*
2. *The runner was given the suite.*

Exit status and sentinel are both **terminal-state** evidence, emitted by the runner about
its own completion. Discovery happens before either is decided, and it fails **by
subtraction**: a private method or an unmatched filename produces no output at all, so there
is no error to propagate into a status or a sentinel. No amount of care about how the run
*ends* can recover information about what never entered it.

A count is the only cheap evidence of the second proposition, because it is the only signal
in the log that is a function of discovery's *size* rather than of the run's *outcome*.
`159 tests run` and `12 tests run` are both "clean finishes"; they differ only in the
quantity, and a threshold on that quantity is what turns the difference into a verdict.

The verdict anchoring rests on a different property: **ordering**. The summary at
`Oa4mpTestShell.php:94` is printed once, after the file loop at `:59-91` has completed, so
the region of the log after it is a region no test can write into. That makes the verdict
block a trustworthy haystack in a way the whole log is not, and it is why the anchor is the
summary line rather than the log's last line -- shutdown noise after the sentinel is
expected, whereas test output before the summary is impossible.

## Prevention

**The property every gate needs: its disarmed state must be distinguishable from its passing
state.** A scanner with no rules loaded prints `no leaks found`. A suite with no tests
discovered prints `ALL_TESTS_PASSED`. A schema that never applied leaves the previous
release's tables in place and `cake database` still exits 0. In each case the "everything is
fine" output and the "I checked nothing" output are the same bytes. Whenever a gate's answer
is a *verdict* rather than a *measurement*, ask what it prints when it is doing nothing --
and if the answer is "the same thing", add a measurement with a floor. This repo now has
three instances of that same move: `min_plugin_tables` (`Test/run.sh:46`), `min_tests_run`
(`Test/run.sh:125`), and gitleaks' `[extend] useDefault = true`, locked by
`CiWorkflowTest::testSecretScanConfigExtendsTheDefaultRules`.

**Guard the partial case, not just the empty one.** `07f448f` had already closed
zero-discovery, and it was reasonable to think the hole was shut. It was not: zero is the
one loss a runner notices for free, and every other loss looks like success. When a check
defends against "none", ask separately what it does about "fewer".

**Floors, not equalities.** `-lt` against a number a few below current is the shape that
survives ordinary churn. An exact-equality check would go red on every legitimate test
addition and train people to edit the constant reflexively -- which is precisely the habit
that would let a real loss through. The rule attached to the constant matters as much as the
constant: raise it deliberately as the suite grows; lower it only alongside a deliberate
removal, **never to make a red run green.**

**A hardcoded floor is a hand-maintained number, so expect it to drift.** This one drifted
inside the pull request that introduced it: the constant was raised from 143 to 155 while
the comment explaining it kept the original numbers. The constant stayed correct because the
suite's red/green outcome depends on it; the prose did not, because nothing depends on prose.
Prefer deriving a floor from something the suite already computes over restating it, and
where a literal is unavoidable, expect its justification to rot faster than the literal.

**Test the gate, not just through the gate.** Three gaps were open when this was first
written, and writing it down is what surfaced them. All three are closed on branch
`fix/test-lock-the-count-gate` (merge pending):

- One test asserted gate 2 existed
  (`ClaimsControllerHarnessTest::testRunShRequiresTheAllTestsPassedSentinel`); **nothing
  asserted gate 3 existed**, so deleting `min_tests_run` from `Test/run.sh` reddened nothing.
  `testRunShRequiresAPlausibleTestCount` now locks it. It derives the expected count by
  scanning the source for public `test*` declarations rather than by calling
  `get_class_methods()` -- deliberately, because that is the mechanism whose silent
  subtraction the gate exists to catch, so counting with it would agree with the runner by
  construction and prove nothing. Counting the source is an independent derivation that
  *disagrees* when discovery shrinks. It also bounds how far the floor may sit below the
  tree, so the drift described above is caught next time even if the comment goes stale
  again.
- The gate-2 test asserted that `run.sh` greps for the literal, not that it matches it
  **anchored** inside the verdict block -- and that same test file contains the sentinel
  literal, which is exactly why the anchoring exists. The assertion now covers the anchoring,
  which is the property doing the work.
- `Test/README.md` documented gates 1 and 2 and the table floor but never the count gate. A
  gate nobody has read about is a gate somebody will delete during a cleanup. It now has a
  "The three gates" section.
- `Test/run-live.sh` ran the same runner but ended at the `docker compose exec` with no
  sentinel check and no count check. It now applies gate 2, which matters more there than in
  the hermetic tier: that tier creates real clients, so a run that stops early can strand one
  on the server and would have reported success. The count floor is deliberately not mirrored
  -- it discovers one directory, where a floor would need editing on nearly every change and
  would stop meaning anything.

**Verified red, applied to the gate itself.** The repo already requires every regression test
to be proven red by restoring the pre-fix path (`Test/README.md:234-237`). A gate is subject
to the same rule, and the two directions differ: prove it goes red when the thing it watches
is broken, *and* prove the old gate would have stayed green on the same input. Without the
second half you have not shown the new gate adds anything.

**Beyond this repo.** The pattern generalises to any tool whose failure mode is doing less
work rather than reporting an error: linters with a config that silently matched no files,
coverage runs whose include-path drifted, migration runners with an empty migrations
directory, a CI matrix whose filter excluded every leg. The check is always the same shape --
find the number in the output that scales with *how much work was attempted*, and put a floor
under it. And treat an unexplained drop in runtime as a first-class signal: a sub-minute run
against an expected several minutes was the only thing that made anyone look, and looking
meant reading the log body rather than the badge.

## Verification

The fix was proven in both directions before it landed, per `0dd72aa`'s commit message:

- **Loss of tests must go red.** Four `test*` methods were turned `private`. The run still
  exits 0 and the sentinel is still present -- both old gates green -- and it now fails the
  count gate.
- **A forged sentinel must go red.** A log carrying the `ALL_TESTS_PASSED` literal five times
  with no verdict line passes the old unanchored grep and fails the new anchored one.

Both directions were re-reproduced against the current tree while writing this document. On
a synthetic log containing the literal five times (as a bare line, inside a quoted `grep -q`
line, and inside an `echo`) but no `N tests run` summary, `grep -q 'ALL_TESTS_PASSED'`
returns 0 -- green -- while the verdict-block extraction yields an empty block and
`grep -q '^ALL_TESTS_PASSED$'` returns non-zero -- red. On a truncated-but-clean log
(`12 tests run, 0 failed.` then `ALL_TESTS_PASSED` then a trailing shutdown warning), gate 2
passes, the extracted `tests_run` is `12`, and `[ 12 -lt 157 ]` fails the run. Separately,
the visibility premise was confirmed on PHP 8.4.24: called from an unrelated class,
`get_class_methods()` on a case with public `testA`/`testD`, private `testB` and protected
`testC` returns exactly `[testA, testD]`, silently.

The landing record was confirmed against the remote: cilogon/Oa4mpClient#11, state `MERGED`,
merged 2026-08-23T09:48:55Z, merge commit `ce6ef88` -- which is `HEAD` and equals
`upstream/main`.

## Related Issues

- `docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md`
  -- the closest sibling: a secret scan that reported green while its rule set was
  structurally disarmed. Different artifact and different root cause, but the same family,
  and its Prevention rule 2 ("for anything that gates a merge, assert it FAILS when it
  should, not just that it passes today") names this shape directly. That doc tracks
  `07f448f` as the first instance; this learning is the one that closes the *partial*
  discovery loss `07f448f`'s zero-floors left open.
- `docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md` -- the plan that established
  the hermetic tier and the merge gate this fix hardens.
- `docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md` -- the plan whose pull
  request (cilogon/Oa4mpClient#11) carried this fix. The gate was suite-hardening that rode
  along, not one of that plan's requirements.
- `CONCEPTS.md` -- **Silent pass** and **Verified red** are the vocabulary this learning
  instantiates; **Hermetic tier** is why it matters, since that tier gates every pull
  request.
- `Test/README.md` -- documents gates 1 and 2 and the table floor, but not the count gate.
  Stale with respect to this fix; see Prevention.
- No related GitHub issues: the tracker's only issue is cilogon/Oa4mpClient#1 (RFC 7591/7592
  migration, closed 2020).
