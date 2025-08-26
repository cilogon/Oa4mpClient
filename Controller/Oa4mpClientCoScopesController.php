<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Scopes Controller
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

class Oa4mpClientCoScopesController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoScopes";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientCoScope.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Edit the scopes for the client.
   *
   * @since COmanage Registry 4.4.2
   */

  function edit_scopes() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->admin($clientId);

    // PUT request
    if($this->request->is(array('post','put'))) {

      $selectedScopes = array_filter(
        $this->request->data['Oa4mpClientCoScope'],
        function($s) {
          return $s['scope'] != 0;
        }
      );

      $newClient = array_replace($client, array('Oa4mpClientCoScope' => $selectedScopes));

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
        // Update successful so save the new scope state.

        // First create any new scopes for this client.
        foreach($selectedScopes as $s) {
          if(empty($s['id'])) {
            $this->Oa4mpClientCoScope->clear();

            $data = array();
            $data['Oa4mpClientCoScope']['client_id'] = $client['Oa4mpClientCoOidcClient']['id'];
            $data['Oa4mpClientCoScope']['scope'] = $s['scope'];

            $this->Oa4mpClientCoScope->save($data);
          }
        }

        // Next delete any scopes that were deselected.
        foreach($client['Oa4mpClientCoScope'] as $previous) {
          $delete = true;
          foreach($selectedScopes as $s) {
            if(!empty($s['id']) && ($s['id'] == $previous['id'])) {
              $delete = false;
            }
          }
          if($delete) {
            $this->Oa4mpClientCoScope->delete($previous['id']);
          }
        }

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_co_scope.scope.flash.success'), array('key' => 'success'));

        // Read the client state again and fall through to the GET.
        $client = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->current($clientId);
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

    $this->request->data = $client;

    // Set the title for the view.
    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_scope.scope.edit.name',
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
    $roles = $this->Role->calculateCMRoles();

    $coId = $this->cur_co['Co']['id'];

    $coPersonId = $this->Session->read('Auth.User.co_person_id');

    // If the user is not logged in, return false.
    if(empty($coPersonId)) {
      $this->set('permissions', $p);
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

      $args = array();
      $args['conditions']['Oa4mpClientCoOidcClient.id'] = $clientId;
      $args['contain'] = 'Oa4mpClientCoAdminClient';

      $oidcClient = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->find('first', $args);

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
