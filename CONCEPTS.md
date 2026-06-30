# Concepts

Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Clients

### OIDC client
A relying-party application registered with the OA4MP authorization server and managed through this plugin. Each OIDC client exists in two places at once — a row in the plugin's database and a representation on the OA4MP server — and the plugin keeps the two in step. Every OIDC client is owned by exactly one Admin client.

### Admin client
A privileged OA4MP client whose credentials the plugin uses to create and edit OIDC clients on the server. An Admin client also holds the Default configurations (LDAP and DynamoDB) that its OIDC clients inherit, and the set of Named configurations and scopes available to them.

## Configuration

### cfg
The OA4MP server's JSON configuration object for an OIDC client, describing how identity tokens and claims are produced — which QDL script to load, the phases at which it runs, and the arguments passed to it. The plugin marshals its own data model into a cfg when sending a client to the server, and compares the server's cfg against a freshly marshalled one to detect drift.

### QDL
The scripting language the OA4MP server executes to compute identity-token claims. A client's cfg names a QDL script to load, the execution phases at which it runs (e.g. post-auth, post-token), and the arguments passed to it, including the claim source connection settings.

### Named configuration
A reusable, stored cfg that an OIDC client can reference by id instead of carrying its own inline configuration. When a client uses a Named configuration, the plugin emits that stored JSON rather than building the cfg from the client's own associations.

### Default configuration
A claim-source configuration (LDAP or DynamoDB connection settings) attached to an Admin client and inherited by the OIDC clients it manages. A per-client configuration overrides it; when a client has no per-client row, the Default configuration applies.
*Avoid:* DefaultDynamoConfig, DefaultLdapConfig (these are the model names, not the concept).

## Claims

### Claim
A piece of identity information the OA4MP server releases in a token (for example email, groups, or ORCID), configured per OIDC client as a mapping from a Registry value to an output claim name. A Claim carries zero or more Claim constraints.

### Search attribute
The deprecated predecessor of a Claim — an LDAP search-attribute definition that the plugin migrates into a Claim when the client's edit page loads, recording a back-pointer from the search attribute to the Claim it produced so the migration is not repeated.

### Claim constraint
A condition attached to a Claim that restricts when or how its value is emitted (for example a type filter or a value pattern). Both its field and its value must be present; a constraint missing either is dropped rather than sent to the server.

### Orphan claim
A Claim row left with no Search attribute pointing at it, produced when claim migration persisted the Claim but failed before writing the back-pointer. It surfaces as a plugin-versus-server claim-count mismatch during Sync verification.

## Synchronization

### Sync verification
The check that the plugin's stored representation of an OIDC client matches the OA4MP server's current representation before an edit is applied. On a mismatch the client is reported "out of sync" and the edit is blocked, so that changes made to the client outside the plugin are not silently overwritten. Reliable verification requires that the value the plugin sends (marshalling) and the value it compares against are derived the same way.
