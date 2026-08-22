# Concepts

Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Clients

### OIDC client
A relying-party application registered with the OA4MP authorization server and managed through this plugin. Each OIDC client exists in two places at once — a row in the plugin's database and a representation on the OA4MP server — and the plugin keeps the two in step. Every OIDC client is owned by exactly one Admin client.

### Admin client
A privileged OA4MP client whose credentials the plugin uses to create and edit OIDC clients on the server. An Admin client also holds the Default configurations (LDAP and DynamoDB) that its OIDC clients inherit, and the set of Named configurations and scopes available to them.

### Public client
An OIDC client that authenticates with no client secret. A public client releases
only the standard subject claim: it may carry no cfg, no configurable Claims, and
no claim-source configuration, because the authorization server rejects a custom
configuration on one. Its opposite, a **confidential client**, holds a secret and
may carry all of these.

### Callback
A redirect URI registered on an OIDC client, to which the authorization server
returns the user after authentication. An OIDC client carries a list of them.
*Avoid:* callback URL (use Callback for the registered entry).

## Configuration

### cfg
The OA4MP server's JSON configuration object for an OIDC client, describing how identity tokens and claims are produced — which QDL script to load, the phases at which it runs, and the arguments passed to it. The plugin marshals its own data model into a cfg when sending a client to the server, and compares the server's cfg against a freshly marshalled one to detect drift. A cfg is permitted only on confidential clients; OA4MP rejects a custom configuration on a public client, so the plugin must not marshal one for a public OIDC client.

### cfg format
One of the several shapes a cfg may take, distinguished by how it nests the QDL
script and its arguments. Several coexist in the wild — a current shape and
older ones kept working for clients registered under them — so the plugin
detects the format by trying each in turn and using the first that yields a
result. A cfg is never rejected for being in an older format; failing to
recognize one is a bug, not a policy.

### QDL
The scripting language the OA4MP server executes to compute identity-token claims. A client's cfg names a QDL script to load, the execution phases at which it runs (e.g. post-auth, post-token), and the arguments passed to it, including the claim source connection settings.

### Named configuration
A reusable, stored cfg that an OIDC client can reference by id instead of carrying its own inline configuration. When a client uses a Named configuration, the plugin emits that stored JSON rather than building the cfg from the client's own associations.

### Default configuration
A claim-source configuration (LDAP or DynamoDB connection settings) attached to an Admin client and inherited by the OIDC clients it manages. A per-client configuration overrides it; when a client has no per-client row, the Default configuration applies.
*Avoid:* DefaultDynamoConfig, DefaultLdapConfig (these are the model names, not the concept).

### Marshalling
Building the authorization server's representation of an OIDC client from the
plugin's own data model, to be sent when creating or editing the client. All
outbound traffic passes through a single marshalling step, which makes it the
place to enforce any rule the server imposes on what a client of a given type
may carry.

### Unmarshalling
The inverse of Marshalling: translating the server's stored representation of a
client back into the plugin's data model shape, so the two can be compared. It
is read-only — unmarshalling never writes — and must produce the same shape
Marshalling produces, or Sync verification reports False drift.

## Claims

### Claim
A piece of identity information the OA4MP server releases in a token (for example email, groups, or ORCID), configured per OIDC client as a mapping from a Registry value to an output claim name. A Claim carries zero or more Claim constraints.

### Search attribute
The deprecated predecessor of a Claim — an LDAP search-attribute definition that the plugin migrates into a Claim when the client's edit page loads, recording a back-pointer from the search attribute to the Claim it produced so the migration is not repeated.

### Claim constraint
A condition attached to a Claim that restricts when or how its value is emitted (for example a type filter or a value pattern). Both its field and its value must be present; a constraint missing either is dropped rather than sent to the server.

Where a claim source offers an explicit "any type" choice, that choice is stored
as an empty value but must be sent to the server as an explicit "all" literal.
Both the writer and the comparator must apply that normalization identically, or
the claim fails validation on write and reports False drift on compare.

### Orphan claim
A Claim row left with no Search attribute pointing at it, produced when Claim migration persisted the Claim but failed before writing the back-pointer. It surfaces as a plugin-versus-server claim-count mismatch during Sync verification.

On a later migration pass, a Search attribute that would produce a Claim identical
to an existing orphan adopts that orphan and writes the missing back-pointer
instead of inserting a duplicate. Adoption requires an exact match on the identity
fields and the full constraint set, and is refused when more than one orphan
matches; anything left unadopted needs operator cleanup.

### Claim migration
The one-way conversion of a deprecated Search attribute into a Claim, run when a
client's edit page loads. The Claim and its back-pointer must be written
together: if the Claim persists and the back-pointer does not, migration repeats
on the next page load and leaves an Orphan claim behind each time.

### Wired-but-stale claim
A Claim that is correctly pointed at by its Search attribute — so migration will
never revisit it — but whose persisted constraint shape no longer matches what
the current code would build for it. Distinct from an Orphan claim, which is
unreferenced: this one is properly wired and therefore invisible to the
migration path, and only surfaces as Drift or as a claim the server never
receives.

### Effective filter
A Claim constraint value computed from live configuration rather than copied from
a fixed setting — for example, a value restricted to the set of identifier types
a CO actually offers. An empty effective filter means the claim is suppressed
entirely rather than emitted with an empty constraint, so the plugin deliberately
expects one fewer claim than a naive count would predict.

## Synchronization

### Sync verification
The check that the plugin's stored representation of an OIDC client matches the OA4MP server's current representation before an edit is applied. On a mismatch the client is reported "out of sync" and the edit is blocked, so that changes made to the client outside the plugin are not silently overwritten. Reliable verification requires that the value the plugin sends (Marshalling) and the value it compares against are derived the same way.

Because the writing path and the comparing path are separate code, any rule that
shapes a value must be applied identically by both. Where the two must agree,
sharing one implementation is preferred to maintaining mirrored copies, since
mirrored copies drift silently and the failure surfaces only as False drift.

### Drift
A real difference between the plugin's stored representation of an OIDC client
and the server's. **False drift** is the failure mode where the two sides agree
in substance but the comparison reports a difference anyway, because the values
were derived differently. False drift is worse than it looks: it blocks edits on
correct data, and it trains operators to distrust the check.

## Workflow and verification

### Landing record
The durable identifier for where a change actually merged. Because this project
is developed on a fork and merged in the canonical repository, work passes
through two pull requests with different numbers, and only the canonical one is
the landing record. Anything that records where a change landed — a learning, a
plan, a commit message — cites that one, qualified by repository because a bare
number is ambiguous across the two. Until the merge happens there is no landing
record; name the branch and say the merge is pending instead.

### Fork pull request
The pull request opened against the developer's own fork, used to read a change
and confirm its checks. It is always closed unmerged and is never the Landing
record. Its counterpart, the **upstream pull request**, is opened against the
canonical repository, is where the work merges, and carries a different number.

### Hermetic tier
The automated test tier that runs with no credentials and no network access to
any real server, standing up its own database and a stubbed authorization
server. It gates every pull request, so it must stay runnable by anyone.

### Live-server tier
The separate, non-gating test tier that exercises a real authorization server
with a dedicated test credential. It cannot run in the Hermetic tier's
conditions and is not run casually.

### Silent pass
A check that reports success while verifying nothing — a green gate guarding an
empty rule set, a scan whose configuration disarmed it, a test asserting on data
it never loaded. Distinct from an ordinary false negative in that the check is
not merely wrong but structurally incapable of failing, so it reports success
forever. Any gate whose disarmed state is indistinguishable from its passing
state needs a test asserting the gate itself is armed.

### Verified red
The discipline of confirming a new regression test fails against the pre-fix
behavior before shipping it. A test that has never been observed to fail is a
Silent pass waiting to happen.
