---
title: "A live test verified unsaved create data, and the sync check read its authorization as absent"
date: 2026-10-09
category: test-failures
module: Oa4mpClient plugin (sync verification, live-server test tier)
problem_type: test_failure
component: testing_framework
related_components:
  - rails_model
symptoms:
  - "Nightly live-server run failed on LiveClientLifecycleTest::testConfidentialClientCreatedRequiringActiveStatusStaysInSync"
  - "Registry log: Oa4mpClientAuthorization Oa4mp server has authorization configuration but plugin does not"
  - "The same run showed OA4MP had accepted the create cfg and returned require_active_status true, so the server side was correct"
root_cause: wrong_api
resolution_type: test_fix
severity: medium
tags: [sync-verification, comparator, live-tier, test-fixture, saved-shape, hasone, phantom-null-array, require-active-status]
---

# A live test verified unsaved create data, and the sync check read its authorization as absent

## Problem

`isClientDataSynchronized()` expects the plugin side of the comparison to be a
client **as loaded from the database**. A live-server test handed it the
unsaved create data from `newClientData()` instead. The comparator decides
whether the plugin has an authorization by looking for the row's `id`, so the
unsaved row read as "no authorization" and a correctly created client was
reported out of sync. Production was never affected; the nightly live run
went red the first night after the test merged (cilogon/Oa4mpClient#31).

## Symptoms

- `LiveClientLifecycleTest::testConfidentialClientCreatedRequiringActiveStatusStaysInSync`
  failed: "the client created requiring Active Status must read back in sync".
- The log line just before it: `Oa4mpClientAuthorization Oa4mp server has
  authorization configuration but plugin does not`.
- Earlier in the same log, the server's cfg carried
  `"require_active_status":true` and unmarshalled to
  `{"Oa4mpClientAuthorization":{"require_active":true}}`. The server had done
  exactly what the plugin asked.

## What Didn't Work

- **Reading the failure as an OA4MP problem.** The test had been written to
  prove the server accepts a cfg on create, so a red result first looks like
  "the server rejected or dropped it". The log showed the opposite. Read the
  unmarshalled server data before suspecting the server.
- **Adding only the row `id`.** A hermetic probe reproduced the failure, and
  adding `id` made it pass, but the comparator then reads
  `authz_co_group_id`, `authz_group_redirect_url` and
  `require_active_redirect_url` from the plugin row without `??`
  (`Model/Oa4mpClientOa4mpServer.php:1140-1165`), and an unsaved row has none
  of those keys, so PHP logged undefined-key warnings. A row loaded from the
  database carries every column, as null when unset; the stand-in has to as
  well.
- **A probe built from `CreateTimeCfgTest::posted()`** first failed for an
  unrelated reason, `proxy_limited is out of sync (plugin=NULL, oa4mp='0')`,
  because that fixture omits `proxy_limited` while the live test's
  `clientData()` sets it. Matching the live test's data exactly was needed
  before the probe said anything about the live failure.

## Solution

The fix (cilogon/Oa4mpClient#34) makes the live test's plugin side look like a
saved client before it verifies, the same way its `currentData()` helper
already supplies `oa4mp_identifier`
(`Test/Case/LiveServer/LiveClientLifecycleTest.php:161`; the stand-in is at
`:252-259`):

```php
$current = $this->currentData($marshallData, $result['clientId']);
// The Add action saves the authorization row before any verify, and the
// comparison only counts a row that has an id: ...
$current['Oa4mpClientAuthorization'] += array(
  'id' => 1,
  'authz_co_group_id' => null,
  'authz_group_redirect_url' => null,
  'require_active_redirect_url' => null
);
$this->assertTrue($this->server()->oa4mpVerifyClient($admin, $current) === true, ...);
```

The hermetic tier's version of the same check never had this problem:
`CreateTimeCfgTest::testCreatedClientReadsBackInSync`
(`Test/Case/Model/CreateTimeCfgTest.php:270`) saves the client with
`saveAssociated()` and reloads it through `current()` before comparing. The
live tier has no database to save into, so it has to stand in for the row.

A `workflow_dispatch` run of the live tier on `main` after the merge passed
4/4, including this test.

## Why This Works

The comparator keys the authorization section on the row id
(`Model/Oa4mpClientOa4mpServer.php:1117`, `:1125`, `:1131`):

```php
if(empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
  $this->log("Oa4mpClientAuthorization Oa4mp server has authorization configuration but plugin does not");
```

That is deliberate. A client with no authorization row is read by Containable
as an all-null array, not as an empty one (see the phantom-row learning
below), so "is there a row" cannot be answered by `!empty()` on the section;
the `id` is the reliable signal. `newClientData()` builds the row as
`array('require_active' => true)` (`Model/Oa4mpClientOa4mpServer.php:768`)
because it is save data: the database assigns the `id` later.

In production the order is always save, then verify. The Add action saves the
row (`Controller/Oa4mpClientCoOidcClientsController.php:173`) and every
controller verifies a client it loaded from the database, so the `id` is
always present. Only a caller that skips the save -- in practice, a test --
can hand the comparator a row without one.

With the stand-in row, the "both sides have it" branch runs and compares
`require_active` on each side. That restores what the test was written to
prove: had the server dropped `require_active_status`, the plugin side would
now be reported as having an authorization the server lacks. Without the `id`
the test could not have caught that either.

## Prevention

- **Verify the saved shape, not the save data.** Anything passed as the
  plugin side of `oa4mpVerifyClient()` / `isClientDataSynchronized()` should
  look like `Oa4mpClientCoOidcClient::current()` output. In the hermetic
  tier, save and reload. Where saving is impossible (the live tier), add what
  the save would have added: ids and every column of each hasOne row.
- **The live tier first runs after merge.** The workflow runs only on `main`
  of `cilogon/Oa4mpClient` (`.github/workflows/live-server-tests.yml:62`), so
  a new live test has never executed when its PR is reviewed. Before merging
  one, run `Test/run-live.sh` locally, or reproduce its exact data shape
  hermetically -- a throwaway test feeding the same data through the real
  marshaller, `oa4mpUnMarshallContent()` and comparator reproduced this
  failure in seconds.
- **Today only the authorization section is keyed on `id`** in the
  comparator. If another hasOne section adopts the same phantom-row guard,
  tests that verify unsaved data will start failing on it the same way.

## Related Issues

- cilogon/Oa4mpClient#31 -- added the test (and the create-time Require
  Active Status default it exercises).
- cilogon/Oa4mpClient#34 -- this fix.
- `docs/solutions/logic-errors/oa4mp-require-active-status-shown-ticked-when-unsaved.md`
  -- the feature this test covers.
- `docs/solutions/logic-errors/oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md`
  -- why a missing hasOne row is an all-null array, the reason the
  comparator keys on `id`.
