<?php
/**
 * COmanage Registry Oa4mp Client Plugin Language File
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

global $cm_lang, $cm_texts;

// When localizing, the number in format specifications (eg: %1$s) indicates the argument
// position as passed to _txt.  This can be used to process the arguments in
// a different order than they were passed.

$cm_oa4mp_client_texts['en_US'] = array(
  // Title, per-controller
  'ct.oa4mp_client_co_admin_clients.1' => 'Oa4mp Admin Client',
  'ct.oa4mp_client_co_admin_clients.pl' => 'Oa4mp Admin Clients',
  'ct.oa4mp_client_co_oidc_clients.1' => 'OIDC Client',
  'ct.oa4mp_client_co_oidc_clients.pl' => 'OIDC Clients',
  'ct.oa4mp_client_co_named_configs.1' => 'Oa4mp Named Configuration',
  'ct.oa4mp_client_co_named_configs.pl' => 'Oa4mp Named Configurations',
  'ct.oa4mp_client_co_callbacks.1' => 'Callback',
  'ct.oa4mp_client_co_callbacks.pl' => 'Callbacks',
  'ct.oa4mp_client_co_claims.1' => 'Claim',
  'ct.oa4mp_client_co_claims.pl' => 'Claims',

  // Menu
  'pl.oa4mp_client.menu.admin_clients.cmp' => 'Oa4mp Admin Clients',
  'pl.oa4mp_client.menu.named_configs.cmp' => 'Oa4mp Named Configurations',
  'pl.oa4mp_client.menu.coconfig' => 'OIDC Clients',

  // Plugin texts
  'pl.oa4mp_client_co_admin_client.admin_identifier.fd.name' => 'Admin ID',
  'pl.oa4mp_client_co_admin_client.admin_identifier.fd.description' => 'ID of the admin client for the CO',
  'pl.oa4mp_client_co_admin_client.dynamo.region.fd.name' => 'DynamoDB Table Region',
  'pl.oa4mp_client_co_admin_client.dynamo.region.fd.description' => 'AWS region where DynamoDB table is hosted',
  'pl.oa4mp_client_co_admin_client.dynamo.table_name.fd.name' => 'DynamoDB Table Name',
  'pl.oa4mp_client_co_admin_client.dynamo.table_name.fd.description' => 'Name of the DynamoDB table used to resolve claims',
  'pl.oa4mp_client_co_admin_client.dynamo.aws_access_key_id.fd.name' => 'AWS Access Key ID',
  'pl.oa4mp_client_co_admin_client.dynamo.aws_access_key_id.fd.description' => 'AWS access key ID for read access to DynamoDB table',
  'pl.oa4mp_client_co_admin_client.dynamo.aws_secret_access_key.fd.name' => 'AWS Secret Access Key',
  'pl.oa4mp_client_co_admin_client.dynamo.aws_secret_access_key.fd.description' => 'Corresponding secret for AWS access key',
  'pl.oa4mp_client_co_admin_client.dynamo.partition_key.fd.name' => 'Partition Key Attribute Name',
  'pl.oa4mp_client_co_admin_client.dynamo.partition_key.fd.description' => 'Default name of the partition key attribute in the DynamoDB table',
  'pl.oa4mp_client_co_admin_client.dynamo.partition_key_template.fd.name' => 'Partition Key Value Template',
  'pl.oa4mp_client_co_admin_client.dynamo.partition_key_template.fd.description' => 'Default template for constructing partition key using claims',
  'pl.oa4mp_client_co_admin_client.dynamo.partition_key_claim_name.fd.name' => 'Partition Key Claim',
  'pl.oa4mp_client_co_admin_client.dynamo.partition_key_claim_name.fd.description' => 'Default claim to use with template for constructing partition key',
  'pl.oa4mp_client_co_admin_client.dynamo.sort_key.fd.name' => 'Sort Key Attribute Name',
  'pl.oa4mp_client_co_admin_client.dynamo.sort_key.fd.description' => 'Default name of the sort key attribute in the DynamoDB table',
  'pl.oa4mp_client_co_admin_client.dynamo.sort_key_template.fd.name' => 'Sort Key Value Template',
  'pl.oa4mp_client_co_admin_client.dynamo.sort_key_template.fd.description' => 'Default template for constructing sort key using claims',
  'pl.oa4mp_client_co_admin_client.mail.fd.name' => 'Contact Email Address',
  'pl.oa4mp_client_co_admin_client.mail.fd.description' => 'This email address is used for operational notices regarding clients.',
  'pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.name' => 'Delegated Management Group',
  'pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.description' => 'If set, members of this group may create and manage OIDC clients',
  'pl.oa4mp_client_co_admin_client.serverurl.fd.name' => 'Server URL',
  'pl.oa4mp_client_co_admin_client.serverurl.fd.description' => 'OA4MP server URL (e.g. https://cilogon.org/oauth2/oidc-cm)',
  'pl.oa4mp_client_co_admin_client.name.fd.name' => 'Display Name',
  'pl.oa4mp_client_co_admin_client.name.fd.description' => 'Admin client display name',
  'pl.oa4mp_client_co_admin_client.issuer.fd.name' => 'Issuer',
  'pl.oa4mp_client_co_admin_client.issuer.fd.description' => 'OAuth2 Issuer (e.g. https://cilogon.org)',
  'pl.oa4mp_client_co_admin_client.secret.fd.name' => 'Secret',
  'pl.oa4mp_client_co_admin_client.secret.fd.description' => 'Secret for the admin client for the CO',
  'pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.name' => 'Claims QDL Path',
  'pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.description' => 'Path to QDL file for resolving claims',
  'pl.oa4mp_client_co_admin_client.co_id.fd.all_taken' => 'No COs without existing admin client',
  'pl.oa4mp_client_co_admin_client.ldap.server.fd.description' => 'Default LDAP server URL to use with OIDC clients for this CO',
  'pl.oa4mp_client_co_admin_client.ldap.binddn.fd.description' => 'Default bind DN to use with OIDC clients for this CO',
  'pl.oa4mp_client_co_admin_client.ldap.bindpassword.fd.description' => 'Default bind password to use with OIDC clients for this CO',
  'pl.oa4mp_client_co_admin_client.ldap.searchbase.fd.description' => 'Default search base for person records (ou=people,...)',
  'pl.oa4mp_client_co_admin_client.save.dialog.title' => 'Edits to Admin Client',
  'pl.oa4mp_client_co_admin_client.save.dialog.text' => 'Any changes to the QDL paths or default LDAP configuration do not propagate to existing OIDC clients!',
  'pl.oa4mp_client_co_admin_client.save.dialog.understand' => 'I understand',

  'pl.oa4mp_client_co_admin_client.er.client_exists' => 'A CO may only have one Oa4mp Admin Client',
  'pl.oa4mp_client_co_admin_client.er.create_error' => 'OAuth2 server is unwilling to create new OIDC client. Please check your settings.',
  'pl.oa4mp_client_co_admin_client.er.delete_error' => 'Unable to delete OIDC client',
  'pl.oa4mp_client_co_admin_client.er.edit_error' => 'Unable to edit OIDC client',

  'pl.oa4mp_client_co_oidc_client.admin_id.fd.name' => 'OAuth2 Server and Issuer',
  'pl.oa4mp_client_co_oidc_client.admin_id.fd.name.select' => 'Select OAuth2 Server and Issuer for New Client',
  'pl.oa4mp_client_co_oidc_client.admin_id.fd.issuer' => 'Issuer',
  'pl.oa4mp_client_co_oidc_client.admin_id.fd.warn' => 'The OAuth2 Server and Issuer cannot be changed after the client is created.',
  'pl.oa4mp_client_co_oidc_client.name.fd.name' => 'Name',
  'pl.oa4mp_client_co_oidc_client.name.fd.description' => 'The client Name is displayed to end-users on the Identity Provider selection page',
  'pl.oa4mp_client_co_oidc_client.mail.fd.name' => 'Contact Email Address',
  'pl.oa4mp_client_co_oidc_client.mail.fd.description' => 'This email address is used for operational notices regarding clients.',
  'pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name' => 'Client ID',
  'pl.oa4mp_client_co_oidc_client.secret.fd.name' => 'Client Secret',
  'pl.oa4mp_client_co_oidc_client.home_url.fd.name' => 'Home URL',
  'pl.oa4mp_client_co_oidc_client.home_url.fd.description' => 'Used as the hyperlink for the client Name on the Identity Provider selection page',
  'pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.name' => 'Refresh Token Lifetime',
  'pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.description' => 'Token lifetime in seconds',

  'pl.oa4mp_client_co_oidc_client.callbacks.tab.name' => 'Callbacks',
  'pl.oa4mp_client_co_oidc_client.callbacks.add.name' => 'Add Callback for %1$s',
  'pl.oa4mp_client_co_oidc_client.callbacks.edit.name' => 'Edit Callback for %1$s',
  'pl.oa4mp_client_co_oidc_client.callbacks.fd.name' => 'URL',
  'pl.oa4mp_client_co_oidc_client.callbacks.fd.description' => 'The OIDC protocol redirect_uri parameter must exactly match the callback URL',
  'pl.oa4mp_client_co_oidc_client.callbacks.fd.add_button' => 'Add another Callback URL',

  'pl.oa4mp_client_co_oidc_client.claims.tab.name' => 'Claims',
  'pl.oa4mp_client_co_oidc_client.claims.add.name' => 'Add Claim for %1$s',
  'pl.oa4mp_client_co_oidc_client.claims.edit.name' => 'Edit Claim for %1$s',
  'pl.oa4mp_client_co_oidc_client.claims.fd.claim_name.name' => 'Name',
  'pl.oa4mp_client_co_oidc_client.claims.fd.claim_name.description' => 'The name of the claim as it will be asserted in the token',
  'pl.oa4mp_client_co_oidc_client.claims.fd.source_model.name' => 'Source',
  'pl.oa4mp_client_co_oidc_client.claims.fd.source_model.description' => 'The Registry object from the user record that is the source of the claim value',
  'pl.oa4mp_client_co_oidc_client.claims.fd.value_req.name' => 'Selector',
  'pl.oa4mp_client_co_oidc_client.claims.fd.value_req.description' => 'Use the first value found or all values of the configured type',
  'pl.oa4mp_client_co_oidc_client.claims.fd.value_format.name' => 'Format',
  'pl.oa4mp_client_co_oidc_client.claims.fd.value_format.description' => 'How to format the claim value(s) in the token',
  'pl.oa4mp_client_co_oidc_client.claims.fd.value_string_d.name' => 'String Delineator Character',
  'pl.oa4mp_client_co_oidc_client.claims.fd.value_string_d.description' => 'Which character(s) to use to delineate token values when formatted as a JSON string',

  'pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.name' => 'Type',
  'pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.description' => 'The Identifier type from the user record that is the source of the claim value',

  'pl.oa4mp_client_co_oidc_client.claims.fd.add_button' => 'Add another claim',
  'pl.oa4mp_client_claim.add.flash.success' => 'Claim Added',

  'pl.oa4mp_client_co_oidc_client.issuer.fd.name' => 'Issuer',
  'pl.oa4mp_client_co_oidc_client.issuer.fd.description' => 'Value asserted by the authorization server in the iss parameter',
  'pl.oa4mp_client_co_oidc_client.public_client.fd.name' => 'Public Client',
  'pl.oa4mp_client_co_oidc_client.public_client.fd.description.add' => 'Public clients have no client secret and only the openid scope is allowed. <a href="https://oauth.net/2/client-types/">See OAuth 2.0 Client Types</a>',
  'pl.oa4mp_client_co_oidc_client.public_client.fd.description.edit' => 'The client type cannot be changed after the client is created',
  'pl.oa4mp_client_co_oidc_client.named_config.fd.name' => 'Use a Named Configuration',
  'pl.oa4mp_client_co_oidc_client.named_config.fd.description' => 'Configure scopes, claims, and other details using an existing template. Check the box to see available templates.',
  'pl.oa4mp_client_co_oidc_client.wellknown.fd.name' => 'Well-known OpenID Configuration',
  'pl.oa4mp_client_co_oidc_client.wellknown.fd.description' => 'URL for the well-known openid-configuration discovery document',
  'pl.oa4mp_client_co_oidc_client.dynamo.partition_key_claim_name.fd.name' => 'Authenticated User Search Claim',
  'pl.oa4mp_client_co_oidc_client.dynamo.partition_key_claim_name.fd.description' => 'Claim holding the authenticated user identifier',

  'pl.oa4mp_client_co_oidc_client.public.title' => 'New OIDC Client',
  'pl.oa4mp_client_co_oidc_client.public.text' => 'This is a public OIDC client and therefore it has no client secret.',
  'pl.oa4mp_client_co_oidc_client.public.understand' => 'I understand',

  'pl.oa4mp_client_co_oidc_client.secret.title' => 'New OIDC Client',
  'pl.oa4mp_client_co_oidc_client.secret.text' => 'You MUST permanently record the client secret before continuing. The CILogon servers do not store the client secret.',
  'pl.oa4mp_client_co_oidc_client.secret.understand' => 'I understand',

  'pl.oa4mp_client_co_callback.url.fd.name' => 'URL',
  'pl.oa4mp_client_co_callback.callback.add.flash.success' => 'Callback Added',
  'pl.oa4mp_client_co_callback.callback.edit.flash.success' => 'Callback Updated',
  'pl.oa4mp_client_co_callback.callback.delete.flash.success' => 'Callback Deleted',

  'pl.oa4mp_client_co_scope.scope.tab.name' => 'Scopes',
  'pl.oa4mp_client_co_scope.scope.edit.name' => 'Edit Scopes for %1$s',
  'pl.oa4mp_client_co_scope.scope.fd.name' => 'Scopes',
  'pl.oa4mp_client_co_scope.scope.fd.description' => '<a href="https://www.cilogon.org/oidc">Information on scopes</a>',
  'pl.oa4mp_client_co_scope.scope.fd.description.public' => 'Public clients may only use the openid scope',
  'pl.oa4mp_client_co_scope.scope.openid.fd.name' => 'openid',
  'pl.oa4mp_client_co_scope.scope.openid.required' => 'openid scope is required',
  'pl.oa4mp_client_co_scope.scope.flash.success' => 'Scopes Saved',

  'pl.oa4mp_client_co_scope.scope.profile.fd.name' => 'profile',
  'pl.oa4mp_client_co_scope.scope.profile.dialog.title' => 'Confirm profile scope',
  'pl.oa4mp_client_co_scope.scope.profile.dialog.text' => 'The claim you entered is only released with the profile scope but you have not requested the profile scope. Do you want to check the box for the profile scope?',
  'pl.oa4mp_client_co_scope.scope.profile.dialog.button.yes' => 'Yes',
  'pl.oa4mp_client_co_scope.scope.profile.dialog.button.no' => 'No',

  'pl.oa4mp_client_co_scope.scope.email.fd.name' => 'email',
  'pl.oa4mp_client_co_scope.scope.email.dialog.title' => 'Confirm email scope',
  'pl.oa4mp_client_co_scope.scope.email.dialog.text' => 'The claim you entered is only released with the email scope but you have not requested the email scope. Do you want to check the box for the email scope?',
  'pl.oa4mp_client_co_scope.scope.email.dialog.button.yes' => 'Yes',
  'pl.oa4mp_client_co_scope.scope.email.dialog.button.no' => 'No',

  'pl.oa4mp_client_co_scope.scope.org.cilogon.userinfo.fd.name' => 'org.cilogon.userinfo',
  'pl.oa4mp_client_co_scope.scope.userinfo.dialog.title' => 'Confirm org.cilogon.userinfo scope',
  'pl.oa4mp_client_co_scope.scope.userinfo.dialog.text' => 'The claim you entered is only released with the org.cilogon.userinfo scope but you have not requested the org.cilogon.userinfo scope. Do you want to check the box for the org.cilogon.userinfo scope?',
  'pl.oa4mp_client_co_scope.scope.userinfo.dialog.button.yes' => 'Yes',
  'pl.oa4mp_client_co_scope.scope.userinfo.dialog.button.no' => 'No',

  'pl.oa4mp_client_co_scope.scope.getcert.fd.name' => 'edu.uiuc.ncsa.myproxy.getcert',
  'pl.oa4mp_client_co_scope.scope.getcert.dialog.title' => 'Confirm edu.uiuc.ncsa.myproxy.getcert Scope',
  'pl.oa4mp_client_co_scope.scope.getcert.dialog.text' => 'The edu.uiuc.ncsa.myprox.getcert scope is only necessary if your client will request X.509 certificates on behalf of users. Are you sure your client needs the edu.uiuc.ncsa.myprox.getcert scope?',
  'pl.oa4mp_client_co_scope.scope.getcert.dialog.button.yes' => 'Yes',
  'pl.oa4mp_client_co_scope.scope.getcert.dialog.button.no' => 'No, cancel scope',

  'pl.oa4mp_client_co_scope.scope.override' => ' Be aware this name overrides a standard claim',

  'pl.oa4mp_client_co_ldap_config.explorer.heading.connection' => 'Connection Details',

  'pl.oa4mp_client_co_ldap_config.serverurl.fd.name' => 'LDAP Server URL',
  'pl.oa4mp_client_co_ldap_config.binddn.fd.name' => 'LDAP Bind DN',
  'pl.oa4mp_client_co_ldap_config.password.fd.name' => 'LDAP Bind Password',

  'pl.oa4mp_client_co_ldap_config.explorer.heading.search' => 'Search Details',

  'pl.oa4mp_client_co_ldap_config.basedn.fd.name' => 'LDAP Search Base DN',
  'pl.oa4mp_client_co_ldap_config.search_name.fd.name' => 'LDAP Search Attribute for Authenticated User Identifier',

  'pl.oa4mp_client_co_ldap_config.explorer.heading.mappings' => 'Mappings',

  'pl.oa4mp_client_co_search_attribute.fd.title' => 'LDAP to Claim Mappings',
  'pl.oa4mp_client_co_search_attribute.fd.add_first_button' => 'Add a LDAP to Claim Mapping',
  'pl.oa4mp_client_co_search_attribute.fd.add_another_button' => 'Add another LDAP to Claim Mapping',
  'pl.oa4mp_client_co_search_attribute.fd.description' => '<a href="https://www.cilogon.org/oidc">Information on LDAP to claim mappings</a>',
  'pl.oa4mp_client_co_search_attribute.name.fd.name' => 'LDAP Attribute Name',
  'pl.oa4mp_client_co_search_attribute.return_name.fd.name' => 'OIDC Claim Name',
  'pl.oa4mp_client_co_search_attribute.return_as_list.fd.name' => 'Multivalued?',

  'pl.oa4mp_client_co_named_config.admin.fd' => 'Admin Client',
  'pl.oa4mp_client_co_named_config.admin.description' => 'Each Named Configuration is bound to a single admin client/issuer.',
  'pl.oa4mp_client_co_named_config.config_name.fd' => 'Configuration Name',
  'pl.oa4mp_client_co_named_config.config_name.description' => 'This name will be shown to admininstrators when managing OIDC clients',
  'pl.oa4mp_client_co_named_config.description.fd' => 'Description',
  'pl.oa4mp_client_co_named_config.description.description' => 'This description will be shown to administrators when managing OIDC clients',
  'pl.oa4mp_client_co_named_config.config.fd' => 'Configuration',
  'pl.oa4mp_client_co_named_config.config.description' => 'This is the full Oa4mp server cfg JSON and will not be displayed to administrators',
  'pl.oa4mp_client_co_named_config.scope.description' => 'Scopes the client must request; these will be displayed to administrators but will not be editable',
  'pl.oa4mp_client_co_named_config.additional_scope.fd' => 'Additional Scopes',
  'pl.oa4mp_client_co_named_config.additional_scope.description' => 'Any additional scopes the client may request, e.g. scopes needed for GA4GH passports. These will be displayed to administrators but not editable. Templates with substitutions representing multiple classes of scopes are allowed, e.g. storage.${action}:/${students}/public/data/${sub}',
  'pl.oa4mp_client_co_named_config.additional_scope.scope.fd' => 'Scope',
  'pl.oa4mp_client_co_named_config.add_first_additional_scope' => 'Add scope',
  'pl.oa4mp_client_co_named_config.allowed_scopes' => 'allowed scope(s) include',

  'pl.oa4mp_client_co_named_config.not_selected.dialog.title' => 'No Named Configuration Selected',
  'pl.oa4mp_client_co_named_config.not_selected.dialog.text' => 'You have not selected a specific named configuration. Please select a specific named configuration or uncheck the box to indicate you do not want to use a named configuration.',
  'pl.oa4mp_client_co_named_config.no_auto_update.dialog.title' => 'No Clients Will Be Updated',
  'pl.oa4mp_client_co_named_config.no_auto_update.dialog.text' => 'Editing the Named Configuration does NOT cause the cfg for any client to be automatically updated. You must edit and re-save any client that uses the Named Configuration you just edited.',

  // Enumerations
  'pl.oa4mp_client.en.scope' => array(
    Oa4mpClientScopeEnum::OpenId             => 'openid',
    Oa4mpClientScopeEnum::Profile            => 'profile',
    Oa4mpClientScopeEnum::Email              => 'email',
    Oa4mpClientScopeEnum::OrgCilogonUserInfo => 'org.cilogon.userinfo',
    Oa4mpClientScopeEnum::Getcert            => 'edu.uiuc.ncsa.myproxy.getcert'
  ),

  // Text flag to signal OIDC client created by COmanage Registry Oa4mpClient Plugin.
  'pl.oa4mp_client_co_oidc_client.signature' => 'Created by COmanage Oa4mpClient Plugin',

  // Exceptions
  'pl.oa4mp_client_co_oidc_client.er.bad_signature' => 'Client object from Oa4mp server failed signature check',
  'pl.oa4mp_client_co_oidc_client.er.marshall' => 'Error marshalling OIDC client object for Oa4mp server',
  'pl.oa4mp_client_co_oidc_client.er.unmarshall' => 'Error unmarshalling OIDC client object from Oa4mp server',
  'pl.oa4mp_client_co_oidc_client.er.unmarshall.cfg' => 'Error unmarshalling cfg object from Oa4mp server',
  'pl.oa4mp_client_co_oidc_client.er.preprocessing' => 'Found bad preProcessing block from Oa4mp server',
  'pl.oa4mp_client_co_oidc_client.er.bad_client' => 'This client has been modified outside of the Registry. Please email help@cilogon.org for assistance.',
  'pl.oa4mp_client_co_oidc_client.er.wildcards' => 'Wildcards are not allowed in callback URLs',
  'pl.oa4mp_client_co_oidc_client.er.invalid_scheme' => 'Please use a valid scheme for callback URLs',
  'pl.oa4mp_client_co_oidc_client.er.valid_domain' => 'Private-use URI schemes require a valid domain',
  'pl.oa4mp_client_co_oidc_client.er.callback_default' => 'Please provide a valid callback URL',
  'pl.oa4mp_client_co_oidc_client.er.bad_admin_id' => 'The selected OAuth2 server is not available',
  'pl.oa4mp_client_co_oidc_client.er.id' => 'The OIDC client with ID %1%s cannot be found',
);
