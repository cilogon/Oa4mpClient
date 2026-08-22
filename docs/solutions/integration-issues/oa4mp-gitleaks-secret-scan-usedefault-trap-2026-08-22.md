---
title: Masked AWS key in cfg_example.json red-lighted the gitleaks secret scan, and the obvious allowlist fix disarms every rule without [extend] useDefault = true
date: 2026-08-22
category: integration-issues
module: Oa4mpClient plugin (CI secret scanning)
problem_type: integration_issue
component: tooling
related_components:
  - ".github/workflows/hermetic-tests.yml (secret-scan job)"
  - ".gitleaks.toml"
  - "Test/Case/CiWorkflowTest.php"
  - "cfg_example.json"
severity: medium
symptoms:
  - "GitHub Actions Secret scan job exited 1 on pull request #3 while the sibling Hermetic suite job passed in 51s"
  - "gitleaks reported RuleID aws-access-token at cfg_example.json line 119, in a file the branch never touched"
  - "The flagged value is a masked documentation placeholder whose paired secret_access_key is a literal dummy/... string"
  - "No working-tree edit cleared the finding, because gitleaks detect scans git history rather than the checkout"
  - "After a .gitleaks.toml was added with no [extend] block, the same scan printed no leaks found and exited 0 with zero rules loaded"
root_cause: wrong_api
resolution_type: config_change
framework_version: gitleaks v8.18.4
tags: [gitleaks, secret-scanning, github-actions, ci, allowlist, silent-pass, false-positive, oa4mp]
---

# A gitleaks allowlist without an [extend] block disarms the whole scanner

## Problem

The `Secret scan` job added alongside the hermetic test suite red-lighted pull request #3 on a masked AWS key id that has sat in `cfg_example.json` since January 2026. The obvious remedy -- a `.gitleaks.toml` allowlist -- carries a trap: a config file with no `[extend]` block *replaces* the built-in ruleset instead of adding to it, so the scanner loads zero rules, prints `no leaks found`, and exits 0 while detecting nothing at all.

## Symptoms

- GitHub Actions run 32372738600 (`Hermetic tests`, triggered by `pull_request`) concluded `failure`. The `Hermetic suite` job passed in 51s; only `Secret scan` failed.
- The failing step ended with `##[error]Process completed with exit code 1.`
- The gitleaks output named the finding precisely:

  ```
  RuleID:      aws-access-token
  File:        cfg_example.json
  Line:        119
  Entropy:     3.541446
  Commit:      c8d29ad80f8a7770ffb760b004904454e4538768
  Date:        2026-01-13T15:08:15Z
  ```

- Summary lines: `141 commits scanned.` then `leaks found: 1`.
- The flagged value is `cfg_example.json:119`, `"access_key_id": "AKIA6MESHHJ73ZODZZZZ"`. Its paired `cfg_example.json:122` is `"secret_access_key": "dummy/KC1ewZ43JijREjsUoQ5btqzzzzzz"` -- both tail-masked, both documentation.
- Nothing on the branch under review touched `cfg_example.json`. The commit named in the finding predates the test-suite branch by seven months.

## What Didn't Work

**Predicting the failure was git's dubious-ownership check.** When the PR was opened, the expected first-run failure was `detected dubious ownership in repository` -- gitleaks runs as root inside the container against a bind-mounted checkout owned by the runner user, which is the classic way a containerised git command fails on a mounted repo. That prediction was simply wrong. The job log shows the image pulling cleanly, git working fine, and 141 commits being scanned to completion. The failure was a real finding, not a plumbing error. The cost of the wrong guess was small only because the log was read before any fix was attempted; reading `Process completed with exit code 1` and reaching for a `safe.directory` workaround would have wasted the cycle.

**Scrubbing the placeholder out of `cfg_example.json`.** The natural first instinct is to replace `AKIA6MESHHJ73ZODZZZZ` with something that does not match the AWS pattern -- `EXAMPLE_ACCESS_KEY_ID` or similar -- and be done. This does not work, and the failure mode is quiet: the working tree scans clean while CI still goes red. `gitleaks detect` walks git history, not the checkout. A local reproduction with the original config reported the same `cfg_example.json:119` finding on every commit whose *diff* introduces the value -- `gitleaks detect` walks commit patches, not snapshots, so descendant commits that merely carry the file are not re-reported. On the history the pull request's scan actually walks -- the branch and `main` -- that is `c8d29ad` alone, verified with `git show c8d29ad:cfg_example.json`. A scan of a full clone finds it on `32347b8` and `6b74937` as well, but those sit on the unmerged `feature/registry5` and `feature/dynamodb` branches: invisible to this pull request, and a red gate waiting for either of those branches if it is ever proposed. Editing the working tree leaves every one of them untouched. Only rewriting history would remove them, which is not on the table for a shared branch. An edit is therefore optional hygiene; it is never the fix.

**Reaching for the `paths` allowlist condition.** With history immovable, an allowlist is the only route, and the obvious entry is the file:

```toml
[allowlist]
paths = ['''cfg_example\.json''']
```

This does silence the finding, and it is the wrong shape. gitleaks ORs the global allowlist conditions together -- a candidate is exempted if it matches the path *or* a listed commit *or* a regex *or* a stopword. A `paths` entry therefore exempts **every rule** across the whole of `cfg_example.json`, for all time. A genuine AWS key, GitHub token, or private key added to that same example file later would scan clean and merge. The same objection applies to `commits`: it exempts every rule in the named commits, not just the finding that prompted it. Neither condition can be narrowed to "this one value" -- that is what `regexes` is for.

## Solution

The fix is on branch `test/plugin-test-suite` in commit `2707dd2` and is open in pull request #3; it is not yet merged to `main`.

### Step 1 -- Add `.gitleaks.toml` with the narrow allowlist

The whole file, as written:

```toml
# gitleaks configuration for the secret-scan job in
# .github/workflows/hermetic-tests.yml.
#
# The scan runs over the full history, so a value that was ever committed keeps
# being found no matter how the working tree changes today. cfg_example.json has
# carried a masked AWS key id since the DynamoDB claims work in January 2026
# (c8d29ad and its ancestors); it is documentation, its paired secret_access_key
# is a literal "dummy/..." string, and it cannot be edited out of history.
#
# Locked by Test/Case/CiWorkflowTest.php, which runs inside the hermetic gate.

# Add to the built-in ruleset rather than replacing it. Without this block the
# scanner keeps exiting zero while detecting nothing: a green gate guarding
# nothing at all.
[extend]
useDefault = true

# Exempt the one documented placeholder by its literal value.
#
# gitleaks ORs the global allowlist conditions together, so a `paths` entry
# would exempt every rule across the whole of cfg_example.json and a `commits`
# entry would exempt every rule in those commits -- a genuine credential added
# to that file later would then scan clean. Matching the literal exempts that
# one string and leaves every other rule live on the same file.
[allowlist]
description = "Masked AWS key id documented in cfg_example.json (not a credential)"
regexes = [
  '''AKIA6MESHHJ73ZODZZZZ''',
]
```

The two load-bearing parts are `.gitleaks.toml:15-16` (the `[extend]` block) and `.gitleaks.toml:25-29` (the allowlist scoped to one literal).

### Step 2 -- Name the config explicitly in the workflow

gitleaks auto-discovers `.gitleaks.toml` relative to `--source`, which here is a bind mount. Relying on that discovery means a scan that cannot read the config silently falls back to the default ruleset and red-lights the gate on the known placeholder again -- indistinguishable, in the log, from a genuine regression.

```yaml
# Before (.github/workflows/hermetic-tests.yml):
        run: |
          docker run --rm \
            -v "$PWD:/repo" \
            zricethezav/gitleaks:v8.18.4 \
            detect --source=/repo --redact --verbose --no-banner

# After (.github/workflows/hermetic-tests.yml:67-72):
        run: |
          docker run --rm \
            -v "$PWD:/repo" \
            zricethezav/gitleaks:v8.18.4 \
            detect --source=/repo --config=/repo/.gitleaks.toml \
            --redact --verbose --no-banner
```

### Step 3 -- Lock the config from inside the gate

Three tests were added to `Test/Case/CiWorkflowTest.php`, which already locks the workflows' security wiring. They share a `gitleaksConfig()` helper (`Test/Case/CiWorkflowTest.php:153-157`) that reads the TOML through the same comment-stripping `directives()` filter the workflow assertions use, so a directive surviving only inside a `#` comment is not a match.

### Verification

- The CI-equivalent scan (`--log-opts="test/plugin-test-suite"`, the history the runner sees) reports `no leaks found` and exits 0. It scanned 141 commits at the time of the failing run and 142 with the fix commit added.
- The full suite is 46 tests, 0 failed; `Test/run.sh` exits 0.
- GitHub Actions run 32590002495 concluded `success` with both `Hermetic suite` and `Secret scan` green.

## Why This Works

**`[extend] useDefault = true` adds to the built-in ruleset rather than replacing it.** A `.gitleaks.toml` that omits `[extend]` is treated as the complete rule definition. If it declares only an `[allowlist]` and no `[[rules]]`, the scanner has nothing to match with -- it walks every commit and finds nothing, because there is nothing to find with.

This was confirmed by running two configs against the same tree and the same 221 commits, differing only in the presence of the `[extend]` block and with no allowlist in either:

```
# with [extend] useDefault = true:
221 commits scanned.
leaks found: 5

# with no [extend] block:
221 commits scanned.
no leaks found
```

That second result is the entire hazard. It is not an error, not a warning, not a different exit code -- it is the exact output of a clean repository. A reviewer skimming a green `Secret scan` job has no signal at all that the scanner was unarmed.

**Global `[allowlist]` conditions are OR'd, so `regexes` on a literal is the only narrow form.** `paths`, `commits`, `stopwords`, and `regexes` are alternatives, not a conjunction: matching any one of them exempts the candidate. `paths` therefore means "every rule, this whole file, forever" and `commits` means "every rule, these whole commits, forever". Matching the literal `AKIA6MESHHJ73ZODZZZZ` exempts exactly that string wherever it appears and leaves every other rule live on the same file -- a real key added to `cfg_example.json` next month is still caught.

One thing that is *not* an argument for literal scoping, though it looks like one: `.gitleaks.toml` carries the placeholder in its own `regexes` array, so it seems the config must be exempting itself. It is not. gitleaks v8.18.4's default global allowlist already exempts `gitleaks.toml` by path, so the config file is unflagged under either scoping -- verified in an isolated probe repository holding two committed files with the same literal, where only the non-config file was reported.

**The explicit `--config` turns a silent degradation into a loud one.** With auto-discovery, a config the container cannot read produces a default-ruleset scan that looks like a legitimate failure. With `--config=/repo/.gitleaks.toml`, an unreadable config is a startup error naming the path.

## Prevention

**1. The three tests that lock this configuration.**

All three live in `Test/Case/CiWorkflowTest.php` and run inside the hermetic gate, so breaking any of them red-lights the merge.

- `testSecretScanConfigExtendsTheDefaultRules` (`Test/Case/CiWorkflowTest.php:166-173`) asserts `[extend]` is present and that a line matching `/^\s*useDefault\s*=\s*true\s*$/m` exists. This is the one that matters most: deleting that single line is invisible in every downstream signal.

  ```php
  $this->assertContains('[extend]', $toml,
    'the config must extend the built-in ruleset, not replace it');
  $this->assertTrue((bool)preg_match('/^\s*useDefault\s*=\s*true\s*$/m', $toml),
    'useDefault = true keeps every built-in rule armed alongside the allowlist');
  ```

- `testSecretScanAllowlistIsScopedToALiteralNotAPath` (`Test/Case/CiWorkflowTest.php:187-199`) asserts `[allowlist]` and a `regexes =` entry are present, and that `paths`, `commits`, and `stopwords` are all absent. It encodes the OR semantics as a test rather than as a comment someone can talk themselves out of.

  ```php
  foreach (array('paths', 'commits', 'stopwords') as $blanket) {
    $this->assertTrue(!preg_match('/^\s*' . $blanket . '\s*=/m', $toml),
      "the allowlist must not exempt by $blanket; that would hide a real "
      . 'credential added to the same file or commit');
  }
  ```

- `testSecretScanStepPassesTheRepoConfig` (`Test/Case/CiWorkflowTest.php:207-212`) asserts the workflow contains `--config=/repo/.gitleaks.toml`, so the config cannot be silently orphaned by an edit to the docker invocation.

**2. For anything that gates a merge, assert that it FAILS when it should -- not just that it passes today.**

This is the third instance of the same shape on this branch, and the pattern is now unmistakable enough to name. In each case a gate reported success while guarding nothing, and in each case normal output was indistinguishable from a healthy run.

- **The runner exiting 0 on zero discovery** (commit `07f448f`, "close silent-pass holes in the gate"; like everything else on this branch, unmerged at the time of writing). `Oa4mpTestShell` walked the `Test/Case` tree, found no files, and reported success. A typo in a path or a bad `App::uses` would have turned the entire suite into a no-op that CI reported green. Fixed with explicit floors at both ends of the run -- `Console/Command/Oa4mpTestShell.php:48-53` for discovery and `:99-104` for execution:

  ```php
  if (empty($files)) {
    // Discovering nothing is a broken gate, not a pass: exit non-zero so
    // Test/run.sh (and CI with it) goes red instead of silently green.
    $this->out('<error>No test cases found.</error>');
    $this->_stop(1);
  }

  // Same floor after the run: files may load yet contribute no test method,
  // and a run of zero tests must never be reported as success.
  if ($total === 0) {
    $this->out('<error>No tests were executed.</error>');
    $this->_stop(1);
  }
  ```

- **A comparator-drift lock whose two sides were built from the same array** (commit `f678ee8`, "make the weak locks discriminate"). The sync-verification test compared a hand-built structure against itself, with `'Oa4mpClientClaim' => array()` on both sides, so the comparator branch under test never executed. The test passed and would have kept passing through any regression in the code it named. Fixed by driving the config through `oa4mpUnMarshallContent()` for real, then re-verifying that restoring the old abbreviated key makes the new tests fail.

- **This one**: an allowlist that disarms the scanner.

The discipline that catches all three is the same, and it is cheap: after writing a gate, break the thing it guards and confirm the gate goes red. Every one of these took under a minute to falsify once the question was asked. The mutation run in **Why This Works** above is that check for the gitleaks config; the "verified red" step before each regression test is that check for the suite.

**3. When adding a third-party scanner to CI, run it locally once before shipping the job.**

The gitleaks job was added to `.github/workflows/hermetic-tests.yml` without ever being executed against this repository. One `docker run` locally would have surfaced the `cfg_example.json` finding before the PR was opened, and the config would have shipped with the job instead of as a follow-up fix. A scanner's built-in ruleset is a claim about *your* repository's history that you have not checked.

**4. A placeholder that matches a credential's structural pattern will be flagged forever.**

`AKIA` followed by 16 uppercase alphanumerics is the AWS access-key-id shape; `AKIA6MESHHJ73ZODZZZZ` matches it and clears the entropy floor at 3.54 despite the `ZZZZ` tail. When writing example configuration, prefer placeholders that cannot match: `EXAMPLE_ACCESS_KEY_ID`, `<your-access-key-id>`, or a value that breaks the character-class rule. Once such a value is committed it is in history permanently, and every scanner added later must be taught about it individually.

**5. Known limitation: these locks are static analysis, not a positive control.**

The three tests assert the *shape* of `.gitleaks.toml` and of the workflow YAML. They cannot prove the scanner fires end to end, and they would not catch a future gitleaks major version changing what `[extend]`/`useDefault` mean -- the assertions would still pass against a config that no longer arms anything. The stronger lock is a positive control: a scan over a fixture containing a seeded, obviously-fake-but-pattern-matching secret, asserted to be *found*. That is the same device commit `f678ee8` added for the public-client `cfg` test, where an absence assertion with no positive control could have passed because `cfg` broke for every client. Adding one here is open work, not something this fix closed.

## Related Issues

- Pull request #3 (`skoranda/Oa4mpClient`) -- the test-suite branch this fix lands on. Failing run 32372738600; green run 32590002495.
- `docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md` -- U7 and U8 define the CI tiers; the `Secret scan` job is the backstop to the gitignored `Test/.env` that carries the dev.cilogon.org credential.
- `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` -- the comparator whose regression lock was the second silent-pass instance cited in Prevention rule 2.
- The masked placeholder itself is untouched. It entered `main` on 2026-01-13 in `c8d29ad` ("Use DynamoDB to resolve claims"), alongside `"secret_access_key": "dummy/KC1ewZ43JijREjsUoQ5btqzzzzzz"` and `"table_name": "multitenant_mess_dev"`, which reads as a scrubbed example rather than a live credential. Nothing in the repository states outright whether `AKIA6MESHHJ73ZOD****` was ever a real key prefix, and the allowlist does not settle it. Rotation is a separate decision from making CI green.
