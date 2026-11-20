# Parity fixtures and expected output

This directory is the Registry 4 half of the Parity check for the Registry 5
port of this plugin. Parity is defined toward OA4MP: for the same client
settings, the Registry 5 plugin must send the OA4MP server the same client
representation, `cfg` included, as this plugin does at release 7.0.0.

- `fixtures/` holds client settings, one JSON file per case.
- `expected/` holds, per fixture, exactly what this plugin sends the OA4MP
  server for those settings.

The Registry 5 side copies both directories, records the `main` commit they
came from, builds each fixture with its own code, and compares the result with
these files. Nothing here needs network access.

## Baseline: release 7.0.0

The committed expected set was generated from tag `7.0.0` (commit
`d1732909f94e15b731359e62e2ba741485419395`). The exporter ran on a checkout
whose plugin code was that tag unchanged; only the fixtures, the exporter
shell and its test were added. The files under `expected/` are therefore what
7.0.0 sends, and the Registry 5 port is held to that.

A later change on `main` that alters what the plugin sends turns
`Test/Case/Parity/ParityExporterTest.php` red until the expected files are
regenerated. Regenerating replaces the 7.0.0 set on `main` with the newer
output. Bringing the Registry 5 port up to such a change is a separate
catch-up activity: the 7.0.0 set stays retrievable from the commit that added
it, and the Registry 5 side records which `main` commit its copy came from.

## Fixture format

Each fixture is a JSON object with three keys:

- `description`: what the case covers.
- `adminClient`: the `$adminClient` argument of
  `Oa4mpClientOa4mpServer::oa4mpMarshallContent()`.
- `data`: the `$data` argument of the same function, which is also what it
  hands to `oa4mpMarshallCfgQdl()`.

Both arrays are in the shape the plugin builds them in. Most fixtures are a
client as `Oa4mpClientCoOidcClient::current()` reads it, including the rows of
nulls Containable returns for a missing hasOne association;
`confidential-minimal` is the data the add page marshals, as `newClientData()`
builds it.

The exporter refuses a fixture carrying a key it does not know, at any depth,
instead of exporting it as if the key were absent. The model keys it accepts
are listed in `Oa4mpParityExporter::clientShape()` and
`adminClientShape()`; the columns of each model are read from
`Config/Schema/schema.xml`. The LDAP models are not accepted: no fixture uses
LDAP.

Rules for adding a fixture:

- Credentials are the placeholders `Test/Case/Model/ClaimCfgFixtureHygieneTest.php`
  declares (`AKIAEXAMPLE`, `not-a-real-secret`, and the rotated pair). That test
  scans these files too.
- Set `qdl_claim_source` on the admin client. Without it the plugin reads the
  `COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT` environment variable, and the
  output would depend on the container.

## Expected files

For each fixture `<name>`, `expected/<name>/` holds:

- `request-body.json`: the exact bytes of the request body, as
  `oa4mpNewClient()` and `oa4mpEditClient()` build it: `json_encode()` with
  its default flags over what `oa4mpMarshallContent()` returns. No trailing
  newline.
- `cfg.json`: the `cfg` from that body, pretty-printed, so a change shows as a
  readable diff. Present only when the body carries a `cfg`; a public client
  has none.
- `addresses.json`: the two Registry addresses, described next.

## The two Registry addresses

Two values in what the plugin sends depend on where the Registry runs, and
cannot match between Registry 4 and Registry 5:

- the client index address, written into the `comment` after the signature
  (`Created by COmanage Oa4mpClient Plugin: <address>`);
- the Named configuration address, written into the `cfg` at
  `metadata.Oa4mpClient.Oa4mpClientCoNamedConfig`.

The plugin builds both with `Router::url()`. The exporter fixes the base they
are built from, `https://registry.example.org` with the base path
`/registry`, and records the values Registry 4 produced in `addresses.json`:

```json
{
    "client_index_address": "https://registry.example.org/registry/oa4mp_client/oa4mp_client_co_oidc_clients/index/co:2",
    "named_config_address": null
}
```

`named_config_address` is null unless the fixture uses a Named configuration.
The Parity check hands these recorded values to the Registry 5 code instead of
letting it build its own, so the rest of the output can be compared byte for
byte. The running Registry 5 plugin supplies its own addresses.

## Fixtures

| Fixture | Covers |
| --- | --- |
| `confidential-minimal` | A new confidential client, as the add page marshals it |
| `public-client` | A public client: no `cfg`, although it carries a claim |
| `refresh-tokens-on` | A refresh token lifetime: the `refresh_token` grant and `rt_lifetime` |
| `refresh-tokens-off` | No refresh token row |
| `claims-email-address` | EmailAddress claims, without and with constraints |
| `claims-co-group-member` | CoGroupMember claims, without and with constraints |
| `claims-identifier` | Identifier claims, without and with constraints |
| `claims-name` | Name claims, without and with constraints |
| `claims-co-person-role` | CoPersonRole claims, without and with constraints |
| `claims-ssh-key` | SshKey claims, without and with constraints |
| `claims-co-t-and-c-agreement` | A CoTAndCAgreement claim (takes no constraints) |
| `claims-unix-cluster-account` | UnixClusterAccount claims (take no constraints), string and number |
| `require-active` | Require Active Status, no redirect URL |
| `require-active-with-redirect` | Require Active Status with its redirect URL |
| `authorization-group` | Authorized User Group, no redirect URL |
| `authorization-group-with-redirect` | Authorized User Group and Require Active Status with redirect URLs, as the Authorization tab posts them |
| `no-record-redirect` | The no-record redirect URL |
| `dynamo-per-client` | A per-client DynamoDB configuration that differs from the Default |
| `dynamo-default` | No per-client DynamoDB configuration: the Default is sent |
| `named-config` | A Named configuration |
| `jwt-access-token` | JWT access tokens |
| `reserved-key-claim` | A stored claim named like the DynamoDB key claim (`cilogon_sub`) |

## Regenerating the expected files

The exporter is the console shell `Console/Command/Oa4mpParityShell.php`. It
runs inside the hermetic test container and writes into this checkout through
the container's bind mount. Run it only when a change to what the plugin sends
is intended, and review the diff of `expected/` as part of that change.

```bash
cd Test/docker
docker compose -p oa4mp-parity up -d
docker compose -p oa4mp-parity exec -T comanage-registry bash -c '
  mkdir -p /srv/comanage-registry/local/Config
  source /usr/local/lib/comanage_utils.sh
  comanage_utils::prepare_database_config
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_parity'
# The shell runs as root in the container, so hand the files back to you.
docker compose -p oa4mp-parity exec -T comanage-registry \
  chown -R "$(id -u):$(id -g)" /srv/comanage-registry/app/Plugin/Oa4mpClient/Test/Parity/expected
docker compose -p oa4mp-parity down -v
cd ../..
git status --short Test/Parity/expected
```

The shell prints one line per fixture naming the files it wrote, then
`Exported N Parity fixtures to ...`. It reports, but does not delete, an
expected directory whose fixture is gone.

## Tests

`Test/Case/Parity/ParityExporterTest.php` runs in the hermetic suite
(`Test/run.sh`). It checks that each fixture exports the same bytes on two
runs, that every fixture's export equals its committed expected files, that
fixtures and expected directories match one to one, that the public client
exports no `cfg`, that a fixture with an unknown key fails the export, that
the exporter builds no request to the OA4MP server, and that it leaves the
Router as it found it.
