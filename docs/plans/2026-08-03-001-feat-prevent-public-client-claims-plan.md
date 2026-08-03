---
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-brainstorm
execution: code
date: 2026-08-03
type: feat
topic: prevent-public-client-claims
---

# Prevent Claim Configuration for Public Clients - Plan

## Goal Capsule

**Objective**: In the Oa4mpClient plugin, prevent configuring claims for a public OIDC client and make the reason legible in the UI. Public clients release only the default `sub` claim, so per-client claim configuration has no effect for them. This is the interface/controller complement to the already-shipped server-side marshaller guard (`Model/Oa4mpClientOa4mpServer.php`, commit `6cd9065`) that stopped sending a `cfg` for public clients.

**Product authority**: Scott Koranda (CILogon).

**Open blockers**: None. One assumption to confirm at planning time (A1, below) about pre-existing public clients.

## Product Contract

### Problem Frame

A public OIDC client (`token_endpoint_auth_method: none`) releases only the default `sub` claim; OA4MP does not permit a custom configuration on it. Today the CLAIMS tab is shown for every client with no client-type awareness (`View/Oa4mpClientCoOidcClients/tabs.inc:112`), so a deployer can open the tab on a public client and attempt to configure claims that will never be released. The recently fixed server-side symptom was an "Unable to edit OIDC client" failure; this work removes the dead-end configuration path itself and explains why, rather than letting users configure claims that silently do nothing.

The Scopes UI already models the desired treatment: for a public client it keeps the tab visible, disables the controls that do not apply, and swaps in an explanatory description (`View/Oa4mpClientCoScopes/fields.inc:52-83`). This feature applies the same shape to Claims.

### Actors

- **A1 — Deployer/administrator**: a CO or platform administrator managing OIDC clients through the Registry UI. The only actor.

### Requirements

- **R1**: When the client being viewed is a public client, the Claims index view must not offer any way to add a claim (no Add Claim action/button).
- **R2**: When the client is a public client, the Claims view must display an explanatory message, rendered above the claims listing, stating that public clients release only the default `sub` claim and that additional claims cannot be configured. For the normal case of a public client with no claim rows, the empty claims table (the bare `Claim Name` / `Actions` header row with no body rows) is suppressed, so the deployer sees the explanatory message rather than an empty two-column grid.
- **R3**: The CLAIMS tab remains visible for public clients (it is not hidden), consistent with how the Scopes tab is treated.
- **R4**: For a confidential (non-public) client, the Claims UI is unchanged — Add/edit/delete of claims continues to work exactly as today.
- **R5**: `Oa4mpClientClaimsController` must reject every claim write path — `add`, `edit` (both the GET form render and the POST), and `delete` — when the target client is a public client, returning a flash error rather than rendering an edit form or persisting/removing a claim row. This holds even for a request that did not come through the UI (a hand-crafted POST or a direct edit/delete URL).
- **R6**: The public-client determination reuses the existing `public_client` field on the OIDC client record already available where the Claims controller and views run (`Controller/Oa4mpClientClaimsController.php:62` loads the client via `current()`), matching how `Oa4mpClientCoScopes/fields.inc` reads it.
- **R7**: When the client is a public client, the Claims index view must not render the per-row **Edit** or **Delete** action buttons (`View/Oa4mpClientClaims/index.ctp` currently renders these per row, gated only on permissions). Any claim rows shown are read-only. This closes the affordance that would otherwise re-open the exact edit dead-end the feature removes.

### Key Flows

- **F1 — Deployer opens the CLAIMS tab on a public client**: For the normal case (a public client with no claim rows), the tab renders, shows the explanatory message (R2), and offers no Add action (R1). If any claim rows are present and reachable, they are shown read-only with no per-row Edit/Delete affordance (R7), and the controller rejects any direct edit/delete request (R5). The deployer understands why and moves on. (Note: a legacy public client that already holds claim rows may not reach this tab at all — see A1 — which planning must resolve.)
- **F2 — Direct POST to the Claims add/edit endpoint for a public client**: The controller detects the public client and rejects the operation with a flash error (R5); no claim row is created or modified.

### Acceptance Examples

- **AE1**: Given a public client, when the deployer opens its CLAIMS tab, then the explanatory message is shown and there is no Add Claim button.
- **AE2**: Given a public client, when a POST is made to the Claims `add` (or `edit`) action for that client, then the controller returns a flash error and no `Oa4mpClientClaim` row is created or changed.
- **AE3**: Given a confidential client, when the deployer opens its CLAIMS tab, then Add/edit/delete of claims work exactly as before (no regression).
- **AE4**: Given a public client, when a request is made to the Claims `edit` action (GET form render or POST) or the `delete` action for that client — including via a direct URL — then the controller returns a flash error and renders no edit form and mutates no `Oa4mpClientClaim` row.

### Scope Boundaries

**In scope**
- Claims index view for public clients: no Add action, explanatory message, and per-row Edit/Delete affordances suppressed (R1-R3, R7).
- `Oa4mpClientClaimsController` server-side guard rejecting add, edit (GET render + POST), and delete for public clients (R5).

**Out of scope (non-goals)**
- Building the actual cleanup/migration for pre-existing public clients that already hold claim rows is deferred to planning, not this Product Contract — but it is **not** dismissed as unnecessary: whether such rows exist and how to remediate them is a planning question (see A1), because they force an out-of-sync redirect rather than being inert. The DynamoDB default-config rows, by contrast, are genuinely inert (both-sides-gated) and need no attention.
- Scopes behavior for public clients (already implemented) and the marshaller `cfg` guard (already shipped in `6cd9065`).
- Any change to confidential-client claims behavior.

### Key Decisions

- **KD1**: UI treatment is "keep the tab, remove the Add action, and explain," mirroring the Scopes precedent — not hiding the CLAIMS tab. Rationale: consistency with the existing public-client UX in `Oa4mpClientCoScopes/fields.inc`; the tab still communicates *why* nothing can be configured. The Scopes precedent governs the interaction *pattern* (tab visible, controls disabled/removed, explanatory text), not the *layout*: Scopes is a fixed checkbox list with an inline field description, whereas Claims is a data table, so R2 specifies message placement and empty-table handling explicitly rather than by analogy.
- **KD2**: Enforcement is both UI and server-side. Rationale: the UI change alone can be bypassed by a crafted POST; the controller guard makes the prevention authoritative and is cheap defense in depth atop the marshaller guard.

### Assumptions and Outstanding Questions

- **A1 (must be resolved at planning)**: Legacy public-client claim rows are **not** inert. The `cfg`/DynamoDB default-config reasoning does not transfer to per-client claim rows: `isClientDataSynchronized` compares claims with an asymmetric guard (`Model/Oa4mpClientOa4mpServer.php:441` — `if(!empty($curClaims) && empty($oa4mpClaims)) return false`), unlike the both-sides-gated DynamoDB block. Because the marshaller sends no claims for a public client, a public client that already holds `Oa4mpClientClaim` rows is judged out of sync, so `Oa4mpClientClaims::index()`/`edit()` redirect with the `bad_client` flash error and the R2/R3/F1 message never renders for them. Planning must resolve this: either confirm empirically (a query) that no public client holds claim rows, or add a cleanup/remediation requirement. Only the DynamoDB default-config is genuinely inert (it is both-sides-gated).
- **OQ1**: Exact copy for the explanatory message (R2) — wording to be finalized against the existing lang-string style (for example, the sibling `pl.oa4mp_client_co_scope.scope.fd.description.public` string). A wording detail, not a scope question.

---

## Planning Contract

**Product Contract preservation**: Product Contract unchanged — enrichment adds the Planning Contract only; R1-R7, A1, and the acceptance examples carry forward as written.

All file paths are repo-relative to the plugin root (`/mnt/pophome/CILogon2/OA4MPWork/Oa4mpClient`). This plugin has no runnable test harness (`Test/` contains only empty placeholder directories), so verification is manual/behavioral against the acceptance examples plus `php -l` on changed files — see the Verification Contract.

### Key Technical Decisions

- **KTD1**: Determine public-client status from `$client['Oa4mpClientCoOidcClient']['public_client']` on the record already loaded by `Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId)`, mirroring how `View/Oa4mpClientCoScopes/fields.inc` reads the same field. No new query or model method. Governs R6; used by U1 and U2.
- **KTD2**: Place the server guard at the entry of `add()`, `edit()`, and `delete()` — immediately after the client is loaded and before any form render, OA4MP call, or persistence — returning a flash error and redirecting to the Claims index. `edit()` is a single action handling both the GET form render and the POST, so guarding at its entry covers both; `delete()` mutates via `oa4mpEditClient` and so is a genuine write path. Governs R5, KD2; realized in U1.
- **KTD3**: The Scopes precedent governs the interaction *pattern* only. The index view is a data table, so U2 handles Claims-specific rendering (suppress the Add top-link, suppress per-row Edit/Delete, render R2's message above the listing, suppress the empty table) rather than copying the Scopes checkbox layout. Governs R1-R3, R7, KD1; realized in U2.
- **KTD4** (session-settled: user-directed — chosen over deferring to a manual execution-time check and over relaxing the sync comparator: cleanup keeps both the UI feature and the data consistent without touching sync-verification logic): remediate legacy public-client claim rows with a one-time query-and-delete rather than a code change to `isClientDataSynchronized`. Governs A1; realized in U3.

### Implementation Units

### U1. Reject claim write paths for public clients (server guard)

- **Goal**: `Oa4mpClientClaimsController` rejects `add`, `edit` (GET render and POST), and `delete` for a public client, so no crafted request or direct URL can create, edit, or remove a claim on a public client.
- **Requirements**: R5, R6; enforces KD2 (per KTD2).
- **Dependencies**: none.
- **Files**:
  - `Controller/Oa4mpClientClaimsController.php` (modify)
  - `Lib/lang.php` (modify — add a flash-error lang key, e.g. `pl.oa4mp_client_claim.er.public_client`)
- **Approach**:
  1. Add a small private helper that returns whether the loaded client is a public client, reading `Oa4mpClientCoOidcClient.public_client` (per KTD1).
  2. In `add()`, `edit()`, and `delete()`, immediately after the client is loaded via `current($clientId)` and before any form render / `oa4mpEditClient` call / save, return early when the client is public: set a flash error (new lang key) and redirect to the Claims `index` for that `clientid`.
  3. Reuse the existing `clientid` named-param and redirect pattern already used by these actions.
- **Patterns to follow**: the public-client read in `View/Oa4mpClientCoScopes/fields.inc:52-60`; the existing flash-then-redirect shape in `delete()`/`edit()`.
- **Test scenarios**: Test expectation: none — no runnable harness in this plugin (`Test/` is empty scaffolding). Verify manually per AE2 and AE4 (below) and lint with `php -l`.
- **Verification**: For a public client, a POST to `add`, a GET or POST to `edit`, and a POST to `delete` each return the flash error and redirect to the index with no `Oa4mpClientClaim` row created, changed, or removed. Covers AE2, AE4.

### U2. Public-client treatment in the Claims index view

- **Goal**: For a public client, the CLAIMS tab renders with the explanatory message and no way to configure claims: no Add action, no per-row Edit/Delete, no empty grid; the tab stays visible.
- **Requirements**: R1, R2, R3, R7; enforces KD1 (per KTD3).
- **Dependencies**: none (independent of U1; both read `public_client`).
- **Files**:
  - `View/Oa4mpClientClaims/index.ctp` (modify)
  - `Lib/lang.php` (modify — add the R2 explanatory-message lang key, e.g. `pl.oa4mp_client_co_oidc_client.claims.public_client.description`, wording per OQ1)
- **Approach**:
  1. Set `$is_public_client` from `$this->request->data['Oa4mpClientCoOidcClient']['public_client']` (available because `index()` assigns the loaded client to `request->data`), mirroring `View/Oa4mpClientCoScopes/fields.inc`.
  2. When public: do not render the Add top-link (R1); render the explanatory message above the claims listing (R2); do not render the per-row Edit and Delete action buttons (R7); when there are zero claim rows, suppress the empty `Claim Name / Actions` table so only the message shows (R2).
  3. Leave the confidential-client path untouched (R4).
- **Patterns to follow**: `View/Oa4mpClientCoScopes/fields.inc` (`$is_public_client` gating + explanatory `field-desc`); the existing Add top-link and per-row action rendering in `View/Oa4mpClientClaims/index.ctp`.
- **Test scenarios**: Test expectation: none — view change, no runnable harness. Verify manually per AE1 and AE3.
- **Verification**: A public client's CLAIMS tab shows the message with no Add button, no per-row Edit/Delete, and no empty two-column grid; a confidential client's CLAIMS tab is unchanged (Add/edit/delete all work). Covers AE1, AE3.

### U3. One-time cleanup of legacy public-client claim rows

- **Goal**: Ensure no public client holds `Oa4mpClientClaim` rows, so such clients stop failing `oa4mpVerifyClient` (the asymmetric comparator at `Model/Oa4mpClientOa4mpServer.php:441`) and their CLAIMS tab renders instead of redirecting with `bad_client`.
- **Requirements**: resolves A1 (per KTD4).
- **Dependencies**: none.
- **Files**: none in the plugin — this is a one-time data operation, not a code change. The deliverable is a drafted diagnostic query plus a transaction-wrapped cleanup for the user to run.
- **Approach**:
  1. Draft a diagnostic query that joins the claims table to the OIDC-clients table and returns any claim rows whose owning client has `public_client = 1` (exact table/column names — the `cm_`-prefixed claims and OIDC-client tables and the claims→client foreign key — confirmed against the schema at execution).
  2. If the query returns no rows: done — record that A1 was verified empirically (no cleanup needed).
  3. If it returns rows: draft a transaction-wrapped `DELETE` of exactly those claim rows (claim rows carry no inbound references that would break, mirroring the transaction-wrapped one-time dedup described in `docs/solutions/logic-errors/oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md`).
- **Execution note**: This is a mutating data operation; the user runs it. Draft the diagnostic query and the cleanup SQL for review — do not execute it here.
- **Test scenarios**: Test expectation: none — one-time data operation.
- **Verification**: After the operation, the diagnostic query returns zero public clients with claim rows, and opening the CLAIMS tab for a previously-affected public client renders (message shown) rather than redirecting with `bad_client`.

---

## Verification Contract

This plugin has no runnable test suite (`Test/` is empty scaffolding), so the gates are behavioral and mechanical rather than automated:

1. **Lint**: `php -l` passes on `Controller/Oa4mpClientClaimsController.php`, `View/Oa4mpClientClaims/index.ctp`, and `Lib/lang.php`.
2. **Public-client UI (AE1)**: the CLAIMS tab for a public client shows the explanatory message, no Add button, no per-row Edit/Delete, and no empty grid.
3. **Public-client server guard (AE2, AE4)**: `add`/`edit` (GET+POST)/`delete` requests for a public client — including direct URLs — are rejected with a flash error and mutate nothing.
4. **No confidential-client regression (AE3)**: a confidential client's CLAIMS tab and add/edit/delete behavior are unchanged.
5. **Legacy cleanup (A1)**: the U3 diagnostic query returns zero public clients holding claim rows after remediation.

## Definition of Done

- R1-R7 implemented; AE1-AE4 verified manually; A1 resolved via U3 (query clean).
- New lang keys added for R2's message and U1's flash error (wording per OQ1).
- Changed PHP files lint clean; no confidential-client regression.
- The U3 diagnostic/cleanup SQL is drafted and handed to the user (the user runs mutating SQL).
