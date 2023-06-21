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
   * Add an admin client.
   *
   * @since COmanage Registry 3.1.1
   */

  function add() {
    // Process POST data
    if($this->request->is('post')) {

      // We do not currently expose all of the LDAP configuration options
      // in the form so add default values before validating the data. 
      $this->request->data['DefaultLdapConfig']['enabled'] = true;
      $this->request->data['DefaultLdapConfig']['authorization_type'] = 'simple';
      $this->request->data['DefaultLdapConfig']['search_name'] = 'username';
    }

    parent::add();
  }

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

    // We no longer constrain the CO to having a single admin client.
    $co_options = array();
    foreach($cos as $co) {
        $co_options[$co['Co']['id']] = $co['Co']['name'];
    }

    $this->set('co_options', $co_options);

    // Read the default path for QDL configuration and set a view
    // variable so that the default can be supplied in the form
    // if there is no existing value.
    $qdlClaimDefault = getenv('COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT');

    $this->set('qdlClaimDefault', $qdlClaimDefault);
    
    parent::beforeRender();
  }

  /**
   * Edit an admin client.
   *
   * @since COmanage Registry 3.1.1
   */

  function edit($id) {
    // Pull the current data.
    $args = array();
    $args['conditions']['Oa4mpClientCoAdminClient.id'] = $id;
    $args['contain'] = $this->edit_contains;

    $curdata = $this->Oa4mpClientCoAdminClient->find('first', $args);

    if(empty($curdata)) {
      $this->Flash->set(_txt('er.notfound', array(_txt('ct.oa4mp_client_co_admin_clients.1'), $id)), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    $co_id = $curdata['Oa4mpClientCoAdminClient']['co_id'];

    // Pull the available groups.
    $args = array();
    $args['conditions']['ManageCoGroup.co_id'] = $co_id;
    $args['conditions']['ManageCoGroup.status'] = SuspendableStatusEnum::Active;
    $args['order'] = array('ManageCoGroup.name ASC');
    $args['contain'] = false;

    $this->set('vv_available_groups', $this->Oa4mpClientCoAdminClient->ManageCoGroup->find("list", $args));

    // Process POST data
    if($this->request->is(array('post', 'put'))) {

      // We do not currently expose all of the LDAP configuration options
      // in the form so add default values before validating the data. 
      $this->request->data['DefaultLdapConfig']['enabled'] = true;
      $this->request->data['DefaultLdapConfig']['authorization_type'] = 'simple';
      $this->request->data['DefaultLdapConfig']['search_name'] = 'username';
    } 

    parent::edit($id);
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
    // Only authenticated users are authorized.
    if($this->Session->check('Auth.User.username')) {
        $username = $this->Session->read('Auth.User.username');
    } else {
      return false;
    }

    $allowedUsernames = array();

    // If defined read a comma separated list of authorized usernames
    // from environmemt variable.
    $allowedUsernamesString = getenv('COMANAGE_REGISTRY_OA4MP_ADMIN_USERS');

    if($allowedUsernamesString) {
      $allowedUsernames = explode(',', $allowedUsernamesString);
    } else {
      $allowedUsernames[] = $username;
    }

    if(in_array($username, $allowedUsernames)) {
      // Authorized usernames must have the platform admin role.
      $roles = $this->Role->calculateCMRoles();

      $p = array();

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
    } else {
      return false;
    }
  }
}
