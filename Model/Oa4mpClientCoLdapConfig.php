<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO LDAP Config Model
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

class Oa4mpClientCoLdapConfig extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoLdapConfig";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp Client LDAP config is attached to an OIDC client
    "Oa4mpClient.Oa4mpClientCoOidcClient" => array(
      'foreignKey' => 'client_id'
    )
  );

  public $hasMany = array(
    "Oa4mpClient.Oa4mpClientCoSearchAttribute" => array(
      'foreignKey' => 'ldap_id',
      'dependent' => true
    )
  );

  // Default display field for cake generated views
  public $displayField = "serverurl";

  // Validation rules for table elements
  public $validate = array(
    'client_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
    ),
    'enabled' => array(
      'rule' => 'boolean',
      'required' => true,
      'allowEmpty' => false
    ),
    'authorization_type' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'serverurl' => array(
      'rule' => array('custom', '/^ldaps?:\/\/.*/'),
      'required' => true,
      'allowEmpty' => false,
      'message' => 'Please enter a valid ldap or ldaps URL'
    ),
    'binddn' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'password' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'basedn' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'search_name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    )
  );
  
}
