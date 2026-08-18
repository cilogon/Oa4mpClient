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

---

## 3. Managing clients

This chapter covers viewing Admin clients and the day-to-day work of creating,
editing, and deleting OIDC clients.

### Admin clients

The **Admin Clients** list shows the privileged clients whose credentials the
plugin uses to talk to each OA4MP server (see "Admin client" in Chapter 1). Each
Admin client owns a set of OIDC clients and supplies the defaults they inherit.

Creating and configuring Admin clients is a one-time setup task usually performed
by a CILogon deployer, and is documented in the internal CILogon operational
runbooks rather than here. As an end user you will mostly work with the OIDC
clients an Admin client owns; you may need the Admin Clients list only to confirm
which OA4MP server and issuer a client belongs to, or to configure delegated
management (Chapter 2).

### Viewing OIDC clients

The **OIDC Clients** list is the plugin's main screen. Each row shows:

- **Name** -- the client's display name; links to its edit page.
- **OAuth2 Server and Issuer** -- the Admin client that owns it, shown as
  *name - issuer*.
- **OIDC Identifier** -- the client's identifier on the OA4MP server, with a
  **Copy ID** button.

The list also carries the action buttons you have permission to use: **Edit** and
**Delete** per client, an **Add a New OIDC Client** button, and -- for
administrators -- the **Edit Delegated Management Group** button described in
Chapter 2.

### Creating an OIDC client

1. From the **OIDC Clients** list, select **Add a New OIDC Client**.
2. Fill in the form:
   - **OAuth2 Server and Issuer** (the Admin client) -- required. It *cannot be
     changed after the client is created*, so choose carefully.
   - **Name** -- required; the client's display name.
   - **Home URL** -- required; used as the hyperlink for the client's name on the
     Identity Provider selection page.
   - **Contact Email** -- required; used for operational notices about the client.
   - **Public Client** -- leave unchecked for a confidential client (the common
     case). Check it only for a public client, which has no client secret and may
     request only the `openid` scope. **This choice cannot be changed after the
     client is created.**
3. Select **Next**.

The plugin creates the client on the OA4MP server and shows the **New OIDC
Client** page:

- The **OIDC Identifier** (client id), with a **Copy** button.
- For a **confidential** client, the **Client Secret**, with a **Copy** button
  and a dialog that warns: "You MUST permanently record the client secret before
  continuing. The CILogon servers do not store the client secret." Record it now
  -- it is shown only this once and cannot be retrieved later.
- For a **public** client, a dialog confirming the client has no secret.

Select **Continue** to go to the client's **Scopes** page, where you choose which
scopes it may request (see Chapter 4).

### Editing an OIDC client

Select a client's **Name** or its **Edit** button from the list. The edit form
shows the same fields as creation, with two differences:

- The **OIDC Identifier** and the **OAuth2 Server and Issuer** are shown but
  cannot be changed.
- The **Public Client** checkbox is disabled -- a client's type is fixed at
  creation.

Editing a client also gives you the tab bar for its configuration surfaces
(Claims, Scopes, Callbacks, Editors, and token management), covered in the next
chapters. Select **Save** to apply changes to the name, home URL, or contact
email.

### Deleting an OIDC client

Select a client's **Delete** button and confirm. This removes the client from
both the plugin and the OA4MP server.

### When a client is "out of sync"

Before it applies an edit, the plugin checks that its stored copy of the client
still matches the OA4MP server's copy (see "The sync model" in Chapter 1). If the
client was changed directly on the server -- outside the Registry -- the two no
longer match, and the plugin blocks the edit rather than overwriting the server's
copy. You will see:

> This client has been modified outside of the Registry. Please email
> help@cilogon.org for assistance.

This is a safety stop, not data loss: your change was not applied and the
server's copy is untouched. Follow the message and contact CILogon support to
reconcile the two copies before editing the client again.

---

## 4. Configuring claims, scopes, callbacks, and named configurations

Once a client exists, you configure its behavior through the tabs that appear
when you edit it: **Claims**, **Scopes**, **Callbacks**, and **Named
Configuration** (plus the **Editors** and token tabs covered in Chapters 2 and
5). This chapter covers the first four.

### Scopes

Scopes are the groups of claims a client is allowed to request. Open the
**Scopes** tab -- you land here automatically right after creating a client. You
will see a checklist:

- **openid** -- always required; it cannot be unchecked.
- **profile**, **email**, **org.cilogon.userinfo** -- check the ones this client
  may request.
- **edu.uiuc.ncsa.myproxy.getcert** -- shown for existing clients that already
  have it.

Check the scopes the client should be allowed to request and select **Save**.

**Public clients** may request only the `openid` scope. On the Scopes tab for a
public client, every other scope is disabled and there is no Save button --
there is nothing to change.

### Claims

The **Claims** tab lists the custom claims a client releases and lets you add,
edit, and remove them. Each claim in the list links by name to its editor.

To add a claim, select **Add a New Claim**, then fill in the form:

1. **Claim Name** -- required; the name of the claim as it will be asserted in
   the token. If you enter the name of a standard claim (such as `email` or
   `name`), the form warns you that you are overriding a standard claim.
2. **Source** -- required; where the claim's value comes from. The choices are
   EmailAddress, Groups, Identifier, Name, Role, SSH Key, Terms & Conditions,
   and Unix Cluster Account.
3. Depending on the Source you pick, the form reveals the fields relevant to it,
   which may include:
   - a **field selector** (for example, which part of a Name or SSH Key to use);
   - **constraints** that filter which values qualify (for example, an email
     type, or a "Verified" checkbox for email addresses);
   - **Value Selection** -- "First value found" or "All values found";
   - **Multiple Value Format** -- when a claim can carry several values, whether
     to serialize them as a JSON array or a delimited string (and, for a
     delimited string, the delimiter);
   - **JSON format** -- whether the value is emitted as a JSON string or number.

Select **Add** (or **Save** when editing) to apply.

Public clients release only the standard `sub` claim, so **the Claims tab does
not offer claim configuration for a public client.** Instead it shows the
message "Public clients release only the standard sub claim, so additional
claims cannot be configured," and the Add, Edit, and Delete actions are not
available.

### Callbacks

The **Callbacks** tab manages the client's callback (redirect) URLs. Each
callback is a single **Callback URL**; the form notes that "The OIDC protocol
redirect_uri parameter must exactly match the callback URL." Add one callback
per URL the client will redirect to, and edit or delete them from the callback
list.

### Named configurations

A **named configuration** is a reusable server configuration that a client can
reference instead of building its own. Most clients never need one -- they
inherit their Admin client's default. Named configurations are managed from the
**Named Configuration** area, where you can list, add, and edit them.

When you add or edit a named configuration you provide:

- **Admin Client** -- which Admin client (and CO) the configuration belongs to.
- **Configuration Name** -- required; the name clients reference it by.
- **Description** -- optional.
- **Configuration** -- required; the full OA4MP server `cfg` JSON. This is the
  raw server configuration; for its structure see `cfg_format.md` in the plugin
  repository. The stored JSON is not displayed back to administrators after it
  is saved.
- **Scopes** -- the scopes the configuration allows, including any additional
  ad-hoc scopes you add.

When you save, the plugin reminds you: "Editing the Named Configuration does NOT
cause the cfg for any client to be automatically updated. You must edit and
re-save any client that uses the Named Configuration you just edited." Plan to
re-save each affected client after changing a named configuration.

---

## 5. Managing tokens

The remaining tabs on a client's edit page control token issuance and who is
authorized to use the client.

### Access Token

The **Access Token** tab has one setting, **JWT Format** -- whether access
tokens for this client are issued in JWT format. Check or clear it and select
**Save**.

### Refresh Token

The **Refresh Token** tab sets the **Refresh Token Lifetime**, given as a token
lifetime in seconds. Enter a value and select **Save**.

### Authorization

The **Authorization** tab controls *who may use* the client -- as distinct from
the **Editors** tab (Chapter 2), which controls who may *edit its
configuration*. It has these settings:

- **Authorized User Group** -- a CO group whose members may access this client.
  Leave it unset to place no group restriction on use.
- **Group authorization Redirect URL** -- appears once you select an authorized
  group; the URL to send users to when they are not members of that group. If
  you leave it blank, the client receives a standard protocol error message
  instead.
- **Require Active Status** -- when checked (the default), a user's record must
  have active status to access the client.
- **Active Status Redirect URL** -- appears when Require Active Status is
  checked; where to send users who do not have active status. If blank, the
  client receives a standard protocol error message.

Select **Save** to apply the authorization policy.
