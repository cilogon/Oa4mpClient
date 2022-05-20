<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Named Configs Controller
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
 * @since         COmanage Registry v4.0.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("StandardController", "Controller");

class Oa4mpClientCoNamedConfigsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoNamedConfigs";

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'fields' => array(
      'Oa4mpClientCoNamedConfig.id',
      'Oa4mpClientCoNamedConfig.config_name',
      'Co.name',
    ),
    'limit' => 25,
    'joins' => array(
      array(
        'table' => 'cm_cos',
        'alias' => 'Co',
        'type' => 'LEFT',
        'conditions' => array(
          'Co.id = Oa4mpClientCoAdminClient.co_id'
        )
      )
    ),
    'order' => 'Co.name ASC, Oa4mpClientCoNamedConfig.config_name ASC'
  );

  // This controller does not need a CO to be set
  public $requires_co = false;

  // Add models to contain when querying for data
  // as part of an edit action.
  public $edit_contains = array(
    'Oa4mpClientCoAdminClient',
    'Oa4mpClientCoScope' => array(
      'order' => 'Oa4mpClientCoScope.id'
    ),
  );

  // Add models to contain when querying for data to be
  // used in views populated by the StandardController.
  public $view_contains = array(
    // Include the CO for rendering of the index view.
    //'Oa4mpClientCoAdminClient.Co'
    'Oa4mpClientCoAdminClient' => 'Co'
  );

  /**
   * Add a named configuration
   *
   * @since COmanage Registry 4.0.2
   */

  function add() {
    // Process POST data
    if($this->request->is('post')) {
      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there any validation 
        // error so just return.
        return;
      }

      // Save the named configuration and associated data.
      $args = array();
      $args['validate'] = false;
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoNamedConfig->saveAssociated($data, $args);

      if(!$ret) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return;
      }

      // Redirect to the index view
      $this->Flash->set(_txt('rs.added-a', array(filter_var($this->generateDisplayKey(),FILTER_SANITIZE_SPECIAL_CHARS))), array('key' => 'success'));
      $this->performRedirect();
    }

    // GET
    parent::add();
  }

  /**
   * Callback before other controller methods are invoked or views are rendered.
   * - precondition:
   * - postcondition: Auth component is configured 
   * - postcondition:
   *
   * @since  COmanage Registry v4.0.2
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
   * @since  COmanage Registry v4.0.2
   */
 
  function beforeRender() {
    // Compute the available CO options when adding or
    // editing an named configuration.
    
    $args = array();
    $args['contain'] = 'Co';

    $adminClients = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->find('all', $args);

    $cos = array();
    foreach($adminClients as $c) {
      $cos[$c['Oa4mpClientCoAdminClient']['id']] = $c['Co']['name'];
    }

    $this->set('cos', $cos);

    parent::beforeRender();
  }

  /**
   * Edit a Named Config.
   *
   * @since COmanage Registry v4.0.2
   */
  function edit($id) {
    // Pull the current data.
    $args = array();
    $args['conditions']['Oa4mpClientCoNamedConfig.id'] = $id;
    $args['contain'] = $this->edit_contains;

    $curdata = $this->Oa4mpClientCoNamedConfig->find('first', $args);

    if(empty($curdata)) {
      $this->Flash->set(_txt('er.notfound', array(_txt('ct.oa4mp_client_co_named_configs.1'), $id)), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $this->redirect($args);
    }

    // Set the title for the view.
    $this->set('title_for_layout', _txt('op.edit-a', array(filter_var($curdata['Oa4mpClientCoNamedConfig']['config_name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    // PUT request
    if($this->request->is(array('post','put'))) {

      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there any validation 
        // error so just return.
        return;
      }

      // Make sure the ID is set for the Named Config model.
      $data['Oa4mpClientCoNamedConfig']['id'] = $curdata['Oa4mpClientCoNamedConfig']['id'];

      // saveAssociated will not delete a scope that is no longer
      // in the submitted form data but is in the current data so
      // delete it directly.
      foreach($curdata['Oa4mpClientCoScope'] as $current_scope) {
        $delete = true;
        foreach($data['Oa4mpClientCoScope'] as $data_scope) {
          if(!empty($data_scope['id']) && ($data_scope['id'] == $current_scope['id'])) {
            $delete = false;
          }
        }
        if($delete) {
          $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoScope->delete($current_scope['id']);
        }
      }

      // Save the named configuration and associated data.
      $args = array();
      $args['validate'] = false;
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoNamedConfig->saveAssociated($data, $args);

      if(!$ret) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return;
      }

      // Redirect to the index view
      $this->Flash->set(_txt('rs.updated', array(filter_var($this->generateDisplayKey(),FILTER_SANITIZE_SPECIAL_CHARS))), array('key' => 'success'));
      $this->performRedirect();
    }

    // GET request
    $this->request->data = $curdata;

    // Need to re-order the scopes to fit our checkbox use of them
    // in the form and handle any adhoc attributes.
    $newScopes = array();
    $i = 5;
    foreach($curdata['Oa4mpClientCoScope'] as $s) {
      switch ($s['scope']) {
        case Oa4mpClientScopeEnum::OpenId:
          $newScopes[0] = $s;
          break;
        case Oa4mpClientScopeEnum::Profile:
          $newScopes[1] = $s;
          break;
        case Oa4mpClientScopeEnum::Email:
          $newScopes[2] = $s;
          break;
        case Oa4mpClientScopeEnum::OrgCilogonUserInfo:
          $newScopes[3] = $s;
          break;
        case Oa4mpClientScopeEnum::Getcert:
          $newScopes[4] = $s;
          break;
        default:
          $newScopes[$i] = $s;
          $i = $i + 1;
          break;
      }
    }

    $this->request->data['Oa4mpClientCoScope'] = $newScopes;
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry v4.0.2
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

  /**
   * Validate and clean POST data from an add or edit action.
   *
   * @since  COmanage Registry v4.0.2
   * @return Array of validated data ready for saving or false if not validated.
   */

  private function validatePost() {
    $data = $this->request->data;

    // Trim leading and trailing whitespace from user input.
    array_walk_recursive($data, function (&$value,$key) { 
      if (is_string($value)) { 
        $value = trim($value); 
      } 
    });

    // We validate necessary fields here in the controller so that we can
    // leverage saveAssociated to save the data with validate set to false.
    // When it is set to true and there are multiple rows of associated data
    // validation fails.
    
    // Validate the Named Config client fields.
    $this->Oa4mpClientCoNamedConfig->set($data);

    $fields = array();
    $fields[] = 'admin_id';
    $fields[] = 'config_name';
    $fields[] = 'description';
    $fields[] = 'config';

    $args = array();
    $args['fieldList'] = $fields;

    if(!$this->Oa4mpClientCoNamedConfig->validates($args)) {
      $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
      return false;
    }

    // Validate the standard scope fields and remove empty values.
    for ($i = 0; $i < 10; $i++) {
      $scope = $data['Oa4mpClientCoScope'][$i];
      if(empty($scope['scope'])) {
        unset($data['Oa4mpClientCoScope'][$i]);
        continue; 
      }

      // We only validate the standard scopes.
      if($i < 5) {
        $d = array();
        $d['Oa4mpClientCoScope'] = $scope;
        $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoScope->set($d);

        $fields = array();
        $fields[] = 'scope';

        $args = array();
        $args['fieldList'] = $fields;

        if(!$this->Oa4mpClientCoNamedConfig->Oa4mpClientCoScope->validates($args)) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return false;
        }
      }
    }

    return $data;
  }
}
