<?php
/**
 * COmanage Registry Oa4mp Client Plugin Authorization Controller
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
App::uses("Oa4mpClientNamedConfigActions", "Oa4mpClient.Controller");
App::uses("StandardController", "Controller");

class Oa4mpClientAuthorizationsController extends StandardController {
  use Oa4mpClientNamedConfigActions;

  // Class name, used by Cake
  public $name = "Oa4mpClientAuthorizations";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientAuthorization.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Construct the OA4MP server object the authorization actions talk to.
   *
   * The single construction point for Oa4mpClientOa4mpServer in this
   * controller. It exists so a test subclass can substitute a fake server and
   * drive an action without reaching the network; production behavior is
   * unchanged. Same shape as the factory in Oa4mpClientClaimsController.
   *
   * @since  COmanage Registry v4.5.0
   * @return Oa4mpClientOa4mpServer
   */

  protected function _oa4mpServer() {
    return new Oa4mpClientOa4mpServer();
  }

  /**
   * Manage an authorization configuration.
   *
   * @since COmanage Registry 4.5.0
   * @param integer $id Oa4mpClientAuthorization ID
   * @return null
   */

  function manage() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations. Loading the client
    // deletes any rows its Named configuration replaces.
    $client = $this->_currentClientPruned($this->Oa4mpClientAuthorization->Oa4mpClientCoOidcClient, $clientId);
    $admin = $this->Oa4mpClientAuthorization->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // While a Named configuration is applied its own script decides who may
    // log in, so this page is refused, GET and POST alike, before anything is
    // sent (cilogon/Oa4mpClient#58).
    $this->_refuseWhileNamedConfigApplied($client, $clientId);

    // Pull the available groups.
    $args = array();
    $args['conditions']['ManageCoGroup.co_id'] = $admin['Oa4mpClientCoAdminClient']['co_id'];
    $args['conditions']['ManageCoGroup.status'] = SuspendableStatusEnum::Active;
    $args['order'] = array('ManageCoGroup.name ASC');
    $args['contain'] = false;

    $availableGroups = $this->Oa4mpClientAuthorization
                            ->Oa4mpClientCoOidcClient
                            ->Oa4mpClientCoAdminClient
                            ->ManageCoGroup
                            ->find("list", $args);

    // Add blank option to allow unsetting the group
    $availableGroupsWithBlank = array('' => _txt('pl.oa4mp_client_authorization.fd.authz_co_group_id.select.empty')) + $availableGroups;

    $this->set('vv_available_groups', $availableGroupsWithBlank);

    // OA4MP rejects a custom configuration (cfg) on a public client, so the
    // plugin never sends one, and the authorization settings travel only in
    // the cfg. Saved settings would never be enforced, and Require Active
    // Status would read as drift on the next synchronization check, so refuse
    // the write before anything is sent or saved, and show the read-only page
    // again (cilogon/Oa4mpClient#47).
    if($this->request->is(array('post','put')) && $oa4mpServer->isPublicClient($client)) {
      $this->Flash->set(_txt('pl.oa4mp_client_authorization.er.public_client'), array('key' => 'error'));

      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_authorizations';
      $args['action'] = 'manage';
      $args['clientid'] = $clientId;
      $this->redirect($args);
    }

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Validate before anything is sent to the OA4MP server, and send the
      // data validation leaves behind. A redirect URL the plugin rejects --
      // most often one with a stray space around it -- used to be stored by
      // the server and not by the plugin, leaving the two out of sync
      // (cilogon/Oa4mpClient#48). Validation trims the URLs, so the value sent
      // and the value saved are the same.
      $this->Oa4mpClientAuthorization->create(false);
      $this->Oa4mpClientAuthorization->set($this->request->data);

      if(!$this->Oa4mpClientAuthorization->validates()) {
        // Set flash and fall through to the GET logic, which renders the form
        // again with the validation error. Nothing was sent to the server.
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
      } else {
        $this->request->data['Oa4mpClientAuthorization'] = $this->Oa4mpClientAuthorization->data['Oa4mpClientAuthorization'];

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
          // Update successful so save the edit. The server has already
          // accepted it, so a failed save here leaves the two sides out of
          // sync and must be reported, not flashed as success.
          if(!$this->Oa4mpClientAuthorization->save($this->request->data)) {
            $this->Flash->set(_txt('pl.oa4mp_client_authorization.er.edit.save'), array('key' => 'error'));
          } else {
            $this->Flash->set(_txt('pl.oa4mp_client_authorization.edit.flash.success'), array('key' => 'success'));
          }

          // Redirect to the manage view.
          $args = array();
          $args['plugin'] = 'oa4mp_client';
          $args['controller'] = 'oa4mp_client_authorizations';
          $args['action'] = 'manage';
          $args['clientid'] = $clientId;

          $this->redirect($args);
        }
      }
    }

    // GET 

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
    $verifyResult = $oa4mpServer->oa4mpVerifyClient($admin, $client, true);

    // 'error' means the comparison did not run -- an unreadable or malformed
    // cfg_contract.json, a shape the unmarshaller cannot read. That is not
    // evidence the client was changed outside the Registry, so test it first
    // and say what actually happened. The redirect below is unchanged either
    // way: a check that could not run must not fall through as though the
    // client were in sync.
    $verifyFailed = !empty($verifyResult['error']);
    if($verifyFailed || !$verifyResult['synchronized']) {
      if($verifyFailed) {
        $this->log("Oa4mpClient: the synchronization check for OIDC client "
                   . ($client['Oa4mpClientCoOidcClient']['oa4mp_identifier'] ?? '?')
                   . " did not complete in " . $this->name . "::" . $this->action);
      }

      $this->Flash->set(_txt($verifyFailed
                             ? 'pl.oa4mp_client_co_oidc_client.er.verify_failed'
                             : 'pl.oa4mp_client_co_oidc_client.er.bad_client'),
                        array('key' => 'error'));
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Update oa4mp_server_extra if the OA4MP server returned different extra keys.
    $currentExtra = $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $serverExtra = $verifyResult['oa4mp_server_extra'] ?? null;
    if($serverExtra !== $currentExtra) {
      $this->Oa4mpClientAuthorization->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientAuthorization->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    // A posted configuration that failed validation stays in the form over
    // the stored client, so the subscriber can see and correct what was
    // rejected.
    $rejected = null;
    if(!empty($this->Oa4mpClientAuthorization->validationErrors)) {
      $rejected = $this->request->data['Oa4mpClientAuthorization'] ?? array();
    }

    $this->request->data = $client;

    if($rejected !== null) {
      $this->request->data['Oa4mpClientAuthorization'] = $rejected;
    }

    // Tick Require Active Status only when it was actually saved, asking the
    // same question the marshaller asks before sending it to the server. For
    // a rejected post, reflect what was posted instead, so the box and the
    // redirect URL field it shows or hides match the input being corrected.
    $this->set('vv_require_active', $oa4mpServer->requiresActiveStatus($rejected !== null
                                      ? array('Oa4mpClientAuthorization' => $rejected)
                                      : $client));

    // Whether the client is public. While it is, the view shows the saved
    // settings disabled under a notice.
    $this->set('vv_public_client', $oa4mpServer->isPublicClient($client));

    $this->set('title_for_layout', _txt('pl.oa4mp_client_authorization.edit.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));
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

  function parseCOID($data = null) {
    try {
      $clientId = $this->request->params['named']['clientid'];
      $oidcClient = $this->Oa4mpClientAuthorization->Oa4mpClientCoOidcClient->current($clientId);
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