---
title: Plugin Test Suite and CI - Plan
type: test
date: 2026-08-19
topic: plugin-test-suite
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-brainstorm
execution: code
---

# Plugin Test Suite and CI - Plan

## Goal Capsule

- **Objective:** The plugin has an automated test suite that verifies its core logic and locks its documented bugs against a real database, runs on every pull request as a merge gate, and can be run identically during development and by Claude — with a separate live-server tier for true end-to-end coverage against `dev.cilogon.org`.
- **Product authority:** This document, distilled from dialogue with CILogon staff (Scott Koranda), grounded in the plugin's code and the current absence of any test or CI infrastructure.
- **Open blockers:** None. Secret handling and the live-server tier's framing are settled as decisions and assumptions below.

---

## Product Contract

### Summary

An automated, two-tier test suite and CI for the plugin. A hermetic tier runs on every pull request against a real COmanage Registry and a real database with the OA4MP server stubbed, covering the plugin's core logic and locking each of the 9 documented bugs. A separate live-server tier runs against `dev.cilogon.org` on-demand or on a schedule. The same containerized environment runs locally, in CI, and for Claude.

### Problem Frame

The plugin has no automated tests and no CI: `Test/` is empty placeholder directories, and there is no `.github/` workflow, `composer.json`, or PHPUnit configuration. Its logic is bug-prone in specific areas — 9 bugs are already documented in `docs/solutions/` (for example, claim comparator drift, orphan-claim recovery, hasOne phantom inserts, claim migration, cfg marshalling, the public-client cfg rejection, and a view double-encoding). Today a regression in this logic reaches a running Registry and OA4MP server before anyone notices. Because the plugin depends on the Registry framework it cannot be tested standalone, which has been the practical barrier to having any tests at all.

### Key Decisions

- **Two tiers, split by hermeticity** (session-settled: user-directed — chosen over a single full-stack per-PR suite: the merge gate must stay hermetic so it survives fork PRs and network/secret flakiness). Governs R1, R5, R6.
- **The per-PR tier verifies against a real database with OA4MP stubbed** (session-settled: user-directed — chosen over pure-mock unit tests: the documented bugs are database and logic bugs that only a real database exposes). Governs R2, R4.
- **The environment is the prebuilt public image with the plugin-under-test overlaid** (session-settled: user-directed — chosen over building Registry from source: pull the `public.ecr.aws/cilogon/comanage-registry` image and overlay the PR's plugin and its schema). Governs R7.
- **Targeted core plus compounding coverage** (session-settled: user-directed — chosen over broad line-coverage: cover the high-risk logic and lock every documented and future bug, not the low-risk CRUD and view surface). Governs R2, R3.
- **One environment for development, CI, and Claude** (session-settled: user-directed — the suite is runnable identically in all three). Governs R8.

### Requirements

**Coverage**

- R1. The suite is organized in two tiers: a hermetic tier that gates every pull request, and a live-server tier that does not gate pull requests.
- R2. The hermetic tier covers the plugin's core logic: the three-role authorization model (`Controller/Component/Oa4mpClientAuthzComponent.php`), cfg marshalling and unmarshalling and sync verification (`Model/Oa4mpClientOa4mpServer.php`), claim mapping and search-attribute migration (`Model/Oa4mpClientCoSearchAttribute.php`), and public-vs-confidential client handling.
- R3. The hermetic tier includes a regression test for each of the 9 bugs documented in `docs/solutions/`, and the project's norm is that every future bug adds a regression test, linking to its `docs/solutions/` learning where useful. The norm is backed by a lightweight enforcement hook — for example, a pull-request-template checklist item or a stated reviewer expectation — so a bug-fix pull request is expected to carry its regression test rather than relying on unassisted discipline.

**Hermetic per-PR tier**

- R4. The hermetic tier runs against a real COmanage Registry and a real database, with all OA4MP server interactions stubbed, asserting the marshalled `cfg` and the resulting database state rather than contacting a server. Because the stub encodes an assumption about how the server responds, the hermetic regression test for the public-client cfg-rejection bug locks the plugin's marshalled `cfg` output, not the server's acceptance of it — the true server-acceptance check lives only in the live-server tier (R9). The stub is pinned to a captured real server response so it does not merely re-encode the belief the original bug violated.
- R5. The hermetic tier requires no secrets and no network egress, so it runs on every pull request — including pull requests from forks, where GitHub withholds repository secrets.
- R6. The hermetic tier is the merge gate: a pull request is not merge-ready until it passes.

**Environment**

- R7. The environment is prepared by pulling the public prebuilt image `public.ecr.aws/cilogon/comanage-registry` — pinned to a specific digest for the gating tier, so a pass or fail is attributable to the pull request's code rather than image drift — and overlaying the plugin-under-test over the image's bundled released copy, including reconciling the database schema with the plugin-under-test's `Config/Schema/schema.xml`, so tests run the pull request's code and schema rather than the baked-in release. Because the image's bundled release has already created the plugin's tables, this schema step is a reconciliation, not a clean apply (see KTD2 and U1).
- R8. The same containerized environment is runnable during development, in CI, and by Claude, from a single definition.

**Live-server tier**

- R9. The live-server tier exercises a real admin client provisioned on `dev.cilogon.org`, creating, editing, and deleting real OIDC clients to verify end-to-end behavior and sync, and cleans up what it creates.
- R10. The live-server tier runs on-demand or on a schedule, never as part of the per-pull-request merge gate, in a trusted context (for example, scheduled runs on `main`) where the admin-client credential is available.

**Secrets**

- R11. The `dev.cilogon.org` admin-client secret is never committed. It is provided to CI through GitHub encrypted secrets and to local and Claude runs through a gitignored environment file.
- R12. The admin-client credential is reachable only by the live-server workflow (scheduled or manually dispatched on `main`). It is never present in the environment of any pull-request-triggered workflow — including same-repo branch pull requests — and the live-server tier is never wired to a trigger that runs a pull request head's code with the credential in scope (for example, `pull_request_target` or `workflow_run` on pull-request artifacts).
- R13. The live-server tier uses a dedicated admin client provisioned solely for testing on `dev.cilogon.org`, scoped to the minimum privileges it needs and distinct from any staff or production admin credential, so a leak or an errant run has a bounded blast radius.

### Acceptance Examples

- AE1. Fork pull request, no secrets. **Covers R5, R6.** **Given** a pull request from a contributor's fork, **when** CI runs, **then** the hermetic tier runs to completion and gates the merge without needing any secret or network access to `dev.cilogon.org`.
- AE2. Schema-changing pull request. **Covers R7.** **Given** a pull request whose `Config/Schema/schema.xml` adds or changes a table, **when** the environment is prepared, **then** the schema reconciliation brings the database to the pull request's table shape (not the released image's) so tests run against it, by whichever reconciliation strategy U1 validates (see KTD2 and U1).
- AE3. A documented bug recurs. **Covers R3.** **Given** a change that reintroduces one of the 9 documented bugs (for example, the claim comparator drift), **when** the hermetic tier runs, **then** its regression test fails and blocks the merge.
- AE4. Live-server tier stays off the pull-request path. **Covers R6, R10.** **Given** any pull request, **when** CI runs, **then** the live-server tier does not run as part of the merge gate; it runs only on its schedule or on demand.

### Scope Boundaries

- Not in scope: broad line-coverage of all controllers and views toward a coverage percentage; the low-risk CRUD and view boilerplate is not a coverage target.
- Not in scope: testing COmanage Registry core itself — only the plugin's own behavior.
- Deferred and secondary: the live-server tier (R9-R13) is defined here but is a dependent follow-on that reuses the hermetic tier's harness; the hermetic per-PR tier is the primary build target. See How This Work Fits Together.

<!-- ce-section: work-relationships -->
### How This Work Fits Together

This plan owns the plugin test suite and CI. The breakdown below is the current understanding, not a committed roadmap.

- Hermetic per-PR tier — the primary build target; everything else **depends on** its environment and harness.
- Live-server tier — **Depends on** the hermetic tier's environment and harness; adds the `dev.cilogon.org` admin client and secret handling, and **can proceed independently of** the per-PR tier once that tier is green.
- `docs/solutions/` learnings — **Enable** the regression suite: each existing learning maps to a regression test, and the practice compounds as new bugs are documented.

### Dependencies / Assumptions

- The prebuilt image `public.ecr.aws/cilogon/comanage-registry` is reachable from CI and local environments and provides a working Registry, database, and PHP runtime. The gating tier pins a specific image digest so results are attributable to the pull request's code rather than image drift, and bumping the digest is an explicit, reviewable change. The live-server tier also pins a digest, because it runs the harness with the live `dev.cilogon.org` credential in scope and a mutable image alongside a secret is a supply-chain exfiltration risk.
- This repository's pull requests are branch-to-`main` within the developer's fork today (so secrets are available), but the hermetic tier is deliberately designed not to depend on that, so it keeps working if pull requests later come from other forks or the canonical home moves to `cilogon`.
- A container runtime is available in CI and local development. Whether the Claude/agent environment can run one is an open question resolved by U1's spike; if it cannot, R8's "runnable by Claude" degrades to Claude authoring and running the suite against a remote or CI-triggered environment rather than a local container.
- The plugin cannot be tested standalone; it depends on the Registry framework, which is why the environment carries a full Registry.

### Sources / Research

- Verified this session: no `.github/` workflows, no `composer.json`, no PHPUnit configuration, and no test bootstrap; `Test/` is empty placeholder directories only.
- `docs/solutions/` — 9 documented bugs (7 logic-errors, 1 integration-issue, 1 ui-bug) that seed the regression suite.
- Core-logic locations: `Controller/Component/Oa4mpClientAuthzComponent.php`, `Model/Oa4mpClientOa4mpServer.php`, `Model/Oa4mpClientCoSearchAttribute.php`, `Config/Schema/schema.xml`.
- Image: `public.ecr.aws/cilogon/comanage-registry:latest` (CILogon-managed public ECR), which bundles a previously released version of this plugin.
- The 9 documented bugs read for regression scenarios: `docs/solutions/logic-errors/` (admin-client hasOne duplicate insert; cfg-unmarshall swallowed TypeError; claim-migration three latent bugs; dynamo-config hasOne phantom null array; ldap-provisioner empty-type constraint; legacy orphan-claim recovery; unmarshall comparator drift), `docs/solutions/integration-issues/oa4mp-public-client-cfg-rejected`, `docs/solutions/ui-bugs/oa4mp-view-title-double-html-encoding`.

---

## Planning Contract

**Product Contract preservation:** unchanged — this enrichment adds planning sections only; requirements R1-R13 and acceptance examples AE1-AE4 (including the six review-hardened additions) are carried forward as written.

### Key Technical Decisions

- KTD1. **Spike-first: validate viability before building the harness.** U1 proves a CakePHP 2.x runner executes inside the image and settles how the pull request's schema reconciles over the image's pre-populated database, because either could invalidate the hermetic tier; the harness (U2) is built only after U1 succeeds. (session-settled: user-approved — chosen over building the harness first and discovering a blocker late.) Governs U1, U2.
- KTD2. **Schema reconciliation defaults to fresh-init from the pull request schema.** U1 first drops and re-creates the plugin's tables from the checkout's `Config/Schema/schema.xml`, side-stepping the non-idempotent raw-SQL foreign-key re-apply and the static `version="0.3"` gate over the image's already-initialized tables; incremental migration is the fallback only if fresh-init proves unworkable. (session-settled: user-approved — recommended and confirmed at plan synthesis.) Governs U1; instantiates R7.
- KTD3. **The OA4MP stub is pinned to captured real server responses** rather than hand-authored, so it does not re-encode the belief a marshalling bug violated (per R4). Governs U2, U4.
- KTD4. **One container-compose definition drives local, CI, and Claude runs**: the digest-pinned Registry image plus a companion database container, the plugin overlay, and a single entry command. (session-settled: user-directed — the one-environment Product Contract Key Decision instantiated as compose.) Governs U2; instantiates R6, R8.
- KTD5. **Test units are organized by subsystem, each covering core logic and its documented bugs together**, rather than a separate core-versus-regression split, so a subsystem's behavior and its regressions live in one place. (session-settled: user-approved — confirmed at plan synthesis.) Governs U3, U4, U5, U6.

### Assumptions

- Deferred to implementation (resolved in U1): the concrete schema-reconciliation mechanism and the concrete test runner both depend on running the actual image; U1's spike settles them and records the outcome before U2 proceeds.
- If U1 finds the Claude/agent environment cannot run the container, "runnable by Claude" (R8) degrades to Claude authoring and driving the suite against a CI-triggered or remote environment; this does not block the hermetic gate, which runs in CI.
- The plugin's fixes for all 9 documented bugs are already in the codebase; the regression tests lock the current fixed behavior and are written to fail against the pre-fix code path each learning describes.

---

## Implementation Units

### U1. Validate the runner and schema reconciliation (spike)

- **Goal:** Prove the hermetic environment is viable — a CakePHP 2.x test executes inside the image against a real database, and the pull request's schema is reconciled over the image's pre-populated tables.
- **Requirements:** R7, R8; see KTD1, KTD2.
- **Dependencies:** none.
- **Files:** `Test/bootstrap.php` (create), a throwaway smoke test under `Test/Case/` (create).
- **Approach:**
  1. Pull `public.ecr.aws/cilogon/comanage-registry` at a chosen digest; stand up the Registry app container plus a companion database container.
  2. Determine and record the test runner for this CakePHP 2.x / PHP stack inside the image (the Registry-bundled PHPUnit / CakeTestCase path, or a thin external runner), and get one trivial test to pass.
  3. Try KTD2's fresh-init reconciliation: drop and re-create the plugin's tables from the checkout's `Config/Schema/schema.xml`; confirm a schema-changing checkout yields the checkout's table shape. If fresh-init is unworkable, record why and evaluate incremental migration.
- **Execution note:** This is a spike; its deliverable is a validated environment plus a recorded decision on runner and reconciliation, not feature tests. If neither reconciliation path works or no runner executes, stop and surface a blocker — that invalidates the hermetic tier (R7) and is not a detail to guess past.
- **Test scenarios:** Test expectation: none -- spike; success is one passing smoke test and a recorded reconciliation decision.
- **Verification:** a trivial test passes inside the image against the real DB; a schema-changing checkout produces its own table shape; the runner and reconciliation strategy are recorded for U2.

### U2. Build the test harness and environment definition

- **Goal:** A single reproducible environment that overlays the plugin-under-test onto the image, reconciles the schema per U1, stubs OA4MP, and runs the suite identically locally, in CI, and for Claude.
- **Requirements:** R4, R6, R7, R8; see KTD3, KTD4.
- **Dependencies:** U1.
- **Files:** `Test/docker/docker-compose.yml` (create), `Test/bootstrap.php` (finalize), `Test/Stub/Oa4mpServerStub.php` (create), `Test/fixtures/oa4mp-responses/` (create — captured server responses), `Test/run.sh` (create), `Test/README.md` (create).
- **Approach:**
  1. Compose the digest-pinned Registry image plus a database container (KTD4).
  2. Overlay the checkout's plugin over the image's bundled copy (bind-mount or copy-in per U1) and apply U1's schema reconciliation.
  3. Provide the OA4MP stub (KTD3): intercept the plugin's server calls in `Model/Oa4mpClientOa4mpServer.php` and return captured responses; capture at least the public-client-cfg-rejection HTTP 400 and a confidential-client success.
  4. Expose one command (`Test/run.sh`) that brings up the environment, runs the suite, and reports pass/fail, usable by a developer, CI, and Claude.
- **Patterns to follow:** COmanage Registry plugin test conventions (`Test/Case`, `Test/Fixture`, `Test/bootstrap.php`); the server-call surface in `Model/Oa4mpClientOa4mpServer.php` (`oa4mpEditClient` and the marshalling entry points) for the stub seam.
- **Test scenarios:** Test expectation: none for the harness itself -- it is exercised by U3-U6; add one self-check that the runner exits non-zero when a deliberately failing test is present.
- **Verification:** `Test/run.sh` runs a test against the real DB with OA4MP stubbed, locally and in a clean checkout; a deliberately failing test makes the command exit non-zero.

### U3. Authorization tests

- **Goal:** Lock the three-role permission model.
- **Requirements:** R2; see KTD5.
- **Dependencies:** U2.
- **Files:** `Test/Case/Controller/Component/Oa4mpClientAuthzComponentTest.php` (create), `Test/Fixture/` fixtures for admin clients, OIDC clients, CO groups, and memberships (create).
- **Approach:** Exercise `permissionSet()` in `Controller/Component/Oa4mpClientAuthzComponent.php` across the three roles and the add/delegate/edit/delete/manage/index matrix, including manager-vs-editor gating on whether a client has an authorization (Editor) group.
- **Test scenarios:**
  - CO/platform admin has every capability (add, delegate, edit, delete, manage, index).
  - A delegated-management-group member (manager) has `add` and manages clients with no Editor group, but not `delegate`.
  - A per-client Editor-group member has `edit`/`manage` on that client only, and `add` is false.
  - A manager loses `edit`/`manage` on a client once that client has an Editor group they are not in.
  - `index` is true for a manager and for an editor-of-any-client, false for an unrelated user.
- **Verification:** the computed permission set matches the code for all three roles and the group-gated transitions.

### U4. cfg marshalling and sync-verification tests

- **Goal:** Lock cfg marshalling/unmarshalling and sync verification, including four documented bugs.
- **Requirements:** R2, R3, R5; see KTD3, KTD5.
- **Dependencies:** U2.
- **Files:** `Test/Case/Model/Oa4mpClientOa4mpServerTest.php` (create), `Test/Fixture/` fixtures for confidential/public clients, dynamo/named/default configs, and claims (create).
- **Approach:** Assert the marshalled `cfg` and the `isClientDataSynchronized()` comparison in `Model/Oa4mpClientOa4mpServer.php`, using the OA4MP stub for any server read.
- **Test scenarios:**
  - Covers R3 (public-client-cfg-rejected). Marshalling a public client produces no `cfg` in the PUT body; a confidential client does. The true server-acceptance check is the live tier (per R4, U9).
  - Covers R3 (dynamo phantom-null). A client with no per-client dynamo config marshals the admin `DefaultDynamoConfig` values (not all-null), and `isClientDataSynchronized()` reads the same fallback so it does not report false out-of-sync.
  - Covers R3 (swallowed TypeError). Unmarshalling a structurally valid QDLv2 format-1 cfg produces the expected `Oa4mpClientClaim` rows; a genuinely malformed cfg surfaces a real error rather than the misleading "not a defined format" message.
  - Covers R3 (comparator drift). For each cfg format (QDLv3, QDLv2, deprecated format-1), a freshly marshalled cfg compares in-sync against itself, and repeated edit cycles do not accumulate duplicate claim rows.
  - Happy path: a confidential client with claims round-trips marshall -> unmarshall -> compare as in-sync.
- **Verification:** each bug scenario fails against its documented pre-fix behavior and passes on current code; the happy-path round-trip is in-sync.

### U5. Claim mapping and migration tests

- **Goal:** Lock claim mapping and the search-attribute-to-claim migration, including four documented bugs.
- **Requirements:** R2, R3, R5; see KTD5.
- **Dependencies:** U2.
- **Files:** `Test/Case/Model/Oa4mpClientCoSearchAttributeTest.php` (create), `Test/Case/Model/Oa4mpClientClaimTest.php` (create), `Test/Fixture/` fixtures for search attributes, LDAP configs, claims, and constraints (create).
- **Approach:** Exercise `Model/Oa4mpClientCoSearchAttribute.php::toClaim()` and claim-constraint handling.
- **Test scenarios:**
  - Covers R3 (atomic save / orphan prevention). A migration whose middle save step fails does not leave an orphan claim (claim and back-pointer are atomic); repeated edit-page loads do not accumulate duplicate claim rows.
  - Covers R3 ("All Types" empty-type). An LdapProvisioner "All Types" search attribute (empty-string sentinel) migrates to a `type='all'` constraint, not an empty-string constraint, and the sync comparator treats it consistently.
  - Covers R3 (foreach loop-variable leak). When no LDAP attribute matches, no wrong-type constraint is emitted.
  - Covers R3 (empty-type never serialized). A degenerate `{constraint_field: 'type', constraint_value: ''}` constraint is never serialized to the server config.
  - Happy path: a normal search attribute migrates to a claim plus its back-pointer, and re-loading is idempotent.
- **Verification:** each bug scenario fails against its documented pre-fix behavior and passes on current code; migration is idempotent across repeated loads.

### U6. View-title and form-save regression tests

- **Goal:** Lock the two remaining documented bugs (view double-encoding, admin-client duplicate insert).
- **Requirements:** R3, R5; see KTD5.
- **Dependencies:** U2.
- **Files:** `Test/Case/View/Oa4mpViewTitleTest.php` (create), `Test/Case/Controller/Oa4mpClientCoAdminClientsControllerTest.php` (create), fixtures as needed (create).
- **Approach:** Render a view title with a name containing an apostrophe; save an admin client with an existing default config twice.
- **Test scenarios:**
  - Covers R3 (double-encoding). A view title built from an OIDC client name containing an apostrophe renders `scott's` (single-encoded), not `scott&#39;s`.
  - Covers R3 (admin-client duplicate insert). Saving an admin client whose `DefaultDynamoConfig` already exists, twice, does not create a duplicate dynamo-config row (the hidden id is preserved so the associated save updates rather than inserts).
  - Happy path: a first-time admin-client save creates exactly one default-config row.
- **Verification:** both bug scenarios fail against their documented pre-fix behavior and pass on current code.

### U7. Hermetic per-PR CI workflow

- **Goal:** Run the hermetic suite as the merge gate on every pull request.
- **Requirements:** R1, R5, R6, R7; see KTD4.
- **Dependencies:** U2 (and the test units it gates: U3-U6).
- **Files:** `.github/workflows/hermetic-tests.yml` (create).
- **Approach:**
  1. Trigger on `pull_request`; run `Test/run.sh` against the digest-pinned image with no secrets and no network egress to `dev.cilogon.org`.
  2. Make the job required so a failing suite blocks merge (R6).
  3. Pin the image by digest (KTD4) so results are attributable to the pull request's code.
  4. Add a lightweight secret scan (for example, gitleaks) so an accidentally committed credential red-lights the gate (a backstop to R11's gitignore).
- **Test scenarios:**
  - Covers AE1. A pull request from a fork runs the workflow to completion and gates the merge without any secret or network access.
  - Covers AE3. A change that reintroduces a documented bug makes the corresponding regression test fail and blocks the merge.
- **Verification:** the workflow runs on a pull request, needs no secrets, and its failure blocks merge; a seeded regression failure red-lights the gate.

### U8. Compounding-norm enforcement hook

- **Goal:** Make "every future bug adds a regression test" a mechanism, not just intent (R3's hook).
- **Requirements:** R3.
- **Dependencies:** none (can land alongside U2).
- **Files:** `.github/pull_request_template.md` (create or modify), a contributor note in `Test/README.md` (create/modify).
- **Approach:** Add a pull-request-template checklist item stating that a bug-fix pull request carries a regression test and links its `docs/solutions/` learning, and document the reviewer expectation that enforces it.
- **Test scenarios:** Test expectation: none -- documentation/process change.
- **Verification:** the PR template shows the checklist item; the contributor note states the reviewer expectation.

### U9. Live-server tier (dependent follow-on)

- **Goal:** Exercise a real admin client on `dev.cilogon.org` off the merge-gating path, with the credential scoped away from all PR code.
- **Requirements:** R9, R10, R11, R12, R13; see KTD4.
- **Dependencies:** U2.
- **Files:** `.github/workflows/live-server-tests.yml` (create), `Test/Case/LiveServer/` live-tier tests (create), `Test/.env.example` (create — documents required credential variables), `.gitignore` (modify — add the real env-file path so R11's never-committed is enforced by an explicit ignore entry).
- **Approach:**
  1. Store the credential in a dedicated GitHub Environment (`live-server`) whose deployment-branch policy is restricted to `main`, and reference that environment only from the credential-bearing job; additionally guard that job with `if: github.ref == 'refs/heads/main'`. This mechanizes R12 at the platform level: a repo-level secret alone does not, because a same-repo branch pull request can add a workflow that reads it and `workflow_dispatch` can select a non-main branch — the environment branch policy and the ref guard deny the secret in both cases.
  2. Trigger only on `schedule` or `workflow_dispatch` (never `pull_request`, `pull_request_target`, or `workflow_run` on pull-request head code). Use a dedicated least-privilege test admin client (R13); the credential is delivered through the `live-server` environment secret in CI and a gitignored env file locally (R11). Pin the image by digest here too (per Dependencies / Assumptions).
  3. Create/edit/delete real OIDC clients with uniquely-namespaced names, verify end-to-end acceptance and sync (including that OA4MP actually accepts a confidential cfg and rejects a public one), and clean up; a sweep removes orphaned test clients from a crashed run.
- **Execution note:** Dependent, secondary follow-on; do not gate merges on it. Never wire it to `pull_request_target` or `workflow_run` on PR head code (R12).
- **Test scenarios:**
  - Covers AE4. On any pull request the live-server workflow does not run.
  - End-to-end: creating a confidential client provisions it on `dev.cilogon.org` and reads back in-sync; a public client is accepted releasing only the sub claim.
  - Cleanup: a run leaves no test clients behind; a sweep removes uniquely-namespaced orphans from a prior crashed run.
- **Verification:** the workflow runs only on schedule/dispatch on `main`; a run provisions, verifies, and cleans up real clients; the credential never appears in a PR-triggered job.

---

## Verification Contract

| Gate | How | Applies to |
|---|---|---|
| Environment viability | U1's smoke test passes inside the image against the real DB; schema reconciliation yields the checkout's table shape. | U1 |
| Suite runs one way | `Test/run.sh` runs the suite locally, in CI, and for Claude from one definition; a deliberate failure exits non-zero. | U2, U7 |
| Core-logic coverage | Authorization, cfg marshalling/sync, and claim mapping/migration each have passing tests asserting the documented behavior. | U3, U4, U5 |
| Documented-bug regressions | Each of the 9 bugs has a test that passes on current code and fails against its documented pre-fix behavior. | U4, U5, U6 |
| Merge gate | The hermetic workflow is required on `pull_request`, needs no secrets or network, and blocks merge on failure. | U7 |
| Secret isolation | The `dev.cilogon.org` credential is bound to a `main`-only GitHub Environment and a `github.ref` guard, present only in the live-server job, never in any PR-triggered or non-main-branch job. | U9 |
| Compounding hook | The PR template and contributor note make bug-fix pull requests carry a regression test. | U8 |

---

## Definition of Done

- U1's spike has validated the runner and recorded the schema-reconciliation strategy, or surfaced a blocker.
- `Test/run.sh` runs the hermetic suite against a real database with OA4MP stubbed, identically locally, in CI, and for Claude.
- Authorization, cfg marshalling/sync, and claim mapping/migration have passing tests, and each of the 9 documented bugs has a passing regression test that fails against its pre-fix behavior.
- The hermetic GitHub Actions workflow gates every pull request, needs no secrets or network, and blocks merge on failure (AE1, AE3).
- The suite is organized into the two tiers per R1: U7 the hermetic per-PR gate, U9 the separate non-gating live-server tier.
- The live-server workflow runs only on schedule/dispatch on `main`, with the credential bound to a `main`-only GitHub Environment and a `github.ref` guard so it is scoped away from all PR and non-main-branch code (AE4, R12), uses a dedicated least-privilege test admin client, pins the image by digest, and cleans up what it creates.
- The compounding-norm hook (PR template plus reviewer expectation) is in place.
- All Verification Contract gates pass. Each unit is committed locally with a conventional-commit message (`test:`, `ci:`, or `docs:`) and the repo's Co-Authored-By trailer; the developer pushes.
