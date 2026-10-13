---
title: Group-gated clients refused every group member because the cfg sent the group id as a string
date: 2026-10-13
category: integration-issues
module: Oa4mpClient plugin (Authorization tab, cfg marshalling, OA4MP claim script)
problem_type: integration_issue
component: service_object
related_components:
  - rails_controller
  - testing_framework
severity: high
symptoms:
  - "An Active member of the client's Authorized User Group was refused with access_denied and User does not have the required group membership"
  - "The client's cfg qdl args carried authorization_group_id as a quoted JSON string such as \"12\""
  - "The QDL gate harness passed, because its fixtures used the string '10' for both the cfg id and cm_group_id"
root_cause: logic_error
resolution_type: code_fix
tags: [oa4mp, qdl, cfg, authorization-group, type-mismatch, dynamodb, gate-harness, integration]
---

# Group-gated clients refused every group member because the cfg sent the group id as a string

## Problem

An OIDC client with an Authorized User Group refused members of that group. The plugin sent the group id to the OA4MP server as a JSON string, the claim script compared it with a number read from DynamoDB, and in QDL a string never equals a number. The bug sat on the boundary between two repositories, so neither side's tests could see it.

## Symptoms

- A user registered in the CO, Active, and in the required CO Group was sent to the client's callback with `error=access_denied&error_description=User does not have the required group membership`.
- The client's cfg, read from the server, held `"authorization_group_id": "12"` (quoted) in `tokens.identity.qdl.args`.
- Every plugin test and every case in the QDL gate harness (`roles/oa4mp-server/tests/qdl/test_authorization_gates.qdl` in `cilogon-service-config-us`) passed.

## What Didn't Work

- **Suspecting the change under test.** The failure surfaced on DEV right after the no-record redirect (cilogon/Oa4mpClient#36) and its QDL change were deployed, which also moved the Active check ahead of the group check. Neither touched the comparison: the `cm_group_id == required_group_id` lambda was the same on the dev, test and prod branches before this fix, and the controller path that posts the form predates that work. The reordering only changed *which* denial a user saw.
- **Trusting the gate harness.** It lifts the real gate region out of `dynamodb_claims.qdl` and runs it, so it looked authoritative. But its fixtures wrote `{'cm_group_id' : '10'}` against `{'authorization_group_id' : '10'}`: strings on both sides. It proved the logic and missed the types, and the types were the bug.
- **Checking the deployed file's md5 as proof of what ran.** In the same session an earlier DEV symptom turned out to be the admin client pointing at a different QDL script altogether. The file on disk matched the expected commit; the client was not using it. Confirm which script the admin client loads before reading behavior off the file.

## Solution

Both sides were fixed, so that neither depends on the other's type.

Plugin (cilogon/Oa4mpClient#37): cast the id when building the cfg, `Model/Oa4mpClientOa4mpServer.php:1665`:

```php
// before
$authzArgs['authorization_group_id'] = $data['Oa4mpClientAuthorization']['authz_co_group_id'];
// after
$authzArgs['authorization_group_id'] = (int)$data['Oa4mpClientAuthorization']['authz_co_group_id'];
```

Claim script (`roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl` in `cilogon-service-config-us`, on `us-east-2-dev` as of this writing and not yet promoted to test or prod): compare both ids as strings.

```
required_group_id := to_string(config_params.'authorization_group_id');
...
memberships.\((s.) -> to_string(s.'cm_group_id') == required_group_id)
```

The QDL change is what repairs clients already deployed: every subscriber's plugin version that saved a client from the Authorization tab left a string in that client's cfg, and the one QDL copy per tier serves them all. The plugin cast keeps new cfgs typed correctly regardless of which QDL a tier runs.

## Why This Works

The chain, end to end:

1. Saving the Authorization tab builds the cfg from `array_merge($client, $this->request->data)` (`Controller/Oa4mpClientAuthorizationsController.php:90`). Posted form values are strings, so `authz_co_group_id` is `"12"`, and the marshaller copied it through unchanged.
2. The DynamoProvisioner writes `cm_group_id` as a DynamoDB number (`$map['cm_group_id']['N']`, `CoDynamoProvisionerTarget.php:226`). The QDL dynamo module converts a number attribute with `Long.parseLong` (`DynamoDB.java:354`), so the script sees an integer.
3. In QDL, `'12' == 12` is `false` (checked with `/opt/qdl/bin/qdl-run`). The membership filter returned nothing, the user was treated as outside the group, and with no group redirect URL set the gate raised `access_denied`.

Converting both sides with `to_string` makes the comparison independent of either producer. A reviewer checked that `to_string` yields `"12"` for every numeric shape the module can return (Long, and BigDecimal forms such as `12.0`), so it cannot introduce a new mismatch. The plugin's sync check compares `authz_co_group_id` with a loose `!=` (`Model/Oa4mpClientOa4mpServer.php:1141`), so clients already holding the string form do not read as out of sync after the cast; they pick up the integer on their next save.

## Prevention

- **Test fixtures at a boundary must use the producer's real types.** For the gate harness that means `cm_group_id` as an integer, because that is what DynamoDB returns. The harness now carries cases pairing a numeric `cm_group_id` with a string cfg id and with a numeric one, plus a numeric non-member that must still be denied. The string-on-both-sides fixtures remain, but they no longer stand alone.
- **Any value the QDL compares with `==` is a typed contract.** QDL equality does not coerce between strings and numbers. When the plugin adds a new arg the script compares, decide its JSON type in the plugin, and normalize in the QDL as well, because older plugin versions keep writing whatever they wrote.
- **Assert types, not just keys, in plugin cfg tests.** The contract and allowlist tests checked that `authorization_group_id` was present. `CfgMarshallingTest::testAuthorizationGroupIdIsSentAsAnInteger` feeds the posted string and asserts the arg is the integer `12` and that the JSON is unquoted:

```php
$this->assertTrue(($args['authorization_group_id'] ?? null) === 12, ...);
$this->assertContains('"authorization_group_id":12', json_encode($args), ...);
```

- **Request data reaches the marshaller untyped.** Any controller that marshals `$this->request->data` hands it strings, whereas data read back from the database may arrive as native ints. A value that compares correctly after one save path can fail after another.

## Related Issues

- cilogon/Oa4mpClient#37: the plugin fix.
- cilogon/Oa4mpClient#36: the no-record redirect, whose DEV testing surfaced this.
- [A green run does not prove a test can fail](../test-failures/oa4mp-green-run-does-not-prove-a-test-can-fail.md): the same failure class. Here the harness fixture erased the very difference (string versus number) it needed to catch.
- [Comparator and marshaller asymmetry](../logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md): the plugin's comparator compares loosely, which is why the sync check never flagged the string id while QDL's strict equality refused users.
- [The sync check needs the saved client shape](../test-failures/oa4mp-sync-check-needs-the-saved-client-shape.md): the same posted-versus-stored distinction in the same code region.
- [Public client cfg rejected](oa4mp-public-client-cfg-rejected-2026-08-03.md): an earlier issue on the same plugin cfg to `dynamodb_claims.qdl` boundary.
