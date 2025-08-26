<?php
/**
 * COmanage Registry Oa4mpClientAuthz Component
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

App::uses("Oa4mpClientCoOidcClient", "Oa4mpClient.Model");
 
class Oa4mpClientAuthzComponent extends Component {
  public $components = array("Session", "Role");

  /**
   * Determine if an authorization group exists for the client.
   *
   * @param array $params The parameters passed to the controller.
   * @return boolean True if an authorization group exists for the client, false otherwise.
   */

  private function clientAuthzGroupExists($params) {
    $clientId = null;
    $clientAuthzGroupExists = false;

    if(!empty($params['pass'][0])) {
      $clientId = $params['pass'][0];
    } else {
      $clientId = $params['named']['clientid'];
    }

    if(!empty($clientId)) {
      $Oa4mpClientCoOidcClient = new Oa4mpClientCoOidcClient();
      $currentClient = $Oa4mpClientCoOidcClient->current($clientId);
      $clientAuthzGroupExists = !empty($currentClient['Oa4mpClientAccessControl']['co_group_id']);
    }

    return $clientAuthzGroupExists;
  }

  /**
   * Determine if the user is an OA4MP admin.
   *
   * @return boolean True if the user is an OA4MP admin, false otherwise.
   */

  private function isOa4mpAdmin() {
    $oa4mpAdminsString = getenv('COMANAGE_REGISTRY_OA4MP_ADMIN_USERS');

    if($oa4mpAdminsString) {
      $oa4mpAdmins = explode(',', $oa4mpAdminsString);
    } else {
      $oa4mpAdmins = array();
    }

    $username = $this->Session->check('Auth.User.username') ?? null;

    if(in_array($username, $oa4mpAdmins)) {
      $oa4mpAdmin = true;
    } else {
      $oa4mpAdmin = false;
    }

    return $oa4mpAdmin;
  }

  /**
   * Determine if the user is a manager.
   *
   * @param integer $coId The CO ID.
   * @param integer $coPersonId The CO person ID.
   * @return boolean True if the user is a manager, false otherwise.
   */

  private function isManager($coId, $coPersonId) {
    // Managers are members of the delegated management group
    // and can create new clients. A manager can also edit
    // an existing client unless the client has an authorization
    // group linked to the client and the manager is not a member
    // of that group.
    $manager = false;

    $args = array();
    $args['conditions']['Oa4mpClientCoAdminClient.co_id'] = $coId;
    $args['contain'] = false;

    $Oa4mpClientCoOidcClient = new Oa4mpClientCoOidcClient();
    $adminClients = $Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->find('all', $args);

    foreach($adminClients as $adminClient) {
      $manageGroupId = $adminClient['Oa4mpClientCoAdminClient']['manage_co_group_id'];

      if(!empty($coPersonId) && !empty($manageGroupId)){
        if($this->Role->isCoGroupMember($coPersonId, $manageGroupId)){
          $manager = true;
          break;
        }
      }
    }

    return $manager;
  }

  /**
   * Determine if the user is an editor.
   *
   * @param array $params The parameters passed to the controller.
   * @param integer $coPersonId The CO person ID.
   * @return boolean True if the user is an editor, false otherwise.
   */

  private function isEditor($params, $coPersonId) {
    $clientId = null;
    $editor = false;

    if(!empty($params['pass'][0])) {
      $clientId = $params['pass'][0];
    } else {
      $clientId = $params['named']['clientid'];
    }

    if(!empty($clientId)) {
      $Oa4mpClientCoOidcClient = new Oa4mpClientCoOidcClient();
      $currentClient = $Oa4mpClientCoOidcClient->current($clientId);
      if(!empty($currentClient['Oa4mpClientAccessControl']['co_group_id'])) {
        $editor = $this->Role->isCoGroupMember($coPersonId, $currentClient['Oa4mpClientAccessControl']['co_group_id']);
      }
    }

    return $editor;
  }

  /**
   * Determine the permission set for the user.
   *
   * @param integer $coId The CO ID.
   * @param integer $coPersonId The CO person ID.
   * @param array $roles The roles of the user.
   * @param array $params The parameters passed to the controller.
   * @return array The permission set for the user.
   */

  public function permissionSet($coId, $coPersonId, $roles, $params) {
    $p = array();

    $p['oa4mp_admin'] = $this->isOa4mpAdmin();

    $manager = $this->isManager($coId, $coPersonId);

    $editor = $this->isEditor($params, $coPersonId);

    $clientAuthzGroupExists = $this->clientAuthzGroupExists($params);

    // Platform admins, CO admins, and managers can add new clients.
    $p['add'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager);

    // TODO
    $p['select_admin'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager);

    // Platform admins, CO admins, managers (if no authorization group exists), 
    // and editors (if an authorization group exists) can delete an existing OIDC client.
    $p['delete'] = ($roles['cmadmin'] || $roles['coadmin'] || ($manager && !$clientAuthzGroupExists)) || ($editor && $clientAuthzGroupExists);
    
    // Platform admins, CO admins, managers (if no authorization group exists), 
    // and editors (if an authorization group exists) can edit an existing OIDC client.
    $p['edit'] = ($roles['cmadmin'] || $roles['coadmin'] || ($manager && !$clientAuthzGroupExists)) || ($editor && $clientAuthzGroupExists);

    // Platform admins, CO admins, managers (if no authorization group exists), 
    // and editors (if an authorization group exists) can edit the scopes for an existing OIDC client.
    $p['edit_scopes'] = ($roles['cmadmin'] || $roles['coadmin'] || ($manager && !$clientAuthzGroupExists)) || ($editor && $clientAuthzGroupExists);

    // Platform admins, CO admins, managers, and editors can view the list of existing OIDC clients.
    // The index action filters the list of clients to display in the index view.
    $p['index'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager || $editor);

    // Platform admins, CO admins, managers (if no authorization group exists), 
    // and editors (if an authorization group exists) can manage refresh tokens, access tokens,
    // authorization, and editors
    $p['manage'] = ($roles['cmadmin'] || $roles['coadmin'] || ($manager && !$clientAuthzGroupExists)) || ($editor && $clientAuthzGroupExists);
    
    // Note that the function verifyRequestedId() checks that the
    // passed OIDC client ID belongs to the CO and prevents cross-CO
    // manipulation.

    return $p;
  }
}