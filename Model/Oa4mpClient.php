<?php
/**
 * COmanage Registry Oa4mp Client Model
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
 * @package       registry-plugin
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClient extends AppModel {
  // Required by COmanage Plugins
  public $cmPluginType = "other";

  /**
   * Expose menu items.
   *
   * @since COmanage Registry 2.0.1
   * @return Array with menu location type as key array of labels, controllers, actions as values.
   */

  public function cmPluginMenus() {
    
    $menus = array(
      "cmp" => array(
        _txt('pl.oa4mp_client.menu.admin_clients.cmp') => array('controller' => 'oa4mp_client_co_admin_clients',
                                                  'action' => 'index'),
        _txt('pl.oa4mp_client.menu.named_configs.cmp') => array('controller' => 'oa4mp_client_co_named_configs',
                                                  'action' => 'index')
      ),
      "coconfig" => array(
        _txt('pl.oa4mp_client.menu.coconfig') => array('controller' => 'oa4mp_client_co_oidc_clients',
                                                       'action' => 'index',
                                                       'icon' => 'settings_applications')
      )
    );

    $coPersonId = CakeSession::read('Auth.User.co_person_id');

    if(empty($coPersonId)) {
      return $menus;
    }

    // If the coPersonId is known query to find the coId, and then
    // the admin clients if any are configured.
      $coPersonModel = ClassRegistry::init('CoPerson');
      $args = array();
      $args['conditions']['CoPerson.id'] = $coPersonId;
      $args['contain'] = 'CoGroupMember';
      $coPerson = $coPersonModel->find('first', $args);
      $coId = $coPerson['CoPerson']['co_id'];

      $adminClientModel = ClassRegistry::init('Oa4mpClient.Oa4mpClientCoAdminClient');
      $args = array();
      $args['conditions']['Oa4mpClientCoAdminClient.co_id'] = $coId;
      $args['contain'] = array();
      $args['contain']['ManageCoGroup'] = 'CoGroupMember';
      $args['contain']['Oa4mpClientCoOidcClient']['Oa4mpClientAccessControl']['CoGroup'] = 'CoGroupMember';
      $adminClients = $adminClientModel->find('all', $args);

      // Loop through the admin clients and check if the coPersonId is a member of the manage group
      // and if so, add the menu item to the comain menu.
      foreach($adminClients as $adminClient) {
        if(!empty($adminClient['Oa4mpClientCoAdminClient']['manage_co_group_id'])) {
          $manageCoGroup = $adminClient['ManageCoGroup'];
          foreach($manageCoGroup['CoGroupMember'] as $groupMember) {
            if($groupMember['co_person_id'] == $coPersonId &&
               $groupMember['member'] == true &&
               $groupMember['deleted'] == false &&
               empty($groupMember['co_group_member_id']) &&
               (date('Y-m-d H:i:s', time()) >= $groupMember['valid_from'] || $groupMember['valid_from'] == null) &&
               (date('Y-m-d H:i:s', time()) <= $groupMember['valid_through'] || $groupMember['valid_through'] == null)) {
              $menus["comain"] = array(
                _txt('pl.oa4mp_client.menu.coconfig') => array('controller' => 'oa4mp_client_co_oidc_clients',
                                                               'action' => 'index', 'icon' => 'settings_applications')
              );
              break;
            }
          }
        }
      }

      // Loop through the admin clients and check if the coPersonId is a member of the access control group
      // for any of the OIDC clients that have an access control group set.
      // If so, add the menu item to the comain menu.
      foreach($adminClients as $adminClient) {
        foreach($adminClient['Oa4mpClientCoOidcClient'] as $oidcClient) {
          if(!empty($oidcClient['Oa4mpClientAccessControl']['co_group_id'])) {
            $coGroup = $oidcClient['Oa4mpClientAccessControl']['CoGroup'];
            foreach($coGroup['CoGroupMember'] as $groupMember) {
              if($groupMember['co_person_id'] == $coPersonId &&
                 $groupMember['member'] == true &&
                 $groupMember['deleted'] == false &&
                 empty($groupMember['co_group_member_id']) &&
                 (date('Y-m-d H:i:s', time()) >= $groupMember['valid_from'] || $groupMember['valid_from'] == null) &&
                 (date('Y-m-d H:i:s', time()) <= $groupMember['valid_through'] || $groupMember['valid_through'] == null)) {
                $menus["comain"] = array(
                  _txt('pl.oa4mp_client.menu.coconfig') => array('controller' => 'oa4mp_client_co_oidc_clients',
                                                                 'action' => 'index', 'icon' => 'settings_applications')
                );
                break;
              }
            }
          }
        }
      }

    return $menus;
  }
}