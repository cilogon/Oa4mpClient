<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Model
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
 * @package       registry-plugin
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClientCoOidcClient extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoOidcClient";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp OIDC client is attached to an admin client
    "Oa4mpClient.Oa4mpClientCoAdminClient" => array(
      'foreignKey' => 'admin_id'
    ),
    // An Oa4mp OIDC client may be attached to a named
    // configuration.
    "Oa4mpClient.Oa4mpClientCoNamedConfig" => array(
      'foreignKey' => 'named_config_id'
    )
  );

  public $hasMany = array(
    "Oa4mpClient.Oa4mpClientCoCallback" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientClaim" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientCoLdapConfig" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientCoScope" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientCoEmailAddress" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    )
  );

  public $hasOne = array(
    "Oa4mpClient.Oa4mpClientDynamoConfig" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientRefreshToken" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientAccessToken" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientAuthorization" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    ),
    "Oa4mpClient.Oa4mpClientAccessControl" => array(
      'foreignKey' => 'client_id',
      'dependent' => true
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'admin_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'on' => 'update',
      'allowEmpty' => false,
    ),
    'oa4mp_identifier' => array( 
      'rule' => 'notBlank',
      'required' => true,
      'on' => 'update',
      'allowEmpty' => false,
    ),
    'name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'home_url' => array(
      'rule' => 'url',
      'required' => true,
      'allowEmpty' => false,
      'message' => 'Please supply a valid http:// or https:// URL'
    ),
    'proxy_limited' => array(
      'rule' => 'boolean',
      'required' => true,
      'allowEmpty' => false
    ),
    // refresh_token_lifetime is deprecated and moved to the Oa4mpClientRefreshToken model
    'refresh_token_lifetime' => array(
      'rule1' => array(
        'rule' => array('naturalNumber', true),
        'message' => 'Please supply a value greater than or equal to zero',
        'required' => false,
        'allowEmpty' => true
      ),
      'rule2' => array(
        'rule' => array('range', -1, 31536000),
        'message' => 'Please supply a value less than one year (31536000)'
      )
    ),
    'public_client' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    ),
    'named_config_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'on' => 'update',
      'allowEmpty' => true,
    ),
  );

  /**
   * Get the current canonical representation of the admin client
   * for the OIDC client.
   *
   * @since  COmanage Registry 4.4.2
   * @param  Integer $id ID for the Oa4mpClientCoOidcClient object
   * @return Array The canonical representation including all related models
   */

  public function admin($id) {
    $args = array();
    $args['conditions']['Oa4mpClientCoOidcClient.id'] = $id;
    $args['contain'] = false;

    $client = $this->find('first', $args);

    if(empty($client)) {
      return array();
    }

    $adminId = $client['Oa4mpClientCoOidcClient']['admin_id'];

    $args = array();
    $args['conditions']['Oa4mpClientCoAdminClient.id'] = $adminId;
    $args['contain'] = array(
      'Oa4mpClientCoNamedConfig' => array('Oa4mpClientCoScope'),
      'Oa4mpClientCoEmailAddress',
      'DefaultLdapConfig',
      'DefaultDynamoConfig'
    );

    $admin = $this->Oa4mpClientCoAdminClient->find('first', $args);

    return $admin;
  }

  /**
   * Get the current canonical representation of the OIDC client.
   *
   * @since  COmanage Registry 4.4.2
   * @param  Integer $id ID for the Oa4mpClientCoOidcClient object
   * @return Array The canonical representation including all related models
   */

  public function current($id) {
    $args = array();
    $args['conditions']['Oa4mpClientCoOidcClient.id'] = $id;
    $args['contain'] = array(
      'Oa4mpClientCoAdminClient' => array(
        'Oa4mpClientCoNamedConfig' => array('Oa4mpClientCoScope'),
        'Oa4mpClientCoEmailAddress',
        'DefaultLdapConfig',
        'DefaultDynamoConfig'
      ),
      'Oa4mpClientAccessControl',
      'Oa4mpClientAccessToken',
      'Oa4mpClientAuthorization',
      'Oa4mpClientRefreshToken',
      'Oa4mpClientCoEmailAddress',
      'Oa4mpClientCoScope',
      'Oa4mpClientCoCallback',
      'Oa4mpClientClaim' => array('Oa4mpClientClaimConstraint'),
      'Oa4mpClientCoLdapConfig',
      'Oa4mpClientCoNamedConfig',
      'Oa4mpClientDynamoConfig',
    );

    $client = $this->find('first', $args);

    if(empty($client)) {
      return array();
    }

    // Need to re-order the scopes to fit our checkbox use of them
    // in the form.
    $newScopes = array();
    foreach($client['Oa4mpClientCoScope'] as $s) {
      switch ($s['scope']) {
        case Oa4mpClientScopeEnum::OpenId:
          $newScopes[0] = $s;
          break;
        case Oa4mpClientScopeEnum::Profile:
          $newScopes[1] = $s;
          break;
        case Oa4mpClientScopeEnum::Email:
          $newScopes[2] = $s;
          break;
        case Oa4mpClientScopeEnum::OrgCilogonUserInfo:
          $newScopes[3] = $s;
          break;
        case Oa4mpClientScopeEnum::Getcert:
          $newScopes[4] = $s;
          break;
      }
    }

    $client['Oa4mpClientCoScope'] = $newScopes;

    return $client;
  }
}
