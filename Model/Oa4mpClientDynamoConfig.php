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

  /**
   * The configuration columns a per-client row copies from the admin client's
   * default. Identity and bookkeeping columns (id, client_id, admin_id,
   * created, modified) are deliberately absent: the per-client row keeps its
   * own, and carrying the default's id into a save would update the default
   * row instead of the client's.
   *
   * @since COmanage Registry 4.5.2
   * @return Array Column names.
   */

  private function refreshableColumns() {
    return array(
      'aws_region',
      'aws_access_key_id',
      'aws_secret_access_key',
      'table_name',
      'partition_key',
      'partition_key_template',
      'partition_key_claim_name',
      'sort_key',
      'sort_key_template'
    );
  }

  /**
   * Compute the per-client configuration row refreshed from the admin client's
   * DefaultDynamoConfig.
   *
   * Oa4mpClientCoOidcClientsController::add() copies the admin client's
   * default into a per-client row when the client is created, and
   * Oa4mpClientOa4mpServer::resolveDynamoConfig() prefers that row over the
   * default. Nothing else ever wrote it, so a credential rotated on the admin
   * client never reached an existing client's cfg: the snapshot taken at
   * creation was marshalled forever. This is what brings the snapshot forward.
   *
   * Returns null -- meaning there is nothing to refresh -- in the two cases
   * where writing a row would change behavior rather than preserve it:
   *
   * - The client has no real per-client row. CakePHP's Containable returns the
   *   hasOne association as an array of null-valued fields rather than an
   *   empty array, so aws_region is the test for a real row, the same test
   *   resolveDynamoConfig() makes. Such a client already resolves to the admin
   *   default on every marshall; creating a row for it would only reintroduce
   *   the snapshot this method exists to correct. The id is required on top of
   *   that test, which resolveDynamoConfig() has no reason to check: the
   *   refreshed row is saved, and a save with no id inserts a second
   *   configuration for the client rather than updating the one it has.
   * - The admin client has no default configuration to copy. Overwriting a
   *   populated per-client row with the phantom's nulls would empty the cfg.
   * - The per-client row already holds the default's values. There is nothing
   *   to bring forward, and saving it anyway would write the same row back on
   *   every edit of every client.
   *
   * @since COmanage Registry 4.5.2
   * @param Array $clientData Client data as read by
   *              Oa4mpClientCoOidcClient::current(), carrying both
   *              Oa4mpClientDynamoConfig and
   *              Oa4mpClientCoAdminClient.DefaultDynamoConfig.
   * @return Array|null The per-client row with the default's values, keeping
   *                    its own id, or null when there is nothing to refresh.
   */

  public function refreshedFromAdminDefault($clientData) {
    $perClient = $clientData['Oa4mpClientDynamoConfig'] ?? array();
    $default = $clientData['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'] ?? array();

    if(empty($perClient['aws_region']) || empty($perClient['id'])) {
      return null;
    }

    if(empty($default['aws_region'])) {
      return null;
    }

    $refreshed = $perClient;
    $changed = false;

    foreach($this->refreshableColumns() as $column) {
      $refreshed[$column] = $default[$column] ?? null;

      if($refreshed[$column] !== ($perClient[$column] ?? null)) {
        $changed = true;
      }
    }

    return $changed ? $refreshed : null;
  }
}
