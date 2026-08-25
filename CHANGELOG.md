# Changelog

## 7.0.0-rc8 (2026-08-25)

- Keep the refresh-token grace period, and the other settings the OA4MP server
  holds for a client but the plugin does not model, when a client is edited.
  The plugin asked the server for an older representation that did not report
  those settings at all, so an edit sent the client back without them and the
  server-side values were lost. Nothing in the Registry showed it; the loss
  surfaced later as refresh-token behavior nobody had changed on purpose.

## 7.0.0-rc7 (2026-08-24)

- Read a client back correctly when the OA4MP server answers in ISO-8859-1 and
  the client carries an accented character, rather than reporting it as
  modified outside the Registry. The plugin's handling for that encoding was
  present but discarded by an unconditional re-decode below it.

- Say that the Registry could not verify a client, rather than that the client
  was modified outside the Registry, when the synchronization check itself
  could not run -- so a deployment fault no longer presents as client
  tampering on every claims tab, callback list and scope page.
- Record which client and which page a failed verification check was for, so a
  fault affecting every client is distinguishable from one affecting a single
  client.

- Send an OA4MP client configuration built only from the values the plugin
  declares it may send, so a value the tier's claim script cannot act on is
  no longer sent to be silently ignored.
- Record in the Registry log, each time a client configuration is built, how
  many values were withheld and which fields they sat under -- field names
  only, never the values -- so a withheld value is visible rather than silent.
- Stamp every client configuration the plugin sends with the version of that
  declaration it was built from, so a stored configuration can be read back
  against the vocabulary that produced it.
- Stop writing the LDAP bind password into the Registry log when reading back
  a client whose configuration is still in the QDLv2 or deprecated format.

## 7.0.0-rc6 (2026-08-23)

- Report a failed claim save or delete instead of reporting success when
  the OA4MP server has already accepted the change.
- Say what repairs a client left out of sync by such a failure, rather
  than advising a retry, which the synchronization check blocks.
- Compare a client against the configuration the plugin actually sends,
  so a client carrying a half-populated claim constraint, or a value
  field of `0`, no longer reports as permanently out of sync with no
  edit able to repair it.
- Stop writing the OIDC client secret and the DynamoDB credentials into
  the Registry log when creating, editing, verifying, or deleting a
  client.
- Stop returning the server-generated registration client URI to the
  OA4MP server when editing a client.
- Stop emitting PHP warnings when comparing a client that has no refresh
  token or access token configuration.

## 7.0.0-rc5 (2026-08-10)

- Do not send a custom configuration to the OA4MP server for a public
  client, which the server rejects.
- Hide the claim add, edit, and delete actions for a public client and
  explain on the claims view why claims are unavailable.
- Fix view titles double-encoding the OIDC client name, which displayed
  an apostrophe in a client name as the literal `&#39;`.
- Include the client ID in the claim name link.

## 7.0.0-rc4 (2026-07-02)

- Use the admin client default DynamoDB configuration when a client has
  no per-client configuration of its own, both when sending the client
  to the OA4MP server and when verifying the two representations are
  synchronized.
- Fix editing an admin client inserting a duplicate default DynamoDB
  configuration row on every save instead of updating the existing row.

## 7.0.0-rc3 (2026-05-26)

- Produce claims when reading a legacy cfg (QDLv2 and the deprecated
  format 1) back from the OA4MP server, so clients using those formats
  no longer report as out of sync on every edit.
- Fix duplicate claim rows accumulating on each edit of a client with a
  deprecated cfg.
- Recognize a QDLv2 cfg that omits the optional `list_attributes` key
  instead of reporting it as an undefined cfg format.
- Map the LDAP provisioner "All Types" choice, stored as an empty
  string, to the literal `all` the OA4MP server expects, and apply the
  same mapping when comparing against the server.
- Save a claim and the back-pointer from its search attribute together,
  so a later failure cannot leave the claim stranded and unreferenced.
- Recover from a partial claim migration by rewiring a search attribute
  to a matching orphan claim rather than creating a duplicate.
- Restrict the voPersonApplicationUID claim constraint to the identifier
  types the CO actually offers, and omit the claim when that set is
  empty.
- Do not send a claim constraint that is missing either its field or its
  value.
- Fix the wrong LDAP provisioner attribute type being used for a claim
  constraint when no attribute matched.
- Report per-side detail in the log when synchronization verification
  finds a difference.
- Remove duplicate entries from the OIDC client index view.

## 7.0.0-rc2 (2026-02-02)

- Add support for the deprecated OOB flow.
- Better label the admin client delegate view.
- Better ordering of tables in schema.

## 7.0.0-rc1 (2026-01-13)

- Use DynamoDB and Registry model references for claim resolution.
- Use tab UI.
- Add access control, access token, authorization management.

## 6.1.1 (2026-05-05)

- Better ordering of tables in schema.

## 6.1.0 (2025-04-09)

- Display the OIDC client ID, issuer, and OIDC well-known configuration
  URL.
- Improve the view of the OIDC client ID and secret.
- Default the LDAP search attribute to voPersonExternalID instead of uid
  when adding or editing a client.
- Append a link to the client index view to the comment sent to the
  OA4MP server, and accept any comment that begins with the plugin
  signature rather than requiring an exact match.
- Record a link to the Named Configuration in the cfg metadata sent to
  the OA4MP server.
- Warn when editing a Named Configuration that no client using it is
  updated automatically, and that each such client must be edited and
  re-saved.
- Increase the number of custom scopes for a Named Configuration from
  10 to 50.
- Better labels and wording for Named Configuration scopes.
- Hide the edu.uiuc.ncsa.myproxy.getcert scope except when editing a
  client that already has it.

## 6.0.0 (2023-06-21)

- Enable multiple admin clients per CO.

## 5.4.0 (2023-01-19)

- Accept from OA4MP server both a 200 and a 201 when creating a
  new OIDC client.

## 5.3.0 (2022-11-10)

- Adopt version 2.0.0 of COmanage Registry OA4MP plugin cfg syntax
  that uses a single QDL file for LDAP claims instead of two
  QDL files.
- Add definition of cfg format versions.

## 5.2.0 (2022-08-23)

- Increase the number of callback URLs to 50.

## 5.1.0 (2022-08-03)

- Use different execution phases in cfg.

## 5.0.0 (2022-05-20)

- Add Named Configurations for managing custom cfg and QDL.

## 4.0.0 (2022-05-10)

- Use QDL for configuring claims from LDAP.
- Support requesting a public client.
- Enable configuration of LDAP search filter attribute.
- Include email address in client configuration.

## 3.1.0 (2021-10-14)

- Stylistic changes necessary for use with COmanage Registry version 4.0.0.

## 3.0.1 (2021-05-24)

- Update validation of the field used to track the CoGroup to which
client management privileges are delegated to support COmanage Registry
release 3.3.3.

## 3.0.0 (2020-12-11)

- Enable management of refresh tokens.
- Enable management of the edu.uiuc.ncsa.myproxy.getcert scope.
- Display an informational notice when a LDAP claim mapping
  will override a standard OIDC or CILogon claim.
- Compare scope requests and LDAP claim mappings and display a
  dialogue if reconciliation needed.
- Do not allow the asterisk wildcard character in callback URLs.
- Detect if the comment returned by the server differs from
  that the plugin uses (no user visibility).

## 2.0.0 (2020-07-08)

- Switch to using RFC 7591 and RFC 7592 compliant OA4MP API.
- Add the capability for the platform administrator to configure as part
of the admin client a delegated group of people that will be allowed to
manage OIDC clients.
- Enable private-use URI schemes for callback URLs.
- Fix highlighting of invalid callback URLs.
- Verify plugin and server representations of client are
  synchronized before edit view renders.
- Prevent browser asking to save LDAP bind password.

## 1.1.0 (2020-04-30)

- Better logging of requests and responses to and from OA4MP
  server.

## 1.0.2 (2019-02-18)

- Fixed issue where an OIDC client that had been edited outside of
the plugin with a change in scope was not detected.

## 1.0.1 (2019-02-08)

- Fix issue where editing an existing client that did not have
LDAP to Claim Mappings led to the incorrect values for LDAP connections
being set for the client after adding LDAP to Claim Mappings.

## 1.0.0 (2018-10-04)
