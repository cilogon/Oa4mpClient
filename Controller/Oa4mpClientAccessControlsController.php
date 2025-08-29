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
   * Manage an access control configuration.
   *
   * @since  COmanage Registry v4.5.0
   * @return null
   */

  function manage() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = new Oa4mpClientOa4mpServer();

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
        $ret = $this->Oa4mpClientAccessControl->save($this->request->data);

        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_access_control.manage.flash.success'), array('key' => 'success'));

        // Redirect to the manage view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_access_controls';
        $args['action'] = 'manage';
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

    $this->request->data = $client;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_access_control.manage.name',
               array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));
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