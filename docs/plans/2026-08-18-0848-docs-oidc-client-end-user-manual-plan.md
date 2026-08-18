---
title: OIDC Client Plugin End-User Manual - Plan
type: docs
date: 2026-08-18
topic: oidc-client-end-user-manual
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-brainstorm
execution: code
---

# OIDC Client Plugin End-User Manual - Plan

## Goal Capsule

- **Objective:** CO admins, delegated group members, and per-client editors can learn, from a single in-repo manual, how the OIDC client plugin works and how to accomplish every task in its UI — including the access-control and delegation model that today can only be reverse-engineered from the interface.
- **Product authority:** This document, distilled from dialogue with CILogon staff (Scott Koranda), grounded in the plugin's controllers, views, and `CONCEPTS.md`. The motivating evidence is an email from a deployer (Patrick) who could not discover the access-control model from the updated UI.
- **Open blockers:** None.

---

## Product Contract

### Summary

A comprehensive, in-repo Markdown end-user manual for the OIDC client plugin, written for CO admins, delegated group members, and per-client editors. It is organized concepts-first — OIDC basics and the three-role access-control/delegation model — then task walkthroughs across the full UI surface. The current README stub becomes a front door that links to it.

### Problem Frame

The plugin has no end-user documentation. `README.md` is a five-line stub, and `cfg_format.md` is a configuration-format reference, not a user guide. The access-control model in particular is undiscoverable from the interface: a deployer emailed asking where the "Delegated Management Group" dropdown went in the updated Admin UI, whether a previously linked CO group still grants client creation, and why members granted Editor access to a client cannot add new clients without being over-privileged into managing every other client. These are sharp, technical users who could not reconstruct the model from the UI. The cost lands as repeated support questions, COs stuck during onboarding, and delegation misconfigured into over-privilege.

### Key Decisions

- **Comprehensive manual, not a focused guide** (session-settled: user-directed — chosen over a focused access-control guide and a focused-first-with-roadmap variant: the whole UI should be covered, not only the part currently causing pain). Governs R1.
- **Concepts-first organization** (session-settled: user-directed — chosen over pure task-oriented and by-UI-screen reference layouts: readers need the mental model before the click-paths, because the model is exactly what the UI does not reveal). Governs R2, R3.
- **Home is in-repo Markdown; README becomes a front door** (session-settled: user-directed — chosen over a published docs site and the private `operational-info` repo: versioned with the code and public with the plugin, unlike the internal deployer runbooks). Governs R7, R8.
- **Documentation-only scope** (session-settled: user-directed — "doc now, feature separate"): the manual documents the "Editors cannot add clients" behavior as a current, intentional constraint; changing that capability is a separate feature brainstorm. Governs R4; see Scope Boundaries and How This Work Fits Together.

### Requirements

**Coverage**

- R1. The manual covers the full end-user UI surface: Admin Clients (including delegation), OIDC Clients (create / edit / list, and viewing the client secret), Claims, Scopes, Callbacks, Named configurations, per-client Access Controls (Editors), Authorizations, and Access / Refresh token management.
- R2. The manual opens with a concepts chapter defining the domain vocabulary a reader needs — at least OIDC client, Admin client, public vs confidential client, claim, scope, named and default configuration, and the sync model — pitched as a light primer for COmanage admins rather than IdP engineers.

**Access control and delegation**

- R3. The manual documents the three effective roles and who can do what: platform/CO admin; manager (a member of an admin client's delegated management group); and editor (a member of a per-client authorization group). It explains where delegated management is now configured (the admin-only delegate action) and how per-client Editors are set up.
- R4. The manual documents, as a current and intentional constraint, that an editor can edit, delete, and manage a specific client but cannot add new clients, and that adding requires delegated-management-group membership — naming the over-privilege trade-off a reader would otherwise discover the hard way.

The role-to-capability matrix the access-control chapter must convey, grounded in `Controller/Component/Oa4mpClientAuthzComponent.php`:

| Capability | CO / platform admin | Manager (delegated management group) | Editor (per-client authorization group) |
|---|---|---|---|
| Add a new OIDC client | Yes | Yes | No |
| Configure delegation (delegate action) | Yes | No | No |
| Edit / delete / manage a client | Yes | Yes, unless the client has an Editors group | Yes, only when the client has an Editors group |
| View the client list | Yes | Yes | Yes (clients they can edit) |

**Task walkthroughs**

- R5. Each covered UI surface has a task-oriented walkthrough describing what the screen is for, the fields the user sets, and the resulting outcome — written so a delegated group member who does not know OIDC internals can complete the task.
- R6. Walkthroughs note the behavioral constraints the UI enforces that a user would otherwise trip over — for example, that claims cannot be configured on a public client (only the standard `sub` claim is released), and that an out-of-sync client blocks edits.

**Home and discoverability**

- R7. The manual lives as Markdown under `docs/` in this repository, versioned with the plugin.
- R8. The README is reworked from its current stub into a front door: what the plugin is, how to enable it, and a link to the manual. It links out to — rather than duplicating — the `cfg` format reference (`cfg_format.md`) and the deployer runbooks.

### Acceptance Examples

- AE1. Editors and adding clients. **Covers R4.** **Given** a user who is a member of a client's authorization (Editors) group but not the delegated management group, **when** they view the OIDC clients list, **then** the manual leads them to expect edit and manage actions on that client but no "Add a New OIDC Client" action, and explains why and what to request if they need to add one.
- AE2. Claims on a public client. **Covers R6.** **Given** a public client, **when** the user opens its Claims tab, **then** the manual explains that claim configuration is not offered because a public client releases only the standard `sub` claim.
- AE3. Out-of-sync client. **Covers R6.** **Given** a client whose server representation has drifted from the plugin's stored copy, **when** the user attempts an edit, **then** the manual explains the "out of sync" block and the remediation path.
- AE4. Discovering delegated management after the UI change. **Covers R3.** **Given** a CO admin migrating from the previous Admin UI who looks for the former "Delegated Management Group" dropdown on the admin-client edit form, **when** they consult the manual, **then** it directs them to the admin-only delegate action as the place delegated management is now configured, and explains that a CO group previously linked to an admin client still grants client creation through delegated-management-group membership.

### Scope Boundaries

- Not duplicated here: the `cfg` JSON format reference (`cfg_format.md`) and the deployer runbooks (admin-client setup and DynamoProvisioner target, which live in the private `operational-info` repo). The manual links out to them.
- Deferred and separate: enabling editors to add clients without over-privilege is a separate feature brainstorm; this manual documents only the current behavior.
- Out of scope: developer, maintainer, and architecture documentation; API-level or QDL-authoring reference material.

<!-- ce-section: work-relationships -->
### How This Work Fits Together

This plan owns the end-user manual. The breakdown below is the current understanding, not a committed roadmap.

- Editors-can-add-clients capability — **Still to decide;** a separate feature brainstorm. This manual documents the current constraint (R4) and would be updated if the capability lands.
- Deployer runbooks (admin-client, DynamoProvisioner target) — **Can proceed independently of** this manual; they share an audience overlap but live in the private `operational-info` repo, and the manual links out rather than absorbing them.
- `cfg` format reference (`cfg_format.md`) — **Enables** the claims and configuration walkthroughs; the manual links to it as the authority on the JSON shape.

### Dependencies / Assumptions

- Screenshots: assume text-first with sparing screenshots, because the UI is actively changing (recent public-client and claims work) and screenshots would go stale quickly. Revisit if a different balance is wanted.
- Assume the plugin repository is public / consumer-visible, making in-repo Markdown an appropriate home for audience-facing documentation.
- The access-control model in R3 and R4 is grounded in `Controller/Component/Oa4mpClientAuthzComponent.php`; the manual's description must track that source as the code evolves.

### Sources / Research

- `Controller/Component/Oa4mpClientAuthzComponent.php` — `permissionSet()`, `isManager()`, `isEditor()`: the three-role model and the add / edit / delete / manage / index matrix.
- `Controller/Oa4mpClientCoAdminClientsController.php` (delegate action, ~line 188) — where delegated-management configuration moved after the UI update.
- `CONCEPTS.md` — canonical domain vocabulary for the concepts chapter.
- `cfg_format.md` — the `cfg` JSON reference to link from the claims and configuration walkthroughs.
- UI surface inventory from `Controller/*.php` and `View/*/`: Admin Clients, OIDC Clients, Claims, Scopes, Callbacks, Named Configs, Access Controls, Authorizations, Access Tokens, Refresh Tokens.
- `docs/solutions/integration-issues/oa4mp-public-client-cfg-rejected-2026-08-03.md` — the public-client claims constraint that AE2 documents.

---

## Planning Contract

**Product Contract preservation:** unchanged — this enrichment adds planning sections only; no Product Contract requirement, acceptance example, or scope boundary was altered.

### Key Technical Decisions

- KTD1. **Single Markdown file at `docs/oidc-client-plugin-manual.md`.** One comprehensive document rather than a multi-file guide, matching R7's "single in-repo manual" and the repo's flat `docs/` layout. (session-settled: user-approved — chosen over a `docs/manual/` folder: confirmed at plan synthesis.) Governs R7.
- KTD2. **Concepts-first chapter order.** Concepts, then Access control & delegation, then Client management, then Claims/Scopes/Callbacks/Named configs, then Token management; the README front door is a separate unit. Instantiates the "Concepts-first organization" Product Contract Key Decision. Governs R2, R3, R5.
- KTD3. **Text-first, no screenshots in v1; describe UI locations by function.** The UI is actively changing (recent public-client and claims work), so v1 ships prose click-paths and no screenshots, naming UI locations by purpose (e.g., "the admin-only delegate action") to limit drift. Resolves the previously deferred screenshot question.
- KTD4. **Concepts chapter restates a reader-friendly subset and links to `CONCEPTS.md`.** The chapter defines the vocabulary a reader needs in plain terms and points to `CONCEPTS.md` as the canonical source rather than duplicating it wholesale. Resolves the previously deferred concepts-chapter question. Governs R2.
- KTD5. **"Editors cannot add clients" is framed as a current limitation under active review.** A separate feature brainstorm may remove this constraint; framing it as current-behavior-under-review keeps AE1's guidance from becoming wrong advice if the feature lands. Governs R4; see the work-relationships section.
- KTD6. **A short, access-control-scoped migration note is folded into the delegation chapter.** Rather than a standalone changelog, the delegation chapter carries a brief "what changed from the previous Admin UI" note covering the relocated delegated-management configuration, satisfying AE4 and the deployer cohort's original question. Governs R3.

### Assumptions

- The manual is authored against the current plugin UI on branch `main` (head at planning time) and carries an "as of" note so readers can judge currency.
- The role-to-capability matrix in the access-control chapter is transcribed from `Controller/Component/Oa4mpClientAuthzComponent.php` and must be re-verified against that file at authoring time.
- This repository is public / consumer-visible, so an in-repo Markdown manual is an appropriate home for audience-facing documentation.
- One commit per implementation unit in this repository, carrying the repo's conventional-commit `docs:` prefix and Co-Authored-By trailer; the user pushes.

---

## Implementation Units

### U1. Scaffold the manual and write the Concepts chapter

- **Goal:** Create the manual file with its chapter skeleton and table of contents, and write the opening Concepts chapter.
- **Requirements:** R2 (grounds the vocabulary a reader needs); see KTD1, KTD2, KTD4.
- **Dependencies:** none.
- **Files:** `docs/oidc-client-plugin-manual.md` (create).
- **Approach:**
  1. Create the file with an H1 title, a short intro naming the audience (CO admins, delegated group members, per-client editors), an "as of" currency note, and a table of contents in KTD2 order.
  2. Write the Concepts chapter covering, in plain terms: OIDC client, Admin client, public vs confidential client, claim, scope, named configuration, default configuration, and the sync model (R2).
  3. Restate a reader-friendly subset of vocabulary and link to `CONCEPTS.md` as the canonical source (KTD4) rather than duplicating it.
- **Patterns to follow:** `CONCEPTS.md` for definitions; ASCII-only, no box-drawing characters, per repo `AGENTS.md`.
- **Test scenarios:** Test expectation: none -- prose deliverable; correctness is verified against `CONCEPTS.md` and by the Verification Contract's content-coverage gate.
- **Verification:** the file exists with the full chapter skeleton and TOC; the Concepts chapter defines each R2 term and links to `CONCEPTS.md`.

### U2. Write the Access control & delegation chapter

- **Goal:** Document the three-role model, the capability matrix, how delegation and per-client Editors are configured (including a task walkthrough of the per-client Access Controls screen), the editors-cannot-add constraint, and the migration note.
- **Requirements:** R1 (per-client Access Controls / Editors screen), R3, R4; AE1, AE4; see KTD2, KTD5, KTD6.
- **Dependencies:** U1.
- **Files:** `docs/oidc-client-plugin-manual.md` (modify).
- **Approach:**
  1. Explain the three effective roles — platform/CO admin, manager (delegated management group member), editor (per-client authorization group member) — per R3.
  2. Reproduce the role-to-capability matrix from the Product Contract, transcribed and re-verified against `Controller/Component/Oa4mpClientAuthzComponent.php` (`permissionSet()`).
  3. Describe where delegated management is configured (the admin-only delegate action) and, as a task walkthrough, how per-client Editors are set up on the per-client Access Controls screen (`View/Oa4mpClientAccessControls/`) — the surface R1 names "per-client Access Controls (Editors)" (R1, R3).
  4. Document the editors-cannot-add-clients behavior as a current limitation under active review (R4, KTD5), including AE1's guidance on what to request if an editor needs to add a client.
  5. Add the short access-control-scoped migration note (KTD6, AE4): the relocated delegated-management configuration, and that a previously linked CO group still grants creation via delegated-management-group membership.
- **Patterns to follow:** the capability matrix in this plan's Product Contract; `View/Oa4mpClientCoAdminClients/delegate.ctp` and `Controller/Oa4mpClientCoAdminClientsController.php` for the delegate configuration path; `View/Oa4mpClientAccessControls/` for the per-client Editors (Access Controls) screen.
- **Test scenarios:**
  - Covers AE1. A passage tells an editor (authorization-group member, not delegated-management-group member) to expect edit/manage on their client but no "Add a New OIDC Client" action, and what to request to add one.
  - Covers AE4. A passage directs a CO admin looking for the former "Delegated Management Group" dropdown to the delegate action, explaining that a previously linked CO group still grants creation.
  - The rendered matrix matches `permissionSet()` for add / delegate / edit / delete / manage / index across the three roles.
  - The per-client Access Controls (Editors) screen has a walkthrough covering how an admin assigns the authorization group that grants editors on a client.
- **Verification:** every R3/R4 element, the Access Controls screen walkthrough, and both AE1 and AE4 have a corresponding passage; the matrix is verified against code.

### U3. Write the client-management walkthroughs

- **Goal:** Task walkthroughs for Admin Clients and OIDC Clients, including public vs confidential and the out-of-sync edit block.
- **Requirements:** R1 (Admin Clients, OIDC Clients), R5, R6; AE3; see KTD2, KTD3.
- **Dependencies:** U1.
- **Files:** `docs/oidc-client-plugin-manual.md` (modify).
- **Approach:**
  1. Admin Clients walkthrough (add / edit / list), and what an admin client is for — cite the Concepts chapter rather than restating.
  2. OIDC Clients walkthrough (create / edit / list), viewing the client secret, and the public-vs-confidential choice at creation (R5).
  3. Document the out-of-sync edit block and its remediation path (R6, AE3), describing UI locations by function (KTD3).
- **Patterns to follow:** `View/Oa4mpClientCoAdminClients/` and `View/Oa4mpClientCoOidcClients/` for the actual form fields and actions.
- **Test scenarios:**
  - Covers AE3. A passage explains the "out of sync" block on edit and the remediation path.
  - Each covered screen (Admin Clients add/edit/list; OIDC Clients add/edit/list/secret) has a walkthrough naming its purpose, key fields, and outcome.
- **Verification:** the covered screens each have a task walkthrough; AE3 has a passage.

### U4. Write the claims, scopes, callbacks, and named-configuration walkthroughs

- **Goal:** Task walkthroughs for the per-client configuration surfaces.
- **Requirements:** R1 (Claims, Scopes, Callbacks, Named configs), R5, R6; AE2; see KTD2, KTD3.
- **Dependencies:** U1.
- **Files:** `docs/oidc-client-plugin-manual.md` (modify).
- **Approach:**
  1. Claims walkthrough (add / edit / list), and the public-client constraint — claim configuration is not offered on a public client, which releases only the standard `sub` claim (R6, AE2).
  2. Scopes walkthrough: the available scopes and per-client scope editing.
  3. Callbacks walkthrough: managing callback (redirect) URIs.
  4. Named configurations walkthrough (add / edit / list / manage), when to use one, and a link to `cfg_format.md` as the authority on the JSON shape rather than duplicating it.
- **Patterns to follow:** `View/Oa4mpClientClaims/`, `View/Oa4mpClientCoScopes/`, `View/Oa4mpClientCoCallbacks/`, `View/Oa4mpClientCoNamedConfigs/`; the public-client claims behavior in `docs/solutions/integration-issues/oa4mp-public-client-cfg-rejected-2026-08-03.md`; `cfg_format.md`.
- **Test scenarios:**
  - Covers AE2. A passage explains that a public client's Claims tab does not offer claim configuration because only the standard `sub` claim is released.
  - Each covered screen (Claims, Scopes, Callbacks, Named configs) has a walkthrough naming its purpose, key fields, and outcome.
- **Verification:** the four surfaces each have a walkthrough; AE2 has a passage; the named-config walkthrough links to `cfg_format.md`.

### U5. Write the token-management walkthroughs

- **Goal:** Task walkthroughs for access tokens, refresh tokens, and authorizations.
- **Requirements:** R1 (Access Tokens, Refresh Tokens, Authorizations), R5; see KTD2.
- **Dependencies:** U1.
- **Files:** `docs/oidc-client-plugin-manual.md` (modify).
- **Approach:**
  1. Access token management walkthrough (the `manage` view).
  2. Refresh token management walkthrough.
  3. Authorizations management walkthrough, cross-referencing the access-control chapter (U2) for the editor / authorization-group relationship.
- **Patterns to follow:** `View/Oa4mpClientAccessTokens/`, `View/Oa4mpClientRefreshTokens/`, `View/Oa4mpClientAuthorizations/`.
- **Test scenarios:** each of the three token/authorization surfaces has a walkthrough naming its purpose and outcome. Test expectation otherwise: none -- prose deliverable.
- **Verification:** the three surfaces each have a walkthrough; authorizations cross-references U2.

### U6. Rework the README into a front door and link out

- **Goal:** Turn the README stub into a real front door linking to the manual, and link out to existing references.
- **Requirements:** R7, R8; see KTD1.
- **Dependencies:** U1 (the manual must exist to link to).
- **Files:** `README.md` (modify).
- **Approach:**
  1. Expand the README from its stub to state what the plugin is, how to enable it, and a link to `docs/oidc-client-plugin-manual.md` (R8).
  2. Link out to — not duplicate — `cfg_format.md` (the `cfg` reference), and note that the deployer runbooks live in the private `operational-info` repo.
- **Patterns to follow:** the current `README.md`; the manual path from KTD1.
- **Test scenarios:** the README links resolve (the manual path and `cfg_format.md` exist); no reference content is duplicated into the README. Test expectation otherwise: none -- prose deliverable.
- **Verification:** the README names the plugin, describes enabling it, and links to the manual and `cfg_format.md`; links resolve.

---

## Verification Contract

There is no automated test harness for prose (the repo's `Test/` tree is a placeholder), so verification is author-side and content-based.

| Gate | How | Applies to |
|---|---|---|
| Content coverage | Every requirement R1–R8 has corresponding manual content; every acceptance example AE1–AE4 has a passage that satisfies it. | U1–U6 |
| Matrix accuracy | The role-to-capability matrix in the access-control chapter matches `permissionSet()` in `Controller/Component/Oa4mpClientAuthzComponent.php`. | U2 |
| Link integrity | The README's links to the manual and `cfg_format.md` resolve; the named-config walkthrough's link to `cfg_format.md` resolves. | U4, U6 |
| Rendering and style | The manual and README render as valid Markdown; ASCII-only, no box-drawing characters, per repo `AGENTS.md`. | U1–U6 |
| No duplication | The README and manual link out to `cfg_format.md` and the runbooks rather than duplicating them. | U4, U6 |

---

## Definition of Done

- `docs/oidc-client-plugin-manual.md` exists and covers R1–R8: the Concepts chapter, the Access control & delegation chapter (with the code-verified capability matrix), and task walkthroughs for every UI surface named in R1.
- AE1, AE2, AE3, and AE4 each have a passage in the manual that satisfies them.
- The editors-cannot-add-clients behavior is framed as a current limitation under active review (KTD5).
- `README.md` is reworked into a front door that names the plugin, describes enabling it, links to the manual, and links out to `cfg_format.md` and the runbooks (R8).
- All Verification Contract gates pass.
- Each unit is committed locally in this repository with a `docs:` conventional-commit message and the repo's Co-Authored-By trailer; nothing is pushed by the agent.
