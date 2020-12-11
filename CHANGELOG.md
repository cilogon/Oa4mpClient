# Changelog

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
