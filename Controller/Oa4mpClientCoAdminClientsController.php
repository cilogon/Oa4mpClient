<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Admin Clients Controller
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
 * @package       registry
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("StandardController", "Controller");
App::uses("HttpSocket", "Network/Http");

class Oa4mpClientCoAdminClientsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoAdminClients";

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientCoAdminClient.id' => 'asc'
    )
  );

  // This controller does not need a CO to be set
  public $requires_co = false;

  /**
   * Callback before other controller methods are invoked or views are rendered.
   * - precondition:
   * - postcondition: Auth component is configured 
   * - postcondition:
   *
   * @since  COmanage Registry v2.0.1
   */ 
  function beforeFilter() {
    parent::beforeFilter();

    // Circumvent the StandardController and AppController logic for determining
    // the current CO since we do not need it here and want to return to the
    // controller index view after an add() without a co: parameter added
    // automatically.
    $this->cur_co = null;
  }

  /**
   * Perform filtering of CO options for dropdown.
   * - postcondition: co_options set
   *
   * @since  COmanage Registry v2.0.1
   */
 
  function beforeRender() {
    // Compute the available CO options when adding or
    // editing an admin client.
    
    $args = array();
    $args['conditions']['Co.status'] = StatusEnum::Active;
    $args['conditions']['Co.id !='] = 1; // Exclude the COmanage CO.
    $args['contain'] = false;

    $cos = $this->Oa4mpClientCoAdminClient->Co->find('all', $args);

    $args = array();
    $args['contain'] = false;

    $clients = $this->Oa4mpClientCoAdminClient->find('all', $args);
    $co_ids = array();
    foreach($clients as $c) {
      $co_ids[] = $c['Oa4mpClientCoAdminClient']['co_id'];
    }

    $co_options = array();
    foreach($cos as $co) {
      if(!in_array($co['Co']['id'], $co_ids)||
        ($this->action == 'edit' && $this->data['Oa4mpClientCoAdminClient']['co_id'] == $co['Co']['id'])
        ) {
        $co_options[$co['Co']['id']] = $co['Co']['name'];
      }
    }

    $this->set('co_options', $co_options);
    
    parent::beforeRender();
  }

  /**
   * Perform any dependency checks required prior to a write (add/edit) operation.
   *
   * @since  COmanage Registry v2.0.1
   * @param  Array Request data
   * @param  Array Current data
   * @return boolean true if dependency checks succeed, false otherwise.
   */
  
  function checkWriteDependencies($reqdata, $curdata = null) {
    
    if(!isset($curdata) ||
      ($curdata['Oa4mpClientCoAdminClient']['co_id'] != $reqdata['Oa4mpClientCoAdminClient']['co_id'])) {

      $args = array();
      $args['contain'] = false;
      $args['conditions']['co_id'] = $reqdata['Oa4mpClientCoAdminClient']['co_id'];

      $client = $this->Oa4mpClientCoAdminClient->find('first', $args);

      if($client && $reqdata) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.client_exists'), array('key' => 'error'));
        return false;
      }
    }
    
    return true;
  }


  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry 2.0.1
   * @return Array Permissions
   */
  
  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();
    
    // All operations require platform administrator.
    
    // Add a new admin client?
    $p['add'] = $roles['cmadmin'];

    // Delete an existing admin client?
    $p['delete'] = $roles['cmadmin'];
    
    // Edit an existing admin client?
    $p['edit'] = $roles['cmadmin'];

    // View all existing admin clients?
    $p['index'] = $roles['cmadmin'];
    
    // View an existing admin client?
    $p['view'] = $roles['cmadmin'];
    
    $this->set('permissions', $p);
    return $p[$this->action];
  }
}
