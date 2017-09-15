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
  'pl.oa4mp_client_co_admin_client.serverurl.fd.name' => 'Server URL',
  'pl.oa4mp_client_co_admin_client.secret.fd.name' => 'Secret',
  'pl.oa4mp_client_co_admin_client.co_id.fd.all_taken' => 'No COs without existing admin client',

  'pl.oa4mp_client_co_admin_client.er.client_exists' => 'A CO may only have one Oa4mp Admin Client',
  'pl.oa4mp_client_co_admin_client.er.create_error' => 'Unable to create new OIDC client',
  'pl.oa4mp_client_co_admin_client.er.delete_error' => 'Unable to delete OIDC client',
  'pl.oa4mp_client_co_admin_client.er.edit_error' => 'Unable to edit OIDC client',

  'pl.oa4mp_client_co_oidc_client.name.fd.name' => 'Name',
  'pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name' => 'Client ID',
  'pl.oa4mp_client_co_oidc_client.secret.fd.name' => 'Client Secret',
  'pl.oa4mp_client_co_oidc_client.home_url.fd.name' => 'Home URL',

  'pl.oa4mp_client_co_oidc_client.secret.title' => 'New OIDC Client',
  'pl.oa4mp_client_co_oidc_client.secret.text' => 'You MUST permanently record the client secret before continuing. The CILogon servers do not store the client secret.',

  'pl.oa4mp_client_co_callback.url.fd.name' => 'URL',

  'pl.oa4mp_client_co_scope.scope.fd.name' => 'Scope',

  'pl.oa4mp_client_co_ldap_config.serverurl.fd.name' => 'LDAP Server URL',
  'pl.oa4mp_client_co_ldap_config.binddn.fd.name' => 'LDAP Bind DN',
  'pl.oa4mp_client_co_ldap_config.password.fd.name' => 'LDAP Bind Password',
  'pl.oa4mp_client_co_ldap_config.basedn.fd.name' => 'LDAP Search Base DN',

  'pl.oa4mp_client_co_search_attribute.name.fd.name' => 'LDAP Attribute Name',
  'pl.oa4mp_client_co_search_attribute.return_name.fd.name' => 'OIDC Claim Name',
  'pl.oa4mp_client_co_search_attribute.return_as_list.fd.name' => 'Multivalued?',

  // Enumerations
  'pl.oa4mp_client.en.scope' => array(
    Oa4mpClientScopeEnum::OpenId             => 'openid',
    Oa4mpClientScopeEnum::Profile            => 'profile',
    Oa4mpClientScopeEnum::Email              => 'email',
    Oa4mpClientScopeEnum::OrgCilogonUserInfo => 'org.cilogon.userinfo'
  ),

);
