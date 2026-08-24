## What this changes

<!-- One or two sentences on the change and why it is needed. -->

## Checklist

- [ ] The hermetic suite passes (`Test/run.sh`).
- [ ] **If this pull request fixes a bug:** it carries a regression test that
      fails against the pre-fix behaviour, and links the `docs/solutions/`
      learning that documents the bug.
- [ ] Behaviour changes are covered by tests; removed behaviour has its tests
      removed or updated.
- [ ] Every changed expected cfg value in `Test/Case/Model/` names the
      behaviour change it reflects; a re-recorded value is not a fix.
- [ ] **If this pull request changes what the plugin may emit into a cfg** --
      introducing `cfg_contract.json`, adding or retiring an entry, or raising
      `contract_version` -- it records the `bin/qdl-conformance.php` result for
      `us-east-2-dev`: the verdict and the tier name, not pasted output. It also
      names the QDL change that satisfies it. See AGENTS.md, "Testing &
      Verification", for the command and where the QDL lives. Without a checkout
      of the configuration repository, ask a maintainer who has one to run the
      check rather than attesting to it here.

<!--
Reviewer expectation: a bug-fix pull request without a regression test is asked
for one before approval. The suite is how this project stops re-fixing the same
bug -- see Test/README.md, "The compounding norm".
-->
