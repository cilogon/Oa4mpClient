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
