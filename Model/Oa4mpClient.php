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
        _txt('pl.oa4mp_client.menu.cmp') => array('controller' => 'oa4mp_client_co_admin_clients',
                                                  'action' => 'index')
      ),
      "coconfig" => array(
        _txt('pl.oa4mp_client.menu.coconfig') => array('controller' => 'oa4mp_client_co_oidc_clients',
                                                       'action' => 'index',
                                                       'icon' => 'settings_applications')
      )
    );

    $coPersonId = CakeSession::read('Auth.User.co_person_id');

    // If the coPersonId is known query to find the coId, and then
    // the admin client if one is configured.
    if(!empty($coPersonId)) {
      $coPersonModel = ClassRegistry::init('CoPerson');
      $args = array();
      $args['conditions']['CoPerson.id'] = $coPersonId;
      $args['contain'] = 'CoGroupMember';
      $coPerson = $coPersonModel->find('first', $args);
      $coId = $coPerson['CoPerson']['co_id'];

      $adminClientModel = ClassRegistry::init('Oa4mpClient.Oa4mpClientCoAdminClient');
      $args = array();
      $args['conditions']['Oa4mpClientCoAdminClient.co_id'] = $coId;
      $args['contain'] = false;
      $adminClient = $adminClientModel->find('first', $args);

      // If an admin client is configured and a delegated management group
      // is set, determine if the coPersonId is a member of the group.
      if(isset($adminClient['Oa4mpClientCoAdminClient']['manage_co_group_id'])) {
        $manageCoGroupId = $adminClient['Oa4mpClientCoAdminClient']['manage_co_group_id'];

        $args = array();
        $args['conditions'][]['CoGroupMember.co_group_id'] = $manageCoGroupId;
        $args['conditions'][]['CoGroupMember.co_person_id'] = $coPersonId;
        $args['conditions'][]['CoGroupMember.member'] = true;
        $args['conditions'][] = 'CoGroupMember.deleted IS NOT TRUE';
        $args['conditions'][] = 'CoGroupMember.co_group_member_id IS NULL';
        // Only pull currently valid group memberships
        $args['conditions']['AND'][] = array(
          'OR' => array(
            'CoGroupMember.valid_from IS NULL',
            'CoGroupMember.valid_from < ' => date('Y-m-d H:i:s', time())
          )
        );
        $args['conditions']['AND'][] = array(
          'OR' => array(
            'CoGroupMember.valid_through IS NULL',
            'CoGroupMember.valid_through > ' => date('Y-m-d H:i:s', time())
          )
        );
        $args['contain'] = false;
        $memberships = $coPersonModel->CoGroupMember->find('first', $args);

        if(!empty($memberships)) {
          // The user is a member of the delegated management group so
          // display a link in the comain menu to manage clients.
          $menus["comain"] = array(
            _txt('pl.oa4mp_client.menu.coconfig') => array('controller' => 'oa4mp_client_co_oidc_clients',
                                                           'action' => 'index', 'icon' => 'settings_applications')
          );
        }
      }
    }

    return $menus;
  }
}
