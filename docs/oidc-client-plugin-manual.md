# OIDC Client Plugin - End-User Manual

This manual explains how to use the CILogon OA4MP OIDC Client plugin for
COmanage Registry to create and manage OIDC clients registered with an OA4MP
OpenID Provider.

It is written for the people who use the plugin's web interface:

- **CO administrators**, who can do everything the plugin offers.
- **Delegated group members** (managers), whom an administrator has authorized
  to create and manage OIDC clients.
- **Per-client editors**, whom an administrator has authorized to edit a single
  OIDC client.

You do not need to be an OpenID Connect expert to use this manual. Chapter 1
explains the concepts you need; later chapters walk through each task.

> **As of:** this manual describes the plugin's current interface (COmanage
> Registry 4.x, 2026). The interface changes over time; if a screen does not
> match what you see, treat the running interface as authoritative and let the
> plugin maintainers know the manual has drifted.

## Contents

1. [Concepts](#1-concepts)
2. [Access control and delegation](#2-access-control-and-delegation)
3. [Managing clients](#3-managing-clients)
4. [Configuring claims, scopes, callbacks, and named configurations](#4-configuring-claims-scopes-callbacks-and-named-configurations)
5. [Managing tokens](#5-managing-tokens)

---

## 1. Concepts

This chapter defines the terms used throughout the manual. The plugin's
authoritative glossary is `CONCEPTS.md` at the root of the plugin's source
repository; the definitions here are a reader-friendly subset.

### OpenID Connect in one paragraph

OpenID Connect (OIDC) lets an application (a "client") ask an OpenID Provider
(here, an OA4MP server) to authenticate a user and return identity information
about them. The identity information is delivered as **claims** (for example, a
user's email address or group memberships) inside a token. This plugin is where
you register and configure those clients and decide which claims each one
receives.

### OIDC client

An **OIDC client** is a relying-party application registered with the OA4MP
server and managed through this plugin. Each OIDC client exists in two places at
once -- a record in the plugin's database and a representation on the OA4MP
server -- and the plugin keeps the two in step. Every OIDC client is owned by
exactly one Admin client.

### Admin client

An **Admin client** is a privileged OA4MP client whose credentials the plugin
uses to create and edit OIDC clients on the server. An Admin client also holds
the default configurations that its OIDC clients inherit and the set of named
configurations and scopes available to them. You generally set up Admin clients
once; most day-to-day work happens on the OIDC clients they own.

### Public and confidential clients

When you create an OIDC client you choose whether it is **public** or
**confidential**:

- A **confidential client** can keep a secret. It authenticates to the OA4MP
  server with a client secret and can be configured to release custom claims.
- A **public client** cannot keep a secret (for example, a single-page app or a
  native mobile app). It uses no client secret and releases only the standard
  `sub` claim -- an opaque, stable identifier for the user. Because a public
  client releases only `sub`, the plugin does not offer claim configuration for
  it (see Chapter 4).

### Claim

A **claim** is a piece of identity information the OA4MP server releases in a
token -- for example email, groups, or ORCID. In this plugin a claim is
configured per OIDC client as a mapping from a Registry value to an output claim
name. A claim can carry zero or more **claim constraints**, which restrict when
or how its value is emitted.

### Scope

A **scope** is a named group of claims that a client may request at
authentication time (for example `openid`, `email`, or `profile`). The Admin
client defines the scopes available to its OIDC clients; each OIDC client is
configured with the subset of scopes it is allowed to request.

### Named configuration and default configuration

- A **default configuration** is a claim-source connection (LDAP or DynamoDB
  settings) attached to an Admin client and inherited by the OIDC clients it
  manages. A per-client configuration overrides it.
- A **named configuration** is a reusable, stored server configuration that an
  OIDC client can reference by name instead of carrying its own inline settings.

You will rarely need to think about these unless you are wiring up a new claim
source; most OIDC clients simply inherit the Admin client's default.

### The sync model

Because every OIDC client lives both in the plugin's database and on the OA4MP
server, the plugin checks that its stored copy of a client matches the server's
copy before it applies an edit. If the two have drifted apart -- for example,
because the client was changed on the server directly -- the plugin reports the
client **out of sync** and blocks the edit so your change cannot silently
overwrite the server's. Chapter 3 explains what to do when this happens.

---

## 2. Access control and delegation

This chapter explains who can do what in the plugin and how an administrator
grants others the ability to create and manage clients. It is the part of the
plugin most often misunderstood, so read it before delegating anything.

### The three roles

The plugin recognizes three effective roles:

- **CO or platform administrator.** Can do everything: create, edit, delete, and
  manage every OIDC client in the CO, and configure delegation.
- **Manager.** A member of a CO group that an administrator has designated as an
  Admin client's *delegated management group*. Managers can create new OIDC
  clients and manage the clients that do not have their own Editor group.
- **Editor.** A member of a CO group that an administrator has designated as a
  specific OIDC client's *Editor group*. An editor can edit and manage that one
  client, but cannot create new clients.

### Who can do what

The table below summarizes each role's capabilities. Administrators always have
every capability.

| Capability | CO / platform admin | Manager (delegated management group) | Editor (per-client Editor group) |
|---|---|---|---|
| Add a new OIDC client | Yes | Yes | No |
| Configure delegation | Yes | No | No |
| Edit / delete / manage a client | Yes | Yes, unless the client has an Editor group | Yes, only when the client has an Editor group |
| View the client list | Yes | Yes | Yes (the clients they can edit) |

Two subtleties are worth calling out:

- When an OIDC client has an **Editor group**, management of that client passes
  to its editors: a manager who is not in that group can no longer edit it. This
  lets an administrator hand a single client to a team without giving them the
  other clients.
- A **manager** manages every client that has *no* Editor group. Assign an Editor
  group to a client to narrow who can manage that particular client.

### Configuring delegated management (administrators)

Delegated management is set per Admin client, and only an administrator can
configure it.

1. Go to the **OIDC Clients** list.
2. Select **Edit Delegated Management Group**. This button appears only for
   administrators.
3. The **Delegate Management** page lists each Admin client in the CO as
   *name - issuer*, with a **Management Group** dropdown beside it. The field
   description reads "Group members allowed to manage OIDC clients for this
   OAuth2 Server and Issuer."
4. For each Admin client, choose the CO group whose members should be able to
   create and manage its OIDC clients, or leave it at "-- Select Group (or leave
   for no delegation) --" for no delegation.
5. Select **Save**.

Members of the group you select become **managers** for that Admin client's
OIDC clients. Because this setting lives on the Admin client -- not on
individual OIDC clients -- a group you linked earlier keeps granting client
creation until you change it here.

### Configuring per-client editors (administrators and managers)

To let a group edit one specific OIDC client without giving them the others, set
that client's Editor group.

1. From the **OIDC Clients** list, edit the client.
2. Open the **Editors** tab.
3. In the **Editor Group** dropdown, choose the CO group whose members may edit
   this client. The field description reads "Group whose members may edit this
   OIDC client configuration. Default is CO admins and delegated client
   managers."
4. Select **Add** (or **Save** if a group is already set).

Once a client has an Editor group, its editors gain edit and manage rights on
it, and managers who are not in that group lose those rights for that client.

### A current limitation: editors cannot add clients

An editor can edit and manage the client they are assigned to, but **cannot
create new OIDC clients**. Creating clients requires being a manager -- a member
of a delegated management group. Today, the only way to let someone add clients
is to add them to a delegated management group, which also makes them a manager
of every client that has no Editor group.

If you are an editor and need to add a client, ask an administrator to either
create the client for you (and assign you as its editor) or add you to a
delegated management group. This behavior is a current limitation of the plugin
and is under active review; a future version may let editors add clients
directly.

### If you used the previous Admin UI

The earlier Admin client interface had a "Delegated Management Group" dropdown
directly on the Admin client's edit form. That control has moved: delegated
management is now configured through the **Edit Delegated Management Group**
button on the OIDC Clients list (see "Configuring delegated management" above).
A CO group that was linked to an Admin client in the old interface is preserved
-- its members remain managers and can still create OIDC clients -- so nothing
you set previously was lost; it is simply edited in a new place.
