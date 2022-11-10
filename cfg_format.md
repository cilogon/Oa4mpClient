# CILogon COmanage Registry OA4MP Plugin cfg Format

This is the format for the cfg entry written to the OA4MP server.

## Version 2.0.0

### Syntax

```
{
  "tokens": {
    "identity": {
      "qdl": [
        {
          "args": {
            "bind_dn": "<BIND DN>"
            "bind_password": "<BIND PASSWORD>"
            "list_attributes": [],
            "return_attributes": [
              "<ATTRIBUTE 1>",
              "<ATTRIBUTE 2>"
            ],
            "search_attribute": "<SEARCH ATTRIBUTE>",
            "search_base": "<SEARCH BASE>"
            "server_fqdn": "<SERVER FQDN>"
            "server_port": <SERVER PORT>
            "ldap_to_claim_mappings": {
                "<LDAP ATTRIBUTE 1 NAME>": "<CLAIM 1 NAME>",
                "<LDAP ATTRIBUTE 2 NAME>": "<CLAIM 2 NAME>"
            }
          },
          "load": "COmanageRegistry/default/ldap_claims.qdl",
          "xmd": {
            "exec_phase": [
              "post_auth",
              "post_refresh",
              "post_token",
              "post_user_info"
            ]
          }
        }
      ],
      "type": "identity"
    }
  }
}
```

### Example

```
{
  "tokens": {
    "identity": {
      "qdl": [
        {
          "args": {
            "bind_dn": "uid=oa4mp_user,ou=system,o=MESSIER,o=CO,dc=cilogon,dc=org",
            "bind_password": "XXXXXXXX",
            "list_attributes": [],
            "return_attributes": [
              "isMemberOf",
              "voPersonID",
              "cn"
            ],
            "search_attribute": "uid",
            "search_base": "ou=people,o=MESSIER,o=CO,dc=cilogon,dc=org",
            "server_fqdn": "ldap-dev.cilogon.org",
            "server_port": 636,
            "ldap_to_claim_mappings": {
              "isMemberOf": "is_member_of",
              "voPersonID": "voPersonID",
              "cn": "display_name"
            }
          },
          "load": "COmanageRegistry/default/ldap_claims.qdl",
          "xmd": {
            "exec_phase": [
              "post_auth",
              "post_refresh",
              "post_token",
              "post_user_info"
            ]
          }
        }
      ],
      "type": "identity"
    }
  }
}
```

## Version 1.0.0

### Syntax

```
{
  "tokens": {
    "identity": {
      "qdl": [
        {
          "args": {
            "bind_dn": "<BIND DN>"
            "bind_password": "<BIND PASSWORD>"
            "list_attributes": [
              "<ATTRIBUTE 1>",
              "<ATTRIBUTE 2>"
            ],
            "return_attributes": [
              "<ATTRIBUTE 1>",
              "<ATTRIBUTE 2>"
            ],
            "search_attribute": "<SEARCH ATTRIBUTE>",
            "search_base": "<SEARCH BASE>"
            "server_fqdn": "<SERVER FQDN>"
            "server_port": <SERVER PORT>
          },
          "load": "<PATH TO CLAIM SOURCE QDL FILE>"
          "xmd": {
            "exec_phase": [
              "post_auth",
              "post_refresh",
              "post_token",
              "post_user_info"
            ]
          }
        },
        {
          "args": {
            "<LDAP ATTRIBUTE 1 NAME>": "<CLAIM 1 NAME>",
            "<LDAP ATTRIBUTE 2 NAME>": "<CLAIM 2 NAME>"
          },
          "load": "<PATH TO CLAIM PROCESSING QDL FILE>"
          "xmd": {
            "exec_phase": [
              "post_auth",
              "post_refresh",
              "post_token",
              "post_user_info"
            ]
          }
        }
      ],
      "type": "identity"
    }
  }
}
```

### Example

```
{
  "tokens": {
    "identity": {
      "qdl": [
        {
          "args": {
            "bind_dn": "uid=registry_user,ou=system,o=LDaCA,o=CO,dc=ldaca,dc=edu,dc=au",
            "bind_password": "XXXXXXXX",
            "list_attributes": [
              "isMemberOf"
            ],
            "return_attributes": [
              "isMemberOf",
              "voPersonID"
            ],
            "search_attribute": "uid",
            "search_base": "ou=people,o=LDaCA,o=CO,dc=ldaca,dc=edu,dc=au",
            "server_fqdn": "ldap-test.cilogon.org",
            "server_port": 636
          },
          "load": "COmanageRegistry/default/identity_token_ldap_claim_source.qdl",
          "xmd": {
            "exec_phase": [
              "post_auth",
              "post_refresh",
              "post_token",
              "post_user_info"
            ]
          }
        },
        {
          "args": {
            "isMemberOf": "is_member_of",
            "voPersonID": "voPersonID"
          },
          "load": "COmanageRegistry/default/identity_token_ldap_claim_process.qdl",
          "xmd": {
            "exec_phase": [
              "post_auth",
              "post_refresh",
              "post_token",
              "post_user_info"
            ]
          }
        }
      ],
      "type": "identity"
    }
  }
}
```
