---
title: Admins saw the main-menu OIDC Clients link only by coincidence of group membership
date: 2026-11-16
category: ui-bugs
module: Oa4mpClient plugin (Model/Oa4mpClient.php cmPluginMenus)
problem_type: ui_bug
component: rails_model
severity: low
symptoms:
  - "A CO admin of a CO with no delegated management group saw no OIDC Clients entry in the main (left-hand) menu"
  - "The same admin saw the entry in a CO whose management group was CO:admins"
  - "The page itself opened normally through Configuration -> OIDC Clients"
root_cause: logic_error
resolution_type: code_fix
tags: [comanage, plugin-menus, cmPluginMenus, comain, platform-admin, co-admin, session, oa4mp]
---

# Admins saw the main-menu OIDC Clients link only by coincidence of group membership

## Problem

cilogon/Oa4mpClient#78, fixed by cilogon/Oa4mpClient#79. A CO admin reported that "OIDC Clients" was missing from the main menu when no delegated management group was set. They could still open the page through Configuration.

## Root cause

The plugin adds two menu entries for the OIDC Clients page. The `coconfig` entry sits in the Configuration menu, which core shows only to platform and CO admins. The `comain` entry is the main-menu link, and `cmPluginMenus()` added it only for current members of an admin client's management group or an OIDC client's authorization group. That link exists for delegates, who have no Configuration menu.

Nothing added it for admins as such. An admin got it only when they also belonged to one of those groups, for example when the management group was set to CO:admins. Access was never affected: `Oa4mpClientAuthzComponent::permissionSet()` lets admins in regardless.

## Solution

`cmPluginMenus()` now reads the session and hands the CO person id and username to `menusFor()`. That method also adds the link for:

- platform admins, in every CO;
- CO admins, meaning current members of their CO's own admins group with no COU, when the CO has an OIDC admin client.

The issue proposed treating an unset management group as CO:admins instead. That was rejected because it would make CO:admins managers in the permission logic and leave the delegate page showing no group while one was in effect, a heavier change than a menu link needs.

## What the menu hook can and cannot know

These facts about COmanage core shaped the fix and are easy to get wrong:

- **`cmPluginMenus()` takes no arguments.** Core calls it once per page (`AppController.php`, around line 1497) and keeps no "current CO" in the session, so the hook cannot ask which CO is on screen.
- **`Auth.User.co_person_id` is not always a person in the CO on screen.** `RoleComponent::calculateCMRoles()` writes the person for the current CO when the user is a member of it. When a platform admin views a CO they are not in, it writes their COmanage CO person instead (`RoleComponent.php`, around line 552). That is why platform admins get the link in every CO: the hook cannot tell whether the CO on screen has an admin client.
- **The link still opens the right CO.** `retrieve_plugin_menus()` in core `Lib/util.php` adds the CO on screen to every plugin menu URL, so the hook's own idea of the CO does not affect where the link goes.
- **Platform admin means the login identifier, not the CO on screen.** Core's `identifierIsCmpAdmin()` finds the username's CO person in the COmanage CO and checks that CO's admins group. A model cannot call the controller component, so `isPlatformAdmin()` repeats the lookup.
- **`CoPerson::idsForIdentifier()` throws when no login identifier matches.** Core catches it. The menu hook runs on every page, so the plugin must catch it too, or a user without an active login identifier could not load any page.

## Testing

`Test/Case/Model/MenuVisibilityTest.php` calls `menusFor()` directly, because the thin runner has no controller session. The hermetic database has no COmanage CO, since `cake database` does not run setup, so the platform-admin tests create one named `DEF_COMANAGE_CO_NAME` and give the test person a login identifier.

Fixture cleanup deletes rows newest first. An admin client that points at a management group must therefore be created after that group, or cleanup hits the `manage_co_group_id` foreign key.

## Prevention

- When a plugin menu entry is meant for one audience, check whether another audience that can use the page is left without a way to find it.
- Before relying on `Auth.User.co_person_id` in code that runs outside a CO-scoped controller, remember it may belong to the COmanage CO.
