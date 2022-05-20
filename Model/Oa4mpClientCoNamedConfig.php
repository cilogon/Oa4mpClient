<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Named Config Model
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
 * @since         COmanage Registry v4.0.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClientCoNamedConfig extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoNamedConfig";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp Client Named config is attached to an admin client
    "Oa4mpClient.Oa4mpClientCoAdminClient" => array(
      'foreignKey' => 'admin_id'
    )
  );

  public $hasMany = array(
    // An Oa4mp Client LDAP config may have multiple scopes
    "Oa4mpClient.Oa4mpClientCoScope" => array(
      'foreignKey' => 'named_config_id',
      'dependent' => true
    )
  );

  // Default display field for cake generated views
  public $displayField = "config_name";

  // Validation rules for table elements
  public $validate = array(
    'admin_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
    ),
    'config_name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'description' => array(
      'rule' => 'notBlank',
      'required' => false,
      'allowEmpty' => true
    ),
    'config' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    )
  );
}
