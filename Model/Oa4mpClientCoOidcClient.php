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
    )
  );

  public $hasMany = array(
    "Oa4mpClient.Oa4mpClientCoCallback" => array(
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
    ),
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
    )
  );
  
}
