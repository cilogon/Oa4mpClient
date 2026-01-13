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
      'foreignKey' => 'client_id',
      'dependent' => true
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
    'source_model_claim_value_field' => array(
      'rule' => 'notBlank',
      'required' => 'true',
      'allowEmpty' => false
    ),
    'claim_value_selection' => array(
      'rule' => 'notBlank',
      'required' => 'false',
      'allowEmpty' => true
    ),
    'claim_value_json_format' => array(
      'rule' => 'notBlank',
      'required' => 'true',
      'allowEmpty' => false
    ),
    'claim_multiple_value_serialization' => array(
      'rule' => 'notBlank',
      'required' => 'false',
      'allowEmpty' => true
    ),
    'claim_value_string_serialization_delimiter' => array(
      'rule' => 'notBlank',
      'required' => 'false',
      'allowEmpty' => true
    )
  );

  /**
   * Find the CO ID for a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @param  integer Record to retrieve for
   * @return integer Corresponding CO ID, or NULL if record has no corresponding CO ID
   * @throws InvalidArgumentException
   * @throws RuntimeException
   */

  function findCoForRecord($id) {
    $args = array();
    $args['conditions']['Oa4mpClientClaim.id'] = $id;
    $args['contain'] = array(
      'Oa4mpClientCoOidcClient' => array(
        'Oa4mpClientCoAdminClient'
      )
    );

    $claim = $this->find('first', $args);

    $coid = $claim['Oa4mpClientCoOidcClient']['Oa4mpClientCoAdminClient']['co_id'];

    return $coid;
  }
}