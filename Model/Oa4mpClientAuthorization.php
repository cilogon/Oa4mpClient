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
    // Required only on update: when a new OIDC client is saved together with
    // its authorization row, saveAssociated() validates this row before the
    // client exists and sets client_id only when it saves the row.
    'client_id' => array(
      'rule' => 'numeric',
      'required' => 'update',
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
    ),
    'no_record_redirect_url' => array(
      'rule' => 'url',
      'required' => false,
      'allowEmpty' => true,
      'message' => 'Please supply a valid https:// URL'
    )
  );

  /**
   * Trim surrounding whitespace from the redirect URLs before they are
   * validated.
   *
   * A space pasted around a URL is never intended, and the url rule rejects
   * it. The controller validates before it sends anything to the OA4MP server
   * and sends the data validation leaves behind, so the trimmed URLs are the
   * ones both sides store (cilogon/Oa4mpClient#48).
   *
   * @since  COmanage Registry v4.5.0
   * @param  array $options Validation options
   * @return boolean True to continue validation
   */

  public function beforeValidate($options = array()) {
    foreach(array('authz_group_redirect_url', 'require_active_redirect_url', 'no_record_redirect_url') as $field) {
      if(isset($this->data[$this->alias][$field]) && is_string($this->data[$this->alias][$field])) {
        $this->data[$this->alias][$field] = trim($this->data[$this->alias][$field]);
      }
    }

    return parent::beforeValidate($options);
  }
}