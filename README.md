# CILogon COmanage Registry Plugin for OA4MP OIDC Client Management

This plugin for COmanage Registry lets CO administrators and delegated group
members create and manage OIDC clients registered with an OA4MP OpenID Provider
(OP). Clients are managed through the Registry web interface, and the plugin
keeps each client in step with its representation on the OA4MP server.

## Documentation

- **[End-user manual](docs/oidc-client-plugin-manual.md)** -- how to use the
  plugin: the concepts, the access-control and delegation model, and task
  walkthroughs for creating and configuring OIDC clients, claims, scopes,
  callbacks, named configurations, and tokens. Start here.
- **[cfg format reference](cfg_format.md)** -- the structure of the OA4MP server
  `cfg` JSON the plugin marshals for a client.
- **[CONCEPTS.md](CONCEPTS.md)** -- the project's glossary of domain terms.
- **[CHANGELOG.md](CHANGELOG.md)** -- release notes.

Internal CILogon operational runbooks -- configuring an OA4MP Admin client and
configuring a DynamoProvisioner target -- live in the private `operational-info`
repository, not here.

## Installation

Install this plugin as you would any other COmanage Registry plugin: place it in
the Registry's plugin directory and enable it from the Registry's plugin
management interface. See the COmanage Registry documentation for general plugin
installation, and the internal operational runbooks for the CILogon-specific
Admin client and claim-source setup.
