---
title: OA4MP Client-Read api_version=latest - Plan
type: feat
date: 2026-08-25
topic: oa4mp-client-read-api-version-latest
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-brainstorm
execution: code
---

# OA4MP Client-Read api_version=latest - Plan

## Goal Capsule

- **Objective:** An edit made through the plugin never silently discards client configuration that the OA4MP server reports but the plugin does not model -- `rt_grace_period` above all. Keys the newer representation stops reporting are deliberately not preserved, per the third Key Decision; the objective covers what the server currently reports, not what it once did.
- **Means:** Ask the OA4MP server for its newest client representation on the RFC 7592 client-read, so those settings reach the plugin's existing extra-keys round-trip at all.
- **Product authority:** The developer. Preserve-only was chosen deliberately; modelling any of these settings as first-class plugin fields is not active scope.
- **Open blockers:** None blocking. Two unresolved server behaviors were converted to explicit assumptions (A1, A2 under Dependencies / Assumptions) so planning can proceed; both are falsified or confirmed by the pre-production validation R10 and R12 require, and both carry a stated fallback if they prove wrong.

---

## Product Contract

### Summary

The RFC 7592 client-read request asks the OA4MP server for its newest client representation, so settings that only appear there -- `rt_grace_period` and five siblings -- are captured and handed back unchanged on the next edit. None of them become visible or settable in the plugin; they round-trip opaquely, the way the client's own secret already does.

### Problem Frame

The OA4MP server holds client settings the plugin's data model has no place for. The plugin already has a mechanism for this: `oa4mpUnMarshallContent()` captures every key of a client-read response that is not in its known-keys list into `oa4mp_server_extra`, and `oa4mpMarshallContent()` merges that stored blob back into the body of a later client-update so nothing is lost. The mechanism works and is covered by tests.

What it cannot preserve is a setting the server never reports. The client-read response the plugin receives today omits `rt_grace_period` entirely, so there is nothing to capture, nothing to store, and nothing to hand back. Any refresh-token grace period an administrator has configured out of band survives only until someone edits that client through the Registry, at which point the update body simply does not carry it.

The cost lands quietly. The operator sees a successful edit, the plugin's own view of the client is unchanged, and the loss surfaces later as refresh-token behavior nobody changed on purpose.

### Key Decisions

- **Preserve only; do not model.** `rt_grace_period` and its siblings stay opaque values in the extras blob -- no column, no form field, no participation in drift comparison. (session-settled: user-directed -- chosen over modelling it as a first-class field: the value is to stop losing the setting, and modelling it carries schema, form, migration, and comparison-semantics cost for no additional preservation.) Governs R6, R7.
- **Hand back everything the server sent.** The three server-owned ceilings the newer representation adds are echoed on update along with everything else, rather than being suppressed the way `registration_client_uri` is. (session-settled: user-directed -- chosen over adding them to the known-keys suppression list: faithful round-trip over tidy, accepting that the plugin asserts values the server owns.) Governs R4.
- **Accept that keys the newer representation drops stop being preserved.** No union of stored and freshly-read extras. (session-settled: user-directed -- chosen over merging stored extras forward: a union would keep the blob correct today at the cost of accumulating keys the server no longer recognises, with no mechanism to ever remove one.) Governs R5.
- **Ship as a straight code change, validated against dev.cilogon.org before production.** No per-server or per-CO switch. (session-settled: user-directed -- chosen over gating the behavior behind a setting: the developer controls deploy order and validates against a development server first.) Governs R8.

### Requirements

**Client-read request**

- R1. The RFC 7592 client-read request the plugin issues to verify a client asks the OA4MP server for its newest client representation, by carrying `api_version=latest` in the request query.
- R2. The client-delete request is unaffected. The client-create request is unaffected -- creation continues to speak whatever version it speaks today -- unless assumption A1 proves false and the client-update path must declare the newer API version, in which case the create path is reconsidered.

**Round-trip preservation**

- R3. Every key of the client-read response that is not in the plugin's known-keys list is captured and handed back unchanged on the next client-update, `rt_grace_period` included. This is the behavior the existing extra-keys mechanism already provides; the requirement is that the newly-visible keys reach it, not that a new mechanism is built.
- R4. The server-owned ceilings the newer representation reports are handed back along with every other unmodelled key, not suppressed. Per the second Key Decision.
- R5. A key the newer representation no longer reports is not preserved. When the stored extras for a client are refreshed from a response that omits a key, that key leaves the stored set and is not sent on subsequent updates.
- R9. The set of keys the newer representation adds is reviewed for credential- or secret-bearing content before the change ships, and any such key is declared to the plugin's log-redaction names before it can be captured. The review is repeated whenever the server's client representation changes: log redaction matches on key name, and `api_version=latest` is an unpinned alias, so the server can widen the captured-and-logged set later with no plugin change.

**Behavior held constant**

- R6. No setting newly visible in the response becomes readable, displayable, or settable through the plugin's interface.
- R7. No client's drift verdict changes as a result of this work. A client that compares as synchronized today still does, and no client acquires the "modified outside of the Registry" verdict because the response now carries more keys.
- R8. The behavior is unconditional -- it does not depend on a per-server or per-CO setting.

**Pre-production validation**

- R10. Before the change reaches production, an edit made through the plugin against dev.cilogon.org is followed by a fresh client-read confirming that a non-default `rt_grace_period` retained its pre-edit value. A passing hermetic run does not satisfy AE1: the hermetic tier observes capture and merge, which is the layer that passes while the server discards the value.
- R11. The stored extras for all clients are captured to a dated snapshot before the change is deployed to production, so keys removed by the lazy refresh remain recoverable.
- R12. The pre-production validation exercises assumption A2 explicitly: an edit is made against dev.cilogon.org with a ceiling present in the stored extras, and the server-side ceiling values are compared before and after. If they changed, the work halts and returns to the product owner rather than applying a suppression fallback unilaterally.

### Acceptance Examples

- AE1. **Covers R3.** **Given** a client whose server-side configuration includes a refresh-token grace period, **when** an operator edits any unrelated field of that client through the plugin, **then** the client-update body carries the grace period unchanged and the server-side value after the edit equals the value before it.
- AE2. **Covers R7.** **Given** a client that the plugin reports as synchronized before this change, **when** the same client is verified after it, **then** the verdict is still synchronized -- the additional keys in the response do not participate in the comparison.
- AE3. **Covers R5, R7.** **Given** a client whose stored extras contain a key the newer representation no longer reports, **when** that client is next verified, **then** the stored extras are refreshed without that key, the verdict is unaffected, and later updates omit it.
- AE4. **Covers R6.** **Given** any client with a non-default refresh-token grace period, **when** an operator views or edits that client, **then** no interface surface exposes the value and no form control can change it.

### Scope Boundaries

- Giving `rt_grace_period` -- or any of the other newly-visible settings -- a database column, a form field, or a place in the drift comparison. Explicitly deferred; revisit only if operators need to manage the value from the Registry.
- Any per-server or per-CO toggle for this behavior.
- Changing what the client-create path sends, unless assumption A1 proves false and forces it back into scope. As written, a newly created client acquires the newly-visible settings on its first verification, not at creation.
- Reworking how the extras blob handles the client's own secret. That decision stands on its own recorded reasoning and is untouched here.

### Dependencies / Assumptions

- The evidence for "no modelled key changed shape" comes from one client's before-and-after response pair. Every key the plugin models -- including the whole `cfg` object -- was byte-identical between the two. This is strong evidence but not a proof across all client shapes; a client using a materially different `cfg` form has not been compared.
- Five of the six newly-visible settings carried the integer sentinel `-1` in the sample and `ea_support` carried boolean `false`. The sample therefore demonstrates neither a non-default numeric value nor how the server treats an echoed boolean on an update -- and `ea_support` is a real value that will be echoed on every update once this ships.
- A1. **The client-update path does not need to declare the newer API version** for the server to accept and retain the settings the client-read reported. Unverified against a real server, and the highest-consequence assumption here: if it is wrong, an edit succeeds while preserving nothing, and the work extends to the update path and possibly the create path. R10's live edit-then-reread is what falsifies it -- a non-default grace period that does not survive the round trip means this assumption failed, not that the capture failed.
- A2. **The server ignores or rejects an echoed ceiling** rather than honouring it. If dev.cilogon.org honours a client-submitted `max_at_lifetime`, `max_id_token_lifetime`, or `max_rt_lifetime`, the change does not ship as designed. Suppressing the three ceiling keys is the likely remedy, but it overrides a Key Decision the product owner settled deliberately, so U5 halts and returns the decision to them rather than applying it. A server that honours them means an operator's unrelated edit can silently reinstate token-lifetime limits a server administrator has since tightened, from a stored blob of any age.
- The server is assumed to tolerate a client-update body that echoes keys it owns and does not expect a client to set. This assumption has no precedent behind it: the server-owned keys the plugin has met so far -- the registration client URI and the issued-at timestamp -- are both in the known-keys list and are therefore suppressed, not echoed. Nothing in the plugin's history establishes how the server reacts to an echoed key it owns. R9 and R12 exist to convert this assumption into evidence.
- The plugin's stored extras for each client are refreshed lazily, on verification, which several read paths trigger. This work therefore migrates stored data through ordinary page views rather than through a migration step, and the migration is one-way: reverting the code does not restore keys already dropped from a stored blob.

### Outstanding Questions

**Deferred to Planning**

- How the change is verified without a live server. The existing hermetic tier can observe capture and merge behavior directly, so the question is which seam the new coverage attaches to, not whether coverage is possible.
- Whether the keys the newer representation no longer reports are renamed, subsumed, or genuinely retired server-side, and whether omitting them from an update resets them. Both were empty in the sample, so nothing is known to be at risk; a client that actually uses them would settle it.

### Sources / Research

- `Model/Oa4mpClientOa4mpServer.php` -- `oa4mpVerifyClient()` issues the client-read; `oa4mpUnMarshallContent()` holds the known-keys list and the extras capture; `oa4mpMarshallContent()` performs the merge back into an update body; `isClientDataSynchronized()` is the drift comparison the change must not disturb; `oa4mpEditClient()` maps a failed comparison to the operator-visible tampering message.
- Nine controllers -- access tokens, access controls, authorizations, claims, callbacks, scopes, named configs, refresh tokens, and the OIDC client controller itself -- each run the same reconcile-and-persist block on the extras blob after verification, across thirteen verification call sites. That breadth is what makes the stored-data migration lazy and fast-moving.
- `Test/Case/Model/UnmarshallExtraKeysTest.php` -- existing hermetic coverage of the capture-and-merge round trip.
- `client-read-with.json` and `client-read-without.json` -- the same client's response with and without the newer API version, supplied by the developer. These are working evidence, not repository artifacts.

---

## Planning Contract

**Product Contract preservation:** unchanged. No requirement, key decision, acceptance example, or scope boundary was altered during enrichment; R9 through R12 were added by document review before planning began, not by planning.

### Key Technical Decisions

- KTD1. **Route all three request queries through one observable builder.** `oa4mpVerifyClient()` constructs its own `HttpSocket`, so no hermetic test can see the query it builds -- the same obstacle that produced the existing splits for response decoding, verdict reduction, and server-object comparison, each of which carries a comment explaining that the hermetic tier must never make an HTTP request. Follow that precedent, and extend it to all three request kinds: the delete query is set inline and the create query is never set, so neither is reachable hermetically unless it moves behind the same seam. The delete and create paths are refactored without behavior change -- what each sends is identical to today. Governs R1, R2, R8.
- KTD2. **Commit the two supplied responses as test fixtures.** The whole evidence base for R3 and R5 currently lives in untracked working files. (session-settled: user-directed -- chosen over hand-authoring fixtures from key lists recorded in prose: a transcribed fixture can silently fail to differ in the key under test, which is the false-positive fixture failure the repo already has a recorded learning about.) Governs R3, R5.
- KTD3. **Enforce the credential screening as a regression test, not a checklist step.** R9 exists because `api_version=latest` is an unpinned alias and the server can widen what it reports later; a one-time review cannot survive that. The enforceable form asserts that no key captured from a representative response matches a name on the redaction list. (session-settled: user-directed -- chosen over a documented review step.) Governs R9.
- KTD4. **Split the verification contract explicitly: hermetic proves capture and merge, live proves preservation.** A hermetic pass observes exactly the layer that stays green while the server discards the value, so it cannot satisfy AE1 and cannot test A1 or A2. Governs R10, R12; per AE1.
- KTD5. **Red-proof every new regression test.** Restore the pre-change code path or mutate the rule under test, confirm the new test and only it fails, then restore, and say so in the commit message. The repo's standing rule, backed by a pull-request checklist item and a recorded learning: a green run cannot distinguish a real test from one structurally unable to fail.

### High-Level Technical Design

The change is one query parameter; the work is almost entirely proving it is safe. The read path today:

```
oa4mpVerifyClient()
  -> builds request (query: client_id)          <- KTD1 extracts this
  -> HttpSocket request
  -> decodeServerResponse()                     <- existing seam
  -> compareToServerObject()                    <- existing seam
       -> oa4mpUnMarshallContent()
            -> modelled keys  -> comparison fields
            -> everything else -> extras blob   <- rt_grace_period lands here
  -> verdictFromComparison()                    <- existing seam
```

The extras blob then reaches the write path unchanged: `oa4mpEditClient()` overwrites the stored blob with freshly-verified extras, and `oa4mpMarshallContent()` merges any key it has not already set into the update body. No new mechanism is built -- adding the parameter is what makes `rt_grace_period` exist to be carried.

### Assumptions

Both assumptions A1 and A2 (Dependencies / Assumptions) are unverifiable from this repository and resolve only against a live server. U5 is the unit that tests them; neither is treated as settled before it runs.

---

## Implementation Units

### U1. Client-read query carries the newer API version

- **Goal:** The client-read request asks for the server's newest representation, through a seam a hermetic test can observe.
- **Requirements:** R1, R2, R8.
- **Dependencies:** none.
- **Files:** `Model/Oa4mpClientOa4mpServer.php`, `Test/Case/Model/ServerRequestQueryTest.php` (new).
- **Approach:**
  1. Introduce one query-building method parameterised by request kind, mirroring the existing `decodeServerResponse()` / `verdictFromComparison()` splits and carrying the same class of comment: this exists so the hermetic tier can observe it without a socket.
  2. The read kind returns the client identifier plus the API version; the delete kind returns the identifier alone; the create kind returns no query.
  3. Have all three call sites assign their query from it. This is a behavior-preserving refactor of the delete and create paths, not a scope change: what each sends is identical to today, and R2 stays true. Routing all three through one observable method is what makes R2 testable at all -- with the delete query set inline and the create query never set, neither is reachable hermetically, and a source-scan substitute is the false-coverage pattern the repo has already been bitten by.
  4. Assert that each call site assigns the builder's output to its request, not merely that the builder returns the right value -- a builder that is correct and unwired would otherwise pass.
- **Patterns to follow:** the three existing seam extractions in the same file, including their explanatory comments.
- **Execution note:** introduce the builder and route all three call sites through it first, locking today's query shapes with tests, then add the API version to the read kind -- so the test that proves the parameter arrives is demonstrably able to fail.
- **Test scenarios:**
  - The builder's read kind yields a query carrying the API version set to the newest representation, plus the client identifier.
  - The builder's delete kind yields a query carrying the client identifier and no API version parameter.
  - The builder's create kind yields no query.
  - Each of the three call sites assigns the builder's output to its request -- red-proofed by unwiring one call site and confirming only its scenario fails.
- **Verification:** the hermetic suite proves the read query carries the parameter, the other two paths do not, and all three are actually wired to the builder -- with no HTTP request made.

### U2. Commit the sample responses as fixtures

- **Goal:** The before/after evidence for the key-set change lives in the repository rather than in untracked working files.
- **Requirements:** supports R3, R5; enables U3 and U4.
- **Dependencies:** none.
- **Files:** `Test/fixtures/oa4mp-responses/` (two new response fixtures), `Test/Stub/Oa4mpServerStub.php`.
- **Approach:**
  1. **Redact first, before anything else.** Both supplied responses carry live DynamoDB credentials at `cfg.tokens.identity.qdl.args.dynamo_module_config` -- an `access_key_id` and a `secret_access_key`. Replace both values with placeholders, keeping the keys, so the key set stays faithful. This is a required step, not a check: committing either file unmodified publishes a working credential.
  2. Add the redacted pair to the captured-response fixture directory and read them through the existing server stub, which already loads real captured responses by scenario name.
- **Patterns to follow:** `Oa4mpServerStub::response($scenario)` and the existing captured response in `Test/fixtures/oa4mp-responses/`.
- **Test scenarios:** `Test expectation: none -- fixture data with no behavior of its own. Its correctness is proven by the tests in U3 and U4 that consume it.`
- **Verification:** both fixtures load through the stub; the pair differs in exactly the added and removed keys the document records; and neither carries a credential value.

### U3. Newly-visible keys round-trip

- **Goal:** Keys that only the newer representation reports are captured and handed back on an update, and keys it stops reporting leave the stored set.
- **Requirements:** R3, R4, R5, R7; covers AE2, AE3.
- **Dependencies:** U1, U2.
- **Files:** `Model/Oa4mpClientOa4mpServer.php` (assertions only -- no change expected), `Controller/Oa4mpClientCoOidcClientsController.php` (the reconcile-and-persist block, assertions only), `Test/Case/Model/UnmarshallExtraKeysTest.php`.
- **Approach:** extend the existing extra-keys test with the newer representation fixture. The capture-and-merge mechanism is expected to need no change; if a test here requires a code change to pass, that is a finding about the mechanism, not a licence to adjust the test.
- **Patterns to follow:** the existing extra-keys tests, which already assert both the capture side and the merge-into-update side.
- **Test scenarios:**
  - Covers AE3. The refresh-token grace period is captured into the extras blob from the newer representation.
  - The three ceiling keys and the remaining newly-visible keys are captured, not suppressed.
  - An update built from a stored blob carrying the grace period sends it in the body unchanged.
  - Covers AE3. When stored extras carry a key the newer representation does not report, a refresh drops it from the stored set.
  - Covers AE3. A subsequent update built from the refreshed blob does not send the dropped key.
  - Covers AE3. Given a stored extras blob carrying a key and a fresh verification result that omits it, the persisted blob is **replaced**, not merged -- red-proofed by making the persist step merge instead of overwrite. Asserting only that the dropped keys are absent after unmarshalling the newer fixture proves nothing: they are simply absent from that fixture, so the assertion passes regardless of the code.
  - The keys the plugin models are still not captured as extras under the newer representation.
  - Covers AE2. A client that compares as synchronized against the current representation still compares as synchronized when verified against the newer-representation fixture. The existing synchronization suite cannot show this: its fixtures do not carry the newly-visible keys, which is what AE2 is about.
- **Verification:** the extras round trip is proven in both directions against the committed fixtures, and the synchronized verdict is proven to survive the newer representation, with no HTTP request made.
- **Note on R6 and R8:** both hold by construction across the whole plan -- no unit touches a schema, view, form, or controller, and no unit introduces a setting or toggle. This is the evidence behind their Definition of Done lines.

### U4. Captured keys carry no unredacted credential

- **Goal:** A key arriving from the server under a name the redaction list does not cover cannot silently enter the logged, persisted blob.
- **Requirements:** R9.
- **Dependencies:** U2.
- **Files:** `Test/Case/Model/ServerLogRedactionTest.php`, `Test/Case/Model/ContractRedactionTest.php`.
- **Approach:** lock the captured key set. Assert that the set of keys captured into the extras blob from the newer-representation fixture equals a checked-in expected list, so any key the server begins reporting later turns the suite red until someone reviews it and adds it deliberately.

  A name-comparison assertion cannot do this job. Redaction matches on name, so "captured key is absent from the redaction list, or masked" is satisfied by both branches and is a tautology -- and the gap R9 names is precisely a credential arriving under a name nothing declares, which no name list can recognise. The key-set lock detects the arrival itself rather than trying to classify it, which is the only check that survives an unpinned representation alias.

  Keep the existing masking assertions as a separate, unchanged check.
- **Patterns to follow:** the existing log-redaction tests, which lock the masking of credentials that reach a public CI log.
- **Test scenarios:**
  - The set of keys captured from the newer-representation fixture equals the checked-in expected set exactly.
  - Adding any previously unseen key to the fixture turns the assertion red -- the red-proof for this unit, and the case a name-based check would miss.
  - A key removed from the fixture also turns the assertion red, so the lock is not one-directional.
- **Verification:** an undeclared key introduced into the fixture turns this suite red.

### U5. Live verification of preservation and ceiling handling

- **Goal:** The two assumptions this work rests on are tested against a real server before production.
- **Requirements:** R10, R12; covers AE1.
- **Dependencies:** U1, U3.
- **Files:** `Test/Case/LiveServer/LiveClientLifecycleTest.php`.
- **Approach:** extend the existing live lifecycle test, which already creates, edits, verifies, and deletes a client against a real server.

  **Precondition, and the unit's real constraint.** Both steps need server-side values the plugin cannot set: the plugin is forbidden from writing the grace period (first Key Decision), the create path does not send it, and the live tier builds every client it uses from a fixed template. So the values must be placed out of band, and U5 must record which mechanism did it -- a direct administrative call against the OA4MP server, a server-side configuration change, or a request to a dev.cilogon.org administrator. **If no such mechanism is available, A1 is blocked, not confirmed.** A run against a client left at the server defaults passes while proving nothing, which is the failure this precondition exists to prevent.

  1. With a non-default grace period set out of band, edit an unrelated field through the plugin, re-read, and assert the grace period is unchanged. This tests A1: a value that does not survive means the update path needs the version declaration, not that capture failed.
  2. Against a client whose server-side ceiling has been tightened out of band to a non-default value while the stored extras still carry the older value, record the ceilings before an edit and compare after. This tests A2. **If they changed, stop and return to the product owner.** Suppressing the ceilings would falsify R4, override the second Key Decision -- which the owner settled deliberately -- and invert U3's ceiling-capture scenario. That is a scope decision, not a fallback an implementer applies. Do not proceed to deploy on a falsified A2.
- **Patterns to follow:** the existing live edit-and-stay-in-sync test and its environment-driven admin client.
- **Execution note:** this tier is the only proof of preservation. A green hermetic run is not evidence for either assumption, and neither is this unit passing against a client left at the server defaults -- record the out-of-band mechanism and the values it set alongside the run, or the run is not evidence.
- **Test scenarios:**
  - Covers AE1. A non-default grace period survives an unrelated edit unchanged.
  - The server-side ceiling values are identical before and after an edit, against a client whose ceiling differs from the value the stored blob carries. A run with every ceiling at the server default does not test A2: the stored blob echoes the default and reads back the default whether the server honours the value or discards it, so before and after match under both hypotheses.
  - The client still compares as synchronized after the edit.
- **Verification:** the live tier runs green against dev.cilogon.org with a non-default grace period in place, and the run is recorded before any production deploy.

### U6. Pre-deploy snapshot of stored extras

- **Goal:** The one-way lazy migration is recoverable.
- **Requirements:** R11.
- **Dependencies:** none; must complete before deploy.
- **Files:** `docs/runbooks/oa4mp-extras-pre-deploy-snapshot.md` (new).
- **Approach:** the runbook records one read-only query and where its output goes.

  ```sql
  SELECT oa4mp_identifier, oa4mp_server_extra
    FROM cm_oa4mp_client_co_oidc_clients
   WHERE oa4mp_server_extra IS NOT NULL;
  ```

  (Confirm the table prefix against the deployment; the plugin's schema declares the table as `oa4mp_client_co_oidc_clients`.) Output goes to a dated file whose path the runbook names, written and retained by the operator who performs the deploy. The snapshot is taken by hand, not by the plugin -- this unit produces the procedure and the record that it ran, not code.
- **Test scenarios:** `Test expectation: none -- an operational procedure with no plugin behavior.`
- **Verification:** the dated snapshot file exists, is readable, and contains one row per client with a stored blob, before the change is deployed to production.

---

## Verification Contract

The contract has a hard split, per KTD4.

**Hermetic gates** (`Test/run.sh`, no network): U1's query assertions, U3's round-trip assertions, U4's screening assertions. These prove capture, merge, drop, and screening. They cannot prove preservation.

**Live gate** (`Test/run-live.sh` against dev.cilogon.org): U5. This is the only evidence for A1 and A2, and the only thing that satisfies AE1.

**Standing gates:** the existing hermetic suite stays green -- in particular the synchronization tests, since R7 requires no client's drift verdict to change. Every new regression test is red-proofed per KTD5 and the proof is stated in the commit message.

---

## Definition of Done

- The client-read asks for the newest representation; delete and create are unchanged (R1, R2).
- The grace period and its siblings are captured and handed back on an update; keys the newer representation stops reporting leave the stored set (R3, R4, R5).
- Nothing newly visible is readable or settable through the interface, which holds by construction: no unit touches a schema, view, form, or controller behavior (R6, AE4).
- The existing synchronization suite is green, and a client verified against the newer representation still compares as synchronized -- hermetically in U3 and against the live test client in U5 (R7, AE2).
- The behavior is unconditional -- U1 introduces no toggle (R8).
- The screening assertion is in place and proven able to fail (R9).
- The live tier has run green against dev.cilogon.org with a non-default grace period set out of band, the mechanism that set it recorded, confirming A1 (R10, AE1). If no such mechanism was available, A1 is recorded as blocked and the deploy does not proceed on the strength of this item.
- Ceiling values were compared across a live edit against a client whose ceiling differs from the stored value, confirming A2 (R12). If A2 was falsified, the work is halted and returned to the product owner rather than completed -- this item cannot be satisfied by applying a suppression fallback unilaterally.
- A dated snapshot of stored extras exists from before the production deploy (R11).
- The committed fixtures carry no live credential -- both DynamoDB credential values are placeholders.
- Every new regression test was verified red, and the commit message says so.
