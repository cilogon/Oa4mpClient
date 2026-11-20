# AGENTS.md

## Project Overview
This branch, `registry-5`, holds the edition of the plugin for COmanage
Registry 5 (also called Registry PE). The edition for Registry 4.x lives on
`main` and stays in production; both editions send client configurations to
the same OA4MP servers.

- Registry 5 code: CILogon's branch `cilogon-develop` of
  https://github.com/cilogon/registry (upstream
  https://github.com/Internet2/registry). The plugin targets Registry 5.3.0;
  the commit the workbench and CI build from is in `workbench/registry.pin`.
- Registry 5 uses CakePHP 5. The technical manual is at
  https://spaces.at.internet2.edu/spaces/COmanage/pages/190360385/COmanage+Registry+PE+Technical+Manual
  and the coding guide at
  https://spaces.at.internet2.edu/spaces/COmanage/pages/215714439/Registry+PE+Coding+Guide.
  Both pages return an empty response to ordinary fetch tools; read them in a
  real or headless browser.

Registry users use the plugin to create and manage OpenID Connect (OIDC)
client configurations on CILogon's OA4MP server: callback URIs, scopes, token
lifetimes, login gates, and the mapping of Registry values (Identifiers,
Names, Email Addresses, group memberships, Person Roles, SSH keys, Terms and
Conditions agreements) to claims. On Registry 5, claims come from DynamoDB
only: LDAP claim sourcing, Search attributes and Claim migration are not part
of this edition.

## Directory and File Structure & Key Details
- `config/plugin.json`: the plugin's entry points and database tables, in
  Registry 5's schema format. Activating the plugin applies the schema.
- `src/Oa4mpClientPlugin.php`: the plugin class; routes live under
  `/oa4mp-client`.
- `src/Model/Table`, `src/Model/Entity`, `src/Controller`: tables, entities
  and controllers. The Admin client (`Oa4mpAdminClients`) is a Server-type
  entry point; every other plugin table hangs below it.
- `templates/`: page templates, using Registry's `fields.inc` and
  `columns.inc` conventions.
- `resources/locales/en_US/oa4mp_client.po`: user-facing text, used through
  `__d('oa4mp_client', ...)`.
- `webroot/`: plugin front-end assets.
- `workbench/`: a local Registry 5 service for developing and checking the
  plugin, with a CILogon login for the maintainer and a test login for
  agents. See `workbench/README.md`.
- `cfg_format.md`, `cfg_schema.json`, `cfg_example.json`: documentation, a
  JSON schema, and an example of the OA4MP `cfg` object the plugin marshals.
- `cfg_contract.json`: the cfg capability contract, shared with `main`. See
  Architecture & Key Concepts.
- `bin/qdl-conformance.php`: checks a named tier's QDL against
  `cfg_contract.json`. See Testing & Verification.
- `docs/solutions/`: documented solutions to past problems, carried from
  `main`. Many describe Registry 4 code, but the rule each one records about
  OA4MP, the `cfg` or the write path still applies here.
- `docs/plans/`, `docs/brainstorms/`, `docs/runbooks/`: planning artifacts and
  operator procedures.
- `CHANGELOG.md`: release notes. Releases on this line start at 8.0.0.
- `CONCEPTS.md`: shared domain vocabulary.

## Architecture & Key Concepts
- Two-sided state and sync. Each OIDC client exists both as rows in the
  plugin's tables and as a representation on the OA4MP server. Before applying
  an edit the plugin checks that its stored copy matches the server's copy and
  blocks the edit when they have drifted ("out of sync"). Reliable checking
  depends on the value sent (marshalling) and the value compared against being
  derived the same way.
- Parity with `main`. For the same client settings, this edition sends OA4MP
  the same client representation, `cfg` included, as the Registry 4 edition at
  7.0.0. The code that builds, reads and compares what goes to OA4MP is kept
  free of framework calls and in Registry 4's array shapes, so it can be
  compared with `main` line by line.
- cfg marshalling. A `cfg` is permitted only on confidential clients: OA4MP
  rejects a custom configuration on a public client, which releases only the
  standard `sub` claim.
- Ownership. Registry 5 gives plugin data no way to hang directly off a CO.
  An Admin client is a Server-type entry point attached to a core Server row,
  and Registry finds the CO of every plugin record through that row.
- Access control. Three effective roles keep their Registry 4 meaning: CO or
  platform administrators; managers (members of an Admin client's delegated
  management group, who may create and manage OIDC clients); and editors
  (members of a client's Editor group, who may edit that one client). Registry
  5 has no group-membership role, so the plugin answers these questions
  itself. When `COMANAGE_REGISTRY_OA4MP_ADMIN_USERS` is set, only the platform
  administrators it names may configure Admin clients. That check arrives
  with the Admin client pages; until then any platform administrator can.
- Saves never trust posted identifiers. Registry 5's standard edit accepts
  every posted column, so plugin records are written by explicit actions that
  take row ids and parent keys from the stored record, and entities close
  mass assignment for ids, parent keys and server-owned fields.
- The cfg capability contract, and the QDL that consumes it.
  `cfg_contract.json` declares every name the plugin may put into a `cfg`. The
  marshaller emits nothing the contract does not declare, and every `cfg`
  carries the `contract_version` it was built against. A change to the
  declared capability set raises `contract_version`, and is made on `main`
  first, since both editions share the contract.
- Where that QDL lives. The script is `dynamodb_claims.qdl` in the
  `cilogon-service-config-us` repository, at
  `roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl`,
  on the `us-east-2-dev`, `us-east-2-test`, and `us-east-2-prod` branches, one
  per tier; it is absent from that repository's `main`. Read it with
  `git show <branch>:<path>`. One QDL copy per tier serves clients from both
  editions.
- Deployment ordering across repositories. A QDL change is deployed to a tier
  BEFORE any plugin deployment that emits a capability introduced with it.
  Deploying the QDL early is a no-op for older `cfg` values; deploying the
  plugin early never is safe.
- DynamoDB records. The QDL reads person and group records that the
  DynamoProvisioner plugin writes. On Registry 5 that is DynamoProvisioner's
  `registry-5` branch. A DynamoDB table has one writing Registry at a time.
- See `CONCEPTS.md` for the authoritative glossary of these terms.

## Coding Style & Conventions
- Follow the Registry PE Coding Guide: PSR-12 with 2-space indentation, an
  80-character line target (120 soft limit), function opening braces on a new
  line, camelCase method names, short type keywords, typed parameters and
  return types, and named arguments at call sites. CakePHP conventions apply
  where the guide is silent.
- Registry core is partway through moving to that style; new plugin code
  follows the guide, not the older code around it.
- Style is checked, not rewritten: do not run an automatic fixer over the
  tree.
- Label application rules in comments as the guide describes, and do not use
  `beforeDelete` or `afterDelete` on tables with the Changelog behavior.
- Put user-facing strings in `resources/locales/en_US/oa4mp_client.po`. Log
  messages are not translated.

## Testing & Verification
- `tests/run.sh` runs the style check, the fast suite and the integration
  suite, and is the pull request gate: CI runs the same script, and a pull
  request to `registry-5` cannot merge unless it passes. A passing run ends
  with `==> PASSED: ...`. `tests/run.sh --unit` runs the fast suite alone,
  with networking disabled. Both need Docker and the Registry image from
  `workbench/bin/build-image`. See `tests/README.md`.
- The fast suite (`tests/Unit/`) holds framework-free code, the Parity check
  included, and runs with no Registry application and no database. The
  integration suite (`tests/TestCase/`) runs tables, controllers and
  permissions inside a Registry 5 container against a throwaway test
  database, with the plugin activated from this checkout.
- Each suite has a floor under its test count in `tests/run.sh`. Raise it in
  the change that adds tests; lower it only with a deliberate removal.
- The style check runs `phpcs` with `phpcs.xml`, the checkable parts of the
  coding guide. Errors fail the gate; warnings do not. It only reports: fix
  violations by hand, never with `phpcbf` or another fixer.
- Lint changed PHP with `php -l <file>` (inside the workbench container if PHP
  is not installed locally).
- Check pages and behavior in the workbench (`workbench/README.md`). Anything
  that depends on who is logged in is checked in a browser as each role it
  distinguishes, using `workbench/bin/browser-session`. Screenshots stay
  outside the repository.
- Behavior that creates, edits, or synchronizes OIDC clients cannot be
  verified from this repository alone; check it in the workbench against the
  development OA4MP tier, through an Admin client no other Registry uses.
- A change to what claim marshalling emits is checked against the target
  tier's QDL before it is treated as complete:

      php bin/qdl-conformance.php --tier us-east-2-dev \
        --config-repo <path to a cilogon-service-config-us checkout>

  Without a checkout of that repository, ask a maintainer who has one to run
  the check rather than attesting to it yourself.
- Every bug fix carries a regression test in the same pull request.

## Keeping up with `main`
`main` keeps changing while this edition is built and maintained. Every change
merged to `main` after release 7.0.0 is recorded here as ported, not
applicable to Registry 5, or pending, and nothing may be pending when a
Registry 5 release is tagged. `git log 7.0.0..main` lists the changes to
consider.

## Do's & Don'ts
- Do: Respect existing code style and patterns but suggest alternatives
  that provide generally cleaner and more maintainable code.
- Do: Base work for this edition on `registry-5`, and target pull requests
  at it.
- Do: In a plan that changes claim marshalling, carry a QDL implementation
  unit that names `cilogon-service-config-us` and the path within it.
- Don't: Introduce new dependencies without approval.
- Don't: Commit anything from `workbench/local/`, or a screenshot of an Admin
  client or DynamoDB configuration page.

## Git, Remotes, and Pushing
This repository is set up for the GitHub machine account `skoranda-agent`.
It has three remotes (confirm with `git remote -v`; all HTTPS):
- `bot` -> `https://github.com/skoranda-agent/Oa4mpClient.git`, the machine
  account's fork. Agents push feature branches here.
- `upstream` -> `https://github.com/cilogon/Oa4mpClient.git`, the canonical
  repository. Pull requests target it.
- `origin` -> `https://github.com/skoranda/Oa4mpClient.git`, the developer's
  personal fork. Agents do not push here.

The machine account has read-only access to `cilogon/Oa4mpClient` and write
access only to its own fork, and GitHub enforces that.

Rules for agents:
- **Never commit to `registry-5` or `main` directly.** Create a branch from
  `registry-5` first and commit there.
- Before any push or pull request, verify both: `gh api user --jq .login`
  prints `skoranda-agent`, and `git remote get-url bot` is
  `https://github.com/skoranda-agent/Oa4mpClient.git`. If either check fails,
  stop and tell the developer; never log in or switch `gh` accounts.
- **Shipping flow:** push the feature branch to `bot`, then open a
  ready-for-review pull request on `upstream` against `registry-5`:
  `gh pr create --repo cilogon/Oa4mpClient --base registry-5 --head skoranda-agent:<branch>`.
  The developer reviews and merges there.
- Agents may manage that pull request: edit its title and body, push
  follow-up commits to its branch, reply to review comments, and read CI
  results. Force-pushing a `bot` branch, closing a pull request, or deleting a
  `bot` branch needs the developer's approval each time.
- **Upstream CI on bot pull requests:** a pull request from a fork may wait
  for the developer's approval before Actions run, and it gets no repository
  secrets. A pull request showing no checks is not green.
- **Never** push to `upstream` or `origin` (by remote name or by URL), push or
  force-push `main` or `registry-5` on any remote, or approve or merge any
  pull request. Those stay with the developer.

Recording where work landed:
- Cite the **upstream** pull request, owner-qualified
  (`cilogon/Oa4mpClient#5`), and only once it has merged. While the work is
  unmerged, name the branch or the pull request and say the merge is pending.
- Exception: a change may cite the upstream pull request that carries it.
  After `gh pr create` returns the number, replace the "merge pending" wording
  in that change's learning, CHANGELOG entry or plan with the citation, and
  push it as a follow-up commit to the same branch.
- Pull requests before the machine account went through the developer's fork
  first; those fork pull requests were closed unmerged and are never the
  landing record. See
  `docs/solutions/conventions/oa4mp-fork-pr-is-never-the-landing-record-2026-08-22.md`.
