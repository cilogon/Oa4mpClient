<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Search Attribute Model
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

class Oa4mpClientCoSearchAttribute extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoSearchAttribute";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp Client search attribute is attached to an LDAP config
    "Oa4mpClient.Oa4mpClientCoLdapConfig" => array(
      'foreignKey' => 'ldap_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'ldap_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
    ),
    'name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'return_as_list' => array(
      'rule' => 'boolean',
      'required' => true,
      'allowEmpty' => false
    ),
    'return_name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'claim_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    )
  );

  public function toClaim($clientId, $coId, $dynamoConfig, $coLdapConfig, $searchAttribute) {
    $claim = array();
    $claimConstraints = array();

    $claim['client_id'] = $clientId;
    $claim['claim_name'] = $searchAttribute['return_name'];

    // Different logic is required for different LDAP Provisioner Attributes.
    $useLdapProvisionerConfig = false;

    $searchAttributeName = $searchAttribute['name'];

    switch($searchAttributeName) {
      case 'eduPersonOrcid':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'orcid'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'employeeNumber':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'gecos':
        $claim['source_model'] = 'Name';
        $claim['source_model_claim_value_field'] = 'all';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'all'
        );
        $claimConstraints[] = array(
          'constraint_field' => 'primary',
          'constraint_value' => 'true'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'gidNumber':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'gidNumber'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'number';
        break;
      case 'givenName':
        $claim['source_model'] = 'Name';
        $claim['source_model_claim_value_field'] = 'given';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'all'
        );
        $claimConstraints[] = array(
          'constraint_field' => 'primary',
          'constraint_value' => 'true'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'isMemberOf':
        $claim['source_model'] = 'CoGroupMember';
        $claim['source_model_claim_value_field'] = 'member';
        $claimConstraints[] = array(
          'constraint_field' => 'owner',
          'constraint_value' => 'false'
        );
        $claim['claim_value_selection'] = 'all';
        $claim['claim_value_json_format'] = 'string';
        $claim['claim_multiple_value_serialization'] = 'delimited_string';
        $claim['claim_value_string_serialization_delimiter'] = ',';
        break;
      case 'mail':
        $claim['source_model'] = 'EmailAddress';
        $claim['source_model_claim_value_field'] = 'mail';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'sn':
        $claim['source_model'] = 'Name';
        $claim['source_model_claim_value_field'] = 'family';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'all'
        );
        $claimConstraints[] = array(
          'constraint_field' => 'primary',
          'constraint_value' => 'true'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'uid':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'uidNumber':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'uidNumber'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'number';
        break;
      case 'voPersonApplicationUID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'voPersonExternalID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'voPersonID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      default:
        $this->log("Did not convert LDAP search attribute " . $searchAttribute['name'] . " to claim object because it is not supported");
        return;
        break;
    }

    if($useLdapProvisionerConfig) {
      $args = array();
      $args['conditions']['CoProvisioningTarget.co_id'] = $coId;
      $args['conditions']['CoProvisioningTarget.plugin'] = 'LdapProvisioner';
      $args['contain'] = array('CoLdapProvisionerTarget' => array('CoLdapProvisionerAttribute'));

      // We need to dynamically bind the CoLdapProvisionerTarget model to the CoProvisioningTarget model.
      $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoAdminClient->Co->CoProvisioningTarget->bindModel(array(
        'hasOne' => array(
          'CoLdapProvisionerTarget' => array(
            'className' => 'LdapProvisioner.CoLdapProvisionerTarget',
            'foreignKey' => 'co_provisioning_target_id'
          )
        )
      ));

      $coProvisioningTargets = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoAdminClient->Co->CoProvisioningTarget->find('all', $args);

      if(empty($coProvisioningTargets)) {
        $this->log("No coProvisioningTargets found for LDAP Config " . $coLdapConfig['id']);
        $this->log("Did not convert LDAP search attribute " . $searchAttributeName . " to claim object because no coProvisioningTargets were found");
        return;
      }

      // Loop over the coProvisioningTargets and pick out the one where the serverurl matches the LDAP Config's serverurl.
      $ldapProvisionerTarget = null;
      foreach($coProvisioningTargets as $coProvisioningTarget) {
        if($coProvisioningTarget['CoLdapProvisionerTarget']['serverurl'] == $coLdapConfig['serverurl']) {
          $ldapProvisionerTarget = $coProvisioningTarget['CoLdapProvisionerTarget'];
          break;
        }
      }

      if(empty($ldapProvisionerTarget)) {
        $this->log("No ldapProvisionerTarget found for LDAP Config " . $coLdapConfig['id']);
        $this->log("Did not convert LDAP search attribute " . $searchAttributeName . " to claim object because no ldapProvisionerTarget was found");
        return;
      }

      // Loop over the ldapProvisionerTarget's CoLdapProvisionerAttributes and pick out the one
      // where the name matches the searchAttribute's name.
      $ldapProvisionerAttribute = null;
      foreach($ldapProvisionerTarget['CoLdapProvisionerAttribute'] as $ldapProvisionerAttribute) {
        if($ldapProvisionerAttribute['attribute'] == $searchAttributeName){
          $ldapProvisionerAttribute = $ldapProvisionerAttribute;
          break;
        }
      }

      if(empty($ldapProvisionerAttribute)) {
        $this->log("No ldapProvisionerAttribute found for LDAP Config " . $coLdapConfig['id']);
        $this->log("Did not convert LDAP search attribute " . $searchAttributeName . " to claim object because no ldapProvisionerAttribute was found");
        return;
      }

      $claimConstraints[] = array(
        'constraint_field' => 'type',
        'constraint_value' => $ldapProvisionerAttribute['type']
      );
    }

    $claim['Oa4mpClientClaimConstraint'] = $claimConstraints;

    // Save the claim and the associated claim constraint(s).
    $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->clear();
    if(!$this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->saveAssociated($claim)) {
      $this->log("saveAssociated failed for claim " . print_r($claim, true));
      $this->log("Validation errors are " . print_r($this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->validationErrors, true));
      $this->log("Did not convert LDAP search attribute " . $searchAttribute['name'] . " to claim object");
      return;
    }

    // Save the Dynamo configuration.
    $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientDynamoConfig->clear();
    $dynamoConfig['client_id'] = $clientId;
    unset($dynamoConfig['admin_id']);
    unset($dynamoConfig['id']);
    unset($dynamoConfig['created']);
    unset($dynamoConfig['modified']);

    if(!$this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientDynamoConfig->save($dynamoConfig)) {
      $this->log("saveAssociated failed for dynamoConfig " . print_r($dynamoConfig, true));
      $this->log("Validation errors are " . print_r($this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientDynamoConfig->validationErrors, true));
      $this->log("Did not convert LDAP search attribute " . $searchAttribute['name'] . " to claim object");
      return;
    }

    $this->log("Converted LDAP search attribute " . $searchAttribute['name'] . " to claim object " . print_r($claim, true));

    // Update the searchAttribute's claim_id to the new claim's id to mark it as converted.
    $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->id = $searchAttribute['id'];
    $newId = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->id;
    $ret = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->saveField('claim_id', $newId);
    if(!$ret) {
      $this->log("saveField failed for searchAttribute with ID " . $searchAttribute['id'] . " to mark it as converted");
      return;
    }

    return;
  }
}