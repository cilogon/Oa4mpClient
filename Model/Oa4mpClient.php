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
    $username = CakeSession::read('Auth.User.username');

    return $this->menusFor($menus, $coPersonId, $username);
  }

  /**
   * Add the main-menu OIDC Clients link to $menus when the logged-in person
   * should have it.
   *
   * @param  Array   $menus      Base menus from cmPluginMenus()
   * @param  Integer $coPersonId CO Person ID from the session, or null
   * @param  String  $username   Login username from the session, or null
   * @return Array   The menus, with a "comain" entry when the link applies
   */

  public function menusFor($menus, $coPersonId, $username) {
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
            if($this->isActiveMembership($groupMember, $coPersonId)) {
              $menus["comain"] = $this->mainMenuLink();
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
              if($this->isActiveMembership($groupMember, $coPersonId)) {
                $menus["comain"] = $this->mainMenuLink();
                break;
              }
            }
          }
        }
      }

      // Platform admins get the link in every CO. When a platform admin views
      // a CO they are not a member of, the session holds their COmanage CO
      // person, so the CO on screen cannot be known here. Core adds that CO
      // to the link, so it still opens the right page.
      //
      // CO admins get the link in their CO when it has an OIDC admin client.
      if($this->isPlatformAdmin($username) ||
         (!empty($adminClients) && $this->isCoAdminsMember($coPersonId))) {
        $menus["comain"] = $this->mainMenuLink();
      }

    return $menus;
  }

  /**
   * The main-menu OIDC Clients entry.
   *
   * @return Array Menu entry keyed by its label
   */

  private function mainMenuLink() {
    return array(
      _txt('pl.oa4mp_client.menu.coconfig') => array('controller' => 'oa4mp_client_co_oidc_clients',
                                                     'action' => 'index', 'icon' => 'settings_applications')
    );
  }

  /**
   * Whether a contained CoGroupMember row is a current membership of
   * $coPersonId: a member, not deleted, not an archived row, and within its
   * validity dates.
   *
   * @param  Array   $groupMember CoGroupMember row
   * @param  Integer $coPersonId  CO Person ID
   * @return Boolean
   */

  private function isActiveMembership($groupMember, $coPersonId) {
    return $groupMember['co_person_id'] == $coPersonId &&
           $groupMember['member'] == true &&
           $groupMember['deleted'] == false &&
           empty($groupMember['co_group_member_id']) &&
           (date('Y-m-d H:i:s', time()) >= $groupMember['valid_from'] || $groupMember['valid_from'] == null) &&
           (date('Y-m-d H:i:s', time()) <= $groupMember['valid_through'] || $groupMember['valid_through'] == null);
  }

  /**
   * Whether $coPersonId is a current member of their CO's own admins group
   * (no COU). Mirrors core's RoleComponent group check for CO admins.
   *
   * @param  Integer $coPersonId CO Person ID
   * @return Boolean
   */

  private function isCoAdminsMember($coPersonId) {
    $now = date('Y-m-d H:i:s', time());

    $CoGroup = ClassRegistry::init('CoGroup');

    $args = array();
    $args['joins'][0]['table'] = 'co_group_members';
    $args['joins'][0]['alias'] = 'CoGroupMember';
    $args['joins'][0]['type'] = 'INNER';
    $args['joins'][0]['conditions'][0] = 'CoGroup.id=CoGroupMember.co_group_id';
    // The join bypasses ChangelogBehavior, so exclude those rows by hand.
    $args['conditions'][] = 'CoGroupMember.deleted IS NOT TRUE';
    $args['conditions'][] = 'CoGroupMember.co_group_member_id IS NULL';
    $args['conditions']['CoGroup.group_type'] = GroupEnum::Admins;
    $args['conditions']['CoGroup.cou_id'] = null;
    $args['conditions']['CoGroup.status'] = StatusEnum::Active;
    $args['conditions']['CoGroupMember.co_person_id'] = $coPersonId;
    $args['conditions']['CoGroupMember.member'] = true;
    $args['conditions']['AND'][] = array('OR' => array('CoGroupMember.valid_from IS NULL',
                                                       'CoGroupMember.valid_from <' => $now));
    $args['conditions']['AND'][] = array('OR' => array('CoGroupMember.valid_through IS NULL',
                                                       'CoGroupMember.valid_through >' => $now));
    $args['contain'] = false;

    return $CoGroup->find('count', $args) > 0;
  }

  /**
   * Whether $username belongs to a platform admin: a current member of the
   * COmanage CO's admins group, found the way core's
   * RoleComponent::identifierIsCmpAdmin() finds it.
   *
   * @param  String $username Login username, or null
   * @return Boolean
   */

  private function isPlatformAdmin($username) {
    if(empty($username)) {
      return false;
    }

    $CoPerson = ClassRegistry::init('CoPerson');

    try {
      $coPersonIds = $CoPerson->idsForIdentifier($username, null, true);
    }
    catch(Exception $e) {
      // No active login identifier matches. This runs on every page, so it
      // must not throw.
      return false;
    }

    if(empty($coPersonIds)) {
      return false;
    }

    $args = array();
    $args['joins'][0]['table'] = 'cos';
    $args['joins'][0]['alias'] = 'Co';
    $args['joins'][0]['type'] = 'INNER';
    $args['joins'][0]['conditions'][0] = 'CoPerson.co_id=Co.id';
    $args['conditions']['Co.name'] = DEF_COMANAGE_CO_NAME;
    $args['conditions']['Co.status'] = TemplateableStatusEnum::Active;
    $args['conditions']['CoPerson.id'] = $coPersonIds;
    $args['contain'] = false;

    $comanagePerson = $CoPerson->find('first', $args);

    if(empty($comanagePerson['CoPerson']['id'])) {
      return false;
    }

    return $this->isCoAdminsMember($comanagePerson['CoPerson']['id']);
  }
}