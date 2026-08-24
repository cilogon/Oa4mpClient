# AGENTS.md

## Project Overview
This project is a plugin of type other for COmanage Registry version 4.x.
The COmanage Registry code repository for version 4.x is at
https://github.com/Internet2/comanage-registry and the technical manual is
in the wiki at
https://spaces.at.internet2.edu/spaces/COmanage/pages/17105978/COmanage+Registry+Technical+Manual
Version 4.x of COmanage Registry uses the CakePHP version 2.x model view
controller (MVC) framework.

Registry users use the plugin to create and manage OpenID Connect (OIDC)
client configurations for use with the OA4MP OAuth server from CILogon. The
plugin enables users to manage OIDC client details like callback or redirect
URIs, allowed scopes, refresh token lifetimes, and the mapping of values
from Registry objects like Identifiers, Names, EmailAddresses, CoGroupMembers, 
CoPersonRoles, UnixClusterAccounts, and CoTAndCAgreements to claim values.

## Directory and File Structure & Key Details
- `Config/Schema/schema.xml`: database table definitions in AdoDb XML format.
- `Controller`: controllers used in the MVC framework, one per managed resource
  (OIDC clients, admin clients, claims, scopes, callbacks, named configurations,
  access controls, authorizations, and access and refresh tokens).
- `Controller/Component/Oa4mpClientAuthzComponent.php`: centralizes the plugin's
  permission model; `permissionSet()` computes per-action permissions for the
  three effective roles (see Architecture & Key Concepts).
- `Lib/lang.php`: text localization file for the plugin since COmanage Registry
  does not use the standard CakePHP 2.x approach to text localization.
- `Model`: models used in the MVC framework. `Oa4mpClientOa4mpServer.php`
  contains the code that calls out to the OA4MP server to create, update, and
  delete OIDC clients and that marshals the plugin's data model into the OA4MP
  `cfg` JSON. Claim-source and configuration models
  (`Oa4mpClientCoLdapConfig.php`, `Oa4mpClientDynamoConfig.php`,
  `Oa4mpClientCoNamedConfig.php`) hold LDAP/DynamoDB and named configurations;
  `Oa4mpClientCoSearchAttribute.php` migrates deprecated LDAP search attributes
  into claims.
- `View`: view files used in the MVC framework. The plugin uses the same
  conventions as Registry for view files including using symlinks to standard
  add.ctp and edit.ctp template pages and a single fields.inc file that is
  used as a template for both add and edit actions. The plugin further uses the
  file `View/Oa4mpClientCoOidcClients/tabs.inc` and symlinks to it as view
  elements.
- `webroot`: plugin front-end assets, including `css/oa4mpclient.css` and
  `js/clipboard.min.js` (used for the Copy buttons in the views).
- `Test`: the plugin's automated test suite -- a thin CakePHP-shell runner,
  since CakePHP 2.x's PHPUnit-based `cake test` does not run on PHP 8.x. Run it
  with `Test/run.sh` (Docker required); see Testing & Verification and
  `Test/README.md`.
- `Console/Command`: console shells, including `Oa4mpTestShell.php` (the
  thin-runner that discovers and runs the tests under `Test/Case`) and
  `Oa4mpSmokeShell.php` (a bootstrap smoke check).
- `cfg_format.md`, `cfg_schema.json`, `cfg_example.json`: documentation, a JSON
  schema, and an example of the OA4MP server `cfg` object the plugin marshals
  for a client.
- `cfg_contract.json`: the cfg capability contract -- the one declaration of
  every name the plugin may emit into a `cfg`, read by the marshaller, by the
  unmarshaller and the synchronization comparator, by the cfg-side half of the
  log-redaction name list, and by the conformance check. See Architecture &
  Key Concepts.
- `bin/qdl-conformance.php`: checks a named tier's QDL against
  `cfg_contract.json`. See Testing & Verification.
- `docs/solutions/`: documented solutions to past problems (bugs,
  best practices, workflow patterns), organized by category with
  YAML frontmatter (`module`, `tags`, `problem_type`). Relevant
  when implementing or debugging in documented areas.
- `docs/plans/` and `docs/brainstorms/`: planning and requirements artifacts for
  in-progress and completed work.
- `CHANGELOG.md`: release notes.
- `CONCEPTS.md`: shared domain vocabulary (entities, named processes,
  status concepts) with project-specific meaning — relevant when
  orienting to the codebase or discussing domain concepts.

## Architecture & Key Concepts
- Two-sided state and sync. Each OIDC client exists both as rows in the plugin's
  database and as a representation on the OA4MP server, and the plugin keeps the
  two in step. Before applying an edit the plugin verifies that its stored copy
  matches the server's current copy and blocks the edit when they have drifted
  ("out of sync"), so a change made to the client outside the Registry is not
  silently overwritten. Reliable verification depends on the value the plugin
  sends (marshalling) and the value it compares against being derived the same
  way.
- cfg marshalling. The plugin builds the OA4MP `cfg` JSON for a client from its
  associations. A `cfg` is permitted only on confidential clients: OA4MP rejects
  a custom configuration on a public client, so the plugin must not marshal a
  `cfg` for a public client, which releases only the standard `sub` claim.
- Access control. `Controller/Component/Oa4mpClientAuthzComponent.php` computes
  permissions for three effective roles: CO or platform administrators; managers
  (members of an admin client's delegated management group, who may create and
  manage OIDC clients); and editors (members of a client's per-client Editor
  group, who may edit that one client but cannot create clients). Delegation is
  configured through the administrator-only `delegate` action.
- Claims and legacy migration. A claim maps a Registry value (Identifier, Name,
  EmailAddress, group membership, role, and so on) to an output claim asserted
  in the token, and may carry claim constraints. Deprecated LDAP search
  attributes are migrated into claims when a client's edit page loads.
- The cfg capability contract, and the QDL that consumes it. `cfg_contract.json`
  declares every name the plugin may put into a `cfg`. The marshaller emits
  nothing the contract does not declare, and every marshalled `cfg` carries the
  `contract_version` it was built against. A change to what claim marshalling
  emits therefore starts in `cfg_contract.json`, and a change to the declared
  capability set raises `contract_version`.
- Where that QDL lives. The script that reads those capabilities is
  `dynamodb_claims.qdl` in the `cilogon-service-config-us` repository, at
  `roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl`.
  It is absent from that repository's `main`; the live lines are the
  `us-east-2-dev`, `us-east-2-test`, and `us-east-2-prod` branches, one per
  tier. Read it with `git show <branch>:<path>` rather than by switching that
  checkout, and do not conclude from `main` that the file does not exist. A
  capability the plugin emits that a tier's QDL does not implement is a
  silently ignored `cfg` key, not an error, which is why the coupling is
  written down here instead of being met by accident.
- Deployment ordering across the two repositories. A QDL change is deployed to a
  tier BEFORE any plugin deployment that emits a capability introduced with it.
  One QDL copy serves every subscriber on a tier while plugin deployments
  advance per subscriber, so deploying the QDL early is always safe -- it is a
  no-op for older `cfg` values -- and deploying the plugin early never is.
- How a QDL change reaches the tiers. It lands on `us-east-2-dev` first and is
  promoted to `us-east-2-test` and `us-east-2-prod` by hand, by the developer,
  after manual testing on dev. So expect `bin/qdl-conformance.php` to report
  `NOTHING_TO_COMPARE` for test and prod until that promotion happens; that is
  the normal state between the two, not a defect. Run the check against the
  tier you are actually about to deploy.
- What the contract rule covers. The rule above is about `dynamodb_claims.qdl`
  ONLY. The plugin's other claim consumer, the LDAP path, is outside it:
  satisfying the contract and its conformance check says nothing about that
  consumer, and a change there is not handled by having done this.
- See `CONCEPTS.md` for the authoritative glossary of these terms.

## Coding Style & Conventions
- Language: PHP version 8.3 is preferred.
- Naming convention: Follow the convention used by COmanage Registry 4.x.
- Use jQuery for dynamic HTML in view files. More but shorter lines of jQuery
  are preferred over long lines of jQuery code.
- Double slashes are preferred for comments.
- Put user-facing strings in `Lib/lang.php` rather than hard-coding them in
  controllers or views.

## Testing & Verification
- A hermetic automated test suite exists and gates every pull request via
  `.github/workflows/hermetic-tests.yml`. Run it locally with `Test/run.sh`
  (Docker required) before treating a change as complete.
- A separate, non-gating live-server tier (`Test/run-live.sh`) exercises a real
  OA4MP server with a dedicated test admin credential; it needs a real
  credential and must not be run casually.
- Every bug fix carries a regression test in the same pull request, linked to
  its `docs/solutions/` learning; this is enforced by the pull request template
  checklist. See `Test/README.md` for the detailed reference.
- Lint changed PHP with `php -l <file>` to catch syntax errors before treating a
  change as complete.
- Behavior that creates, edits, or synchronizes OIDC clients, or that talks to
  the OA4MP server or the database, cannot be verified from this repository
  alone; validate such changes manually in a running COmanage Registry with a
  reachable OA4MP server.
- A change to what claim marshalling emits is checked against the target tier's
  QDL before it is treated as complete:

      php bin/qdl-conformance.php --tier us-east-2-dev \
        --config-repo <path to a cilogon-service-config-us checkout>

  The check needs a local checkout of that repository, which this plugin's CI
  does not have, so it is a local check and a review-time obligation rather
  than a merge gate. A pull request that raises `contract_version` records the
  check's verdict and the tier it ran against -- the verdict and the tier, not
  pasted output -- and names the QDL change that satisfies it; the pull request
  template carries the item. Without a checkout of that repository, ask a
  maintainer who has one to run the check rather than attesting to it yourself.

## Do's & Don'ts
- Do: Respect existing code style and patterns but suggest alternatives
  that provide generally cleaner and more maintainable code.
- Do: Lint changed PHP with `php -l` after changes.
- Do: In a plan that changes claim marshalling, carry a QDL implementation unit
  that names the target repository (`cilogon-service-config-us`) and the path
  within it, so the cross-repository half of the change is planned rather than
  remembered later.
- Don't: Introduce new dependencies without approval.

## Git, Remotes, and Pushing
This repository uses a fork-based workflow with two remotes:
- `origin` is the developer's own fork of the repository (for example,
  `https://github.com/<developer>/Oa4mpClient`).
- `upstream` is the canonical repository at
  `https://github.com/cilogon/Oa4mpClient`.

Pushing rules for agents:
- Pushing to the developer's fork (`origin`) is allowed **only when the
  environment variable `GH_TOKEN` is defined**. With `GH_TOKEN` set, an agent
  may push the current feature branch to `origin` without asking each time.
  When `GH_TOKEN` is not defined, do not push at all: make local commits only
  and let the developer push.
- **Never push anything to `upstream`.** This is absolute. Never push to the
  remote named `upstream`, and never to any remote whose URL is the canonical
  upstream repository (`https://github.com/cilogon/Oa4mpClient`), regardless of
  what that remote is named, regardless of whether `GH_TOKEN` is set, and
  regardless of any later request to do so. The developer opens pull requests
  from the fork to upstream themselves.
- Before any push, confirm the target is the developer's fork and not upstream
  by matching the remote's **URL** with `git remote -v`, not just its name — a
  clone may have `origin` pointed at the upstream repository. If you cannot
  confirm the target is the fork, do not push.
- Push only the current feature branch to `origin`. Do not force-push a shared
  branch, and do not push to the fork's default branch (`main`/`master`) unless
  the developer explicitly asks.

Recording where work landed:
- When recording where work landed -- in a `docs/solutions/` learning, a
  plan, or a commit message -- cite the **upstream** pull request,
  owner-qualified (`cilogon/Oa4mpClient#5`), and only once it exists. The
  fork's pull request is closed unmerged and is never the landing record;
  recover the upstream number from `git log --merges main`, which carries
  it. While the work is still unmerged, name the branch and say the merge
  is pending rather than citing a pull request. See
  `docs/solutions/conventions/oa4mp-fork-pr-is-never-the-landing-record-2026-08-22.md`.
