# Pre-deploy snapshot of stored OA4MP extra keys

**Run this before deploying the change that makes the client-read ask the OA4MP
server for its newest representation.** It is the only recovery path for a
migration that is one-way and begins on its own.

## Why

The plugin stores whatever the OA4MP server reports outside its known-keys list
in `oa4mp_server_extra`, and refreshes that column from the server every time a
client is verified. Nine controllers run that reconcile-and-persist step, across
thirteen verification call sites, so the refresh is triggered by ordinary page
views rather than by any migration step.

Two consequences follow, and together they are why this snapshot exists:

- The refresh **replaces** the stored value rather than merging into it. A key
  the newer representation stops reporting leaves the stored set. In the sample
  the plugin was developed against, those keys are `proxy_claims_list` and
  `proxy_request_scopes`, both empty; a client that actually populates them
  loses those values from the blob.
- Reverting the code does not undo it. By the time anyone decides to roll back,
  every client someone happened to view has already been rewritten, and the
  pre-change values are gone.

So the recovery has to be taken before the deploy, not arranged after it.

## Procedure

Run this read-only query against the Registry database and keep the output.

```sql
SELECT oa4mp_identifier, oa4mp_server_extra
  FROM cm_oa4mp_client_co_oidc_clients
 WHERE oa4mp_server_extra IS NOT NULL;
```

Confirm the table prefix against the deployment before running it. The plugin's
`Config/Schema/schema.xml` declares the table as `oa4mp_client_co_oidc_clients`;
COmanage Registry applies its own prefix, which is `cm_` in a default install.

Write the result to a dated file and record its location below:

```
snapshot taken:      YYYY-MM-DD HH:MM (timezone)
taken by:            <name>
output written to:   <path or object store location>
row count:           <N>
deploy this covers:  <release or commit>
```

The output contains no credentials of the deployment's own, but it does contain
whatever the OA4MP server reports for each client outside the plugin's model.
Store it wherever that deployment keeps operational data of that sensitivity,
not in this repository.

## Who runs it, and when

The operator performing the deploy, immediately before it. Not the plugin, and
not a test: the snapshot has to reflect production at the moment of the change,
so nothing automated here can stand in for it.

## Checking it happened

The deploy is not covered unless the file exists, is readable, and holds one row
per client with a stored blob. A row count of zero means either that no client
has stored extras yet -- plausible only in a fresh deployment -- or that the
query targeted the wrong table. Confirm which before proceeding.
