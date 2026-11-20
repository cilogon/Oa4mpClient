# DynamoProvisioner on Registry 5: gap assessment

What DynamoProvisioner's `registry-5` branch does on Registry 5.3.0, measured
against what the OIDC client plugin's QDL script reads, and which gaps stop
claims or login gates from working end to end for a client the Registry 5
plugin creates. Gaps that stop them are fixed with the port; every other gap
is reported here and left.

## What was assessed

- DynamoProvisioner: branch `registry-5` of `cilogon/DynamoProvisioner` at
  commit `91ed1d9` (2026-08-06, "Refresh plugin vendor for PE 5.2"). The branch
  shares no history with that repository's `main` (the Registry 4 edition), so
  missing behavior is judged by comparing code, not commits.
- Registry: the workbench at the commit in `workbench/registry.pin`
  (CILogon's `cilogon-develop`, Registry 5.3.0).
- QDL: `dynamodb_claims.qdl` on `us-east-2-dev` in `cilogon-service-config-us`
  (identical on `us-east-2-test`). Production still runs an older script; that
  promotion gap is separate from this assessment.
- Registry 4 reference: `Model/CoDynamoProvisionerTarget.php` on
  DynamoProvisioner's `main`.

Each finding is marked **observed** when it was seen in the workbench, or
**code** when it was read from the source only.

How the observations were made. The branch was exported (not checked out) and
mounted into the workbench under `local/plugins/DynamoProvisioner`. After the
first finding below, assessment continued on a scratch copy whose only change
was the two missing index declarations, so that the plugin's tables existed.
No AWS credentials were used: the records were produced by calling the
plugin's record builder on the provisioning data Registry hands a provisioner,
for seeded people given an organization and department, ownership of a group,
and a Terms and Conditions agreement.

## Blocking gaps

These stop claims or a login gate from working for a client created by the
Registry 5 plugin. None needs a change to the QDL.

| # | Gap | Evidence | Effect | Fix in DynamoProvisioner |
|---|---|---|---|---|
| B1 | `config/plugin.json` declares no index on `dynamo_provisioner_keys.dynamo_provisioner_id` and `dynamo_provisioner_keys.person_id` on their own; Registry 5.3.0 refuses any foreign key without an explicitly named index (enforced since 2026-10-16, after the branch's last commit). | observed: activation stops with `LogicException: Table 'dynamo_provisioner_keys' has an auto-generated index ... Please define an explicit index`; no DynamoProvisioner table is created. | The provisioner cannot be installed. | Declare named indexes for both columns. |
| B2 | The table permissions are not nested under the `ui` context that 5.3.0 reads. | observed: opening the new provisioning target's configuration page as a CO administrator gives an error page; the log shows `Undefined array key "ui"` and a `TypeError` in `RegistryAuthComponent::getTablePermissions()`. | No one can configure a target, so nothing is provisioned. | Wrap the permissions in `'ui' => [...]`. |
| B3 | Role organization and department are read from `o` and `ou`; Registry 5 names them `organization` and `department`. | observed: a role with both set yields a `cm_roles` entry with no `cm_o` or `cm_ou`. | Role claims for organization, department, or all fields lose those values. | Read `organization` and `department`, still written as `cm_o` and `cm_ou`. |
| B4 | The group owner flag is read from `owner` on a membership; Registry 5 memberships have no such column, and owners are members of the group's owners group. | observed: a person in the owners group of "Workbench OIDC Client Managers" gets `cm_owner: false` on every membership. | Group membership claims constrained to owners release nothing. | Set `cm_owner` true for a group when the person is a member of that group's owners group. |
| B5 | The SSH key block is commented out ("does not exist in v5 core yet"); Registry 5.3.0 ships the SshKeyAuthenticator plugin, whose keys reach provisioners as `ssh_keys`. | code: the block is disabled; not exercised, since the workbench has no SSH key authenticator configured. | SSH key claims release nothing. | Re-enable the block, keeping the Registry 4 string form `type key comment` in a string set. |
| B6 | The Terms and Conditions block is commented out and reads `terms_and_conditions_agreements`; Registry 5 delivers `t_and_c_agreements`, without the terms themselves. | observed: a person with an agreement has `t_and_c_agreements` in the provisioning data and no `cm_tandc_agreements` in the record. | Terms and Conditions claims release nothing. | Read `t_and_c_agreements`, look up each agreement's terms for description and status, and write the agreement time as epoch seconds, as Registry 4 does. |

Unix cluster accounts also produce no record (`cm_unix_cluster_accounts` is
commented out), but Registry 5.3.0 has no Unix cluster source to read, so the
plugin does not offer that claim source on Registry 5 yet. It is follow-up
work for when Registry 5 gains Unix cluster support, not a gap to fix now.

## Gaps reported and left

These do not stop claims or gates from working for a Registry 5 client. They
are recorded for the maintainer and DynamoProvisioner's own backlog.

| # | Gap | Evidence | Effect |
|---|---|---|---|
| N1 | Owners groups appear in `cm_memberships` as ordinary memberships, for example `CO:owners:Workbench OIDC Client Managers`. Registry 4 had no owners groups. | observed | Group membership claims without an owner constraint list these extra group names. Behavior change, not a failure; decide whether to filter them. |
| N2 | A person who is not active (eligibility `N`) keeps a record, with `cm_status` such as `Suspended`, no roles, and only the all-members group. Only `Deleted` eligibility removes the record. | observed | The Require Active Status and Authorized User Group gates still refuse such a person correctly. |
| N3 | `cm_status` spells non-active statuses as Registry 5 enum names (`Suspended`, `GracePeriod`, `PendingActivation`), not Registry 4's strings. | observed (`Suspended`) and code | The QDL compares only with `Active`, so gates are unaffected; claims that release role status show the new spelling. |
| N4 | Identifiers reach provisioners only when active, so `cm_identifiers[].cm_status` is always `Active`; invalid roles and memberships are also filtered out before provisioning. | observed | Claims that relied on Registry 4 sending inactive entries with their status lose them. |
| N5 | Attribute-map constraints accept only `PersonRoles`, `Urls` or an identifier type; Registry 4 maps constrained on SSH keys, Terms and Conditions or Unix cluster accounts are read as identifier type names and never met. | code | Extra attributes from such maps are silently not written. The plugin's claims do not read attribute-map attributes. Maps are re-entered by hand when a target is rebuilt on Registry 5. |
| N6 | No relation from People to the key table is declared for hard deletes (the `hasManyPlugins` hook added in 5.3.0), and an `Expunged` eligibility does not delete the record. | code: a hard delete could not be exercised, because hard-deleting a seeded person in the workbench fails on core's `group_members` foreign key before reaching DynamoProvisioner, with or without a key row. | An expunge may fail on the key table's foreign key, or leave a record in DynamoDB. Soft deletes are handled. |
| N7 | `cm_memberships` and `cm_names` are written only when non-empty; Registry 4 always wrote them. | code | The QDL guards each read with `has_key`, so no effect. |
| N8 | A sort key is written only when its value is non-empty. | code | A table with a sort key whose template resolves empty would reject the write. Not checked against CILogon's table configurations. |
| N9 | The branch has no test cases (`tests/` holds a bootstrap and an empty schema file). | code | No automated check of the record shape. |
| N10 | `initialize()` does not call `parent::initialize()`, and the 5.3.0 table helpers other plugins set (display field, redirect goal, view and index contains) are not set. | code | Effect unknown beyond page display; nothing failed in the workbench once B1 and B2 were worked around. |

## What the Registry 5 plugin relies on

With B1 to B6 fixed, a record written from Registry 5 carries every attribute
the QDL's DynamoDB claim handlers and gates read, under the Registry 4 names
and types:

- gates: `cm_status` (`Active`) and `cm_memberships[].cm_group_id`;
- claims: `cm_emailAddresses`, `cm_memberships` (with `cm_owner`), `cm_identifiers`,
  `cm_names`, `cm_roles` (with `cm_o`, `cm_ou`), `cm_sshkeys` and
  `cm_tandc_agreements`.

Group identifiers in `cm_group_id` are Registry 5 group ids. A client's
Authorized User Group is stored in its `cfg` by id, so a client imported from
Registry 4 keeps working only while the import keeps group ids unchanged.
