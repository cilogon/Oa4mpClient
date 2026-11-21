# CILogon COmanage Registry Plugin for OA4MP OIDC Client Management (Registry 5)

This plugin for COmanage Registry lets CO administrators and delegated group
members create and manage OIDC clients registered with an OA4MP OpenID Provider
(OP). Clients are managed through the Registry web interface, and the plugin
keeps each client in step with its representation on the OA4MP server.

This branch, `registry-5`, is the edition for COmanage Registry 5 (Registry
PE), version 5.3.0 or later. It is under construction and not yet ready for
use. The edition for Registry 4.x, in production, is on `main`.

## Documentation

- **[CONCEPTS.md](CONCEPTS.md)** -- the project's glossary of domain terms.
- **[cfg format reference](cfg_format.md)** -- the structure of the OA4MP server
  `cfg` JSON the plugin marshals for a client.
- **[Workbench](workbench/README.md)** -- a local Registry 5 service for
  developing and checking the plugin.
- **[CHANGELOG.md](CHANGELOG.md)** -- release notes.

The Registry 4 user manual, `docs/oidc-client-plugin-manual.md`, describes the
behavior this edition carries over; a Registry 5 edition of it will follow.

## Installation

Place the plugin in the Registry's `local/plugins/Oa4mpClient` directory, then
activate it as a platform administrator, either from Registry's Plugins page or
with:

```bash
cd /srv/comanage-registry/app
./bin/cake plugin --action activate --plugin Oa4mpClient
```

Activation creates the plugin's tables. An Admin client is then added in a CO as
a Server of type `Oa4mpClient.Oa4mpAdminClients`.
