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

  // Menu
  'pl.oa4mp_client.menu.cmp' => 'Oa4mp Admin Clients',
  'pl.oa4mp_client.menu.coconfig' => 'OIDC Clients',

  // Plugin texts
  'pl.oa4mp_client_co_admin_client.admin_identifier.fd.name' => 'Admin ID',
  'pl.oa4mp_client_co_admin_client.admin_identifier.fd.description' => 'ID of the admin client for the CO',
  'pl.oa4mp_client_co_admin_client.mail.fd.name' => 'Contact Email Address',
  'pl.oa4mp_client_co_admin_client.mail.fd.description' => 'This email address is used for operational notices regarding clients.',
  'pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.name' => 'Delegated Management Group',
  'pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.description' => 'If set, members of this group may create and manage OIDC clients',
  'pl.oa4mp_client_co_admin_client.serverurl.fd.name' => 'Server URL',
  'pl.oa4mp_client_co_admin_client.serverurl.fd.description' => 'OA4MP server URL (https://cilogon.org/oauth2/clients)',
  'pl.oa4mp_client_co_admin_client.secret.fd.name' => 'Secret',
  'pl.oa4mp_client_co_admin_client.secret.fd.description' => 'Secret for the admin client for the CO',
  'pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.name' => 'Claim Source QDL Path',
  'pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.description' => 'Path to QDL file for setting claim source',
  'pl.oa4mp_client_co_admin_client.qdl_claim_process.fd.name' => 'Claim Processing QDL Path',
  'pl.oa4mp_client_co_admin_client.qdl_claim_process.fd.description' => 'Path to QDL file for further processing claims',
  'pl.oa4mp_client_co_admin_client.co_id.fd.all_taken' => 'No COs without existing admin client',
  'pl.oa4mp_client_co_admin_client.ldap.server.fd.description' => 'Default LDAP server URL to use with OIDC clients for this CO',
  'pl.oa4mp_client_co_admin_client.ldap.binddn.fd.description' => 'Default bind DN to use with OIDC clients for this CO',
  'pl.oa4mp_client_co_admin_client.ldap.bindpassword.fd.description' => 'Default bind password to use with OIDC clients for this CO',
  'pl.oa4mp_client_co_admin_client.ldap.searchbase.fd.description' => 'Default search base for person records (ou=people,...)',
  'pl.oa4mp_client_co_admin_client.save.dialog.title' => 'Edits to Admin Client',
  'pl.oa4mp_client_co_admin_client.save.dialog.text' => 'Any changes to the QDL paths or default LDAP configuration do not propagate to existing OIDC clients!',
  'pl.oa4mp_client_co_admin_client.save.dialog.understand' => 'I understand',

  'pl.oa4mp_client_co_admin_client.er.client_exists' => 'A CO may only have one Oa4mp Admin Client',
  'pl.oa4mp_client_co_admin_client.er.create_error' => 'Unable to create new OIDC client',
  'pl.oa4mp_client_co_admin_client.er.delete_error' => 'Unable to delete OIDC client',
  'pl.oa4mp_client_co_admin_client.er.edit_error' => 'Unable to edit OIDC client',

  'pl.oa4mp_client_co_oidc_client.name.fd.name' => 'Name',
  'pl.oa4mp_client_co_oidc_client.name.fd.description' => 'The client Name is displayed to end-users on the Identity Provider selection page',
  'pl.oa4mp_client_co_oidc_client.mail.fd.name' => 'Contact Email Address',
  'pl.oa4mp_client_co_oidc_client.mail.fd.description' => 'This email address is used for operational notices regarding clients.',
  'pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name' => 'Client ID',
  'pl.oa4mp_client_co_oidc_client.secret.fd.name' => 'Client Secret',
  'pl.oa4mp_client_co_oidc_client.home_url.fd.name' => 'Home URL',
  'pl.oa4mp_client_co_oidc_client.home_url.fd.description' => 'The Home URL is used as the hyperlink for the client Name',
  'pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.name' => 'Refresh Tokens',
  'pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.enable_button' => 'Enable',
  'pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.disable_button' => 'Disable',
  'pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.name' => 'Lifetime',
  'pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.description' => 'Refresh token lifetime in seconds',
  'pl.oa4mp_client_co_oidc_client.callbacks.fd.name' => 'Callbacks',
  'pl.oa4mp_client_co_oidc_client.callbacks.fd.description' => 'The redirect_uri parameter must exactly match a callback URL',
  'pl.oa4mp_client_co_oidc_client.callbacks.fd.add_button' => 'Add another Callback URL',
  'pl.oa4mp_client_co_oidc_client.public_client.fd.name' => 'Public Client',
  'pl.oa4mp_client_co_oidc_client.public_client.fd.description.add' => 'Public clients have no client secret and only the openid scope is allowed. <a href="https://oauth.net/2/client-types/">See OAuth 2.0 Client Types</a>',
  'pl.oa4mp_client_co_oidc_client.public_client.fd.description.edit' => 'The client type cannot be changed after the client is created',

  'pl.oa4mp_client_co_oidc_client.public.title' => 'New OIDC Client',
  'pl.oa4mp_client_co_oidc_client.public.text' => 'This is a public OIDC client and therefore it has no client secret.',
  'pl.oa4mp_client_co_oidc_client.public.understand' => 'I understand',

  'pl.oa4mp_client_co_oidc_client.secret.title' => 'New OIDC Client',
  'pl.oa4mp_client_co_oidc_client.secret.text' => 'You MUST permanently record the client secret before continuing. The CILogon servers do not store the client secret.',
  'pl.oa4mp_client_co_oidc_client.secret.understand' => 'I understand',

  'pl.oa4mp_client_co_callback.url.fd.name' => 'URL',

  'pl.oa4mp_client_co_scope.scope.fd.name' => 'Scopes',
  'pl.oa4mp_client_co_scope.scope.fd.description' => '<a href="https://www.cilogon.org/oidc">Information on scopes</a>',
  'pl.oa4mp_client_co_scope.scope.fd.description.public' => 'Public clients may only use the openid scope',
  'pl.oa4mp_client_co_scope.scope.openid.fd.name' => 'openid',

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
);
