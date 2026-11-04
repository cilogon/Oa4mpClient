---
title: Converting an LDAP client turned its isMemberOf JSON array into a delimited string
date: 2026-11-04
category: logic-errors
module: Oa4mpClient plugin (LDAP search attribute to DynamoDB claim conversion, legacy-cfg sync comparison)
problem_type: logic_error
component: rails_model
symptoms:
  - "A client converted from LDAP to DynamoDB claims started returning its group claim as \"g1,g2\" instead of [\"g1\",\"g2\"]"
  - "The converted isMemberOf claim showed Delimited String on the Claims tab although nobody chose it"
root_cause: logic_error
resolution_type: code_fix
severity: high
tags: [ldap, dynamodb, claim-migration, ismemberof, serialization, json-array, sync-verification, legacy-cfg]
---

# Converting an LDAP client turned its isMemberOf JSON array into a delimited string

## Problem

cilogon/Oa4mpClient#40. When an LDAP-backed OIDC client was converted to
DynamoDB claims, its `isMemberOf` group claim stopped reaching the relying party
as a JSON array and arrived as a comma-delimited string. Fixed by
cilogon/Oa4mpClient#60.

## Root cause

The conversion runs on the OIDC client edit page GET: each search attribute
without a `claim_id` back-pointer goes through
`Oa4mpClientCoSearchAttribute::toClaim()`, which writes a claim row. The next
save sends that row to OA4MP.

`toClaim()` hard-coded `isMemberOf` to
`claim_multiple_value_serialization = delimited_string` with a `,` delimiter,
and its read-only mirror `Oa4mpClientOa4mpServer::buildClaimFromLdapMapping()`
did the same. Neither read the search attribute's `return_as_list`.

The LDAP side never produced a delimited string. OA4MP's `LDAPClaimsSource`
(`oa4mp-server-loader-oauth2/.../oauth2/claims/LDAPClaimsSource.java`, the
attribute loop) returns **any** attribute with more than one value as a JSON
array, whatever the list flag says. The list flag (`return_as_list` in the
plugin, `list_attributes` in the QDL; the default LDAP script lists
`isMemberOf`) only decides whether a **single** value is wrapped in a one-item
array. So a relying party saw a JSON array for every user with two or more
groups, under every LDAP configuration.

## Solution

- `toClaim()` and `buildClaimFromLdapMapping()` convert `isMemberOf` to
  `json_array` with a null delimiter, always. DynamoDB claims cannot express
  "array for many values, string for one", so the list flag cannot be honored
  exactly; a JSON array matches the LDAP output for every multi-group user, and
  was chosen over following the flag.
- Changing the mirror would have broken the sync check for many clients. A
  client converted on page load but never saved holds `delimited_string` rows
  while the server still holds its legacy LDAP cfg. A legacy cfg (QDLv2 or the
  deprecated syntax) has no serialization setting at all, so the server-side
  value is only what the mirror invents. `oa4mpUnMarshallContent()` now sets
  `LEGACY_CFG_CLAIMS_KEY` on legacy-derived server data, and
  `isClientDataSynchronized()` drops `LEGACY_CFG_UNCOMPARED_CLAIM_FIELDS` (the
  two serialization fields) from both sides of the claim comparison. QDLv3
  cfgs, which carry the serialization, are compared in full.
- Changing a conversion's output also broke `toClaim()`'s orphan recovery,
  which reuses a claim row nothing points at only when every field matches. An
  `isMemberOf` orphan written in the old form no longer matched, so the
  conversion created a second `isMemberOf` row, and the claim-count comparison
  reported the client out of sync. The old form is now recognized
  (`isPreJsonArrayIsMemberOfClaim()`), upgraded, and reused.
- Clients converted but not yet saved are repaired on the edit page:
  `oa4mpVerifyClient()` reports `legacy_cfg`, and only then does
  `repairPreJsonArrayIsMemberOfClaims()` upgrade a row that is exactly the old
  conversion output and linked from the client's own `isMemberOf` search
  attribute. Rows changed by hand are left alone.
- Not repaired automatically: a client already saved under DynamoDB holds the
  delimited string on **both** sides, so changing the row would be real drift.
  Those clients are fixed by switching the claim to JSON Array on the Claims tab.

Tests: `Test/Case/Model/ClaimMigrationPersistenceTest.php`,
`Test/Case/Model/SyncVerificationTest.php`,
`Test/Case/Controller/PreJsonArrayIsMemberOfRepairTest.php`.

## Prevention

- Before choosing a conversion target for an LDAP attribute, read what
  `LDAPClaimsSource` actually emits for it, not what the plugin's flag
  suggests.
- Changing what `toClaim()` writes is a data-format change with three other
  owners of the same fact. Check each:
  - the mirror `buildClaimFromLdapMapping()`, which must move in lockstep;
  - the sync comparison against a legacy cfg, which can only compare what a
    legacy cfg carries;
  - the strict orphan matching, which will stop recognizing rows the old code
    wrote.
- Rows already written by the old conversion keep the old value. Decide
  explicitly, per server state (legacy cfg or already saved), whether they are
  repaired, and say so in the changelog.

## Related Issues

- cilogon/Oa4mpClient#40 (issue of record), fixed by cilogon/Oa4mpClient#60
- `docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md`
  -- the same pair of writer and comparator, and why both must derive a value
  the same way.
- `docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`
  -- why orphaned claim rows exist and the orphan recovery in `toClaim()`.
