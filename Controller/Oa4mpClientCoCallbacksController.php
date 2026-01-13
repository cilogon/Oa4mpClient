<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Callbacks Controller
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
 * @since         COmanage Registry v4.4.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("Oa4mpClientOa4mpServer", "Oa4mpClient.Model");
App::uses("StandardController", "Controller");

class Oa4mpClientCoCallbacksController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoCallbacks";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientCoCallback.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Add a callback.
   *
   * @since  COmanage Registry v4.4.2
   * @return null
   */

  function add() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {

      $newCallbacks = $client['Oa4mpClientCoCallback'];
      $newCallbacks[] = $this->request->data['Oa4mpClientCoCallback'];

      $newClient = array_replace($client, array('Oa4mpClientCoCallback' => $newCallbacks));

      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
      if($ret == 0) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
      } elseif($ret == 2) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      } else {
        // Update successful so save the new callback.
        $ret = $this->Oa4mpClientCoCallback->save($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_co_callback.callback.add.flash.success'), array('key' => 'success'));

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_co_callbacks';
        $args['action'] = 'index';
        $args['clientid'] = $clientId;

        $this->redirect($args);
      }
    } 

    // GET 

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
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
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.callbacks.add.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));
  }

  /**
   * Delete a callback.
   *
   * @since  COmanage Registry v4.2.2
   * @param  integer $id Oa4mpClientCoCallback ID
   * @return null
   */

  function delete($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    $newClient = $client;

    foreach($client['Oa4mpClientCoCallback'] as $i => $c) {
      if($c['id'] == $id) {
        unset($newClient['Oa4mpClientCoCallback'][$i]);
        break;
      }
    } 

    // Call out to oa4mp server.
    // Return value of 0 indicates an error saving the edit.
    // Return value of 2 indicates the plugin representation of the client
    // and the Oa4mp server representation of the client are out of sync.
    $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
    if($ret == 0) {
      // Set flash and fall through to render again.
      $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
    } elseif($ret == 2) {
      // Set flash and fall through to render again.
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
    } else {
      // Update successful so delete the callback.
      $ret = $this->Oa4mpClientCoCallback->delete($id);

      // Set flash successful.
      $this->Flash->set(_txt('pl.oa4mp_client_co_callback.callback.delete.flash.success'), array('key' => 'success'));

      // Redirect to the index view.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_callbacks';
      $args['action'] = 'index';
      $args['clientid'] = $clientId;

      $this->redirect($args);
    }
  }

  /**
   * Edit a callback.
   *
   * @since  COmanage Registry v4.2.2
   * @param  integer $id Oa4mpClientCoCallback ID
   * @return null
   */

  function edit($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      $newClient = $client;

      foreach($client['Oa4mpClientCoCallback'] as $i => $c) {
        if($c['id'] == $id) {
          $newClient['Oa4mpClientCoCallback'][$i] = $this->request->data['Oa4mpClientCoCallback'];
          break;
        }
      } 

      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
      if($ret == 0) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
      } elseif($ret == 2) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      } else {
        // Update successful so save the edited callback.
        $ret = $this->Oa4mpClientCoCallback->save($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_co_callback.callback.edit.flash.success'), array('key' => 'success'));

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_co_callbacks';
        $args['action'] = 'index';
        $args['clientid'] = $clientId;

        $this->redirect($args);
      }
    } 

    // GET 

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
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
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.callbacks.edit.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    foreach($client['Oa4mpClientCoCallback'] as $i => $c) {
      if($c['id'] == $id) {
        $this->request->data = array('Oa4mpClientCoCallback' => $client['Oa4mpClientCoCallback'][$i]);
        return;
      }
    } 
  }

  /**
   * Obtain all callbacks.
   *
   * @since  COmanage Registry v4.2.2
   * @return null
   */

  function index() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
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
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->request->data = $client;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.callbacks.edit.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry 4.4.2
   * @return Array Permissions
   */
  
  function isAuthorized() {
    // Construct the permission set for this user, which will also be passed to the view.
    $roles = $this->Role->calculateCMRoles();

    $coId = $this->cur_co['Co']['id'];

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

      $oidcClient = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);

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
