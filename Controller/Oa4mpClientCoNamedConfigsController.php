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
App::uses("Oa4mpClientOa4mpServer", "Oa4mpClient.Model");

class Oa4mpClientCoNamedConfigsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoNamedConfigs";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

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
    'Oa4mpClientCoAdminClient.name',
    'Oa4mpClientCoAdminClient.issuer',
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

    $this->set('adminClients', $adminClients);

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
   * Manage named configuration for an OIDC client.
   *
   * @since COmanage Registry v4.5.0
   */
  function manage() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      $updatedClient = $client;
      if($this->request->data['Oa4mpClientCoNamedConfig']['selected_config_id'] == 'none') {
        $updatedClient['Oa4mpClientCoOidcClient']['named_config_id'] = null;
      } else {
        $updatedClient['Oa4mpClientCoOidcClient']['named_config_id'] = $this->request->data['Oa4mpClientCoNamedConfig']['selected_config_id'];
      }

      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $updatedClient);

      if($ret == 0) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
      } elseif($ret == 2) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      } else {
        // Update successful so save the edit.
        $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->id = $clientId;
        $ret = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->saveField('named_config_id', $updatedClient['Oa4mpClientCoOidcClient']['named_config_id']);
        if(!$ret) {
          $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.manage.flash.error'), array('key' => 'error'));
          return;
        }

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'), array('key' => 'success'));

        // Redirect to the manage view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_co_named_configs';
        $args['action'] = 'manage';
        $args['clientid'] = $clientId;
        $this->redirect($args);
      }
    }

    // GET

    // Check that the current state of the client before the edit are synchronized.
    $verifyResult = $oa4mpServer->oa4mpVerifyClient($admin, $client, true);
    if(!$verifyResult['synchronized']) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Update oa4mp_server_extra if the OA4MP server returned different extra keys.
    $currentExtra = $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $serverExtra = $verifyResult['oa4mp_server_extra'] ?? null;
    if($serverExtra !== $currentExtra) {
      $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('vv_available_configs', $admin['Oa4mpClientCoNamedConfig'] ?? array());

    $this->request->data = $client;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_named_config.manage.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));
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
    // For the manage action, use the standard authorization component
    if($this->action == 'manage') {
      $roles = $this->Role->calculateCMRoles();
      $coId = $this->parseCOID();
      $coPersonId = $this->Session->read('Auth.User.co_person_id');

      // If the user is not logged in, return false.
      if(empty($coPersonId)) {
        $this->set('permissions', array());
        return false;
      }

      $p = $this->Oa4mpClientAuthz->permissionSet($coId, $coPersonId, $roles, $this->request->params);
      $this->set('permissions', $p);
      return $p[$this->action];
    }

    // For other actions, use the original admin-only authorization
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
   * Find the provided CO ID.
   * This overrides the method defined in AppController.php
   *
   * @since  COmanage Registry v4.5.0
   * @param  Array $data Array of data for calculating implied CO ID
   * @return Integer The CO ID if found, or -1 if not
   */

  function parseCOID($data = null) {
    // This controller requires passing the OIDC client ID as
    // a named parameter. We can use it to infer the CO ID.
    // The isAuthorized function will enforce that the authenticated
    // user is authorized within that CO to edit the client.
    try {
      // The manage action requires passing the OIDC client ID as
      // a named parameter, but the other actions do not, so if it
      // is not present, just return -1.
      $clientId = $this->request->params['named']['clientid'] ?? null;

      if(empty($clientId)) {
        return -1;
      }

      $oidcClient = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->current($clientId);

      if(empty($oidcClient)) {
        throw new RuntimeException(_txt('pl.oa4mp_client_co_oidc_client.er.id'));
      }
    } catch (Exception $e) {
        throw new RuntimeException($e);
      }

      $coid = $oidcClient['Oa4mpClientCoAdminClient']['co_id'];

      return $coid;
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
    for ($i = 0; $i < 50; $i++) {
      if(!isset($data['Oa4mpClientCoScope'][$i])) {
        continue;
      }

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
