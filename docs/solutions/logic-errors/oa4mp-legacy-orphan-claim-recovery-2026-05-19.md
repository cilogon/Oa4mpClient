---
title: One-time cleanup runbook for legacy orphan Oa4mpClientClaim rows from pre-1659690 non-atomic save
date: 2026-05-19
category: logic-errors
module: Oa4mpClient plugin (claim migration; operator data fix)
problem_type: logic_error
component: rails_model
severity: medium
symptoms:
  - "Edit-page GET on certain OIDC clients flashes 'Number of claims is out of sync (plugin=N, oa4mp=M)' (N > M) and redirects to the index"
  - "cm_oa4mp_client_claims contains rows for (client_id, claim_name) with no Oa4mpClientCoSearchAttribute pointing at them (pointed_by_count = 0)"
  - "Affected clients all carry orphan rows created during the same 2026-05-05 deployment window (timestamps on the orphans cluster on that date)"
root_cause: logic_error
resolution_type: operational_runbook
related_components:
  - Model/Oa4mpClientCoSearchAttribute.php (toClaim, orphan-recovery path added in commit 1788f32)
  - Model/Oa4mpClientOa4mpServer.php (isClientDataSynchronized emits the drift error)
  - cm_oa4mp_client_claims, cm_oa4mp_client_claim_constraints, cm_oa4mp_client_co_search_attributes
tags:
  - oa4mp
  - claim-migration
  - operational-runbook
  - orphan-data
  - data-cleanup
  - one-time-fix
---

# One-time cleanup runbook for legacy orphan Oa4mpClientClaim rows from pre-1659690 non-atomic save

## Problem

Before commit `1659690` (`fix(claim-migration): atomic claim+pointer save; remove coarse gate`) landed on `fix/ldap-attribute-options`, `Oa4mpClientCoSearchAttribute::toClaim()` persisted in three steps with no atomicity guarantee:

1. `saveAssociated($claim)` — wrote the claim row and its `Oa4mpClientClaimConstraint` children.
2. `Oa4mpClientDynamoConfig->save($dynamoConfig)` — wrote the per-client dynamo configuration.
3. `Oa4mpClientCoSearchAttribute->saveField('claim_id', $newId)` — wrote the back-pointer linking the search attribute to its newly-created claim.

When step 2 failed validation (the common cause was a `DefaultDynamoConfig` row missing one of its `notBlank` fields, e.g. `aws_region`, `table_name`, or `partition_key_template`), step 3 never ran. The claim row from step 1 was already committed, but `cm_oa4mp_client_co_search_attributes.claim_id` stayed NULL — an **orphan claim**. Every subsequent edit-page GET would re-enter the migration loop with `claim_id IS NULL`, create *another* orphan, and so on.

Commit `d6ffbe1` ("fix(oidc-client): skip deprecated-cfg claim migration once any claim exists") hid the symptom by refusing to enter the migration block once any sibling claim row existed. That suppressed the duplicate-row accumulation but blocked partial-migration recovery as collateral damage. Commit `1659690` removed the coarse gate and reordered the save sequence so steps 1 and 3 are now adjacent: the back-pointer is set immediately after the claim row, before the dynamo save can fail.

**Residue.** `1659690` fixed the forward path but did not detect orphans already in the database. On the next edit-page GET to an affected client, the migration loop (now allowed to run because the coarse gate is gone) re-entered for the still-NULL `claim_id` search attribute and created a **new** claim row plus a fresh back-pointer — atomically this time. The old orphan rows remained. The search attribute now points at the new (correct) claim; the orphan still sits in `cm_oa4mp_client_claims` with `pointed_by_count = 0`.

The server-side OA4MP config dedupes via the `ldap_to_claim_mappings` JSON-object whose keys are claim names — two plugin claim rows with the same `(client_id, claim_name)` collapse to one mapping on the server. The plugin-side `isClientDataSynchronized` comparator (`Model/Oa4mpClientOa4mpServer.php:109`; the count comparison that emits the flash is at `:450-458`) counts every plugin claim row, so it sees `plugin=4` while the OA4MP server reports `oa4mp=2`. The mismatch fires the "Number of claims is out of sync" flash and redirects the operator away from the edit page.

Commit `1788f32` adds an orphan-recovery path to `toClaim()` that handles **State X** below on first re-entry. This runbook handles **State Y** and any **State X** orphans whose shape no longer matches what `toClaim()` would create today (so the orphan-recovery code intentionally falls through).

## Affected clients (verified 2026-05-19)

Fleet-wide inventory at the time this runbook was written. All 15 orphan rows have `created` timestamps in the 2026-05-05 deployment window. Re-run the inventory query (below) before acting — the fleet may have shifted since.

| client_id | orphan claim id(s) | claim_name(s) |
| ---: | --- | --- |
| 106 | 20, 21 | `email`, `is_member_of` |
| 143 | 18, 19 | `groups`, `orcid` |
| 148 | 7, 8 | `groups`, `preferred_username` |
| 161 | 1, 2 | `email`, `member` |
| 162 | 22 | `groups` |
| 168 | 9 | `vo_person_id` |
| 169 | 25 | `vo_person_id` |
| 243 | 26, 27 | `is_member_of`, `vo_person_id` |
| 260 | 23, 24 | `groups`, `preferred_username` |

Total: **15 orphan claim rows across 9 OIDC clients**.

## Two states per affected client

Before the runbook DELETEs anything, identify which state each affected client is in. The classification drives whether `toClaim()`'s orphan-recovery path will eventually self-heal or not.

- **State X — sa.claim_id IS NULL.** The client has not received an edit-page GET since `1659690` landed (or its migration was never retried after the original failure). On the next GET, the orphan-recovery path in commit `1788f32` will detect the orphan and either:
  - rewire `sa.claim_id` to the orphan (if the orphan's identity and constraints byte-for-byte match what `toClaim()` would now write — typical for static-shape claims like `eduPersonOrcid`, `isMemberOf`, `gecos`, `givenName`, `sn`, `uid`, `mail`, `gidNumber`, `uidNumber`), OR
  - fall through to creating a new claim and log `not rewiring -- operator cleanup required` (typical for `voPersonApplicationUID` orphans created before the CoService-derived effective-filter helper landed — the orphan's `constraint_value` is the old literal-type form, the new one is an anchored regex; they will not match).
- **State Y — sa.claim_id IS NOT NULL and points at a *new* claim row.** The client has received at least one edit-page GET since `1659690` landed but before `1788f32` landed; that GET ran the migration loop and created a fresh, correct claim plus back-pointer, leaving the older orphan in place. **Client 143 is in State Y.** This is the state that produces the "Number of claims is out of sync" flash.

## Inventory and classification SQL

Replace `cm_` with the actual table prefix if the deployment uses a different one (the datasource config lives in COmanage Registry core's `Config/database.php`, not in this plugin checkout).

**Dialect.** The SQL below is MySQL/MariaDB (`GROUP_CONCAT`, `CREATE TEMPORARY TABLE`, `SELECT ROW_COUNT()`, `UPDATE ... JOIN`). On a Postgres deployment — which is what this plugin's own hermetic test harness uses — translate: `string_agg`, `CREATE TEMP TABLE`, `GET DIAGNOSTICS` or `DELETE ... RETURNING` for the row count, and `UPDATE ... FROM`.

### Step 1 — fleet-wide orphan inventory

```sql
SELECT
    c.client_id,
    c.id  AS claim_id,
    c.claim_name,
    c.created,
    (
      SELECT COUNT(*)
      FROM   cm_oa4mp_client_co_search_attributes sa
      WHERE  sa.claim_id = c.id
    ) AS pointed_by_count
FROM   cm_oa4mp_client_claims c
WHERE  (
         SELECT COUNT(*)
         FROM   cm_oa4mp_client_co_search_attributes sa
         WHERE  sa.claim_id = c.id
       ) = 0
ORDER BY c.client_id, c.id;
```

Every row returned is a candidate for cleanup. Compare against the table above to spot drift.

### Step 2 — classify each affected client into State X or State Y

`cm_oa4mp_client_co_search_attributes` does not carry a `client_id` column — it only has `ldap_id`. The join through `cm_oa4mp_client_co_ldap_configs` is what reaches `client_id`, and the `<client_id>` parameter has to be substituted in two places (once in the outer `WHERE` to scope the search attributes, once in the orphan-find subquery to scope candidate claim rows).

For each `client_id` from Step 1, substitute it into both `<client_id>` placeholders and run:

```sql
SELECT
    sa.id          AS search_attribute_id,
    sa.name        AS ldap_name,
    sa.return_name AS claim_name,
    sa.claim_id    AS sa_claim_id_pointer,
    (
      SELECT GROUP_CONCAT(c.id ORDER BY c.id)
      FROM   cm_oa4mp_client_claims c
      WHERE  c.client_id    = <client_id>
        AND  c.claim_name   = sa.return_name
        AND  NOT EXISTS (
               SELECT 1
               FROM   cm_oa4mp_client_co_search_attributes sa2
               WHERE  sa2.claim_id = c.id
             )
    ) AS orphan_claim_ids_with_same_name
FROM   cm_oa4mp_client_co_search_attributes sa
JOIN   cm_oa4mp_client_co_ldap_configs ldap
  ON   ldap.id = sa.ldap_id
WHERE  ldap.client_id = <client_id>
ORDER BY sa.id;
```

Per-row reading:

- `sa_claim_id_pointer IS NULL` AND `orphan_claim_ids_with_same_name` non-empty → **State X** for this search attribute.
- `sa_claim_id_pointer IS NOT NULL` AND `orphan_claim_ids_with_same_name` non-empty → **State Y** for this search attribute. The pointer is to a freshly-created claim; the orphan is leftover.

Cross-check the total orphan count against Step 1 — the sum of `orphan_claim_ids_with_same_name` entries across all affected clients should equal the row count from Step 1.

## Recovery procedure

The procedure is the same for State X and State Y: **delete the orphan claim row plus its constraint children.** The forward-path code is now correct either way:

- For State X clients: deleting the orphan removes the rewire candidate, so the next GET runs the orphan-recovery path, finds nothing, and falls through to creating a brand-new claim+back-pointer atomically. Net effect is the same as if the orphan had been rewired, except the new claim row gets a fresh `id`. Functionally equivalent.
- For State Y clients: deleting the orphan brings the plugin-side claim count down to match what the OA4MP server returns, eliminating the sync-mismatch flash.

**Important.** Do not blindly rewire State X orphans by hand. The orphan-recovery path in `toClaim()` (commit `1788f32`) does this safely with a byte-for-byte identity+constraints comparison. Doing it manually risks rewiring a `voPersonApplicationUID` search attribute to an orphan whose `constraint_value` no longer matches what the comparator expects, which would drive a drift error on the very next GET. Let the code handle the rewire path; let the runbook handle deletes only.

### Phase 0 — backup

Take a database backup before running any DELETE. At minimum, snapshot the three affected tables:

```sql
-- MariaDB / MySQL example
CREATE TABLE bkp_2026_05_19_oa4mp_client_claims          AS SELECT * FROM cm_oa4mp_client_claims;
CREATE TABLE bkp_2026_05_19_oa4mp_client_claim_constr    AS SELECT * FROM cm_oa4mp_client_claim_constraints;
CREATE TABLE bkp_2026_05_19_oa4mp_client_co_search_attrs AS SELECT * FROM cm_oa4mp_client_co_search_attributes;
```

### Phase 1 — within a single transaction, delete orphan constraints, then orphans

`cm_oa4mp_client_claim_constraints.claim_id` carries a `REFERENCES cm_oa4mp_client_claims(id)` constraint. Whether that FK enforces ON DELETE depends on the database engine and migration history; do the two-step delete explicitly to avoid surprises.

```sql
START TRANSACTION;

-- 1. Capture the orphan ids into a session-scoped temp set. Re-run the
--    inventory query inside the transaction so the set is captured under
--    the same isolation as the deletes -- if a concurrent admin somehow
--    re-wires an orphan between Step 1 and now, we will not delete it.
DROP TEMPORARY TABLE IF EXISTS _orphan_claim_ids;
CREATE TEMPORARY TABLE _orphan_claim_ids (id INT PRIMARY KEY);

INSERT INTO _orphan_claim_ids (id)
SELECT c.id
FROM   cm_oa4mp_client_claims c
WHERE  NOT EXISTS (
         SELECT 1
         FROM   cm_oa4mp_client_co_search_attributes sa
         WHERE  sa.claim_id = c.id
       );

-- Sanity check -- the row count printed here must match what Step 1
-- returned outside the transaction. If it does not, ROLLBACK and
-- investigate before retrying.
SELECT COUNT(*) AS will_delete FROM _orphan_claim_ids;

-- 2. Delete the constraint children first.
DELETE FROM cm_oa4mp_client_claim_constraints
WHERE  claim_id IN (SELECT id FROM _orphan_claim_ids);

-- 3. Then delete the orphan claim rows.
DELETE FROM cm_oa4mp_client_claims
WHERE  id IN (SELECT id FROM _orphan_claim_ids);

-- 4. Final sanity: zero orphans should remain.
SELECT COUNT(*) AS orphans_remaining
FROM   cm_oa4mp_client_claims c
WHERE  NOT EXISTS (
         SELECT 1
         FROM   cm_oa4mp_client_co_search_attributes sa
         WHERE  sa.claim_id = c.id
       );

-- If will_delete matched and orphans_remaining is 0, commit.
COMMIT;

DROP TEMPORARY TABLE _orphan_claim_ids;
```

If any sanity check disagrees with expectations, `ROLLBACK;` and re-investigate before retrying.

### Phase 2 — per-client verification

For each previously-affected `client_id`:

```sql
-- Re-confirm: every search attribute that should have a claim back-pointer
-- now has one (sa.claim_id IS NOT NULL for migrated rows), and the plugin
-- side claim count equals the OA4MP server side claim count.
SELECT
    sa.id          AS search_attribute_id,
    sa.name        AS ldap_name,
    sa.return_name AS claim_name,
    sa.claim_id    AS sa_claim_id_pointer
FROM   cm_oa4mp_client_co_search_attributes sa
WHERE  sa.ldap_id IN (
         SELECT ldap.id
         FROM   cm_oa4mp_client_co_ldap_configs ldap
         WHERE  ldap.client_id = <client_id>
       )
ORDER BY sa.id;

SELECT id, claim_name, created
FROM   cm_oa4mp_client_claims
WHERE  client_id = <client_id>
ORDER BY id;
```

Counts should match between the per-client claim row count and the OA4MP server's parsed `ldap_to_claim_mappings` count (visit the edit page in the registry UI after cleanup — the sync flash should be gone).

For State X clients, a search attribute may still show `sa.claim_id IS NULL` after Phase 1. That is expected if no edit-page GET has happened yet since the cleanup; the next GET will run the orphan-recovery path on a now-orphan-free database, find nothing to rewire, and create a fresh claim+back-pointer atomically.

## Rollback

If a cleanup batch went sideways:

```sql
START TRANSACTION;

DELETE FROM cm_oa4mp_client_claim_constraints
WHERE  claim_id IN (
         SELECT id FROM bkp_2026_05_19_oa4mp_client_claims_constr  -- restore-source
       );

INSERT INTO cm_oa4mp_client_claims
SELECT * FROM bkp_2026_05_19_oa4mp_client_claims
WHERE  id NOT IN (SELECT id FROM cm_oa4mp_client_claims);

INSERT INTO cm_oa4mp_client_claim_constraints
SELECT * FROM bkp_2026_05_19_oa4mp_client_claim_constr
WHERE  id NOT IN (SELECT id FROM cm_oa4mp_client_claim_constraints);

COMMIT;
```

Then re-run Phase 1 with the fix applied.

## Why not a CakeShell or admin action

A CakePHP shell or admin-action route was considered. Two reasons not to:

1. The data shape is small and bounded (15 rows across 9 clients as of 2026-05-19; even a worst-case fleet sweep would be small). One transaction-wrapped SQL block is more auditable than a long-lived shell that callers may forget to remove.
2. The cleanup is not load-bearing for the forward path. The orphan-recovery code in `toClaim()` (commit `1788f32`) is what prevents *new* orphans from accumulating going forward; this runbook is a one-time fix for legacy data.

If a future incident produces a larger orphan footprint or a recurring source of orphans, a CakeShell is the right next step.

## Verification — full fleet

After running Phase 1, the fleet-wide inventory query from Step 1 should return zero rows. The 9 affected clients should no longer flash the "Number of claims is out of sync" error on edit-page GET. A representative spot check on Client 143 (the originally-reported case):

```sql
-- Should return 2 rows (claims 39, 40 -- the post-fix correct claims)
-- and no rows for claims 18, 19 (the deleted orphans).
SELECT id, client_id, claim_name, created
FROM   cm_oa4mp_client_claims
WHERE  client_id = 143
ORDER BY id;
```

## Related diagnostic — wired-but-stale claim constraints

This runbook handles **orphan** claim rows (search attribute unwired, claim row pointed at by nothing). A related but distinct failure mode is a **wired-but-stale** claim row: the search attribute is correctly wired (`sa.claim_id IS NOT NULL`), the claim row exists, but the DB's constraint shape diverges from what `Oa4mpClientOa4mpServer::buildClaimFromLdapMapping()` would produce now.

`toClaim()`'s orphan-recovery path (commit `1788f32`) only fires when `sa.claim_id IS NULL`, so a wired-but-stale row never re-enters the migration block and never gets a chance to refresh. The DB row sits frozen at whatever shape it had when first persisted, even after the CoService-driven anchored-regex helper landed.

**Observed case (2026-05-26, client 126).** The `voPersonApplicationUID → preferred_username` search attribute is wired to a claim row whose only `Oa4mpClientClaimConstraint` is `constraint_field='type'`, `constraint_value='all'` (legacy literal shape). The freshly-rebuilt expected shape from `buildClaimFromLdapMapping()` is `constraint_value='^(GitHub|cmsuser)$'` (the CoService-derived anchored regex). `isClientDataSynchronized` correctly logs `Oa4mpClientClaim: Claims are out of sync` and the edit-page GET flashes the redirect.

### Inventory query — narrow, wired voPersonApplicationUID with legacy `type='all'`

```sql
SELECT
    ldap.client_id,
    sa.id          AS search_attribute_id,
    sa.name        AS ldap_name,
    sa.return_name AS claim_name_on_sa,
    c.id           AS claim_id,
    c.claim_name   AS claim_name_on_claim,
    cc.id          AS constraint_id,
    cc.constraint_field,
    cc.constraint_value
FROM   cm_oa4mp_client_co_search_attributes sa
JOIN   cm_oa4mp_client_co_ldap_configs    ldap ON ldap.id     = sa.ldap_id
JOIN   cm_oa4mp_client_claims             c    ON c.id        = sa.claim_id
JOIN   cm_oa4mp_client_claim_constraints  cc   ON cc.claim_id = c.id
WHERE  sa.name              = 'voPersonApplicationUID'
  AND  cc.constraint_field  = 'type'
  AND  cc.constraint_value  = 'all'
ORDER BY ldap.client_id, sa.id;
```

Every row returned is a **candidate** for the wired-but-stale failure mode. It is *not* a guarantee of drift — the true drift cohort is the subset where `buildClaimFromLdapMapping()` would actually produce a different shape:

- the CO has a `CoLdapProvisionerTarget` matching the OA4MP server URL, AND
- the matched `CoLdapProvisionerAttribute` has `attr_opts` enabled, AND
- at least one `CoService` produces a non-empty effective filter for that CO and attribute.

Those facts live in CakePHP records and runtime state, not in the three tables this query reads. Treat the result set as the **upper bound** of the affected fleet — a candidate that does not satisfy the runtime conditions will not flash a drift error, because the comparator will produce the same `type='all'` shape and the row will compare equal.

If the helper produces an *empty* effective filter (no matching CoService), the comparator suppresses the claim entirely (see `Oa4mpClientOa4mpServer::buildClaimFromLdapMapping`, `Model/Oa4mpClientOa4mpServer.php:1581-1584`). That's a separate drift signal — the plugin reports one more claim than OA4MP — and is not surfaced by this query.

### Inventory query — broader lens

If the narrow query is empty, or if other LDAP attributes have since migrated to CoService-driven anchored regex, drop the `sa.name` predicate to scan every wired search attribute whose `type` constraint is still the literal `all`:

```sql
SELECT
    ldap.client_id,
    sa.id   AS search_attribute_id,
    sa.name AS ldap_name,
    sa.return_name,
    c.id    AS claim_id,
    cc.constraint_value
FROM   cm_oa4mp_client_co_search_attributes sa
JOIN   cm_oa4mp_client_co_ldap_configs    ldap ON ldap.id     = sa.ldap_id
JOIN   cm_oa4mp_client_claims             c    ON c.id        = sa.claim_id
JOIN   cm_oa4mp_client_claim_constraints  cc   ON cc.claim_id = c.id
WHERE  cc.constraint_field  = 'type'
  AND  cc.constraint_value  = 'all'
ORDER BY ldap.client_id, sa.id;
```

### Fleet-wide footprint as of 2026-05-26

Running the narrow query returned one row (client 126, constraint id 41). Running the broader query returned two candidate rows on client 126:

| client_id | sa.id | ldap_name | claim_name | claim_id | constraint_id | drift? |
| ---: | ---: | --- | --- | ---: | ---: | --- |
| 126 | 78 | `voPersonApplicationUID` | `preferred_username` | 38 | 41 | **yes** |
| 126 | 80 | `gecos` | `gecos` | 35 | 37 | no — false positive |

The `gecos` row is the upper-bound false positive the doc warns about: the comparator log for client 126 shows both `curClaimsNormalized[0]` and `oa4mpClaimsNormalized[0]` carrying the identical pair `[primary=true, type=all]`, so `buildClaimFromLdapMapping()` also produces `type=all` for `Name`-sourced `gecos` claims and the row compares equal. It satisfies the SQL predicate but is not stale.

**Net drift footprint: one constraint row — id 41 on claim 38, attached to SA 78 on client 126.**

### Remediation — narrow, one-shot UPDATE for constraint 41 (2026-05-26 footprint)

The cohort is one row, the target value is already known from the comparator log (`oa4mpClaimsNormalized[2].constraints[0].constraint_value = '^(GitHub|cmsuser)$'`), and the right operational answer is a surgical in-place `UPDATE`. Designing a refresh helper in `toClaim()` is disproportionate to a one-row footprint.

**Important.** The target value `^(GitHub|cmsuser)$` is what `buildClaimFromLdapMapping()` produced in the 2026-05-26 18:34:28 comparator log. Before running the UPDATE, re-derive the current expected value from a fresh comparator log on client 126's edit-page GET — if CoServices have been added, removed, or edited since the inventory was captured, the anchored regex may have changed. Substitute the freshly-derived value where the SQL below has `^(GitHub|cmsuser)$`.

#### Phase 0 — backup

```sql
CREATE TABLE bkp_2026_05_26_oa4mp_client_claim_constraints
    AS SELECT * FROM cm_oa4mp_client_claim_constraints;

-- Confirm the backup captured constraint 41 in its legacy shape.
SELECT id, claim_id, constraint_field, constraint_value
FROM   bkp_2026_05_26_oa4mp_client_claim_constraints
WHERE  id = 41;
-- Expect: id=41, claim_id=38, constraint_field='type', constraint_value='all'.
```

#### Phase 1 — transaction-wrapped UPDATE

```sql
START TRANSACTION;

UPDATE cm_oa4mp_client_claim_constraints
SET    constraint_value  = '^(GitHub|cmsuser)$'
WHERE  id                = 41
  AND  claim_id          = 38
  AND  constraint_field  = 'type'
  AND  constraint_value  = 'all';

-- Sanity: exactly one row updated.
SELECT ROW_COUNT() AS rows_updated;
-- Expect: 1. If 0 or >1, ROLLBACK and re-investigate -- the row may have
-- drifted between the inventory query and now (concurrent admin edit,
-- runtime UI write, or schema-prefix mismatch).

-- Confirm the post-update shape.
SELECT id, claim_id, constraint_field, constraint_value
FROM   cm_oa4mp_client_claim_constraints
WHERE  id = 41;
-- Expect: id=41, claim_id=38, constraint_field='type',
--         constraint_value='^(GitHub|cmsuser)$'.

COMMIT;
```

If the `ROW_COUNT()` check fails or the post-update SELECT does not match the expected shape, `ROLLBACK;` and re-investigate before retrying.

#### Phase 2 — verification

1. Re-run the narrow inventory query from the diagnostic above. It should now return **zero rows** — constraint 41's `constraint_value` no longer matches the legacy `all` predicate.
2. Visit client 126's edit page in the registry UI: `https://<registry>/registry/oa4mp_client/oa4mp_client_co_oidc_clients/edit/126`. The "Number of claims is out of sync" flash should not appear, and the page should render normally.
3. Tail the registry log during the edit-page GET. The line `Oa4mpClientClaim: Claims are out of sync` should not be written. The lines `curClaimsNormalized: ...` and `oa4mpClaimsNormalized: ...` only fire on mismatch, so their absence is also a positive signal.

#### Rollback

```sql
START TRANSACTION;

UPDATE cm_oa4mp_client_claim_constraints
SET    constraint_value = 'all'
WHERE  id              = 41
  AND  constraint_field = 'type';

SELECT ROW_COUNT() AS rows_reverted;  -- Expect: 1.

COMMIT;
```

Or restore from the full backup table:

```sql
START TRANSACTION;

UPDATE cm_oa4mp_client_claim_constraints cc
JOIN   bkp_2026_05_26_oa4mp_client_claim_constraints bkp ON bkp.id = cc.id
SET    cc.constraint_value = bkp.constraint_value
WHERE  cc.id = 41;

COMMIT;
```

### General principle for future wired-stale findings

The one-shot `UPDATE` above is justified by **this specific footprint**: one row, target value already known from the comparator log, no recurrence signal. It is **not** a template procedure. For any future wired-stale finding:

- Re-derive the target value from a fresh `oa4mpClaimsNormalized` log entry. Never hard-code a regex from documentation without verifying it against the current CoService state.
- If the cohort exceeds a handful of rows, or if the same `(client, claim)` pair drifts repeatedly after each CoService edit, escalate to a **code-side refresh path** in `toClaim()` (or a sibling on edit-page GET) that detects byte-level divergence from `buildClaimFromLdapMapping()` and rewrites the constraint row through the normal save discipline. A one-row in-place UPDATE is operationally cheap; a recurring one is a maintenance burden masquerading as cheap.

## Related Issues

- `Test/Case/Model/ClaimMigrationPersistenceTest.php::testOrphanClaimIsRewiredInsteadOfDuplicated` — regression test locking the `1788f32` forward path this runbook pairs with (commit `f156db5`, 2026-08-20).

- `docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md` — documents Bug 1 (the non-atomic save that originally created these orphans) plus the misleading log-line, the foreach loop-variable leak, and the `||`-vs-AND constraint-emit defect.
- `docs/plans/2026-05-19-001-fix-oa4mp-attr-opts-claim-constraint-plan.md` — the plan for the `voPersonApplicationUID` + `attr_opts` claim constraint computation that produces the new uniform-anchored-regex shape (which is exactly why pre-existing `voPersonApplicationUID` orphans intentionally fall through to new-claim creation rather than being rewired in place).
- Commit `1659690` — `fix(claim-migration): atomic claim+pointer save; remove coarse gate` (the forward-path fix that closed the orphan-creation door).
- Commit `1788f32` — `fix(claim-migration): rewire to matching orphan instead of creating duplicate` (the orphan-recovery path that pairs with this runbook for the legacy residue).
