<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Admin Client Model
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

class Oa4mpClientCoAdminClient extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoAdminClient";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp admin client is attached to a CO
    "Co",
    "ManageCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'manage_co_group_id'
    )
  );

  public $hasOne = array(
    // An Oa4mp admin client has one default LDAP config
    "DefaultLdapConfig" => array(
      'className' => 'Oa4mpClient.Oa4mpClientCoLdapConfig',
      'foreignKey' => 'admin_id'
    )
  );

  public $hasMany = array(
    // An Oa4mp admin client manages many OIDC clients
    "Oa4mpClient.Oa4mpClientCoOidcClient" => array(
      'foreignKey' => 'admin_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "admin_identifier";

  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'message' => 'A CO ID must be provided',
    ),
    'manage_co_group_id' => array(
      'content' => array(
        'rule' => 'numeric',
        'required' => false,
        'allowEmpty' => true,
        'unfreeze' => 'CO'
      )
    ),
    'serverurl' => array(
      'rule' => 'url',
      'required' => true,
      'allowEmpty' => false,
      'message' => 'Please supply a valid http:// or https:// URL'
    ),
    'admin_identifier' => array( 
        'rule' => 'notBlank',
        'required' => true,
        'allowEmpty' => false,
    ),
    'secret' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false,
    ),
    'qdl_claim_source' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'qdl_claim_process' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    )
  );
}
