# Changelog

## 7.0.0 (2026-01-13)

- Use DynamoDB and Registry model references for claim resolution.
- Use tab UI.
- Add access control, access token, authorization management.

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
