---
title: The fork pull request is never where work lands, so a learning that cites it is wrong the moment the developer opens the upstream one
date: 2026-08-22
category: conventions
module: Oa4mpClient plugin (repository workflow and docs/solutions authoring)
problem_type: convention
component: development_workflow
related_components:
  - "AGENTS.md (Git, Remotes, and Pushing)"
  - "CLAUDE.md (pushing policy)"
  - ".github/pull_request_template.md"
  - documentation
severity: low
applies_when:
  - "Writing anything that records where a change landed -- a docs/solutions/ learning, a plan, a runbook, a commit message"
  - "A branch has been pushed to the fork but the upstream pull request does not exist yet"
  - "A mechanical validator advises citing a pull request number in place of a local-only commit SHA"
  - "Re-reading a learning written before its work was merged"
symptoms:
  - "A merged learning says a fix is open in a fork pull request and not yet on main, while the work is already on main"
  - "The cited fork pull request shows state CLOSED with mergedAt null, and no fork pull request in the repository has ever been merged"
  - "The commit that actually landed the work is a merge commit naming a different pull request number on a different remote"
root_cause: inadequate_documentation
resolution_type: documentation_update
tags: [fork-workflow, upstream, pull-request, merge-state, stale-claims, docs-solutions, git-remotes, oa4mp]
---

# The fork pull request is never the landing record; cite the upstream one

## Context

This is a fork-based repository (`AGENTS.md:124-128`): `origin` is the developer's
fork at `skoranda/Oa4mpClient`, `upstream` is the canonical `cilogon/Oa4mpClient`.
An agent may push a feature branch to `origin` and open a pull request there, but
the developer opens the pull request that actually lands the work against
`upstream`, and that pull request carries a **different number**. The fork's pull
request is then closed without merging.

A document written while the work is in flight therefore has only one pull request
number available -- the fork's -- and that number is guaranteed never to be the
record of where the work landed.

This surfaced concretely. `docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md`
was committed at 18:37:14Z saying:

```
The fix is on branch `test/plugin-test-suite` in commit `2707dd2` and is open in
pull request #3; it is not yet merged to `main`.
```

and, in its Related Issues list:

```
- Pull request #3 (`skoranda/Oa4mpClient`) -- the test-suite branch this fix lands on.
```

`cilogon/Oa4mpClient#5` merged at 18:43:49Z. Both statements were false **6 minutes
and 35 seconds after they were written**, and `skoranda/Oa4mpClient#3` -- the
pull request named as where the fix "lands" -- was closed with `mergedAt: null`.
Correcting three sentences then cost its own branch, its own fork pull request
(closed unmerged, of course), and its own upstream merge as `cilogon/Oa4mpClient#6`
-- a pull request it happened to share with one unrelated documentation change.

The pattern is consistent, not a one-off. Every fork pull request in this
repository's history has been closed unmerged, with a matching upstream pull
request under a different number. The table below is a snapshot taken as of
`cilogon/Oa4mpClient#6`; `main` has since taken `cilogon#7`
(`docs/fork-pr-landing-record-convention`) and `cilogon#8`
(`docs/claude-md-drop-override-note`), each with its own closed-unmerged fork
counterpart. Re-derive the upstream side at any time with
`git log --merges main`; the fork numbers are only visible on GitHub.

| fork PR (state) | upstream PR (merged) | branch |
| --- | --- | --- |
| `skoranda#1` CLOSED | `cilogon#3` 2026-08-18 | `chore/agents-fork-push-policy` |
| `skoranda#2` CLOSED | `cilogon#4` 2026-08-18 | `docs/oidc-client-user-manual` |
| `skoranda#3` CLOSED | `cilogon#5` 2026-08-22 | `test/plugin-test-suite` |
| `skoranda#4` CLOSED | `cilogon#6` 2026-08-22 | `docs/refresh-gitleaks-learning-merge-state` |

All four fork pull requests report `"mergedAt": null`.

This document itself landed as `cilogon/Oa4mpClient#7` (merge commit `336c098`),
from branch `docs/fork-pr-landing-record-convention`, together with the
`AGENTS.md` subsection that now states the rule.

Two earlier traces show this was already causing quiet confusion before it
produced a wrong document (session history). On 2026-08-18 the fork pull
requests were reported to the developer as `#1` and `#2` at 15:30; by 15:43 the
same work was being described as "your PRs #3 and #4", and again at 15:50, with
no correction recorded anywhere. The renumbering is unexplained in that session
and fully explained by this one: `cilogon#3` and `cilogon#4` merged at 15:38:09
and 15:39:41, five minutes before the new numbers started being used -- and they
were described as "merged into the fork's `main`", which is the same conflation
this document is about.

Separately, on 2026-08-03 -- two weeks before any pull request existed here -- a
`ce-compound` run adjudicated a commit-SHA flag by reasoning that this repository
cites branch commit SHAs in its learning documents and "has no PR workflow to
cite a PR number instead" (session history). That was true when written. The
2026-08-18 fork-push work introduced exactly such a workflow and silently expired
the justification, but the convention was never revisited. The nine learning
documents predating the gitleaks one still cite bare SHAs and branch names.

## Guidance

**While the work is unmerged, do not name a pull request as the landing record.**
State the branch and phrase the merge state as pending. The branch name is stable
and true; the fork pull request number is neither.

```
Bad:  The fix is in commit 2707dd2 and is open in pull request #3; it is not yet
      merged to main.
Good: The fix is commit 2707dd2 on branch test/plugin-test-suite, not yet merged
      to main as of this writing.
```

**When the work has landed, cite the upstream pull request, and only once it
exists.** Qualify it with the repository, because the bare `#N` form is ambiguous
across two remotes that both number from 1:

```
The fix is commit 2707dd2, merged to main on 2026-08-22 as part of
cilogon/Oa4mpClient#5 (merge commit 6288cb8).
```

**Look the upstream number up; do not compute it.** Today the fork and upstream
numbers differ by two, and that is an accident, not a rule. Upstream had already
used numbers 1 and 2 -- an issue from 2019 and a pull request from 2023 -- and
GitHub draws issues and pull requests from the same counter, so the gap is the
residue of an unrelated issue filed seven years ago. Any new issue or third-party
pull request on either remote shifts it again. The number is recoverable from the local clone with
no network at all, because the merge commits carry it:

```
$ git log --merges --oneline -4 main
c7a1ba9 Merge pull request #8 from skoranda/docs/claude-md-drop-override-note
336c098 Merge pull request #7 from skoranda/docs/fork-pr-landing-record-convention
6ca9107 Merge pull request #6 from skoranda/docs/refresh-gitleaks-learning-merge-state
6288cb8 Merge pull request #5 from skoranda/test/plugin-test-suite
```

Those are the **upstream** numbers. No merge commit subject ever carries the
fork's number -- it appears in git only where a commit body deliberately cites it,
as `3af832c` and `09c2dac` do when naming the CI runs. Which is the whole shape of
the trap: the correct identifier is
already sitting in the repository, and the wrong one is the one showing in the
browser tab while the work is in flight.

**Keep the fork pull request citation for what genuinely happened there.** CI runs
belong to the fork pull request, not the upstream one -- the gitleaks document
deliberately keeps its run citations on `skoranda/Oa4mpClient#3` while attributing
the merge to `cilogon/Oa4mpClient#5`
(`docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md:232-233`).
Separating the two is the correct shape, not a compromise.

**Re-run the mechanical claims validator after the merge, not only at writing
time.** The merge itself changes the answer:

```
python3 <ce-compound>/scripts/validate-doc-claims.py \
  docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md
```

For this document the flag count fell from 7 (observed before the merge) to 4
purely because the branch landed: exactly three SHAs -- `2707dd2`, `07f448f` and
`f678ee8` -- were reported as "reachable from HEAD but not origin/main", and all
three became reachable and stopped being flagged. Nothing in the document
changed to cause that. A validator run at writing time therefore cannot be
treated as a durable clean bill.

## Why This Matters

The failure mode is quiet. A reader has no way to distinguish a stale "not yet
merged to main" from a current one -- both are ordinary English sentences, and
nothing in the repository re-checks them. Merge-state assertions are the part of a
learning document that rots first and signals its rot least.

The sharper point is that the tooling's own remedy misfires here. When
`validate-doc-claims.py` -- the mechanical claims checker that ships inside the
compound-engineering `ce-compound` skill bundle, not in this repository -- finds a
commit reachable from HEAD but not from the default branch, it emits:

```
local-only commit whose SHA may be rewritten on merge (rebase/squash).
Prefer citing the PR number.
```

(`validate-doc-claims.py:313-318` in that bundle, and the same advice again at `:326-330`
for rebased-away commits.) That advice is sound in a single-remote repository. Here
it points at the wrong identifier: the only pull request number in hand while
writing is the fork's, which will be closed unmerged, and the upstream number that
will become the real record does not exist yet. Following the hint faithfully
produces exactly the citation that went stale.

There is a second, subtler misfire in the same script. It resolves its notion of
"upstream" as `origin/HEAD`, falling back to `origin/main`
(`validate-doc-claims.py:185-197`). In this repository `origin` is the
**fork**, and `origin/HEAD` is not even set, so the comparison lands on
`origin/main` -- the fork's default branch, not the canonical one. A commit merged
into `cilogon/Oa4mpClient` but not yet pulled down into the fork's `main` would
still read as local-only. That did not bite in this instance only because the
developer rebases local `main` on `upstream` and the fork's `main` was pushed in
step (all three of `HEAD`, `origin/main`, and `upstream/main` sat at `6ca9107`),
which is a habit rather than a guarantee.

One thing that did hold, and should not be assumed to: both upstream merges were
true merge commits rather than squashes. `6288cb8` and `6ca9107` each have two
parents, so every commit SHA the document cites survived on `main`. Under a squash
merge the SHA citations would have died alongside the pull request citations, and
the correction would have been considerably larger than three sentences.

## When to Apply

- Writing or reviewing anything under `docs/solutions/` that records where a
  change landed -- and equally any plan, runbook, or comment that does.
- Any time `validate-doc-claims.py` advises "Prefer citing the PR number" on a
  branch that has not merged yet. Treat that as a prompt to phrase the state as
  pending, not as a prompt to insert the fork's number.
- Immediately after the developer reports an upstream merge: re-run the validator
  and grep the touched documents for merge-state phrasing.

Not applicable to CI run identifiers, artifact URLs, or check names -- those
genuinely belong to the fork pull request and stay there.

## Examples

Before, at `09c2dac^` (three separate claims -- two false within seven minutes,
the third made redundant):

```
line  68: The fix is on branch `test/plugin-test-suite` in commit `2707dd2` and is
          open in pull request #3; it is not yet merged to `main`.
line 194: (commit `07f448f`, "close silent-pass holes in the gate"; like everything
          else on this branch, unmerged at the time of writing)
line 232: - Pull request #3 (`skoranda/Oa4mpClient`) -- the test-suite branch this
          fix lands on. Failing run 32372738600; green run 32590002495.
```

After, at `HEAD`:

```
line  68: The fix is commit `2707dd2`, merged to `main` on 2026-08-22 as part of
          cilogon/Oa4mpClient#5 (merge commit `6288cb8`). That pull request was a
          merge, not a squash, so every commit SHA cited in this document survives
          on `main`.
line 194: (commit `07f448f`, "close silent-pass holes in the gate")
line 232: - cilogon/Oa4mpClient#5 -- the pull request that landed the test-suite
          branch, and this fix with it, on `main`.
line 233: - skoranda/Oa4mpClient#3 -- the fork pull request the failing and green CI
          runs belong to (failing run 32372738600, green run 32590002495). It was
          closed unmerged; the branch landed upstream instead.
```

The line-194 edit is the cheapest lesson in the set: the clause "like everything
else on this branch, unmerged at the time of writing" was accurate when written and
needed deleting rather than correcting. A merge-state hedge attached to a claim
that did not need one is still a maintenance liability.

## Related

- `AGENTS.md:150-158` ("Recording where work landed") now states this rule
  outright and links this document. It was added by `0f3c2eb`, the commit that
  created this learning, precisely because the rule had previously existed only
  as an aside inside a *push* prohibition -- "The developer opens pull requests
  from the fork to upstream themselves" (`AGENTS.md:140-141`) -- which a writer
  recording where a finished fix landed had no reason to be reading.
  `CLAUDE.md:9-10` states the two-remote topology but still omits the direction
  of pull requests entirely, so AGENTS.md remains the only place it is written
  down.
- `docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md:95` states that this
  repository's pull requests are "branch-to-`main` within the developer's fork
  today (so secrets are available)" and treats the canonical home moving to
  `cilogon` as a future possibility. Both halves are wrong under the topology
  above. The direction matters: real pull requests are fork-to-upstream, which is
  the case where GitHub *withholds* secrets, so reality is safer than the plan
  assumed and the hermetic tier's no-secrets design (R5, AE1) is vindicated rather
  than undermined. Only the parenthetical rationale is stale.
- The nine learning documents written before 2026-08-18 cite a commit SHA, a branch
  name, or both -- one cites only a branch, three cite only a SHA. None is wrong -- every cited SHA resolves and sits on `main`, and
  all predate the first fork pull request -- but a branch is a mutable, eventually
  deleted pointer where the durable fact is the merge commit. Recording the merge
  commit covers both weaknesses at once.
