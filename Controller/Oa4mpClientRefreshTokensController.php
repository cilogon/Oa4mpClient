<?php
/**
 * COmanage Registry Oa4mp Client Plugin Refresh Token Controller
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
 * @since         COmanage Registry v4.5.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("Oa4mpClientOa4mpServer", "Oa4mpClient.Model");
App::uses("StandardController", "Controller");

class Oa4mpClientRefreshTokensController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientRefreshTokens";

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientRefreshToken.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Add a refresh token configuration.
   *
   * @since  COmanage Registry v4.5.0
   * @return null
   */

  function add() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientRefreshToken->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientRefreshToken->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $oa4mpServer->oa4mpEditClient($admin, $client, array_merge($client, $this->request->data));
      if($ret == 0) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
      } elseif($ret == 2) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      } else {
        // Update successful so save the new configuration.
        $ret = $this->Oa4mpClientRefreshToken->save($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_refresh_token.token.add.flash.success'), array('key' => 'success'));

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_refresh_tokens';
        $args['action'] = 'index';
        $args['clientid'] = $clientId;

        $this->redirect($args);
      }
    } 

    // GET 

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
    $synchronized = $oa4mpServer->oa4mpVerifyClient($admin, $client);
    if(!$synchronized) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_refresh_token.add.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));
  }

  /**
   * Edit a refresh token configuration.
   *
   * @since  COmanage Registry v4.5.0
   * @param  integer $id Oa4mpClientRefreshToken ID
   * @return null
   */

  function edit($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientRefreshToken->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientRefreshToken->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $oa4mpServer->oa4mpEditClient($admin, $client, array_merge($client, $this->request->data));
      if($ret == 0) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
      } elseif($ret == 2) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      } else {
        // Update successful so save the edit.
        $ret = $this->Oa4mpClientRefreshToken->save($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_refresh_token.token.edit.flash.success'), array('key' => 'success'));

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_refresh_tokens';
        $args['action'] = 'index';
        $args['clientid'] = $clientId;

        $this->redirect($args);
      }
    }

    // GET 

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
    $synchronized = $oa4mpServer->oa4mpVerifyClient($admin, $client);
    if(!$synchronized) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_refresh_token.edit.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));
  }

  /**
   * Index page.
   *
   * @since  COmanage Registry v4.5.0
   * @return void
   */

  function index() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    // Get the current client configuration
    $client = $this->Oa4mpClientRefreshToken->Oa4mpClientCoOidcClient->current($clientId);

    $this->set('title_for_layout', _txt('pl.oa4mp_client_refresh_token.index.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    // Set page title
    $this->set('vv_oidc_client_name', $client['Oa4mpClientCoOidcClient']['name']);

    // Find all refresh token configurations for this client
    $args = array();
    $args['conditions']['Oa4mpClientRefreshToken.client_id'] = $clientId;
    $args['contain'] = false;

    $this->set('refresh_tokens', $this->Oa4mpClientRefreshToken->find('all', $args));
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for auth decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry v4.5.0
   * @return Array Permissions
   */

  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();             // Get all the roles the user has
    
    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();
    
    // Determine what operations this user can perform
    
    // Add a new refresh token configuration?
    $p['add'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // Delete an existing refresh token configuration?
    $p['delete'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // Edit an existing refresh token configuration?
    $p['edit'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // View all existing refresh token configurations?
    $p['index'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    $this->set('permissions', $p);
    return($p[$this->action]);
  }

/**
   * Find the provided CO ID.
   * This overrides the method defined in AppController.php
   *
   * @since  COmanage Registry v4.4.2
   * @param  Array $data Array of data for calculating implied CO ID
   * @return Integer The CO ID if found, or -1 if not
   */
  
function parseCOID($data = null) {
    // This controller requires passing the OIDC client ID as
    // a named parameter. We can use it to infer the CO ID.
    // The isAuthorized function will enforce that the authenticated
    // user is authorized within that CO to edit the client.
    try {
      $clientId = $this->request->params['named']['clientid'];

      $oidcClient = $this->Oa4mpClientRefreshToken->Oa4mpClientCoOidcClient->current($clientId);

      if(empty($oidcClient)) {
        throw new RuntimeException(_txt('pl.oa4mp_client_co_oidc_client.er.id'));
      }
    } catch (Exception $e) {
        throw new RuntimeException($e);
    }

    $coid = $oidcClient['Oa4mpClientCoAdminClient']['co_id'];

    return $coid;
}
} 
