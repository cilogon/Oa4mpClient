<?php
/**
 * COmanage Registry Oa4mp Client Plugin Access Control Controller
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

class Oa4mpClientAccessControlsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientAccessControls";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientAccessControl.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Construct the OA4MP server object the manage action talks to.
   *
   * The single construction point for Oa4mpClientOa4mpServer in this
   * controller. It exists so a test subclass can substitute a fake server and
   * drive the action without reaching the network; production behavior is
   * unchanged. Same shape as the factory in Oa4mpClientAuthorizationsController.
   *
   * @since  COmanage Registry v4.5.0
   * @return Oa4mpClientOa4mpServer
   */

  protected function _oa4mpServer() {
    return new Oa4mpClientOa4mpServer();
  }

  /**
   * Manage an access control configuration.
   *
   * @since  COmanage Registry v4.5.0
   * @return null
   */

  function manage() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientAccessControl->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientAccessControl->Oa4mpClientCoOidcClient->admin($clientId);

    // Pull the available groups.
    $args = array();
    $args['conditions']['ManageCoGroup.co_id'] = $admin['Oa4mpClientCoAdminClient']['co_id'];
    $args['conditions']['ManageCoGroup.status'] = SuspendableStatusEnum::Active;
    $args['order'] = array('ManageCoGroup.name ASC');
    $args['contain'] = false;

    $availableGroups = $this->Oa4mpClientAccessControl
                            ->Oa4mpClientCoOidcClient
                            ->Oa4mpClientCoAdminClient
                            ->ManageCoGroup
                            ->find("list", $args);

    // If the user is not a platform admin or CO admin, remove the groups that the user is not a member of.
    $roles = $this->Role->calculateCMRoles();
    if(empty($roles['cmadmin']) && empty($roles['coadmin'])) {
      $coPersonId = $this->Session->read('Auth.User.co_person_id');

      foreach(array_keys($availableGroups) as $groupId) {
        $isMember = $this->Oa4mpClientAccessControl
                         ->Oa4mpClientCoOidcClient
                         ->Oa4mpClientCoAdminClient
                         ->ManageCoGroup
                         ->CoGroupMember
                         ->isMember($groupId, $coPersonId);
        if(!$isMember) {
          unset($availableGroups[$groupId]);
        }
      }
    }

    // Add blank option to allow unsetting the group
    $availableGroupsWithBlank = array('' => _txt('pl.oa4mp_client_access_control.fd.co_group_id.select.empty')) + $availableGroups;

    $this->set('vv_available_groups', $availableGroupsWithBlank);

    // A post that fails validation, kept for the form the GET logic renders.
    $rejected = null;
    $rejectedErrors = array();

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Validate before anything is sent to the OA4MP server, and send the
      // data validation leaves behind. Input the plugin rejects used to be
      // stored by the server and not by the plugin, leaving the two out of
      // sync.
      $this->request->data = $this->accessControlDataForStoredClient($client, $this->request->data);

      $this->Oa4mpClientAccessControl->create(false);
      $this->Oa4mpClientAccessControl->set($this->request->data);

      $valid = $this->Oa4mpClientAccessControl->validates();

      // Only a group the page offered, or none, may be chosen. The model only
      // checks the id is a number, so a hand-built request could hand the
      // client's editing to any group, including another CO's
      // (cilogon/Oa4mpClient#54).
      $postedGroup = $this->request->data['Oa4mpClientAccessControl']['co_group_id'] ?? null;
      if(array_key_exists('co_group_id', $this->request->data['Oa4mpClientAccessControl'])
         && !$this->groupWasOffered($postedGroup, $availableGroups)) {
        $this->log("Oa4mpClient: the access control of OIDC client " . $clientId . " refused posted group "
                   . (is_scalar($postedGroup) ? substr((string)$postedGroup, 0, 32) : gettype($postedGroup)));

        if(empty($this->Oa4mpClientAccessControl->validationErrors['co_group_id'])) {
          $this->Oa4mpClientAccessControl->invalidate('co_group_id', _txt('pl.oa4mp_client_access_control.er.co_group_id.offered'));
        }
        $valid = false;
      }

      if(!$valid) {
        // Set flash and fall through to the GET logic, which renders the form
        // again with the validation error. Nothing was sent to the server.
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));

        // Keep the input and errors now: the GET logic replaces the request
        // data and may save the client, so they cannot be read back later.
        $rejected = $this->request->data['Oa4mpClientAccessControl'] ?? array();
        $rejectedErrors = $this->Oa4mpClientAccessControl->validationErrors;
      } else {
        $this->request->data['Oa4mpClientAccessControl'] = $this->Oa4mpClientAccessControl->data['Oa4mpClientAccessControl'];

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
          if(!$this->Oa4mpClientAccessControl->save($this->request->data)) {
            $this->Flash->set(_txt('pl.oa4mp_client_access_control.er.edit.save'), array('key' => 'error'));
          } else {
            $this->Flash->set(_txt('pl.oa4mp_client_access_control.manage.flash.success'), array('key' => 'success'));
          }

          // Redirect to the manage view.
          $args = array();
          $args['plugin'] = 'oa4mp_client';
          $args['controller'] = 'oa4mp_client_access_controls';
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
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Update oa4mp_server_extra if the OA4MP server returned different extra keys.
    $currentExtra = $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $serverExtra = $verifyResult['oa4mp_server_extra'] ?? null;
    if($serverExtra !== $currentExtra) {
      $this->Oa4mpClientAccessControl->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientAccessControl->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->request->data = $client;

    // A post that failed validation stays in the form over the stored client,
    // which the view still reads for the client id and name, so the subscriber
    // can see and correct what was rejected.
    if($rejected !== null) {
      $this->request->data['Oa4mpClientAccessControl'] = $rejected;
      $this->Oa4mpClientAccessControl->validationErrors = $rejectedErrors;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_access_control.manage.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));
  }

  /**
   * Rebuild a posted access control around the stored client.
   *
   * The form carries the access control row's id and client id as hidden
   * fields, and the save writes whatever row its data names, so a hand-built
   * post could change another client's editor group (cilogon/Oa4mpClient#54).
   * The row id and client id come from the stored client and only the group
   * is taken from the post; any other posted column is dropped.
   *
   * @since  COmanage Registry v4.5.0
   * @param  array $client The stored client, as current() returns it
   * @param  array $posted The posted request data
   * @return array The data the manage action checks, sends, and saves
   */

  protected function accessControlDataForStoredClient($client, $posted) {
    $postedControl = (isset($posted['Oa4mpClientAccessControl']) && is_array($posted['Oa4mpClientAccessControl']))
                     ? $posted['Oa4mpClientAccessControl']
                     : array();

    $control = array();

    // With no stored access control row one is created for this client.
    if(!empty($client['Oa4mpClientAccessControl']['id'])) {
      $control['id'] = $client['Oa4mpClientAccessControl']['id'];
    }
    $control['client_id'] = $client['Oa4mpClientCoOidcClient']['id'];

    if(array_key_exists('co_group_id', $postedControl)) {
      $control['co_group_id'] = $postedControl['co_group_id'];
    }

    return array('Oa4mpClientAccessControl' => $control);
  }

  /**
   * Whether a posted editor group is one the manage page offered, or blank to
   * clear the group.
   *
   * @since  COmanage Registry v4.5.0
   * @param  mixed $groupId         The posted co_group_id
   * @param  array $availableGroups The groups the page offered, keyed by id
   * @return boolean True if the group may be saved
   */

  protected function groupWasOffered($groupId, $availableGroups) {
    if($groupId === null || $groupId === '') {
      return true;
    }

    if(!is_scalar($groupId)) {
      return false;
    }

    foreach(array_keys($availableGroups) as $offered) {
      if((string)$offered === (string)$groupId) {
        return true;
      }
    }

    return false;
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
      $oidcClient = $this->Oa4mpClientAccessControl->Oa4mpClientCoOidcClient->current($clientId);
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