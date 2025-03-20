<?php
/**
 * COmanage Registry Oa4mp Client Plugin Claims Model
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
 * @since         COmanage Registry v4.4.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClientClaim extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientClaim";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp Client Claim may be attached to an OIDC client
    "Oa4mpClient.Oa4mpClientCoOidcClient" => array(
      'foreignKey' => 'client_id'
    )
  );

  public $hasMany = array(
    // An Oa4mp Client Claim may have multiple claim constraints
    "Oa4mpClient.Oa4mpClientClaimConstraint" => array(
      'foreignKey' => 'claim_id',
      'dependent' => true
    )
  );

  // Default display field for cake generated views
  public $displayField = "field";

  // Validation rules for table elements
  public $validate = array(
    'client_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
    ),
    'claim_name' => array(
      'rule' => 'notBlank',
      'required' => 'true',
      'allowEmpty' => false
    ),
    'source_model' => array(
      'rule' => 'notBlank',
      'required' => 'true',
      'allowEmpty' => false
    ),
    'value_field' => array(
      'rule' => 'notBlank',
      'required' => 'true',
      'allowEmpty' => false
    ),
    'value_req' => array(
      'rule' => 'notBlank',
      'required' => 'false',
      'allowEmpty' => true
    ),
    'value_format' => array(
      'rule' => 'notBlank',
      'required' => 'true',
      'allowEmpty' => false
    ),
    'value_string_d' => array(
      'rule' => 'notBlank',
      'required' => 'false',
      'allowEmpty' => true
    )
  );
}
