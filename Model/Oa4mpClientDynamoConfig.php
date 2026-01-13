<?php
/**
 * COmanage Registry Oa4mp Client Plugin Dynamo Config Model
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

class Oa4mpClientDynamoConfig extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientDynamoConfig";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp Client Dynamo config may be attached to an OIDC client
    "Oa4mpClient.Oa4mpClientCoOidcClient" => array(
      'foreignKey' => 'client_id'
    ),
    // An Oa4mp Client Dynamo config may be attached to an admin client
    "Oa4mpClient.Oa4mpClientCoAdminClient" => array(
      'foreignKey' => 'admin_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "table_name";

  // Validation rules for table elements
  public $validate = array(
    'client_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true,
    ),
    'admin_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true,
    ),
    'aws_region' => array(
        'content' => array(
          'rule' => array('validateAwsRegion'),
          'required' => true,
          'allowEmpty' => false
        )
    ),
    'aws_access_key_id' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'aws_secret_access_key' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'table_name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'partition_key' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'partition_key_template' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'partition_key_claim_name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'sort_key' => array(
      'rule' => 'notBlank',
      'required' => false,
      'allowEmpty' => true
    ),
    'sort_key_template' => array(
      'rule' => 'notBlank',
      'required' => false,
      'allowEmpty' => true
    )
  );

  /**
   * Validate the aws_region field.
   *
   * @since COmanage Registry v4.3.1
   * @param Array $check the input data to check
   * @throws none
   * @return Boolean True if input data validates
   */

  public function validateAwsRegion($check) {
    return array_key_exists($check['aws_region'], AwsRegionEnum::$allAwsRegions);
  }
}
