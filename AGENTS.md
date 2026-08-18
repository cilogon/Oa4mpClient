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
- `Controller`: controllers used in the MVC framework.
- `Lib/lang.php`: text localization file for the plugin since COmanage Registry
  does not use the standard CakePHP 2.x approach to text localization.
- `Model`: models used in the MVC framework. The file Oa4mpClientOa4mpServer.php
  contains code use to call out to the OA4MP server to create, update, and
  delete OIDC clients.
- `View`: view files used in the MVC framework. The plugin uses the same
  conventions as Registry for view files including using symlinks to standard
  add.ctp and edit.ctp template pages and a single fields.inc file that is
  used as a template for both add and edit actions. The plugin further uses the
  file `View/Oa4mpClientCoOidcClients/tabs.inc` and symlinks to it as view
  elements.
- `docs/solutions/`: documented solutions to past problems (bugs,
  best practices, workflow patterns), organized by category with
  YAML frontmatter (`module`, `tags`, `problem_type`). Relevant
  when implementing or debugging in documented areas.
- `CONCEPTS.md`: shared domain vocabulary (entities, named processes,
  status concepts) with project-specific meaning — relevant when
  orienting to the codebase or discussing domain concepts.

## Coding Style & Conventions
- Language: PHP version 8.3 is preferred.
- Naming convention: Follow the convention used by COmanage Registry 4.x.
- Use jQuery for dynamic HTML in view files. More but shorter lines of jQuery
  are preferred over long lines of jQuery code.
- Double slashes are preferred for comments.

## Do's & Don'ts
- Do: Respect existing code style and patterns but suggest alternatives
  that provider generally cleaner and more maintainable code.
- Do: Run lint after changes.
- Don't: Introduce new depedencies without approval.

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
