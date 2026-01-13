<?php
/**
 * COmanage Registry Oa4mp Client Plugin Claims Controller
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

class Oa4mpClientClaimsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientClaims";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientClaim.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Add a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @return null
   */

  function add() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      $claims = $client['Oa4mpClientClaim'];

      $newClaim = $this->request->data['Oa4mpClientClaim'];
      $newClaim['Oa4mpClientClaimConstraint'] = $this->request->data['Oa4mpClientClaimConstraint'];

      $claims[] = $newClaim;

      $newClient = array_replace($client, array('Oa4mpClientClaim' => $claims));

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
        // Update successful so save the claim and related constraints.

        // Remove any empty claim constraints.
        $this->request->data['Oa4mpClientClaimConstraint'] = array_filter($this->request->data['Oa4mpClientClaimConstraint'], function($c) {
          return !empty($c['constraint_field']) && !empty($c['constraint_value']);
        });

        $ret = $this->Oa4mpClientClaim->saveAssociated($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_claim.add.flash.success'), array('key' => 'success'));

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_claims';
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
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    // Clear any request data that may have been set during a failed POST/PUT request.
    $this->request->data = null;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.add.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    // Set the identifier types for the view
    $this->set('vv_identifier_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Identifier
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the email types for the view
    $this->set('vv_email_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->EmailAddress
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the name types for the view
    $this->set('vv_name_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Name
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the SSH key types for the view
    $sshClass = new ReflectionClass(SshKeyTypeEnum::class);
    $sshConstants = $sshClass->getConstants();
    $this->set('vv_ssh_key_types', array_flip($sshConstants));
  }

  /**
   * Delete a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @param  integer $id Oa4mpClientClaim ID
   * @return null
   */

  function delete($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    $newClient = $client;

    foreach($client['Oa4mpClientClaim'] as $i => $c) {
      if($c['id'] == $id) {
        unset($newClient['Oa4mpClientClaim'][$i]);
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
      // Update successful so delete the claim.
      $ret = $this->Oa4mpClientClaim->delete($id);

      // Set flash successful.
      $this->Flash->set(_txt('pl.oa4mp_client_claim.delete.flash.success'), array('key' => 'success'));

      // Redirect to the index view.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_claims';
      $args['action'] = 'index';
      $args['clientid'] = $clientId;

      $this->redirect($args);
    }
  }

  /**
   * Edit a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @param  integer $id Oa4mpClientClaim ID
   * @return null
   */

  function edit($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      $newClient = $client;

      foreach($client['Oa4mpClientClaim'] as $i => $c) {
        if($c['id'] == $id) {
          $newClient['Oa4mpClientClaim'][$i] = $this->request->data['Oa4mpClientClaim'];
          if(!empty($this->request->data['Oa4mpClientClaimConstraint'])) {
            $newClient['Oa4mpClientClaim'][$i]['Oa4mpClientClaimConstraint'] = $this->request->data['Oa4mpClientClaimConstraint'];
          }
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
        // Update successful so save the edited claim and associated constraints.

        // Remove any empty claim constraints.
        $this->request->data['Oa4mpClientClaimConstraint'] = array_filter($this->request->data['Oa4mpClientClaimConstraint'], function($c) {
          return !empty($c['constraint_field']) && !empty($c['constraint_value']);
        });

        $ret = $this->Oa4mpClientClaim->saveAssociated($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_claim.edit.flash.success'), array('key' => 'success'));

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_claims';
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
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.edit.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    // Set the identifier types for the view
    $this->set('vv_identifier_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Identifier
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the email types for the view
    $this->set('vv_email_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->EmailAddress
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the name types for the view
    $this->set('vv_name_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Name
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the SSH key types for the view
    $sshClass = new ReflectionClass(SshKeyTypeEnum::class);
    $sshConstants = $sshClass->getConstants();
    $this->set('vv_ssh_key_types', array_flip($sshConstants));

    foreach($client['Oa4mpClientClaim'] as $i => $c) {
      if($c['id'] == $id) {
        // For edit mode, we need to load the claim with its constraints
        $args = array();
        $args['conditions']['Oa4mpClientClaim.id'] = $id;
        $args['contain'] = array('Oa4mpClientClaimConstraint');

        $claimWithConstraints = $this->Oa4mpClientClaim->find('first', $args);

        if(!empty($claimWithConstraints)) {
          $this->request->data = $claimWithConstraints;
        } else {
          $this->request->data = array('Oa4mpClientClaim' => $client['Oa4mpClientClaim'][$i]);
        }
        return;
      }
    }
  }

  /**
   * Obtain all claims.
   *
   * @since  COmanage Registry v4.2.2
   * @return null
   */

  function index() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

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

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.edit.name',
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

      $oidcClient = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId);

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