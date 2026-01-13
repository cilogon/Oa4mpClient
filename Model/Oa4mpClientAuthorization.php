<?php
/**
 * COmanage Registry Oa4mp Client Plugin Authorization Model
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
 * @since         COmanage Registry v4.5.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClientAuthorization extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientAuthorization";
  
  // Add behaviors
  public $actsAs = array('Containable');
  
  // Association rules from this model to other models
  public $belongsTo = array(
    "Oa4mpClient.Oa4mpClientCoOidcClient" => array(
      'foreignKey' => 'client_id'
    )
  );
  
  // Default display field for cake generated views
  public $displayField = "authz_co_group_id";
  
  // Validation rules for table elements
  public $validate = array(
    'client_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'authz_co_group_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'require_active' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    ),
    'authz_group_redirect_url' => array(
      'rule' => 'url',
      'required' => false,
      'allowEmpty' => true,
      'message' => 'Please supply a valid https:// URL'
    ),
    'require_active_redirect_url' => array(
      'rule' => 'url',
      'required' => false,
      'allowEmpty' => true,
      'message' => 'Please supply a valid https:// URL'
    )
  );
} 