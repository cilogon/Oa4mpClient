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
