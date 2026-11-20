# Registry 5 workbench

A local COmanage Registry 5 service for developing and checking this plugin.
It runs on the developer's machine only and is never deployed.

- One Registry container, built from a pinned commit of CILogon's Registry
  branch, plus a Postgres container.
- The plugin is mounted read-only from this working copy at
  `local/plugins/Oa4mpClient`, so a code change shows without rebuilding.
- Two listeners, both published on `127.0.0.1` only:

| Port | Login | For |
|---|---|---|
| 8443 | CILogon (OIDC) | the maintainer, with real federated identities |
| 8444 | basic authentication against generated test accounts | agents and scripted checks |

The test login exists only in `workbench/local/`, which
`make-local-config` generates on your machine and git ignores. The image is
the unmodified upstream build; started without `workbench/local/` it offers
no test login (`smoke` checks this).

## Requirements

- Docker with Compose v2, `curl`, `openssl` and `htpasswd` (apache2-utils).
- A checkout of `cilogon/registry` containing the pinned commit in
  `workbench/registry.pin`.
- About 4 GB of free disk for the images.

## Quick start

```bash
cd <this checkout>
REGISTRY_CHECKOUT=/path/to/registry workbench/bin/make-local-config
workbench/bin/build-image          # 10-20 minutes the first time
workbench/bin/up
workbench/bin/seed
workbench/bin/smoke
```

`smoke` ends with `==> Smoke passed`. Without a CILogon client it reports the
CILogon listener as not configured and still passes.

## Scripts

| Script | What it does |
|---|---|
| `bin/make-local-config` | Writes `workbench/local/`: settings and generated secrets, the virtual hosts, and the test-login password file. Re-running keeps existing values. |
| `bin/build-image [--registry PATH] [--commit REF]` | Builds the base and `mod_auth_openidc` images from a clean export of one Registry commit (default: the pin). Uncommitted files in the Registry checkout never reach the image. |
| `bin/up [compose options]` | Starts or updates the containers and waits until Registry answers. Pass `--force-recreate` after `make-local-config` changed a file in `workbench/local/`. |
| `bin/seed` | Creates the workbench CO, its groups and the test people. Adds only what is missing. |
| `bin/reset` | Drops the workbench database and starts again from Registry's own setup. Run `seed` afterwards. |
| `bin/smoke` | Checks the published ports, the image without local configuration, every test login, a wrong password, and the CILogon listener. |

To mount another plugin, such as DynamoProvisioner, put a compose override in
`workbench/local/compose.override.yaml` (git ignores it); every script picks it
up. For example:

```yaml
services:
  registry:
    volumes:
      - /path/to/DynamoProvisioner:/srv/comanage-registry/local/plugins/DynamoProvisioner:ro
```

Then run `bin/up --force-recreate` and activate the plugin with
`bin/cake plugin --action activate --plugin DynamoProvisioner` inside the
container.

Changing the pinned Registry commit is a deliberate change: edit
`registry.pin`, rebuild, and rerun the plugin's tests and browser checks.

## Test people

`seed` creates these, each with a login identifier equal to the test-login
username. All test logins share the password in `TEST_LOGIN_PASSWORD` in
`workbench/local/workbench.env`.

| Username | Who | Where Registry sends them after login |
|---|---|---|
| `wb-platform-admin` | Platform administrator, on the plugin's Admin client allowlist | CO chooser |
| `wb-platform-admin-unlisted` | Platform administrator, not on the allowlist | CO chooser |
| `wb-co-admin` | Administrator of Workbench CO | Workbench CO dashboard |
| `wb-manager` | Member of `Workbench OIDC Client Managers` | Workbench CO dashboard |
| `wb-editor` | Member of `Workbench OIDC Client Editors` | Workbench CO dashboard |
| `wb-member` | Ordinary member, in `Workbench Authorized Users` | Workbench CO dashboard |
| `wb-inactive` | Suspended person, in `Workbench Authorized Users` | "not an active member" notice |
| `wb-no-record` | No Registry record at all | "not an active member" notice |

The groups exist so the plugin can be pointed at them: delegate an Admin
client to the managers group, give a client the editors group, and use the
authorized users group as an Authorized User Group. `seed` does not configure
the plugin itself.

The allowlist (`COMANAGE_REGISTRY_OA4MP_ADMIN_USERS`) is set to
`wb-platform-admin`, plus the maintainer's CILogon subject when one is
configured. The plugin does not enforce it yet: until the Admin client pages
arrive, `wb-platform-admin-unlisted` can configure Admin clients too.

## Logging in as a test person

In a browser, open `https://localhost:8444/registry/cos/select` and accept the
self-signed certificate. Registry sends you to
`/registry/auth/login/login.php`, where the browser asks for the username and
password.

From a script, send the credentials to the login script only. Registry treats
any other request that carries basic-authentication credentials as a REST API
call. `smoke` shows the sequence:

1. `GET /registry/cos/select` with a cookie jar, following redirects (records
   where to return to; ends at a 401).
2. `GET /registry/auth/login/login.php` with the credentials.
3. `GET /registry/traffic/process-login` with the cookie jar, following
   redirects.

Cookies are per host, not per port, so one browser profile shares a single
Registry session between the two listeners. Use a separate browser profile
for each.

## Browser sessions for agents

`bin/browser-session <login>` opens a headless browser signed in as one test
person, using [agent-browser](https://github.com/vercel-labs/agent-browser)
(`CI=true npm install -g agent-browser && agent-browser install`). The session
is named after the person, so several can run side by side:

```bash
workbench/bin/browser-session wb-editor
agent-browser --session wb-editor open https://localhost:8444/registry/cos/select
agent-browser --session wb-editor snapshot -i
agent-browser --session wb-editor screenshot ~/claude-artifacts/oa4mp-workbench-screenshots/editor-clients.png
agent-browser --session wb-editor close
```

`--list` prints the test logins; `--headed` shows the window. Each run closes
any earlier session of the same name and logs in afresh. The browser answers
only the login challenge, so the rest of Registry sees an ordinary web
session.

Screenshots go to `WORKBENCH_SCREENSHOT_DIR` (default
`~/claude-artifacts/oa4mp-workbench-screenshots`). The script refuses a
directory inside a git repository. A screenshot of an Admin client or DynamoDB
configuration page shows secrets: never commit it or attach it to a pull
request or issue.

## CILogon login for the maintainer

1. Register a client at https://cilogon.org/oauth2/register with:
   - Callback URL: `https://localhost:8443/secure/redirect`
   - Scopes: `openid`, `email`, `profile`, `org.cilogon.userinfo`
   - Client type: confidential
2. Find your CILogon subject (`sub`), for example from
   https://cilogon.org/me after logging in there.
3. Store the client and your subject, then restart:

   ```bash
   WORKBENCH_CILOGON_CLIENT_ID=... \
   WORKBENCH_CILOGON_CLIENT_SECRET=... \
   WORKBENCH_MAINTAINER_LOGIN=... \
     workbench/bin/make-local-config
   workbench/bin/up --force-recreate
   workbench/bin/seed
   workbench/bin/smoke
   ```

`seed` makes your subject a platform administrator on the allowlist. `smoke`
then checks that logins on 8443 are sent to CILogon.

## Keeping secrets out of the repository

- `workbench/local/` holds the database password, the test-login password,
  the OIDC passphrase and any CILogon client secret. Git ignores it; never
  copy its contents into a commit, issue or pull request.
- Both listeners accept connections from this machine only.
- Screenshots and browser state belong outside every repository.
