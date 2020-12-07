<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Clients Controller
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
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("StandardController", "Controller");
App::uses("HttpSocket", "Network/Http");

class Oa4mpClientCoOidcClientsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoOidcClients";

  public $uses = array('Oa4mpClient.Oa4mpClientCoOidcClient');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientCoOidcClient.name' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  public $edit_contains = array(
    'Oa4mpClientCoAdminClient',
    'Oa4mpClientCoCallback' => array(
      'order' => 'Oa4mpClientCoCallback.id'
    ),
    'Oa4mpClientCoScope' => array(
      'order' => 'Oa4mpClientCoScope.id'
    ),
    'Oa4mpClientCoLdapConfig' => array(
      'Oa4mpClientCoSearchAttribute' => array(
        'order' => 'Oa4mpClientCoSearchAttribute.id'
      )
    )
  );

  /**
   * Add an OIDC client.
   *
   * @since COmanage Registry 2.0.1
   */

  function add() {

    $this->set('title_for_layout', _txt('op.add.new', array(_txt('ct.oa4mp_client_co_oidc_clients.1'))));

    // Process POST data
    if($this->request->is('post')) {
      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there any validation 
        // error so just return.
        return;
      }

      // If there are no search attribute mappings then remove entirely the necessary
      // parts of the input data.
      if(empty($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'])) {
        unset($data['Oa4mpClientCoLdapConfig'][0]);
      } 

      // Use the CO ID to find the admin client, which is needed in the call to
      // the Oa4mp server. 
      $args = array();
      $args['conditions']['Oa4mpClientCoAdminClient.co_id'] = $this->cur_co['Co']['id'];
      $args['contain'] = false;
      $adminClient = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->find('first', $args);

      // Set the admin client ID.
      $data['Oa4mpClientCoOidcClient']['admin_id'] = $adminClient['Oa4mpClientCoAdminClient']['id'];

      // Call out to Oa4mp server to create the new client.
      $newClient = $this->oa4mpNewClient($adminClient, $data);

      if(empty($newClient)) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.create_error'), array('key' => 'error'));
        return;
      }
      
      // Set the client ID returned by the oa4mp server so it is saved and also
      // set a view variable so it can be displayed. The client secret is also
      // set as a view variable so it can be displayed but it is NOT saved.
      $data['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $newClient['clientId'];
      $this->set('vv_client_id', $newClient['clientId']);
      $this->set('vv_client_secret', $newClient['secret']);
      
      // Save the client and associated data.
      $args = array();
      $args['validate'] = false;
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoOidcClient->saveAssociated($data, $args);

      if(!$ret) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return;
      }

      // Render the view to show the new client ID and secret.
      $this->render('secret');

    } else {
      // Process GET request.

      // Use the CO ID to find the admin client and the default LDAP configuration.
      $args = array();
      $args['conditions'] = array('co_id' => $this->cur_co['Co']['id']);
      $args['contain'] = array('DefaultLdapConfig');

      $ret = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->find('first', $args);
      $defaultLdapConfig = $ret['DefaultLdapConfig'];

      $ldapConfig = array();
      $ldapConfig['enabled'] = true;
      $ldapConfig['authorization_type'] = 'simple';
      $ldapConfig['serverurl'] = $defaultLdapConfig['serverurl'];
      $ldapConfig['binddn'] = $defaultLdapConfig['binddn'];
      $ldapConfig['password'] = $defaultLdapConfig['password'];
      $ldapConfig['basedn'] = $defaultLdapConfig['basedn'];
      $ldapConfig['search_name'] = 'username';

      $this->request->data['Oa4mpClientCoLdapConfig'][0] = $ldapConfig;

      parent::add();
    }
  }

  /**
   * Determine the CO ID based on some attribute of the request.
   * This overrides the method defined in AppController.php
   *
   * @since  COmanage Registry 2.0.1
   * @param  Array $data Array of data 
   * @return Integer CO ID, or null if not implemented or not applicable.
   */
  
  protected function calculateImpliedCoId($data = null) {
    $coId =  null;

    if($this->action == 'edit'
       || $this->action == 'view'
       || $this->action == 'delete') {

       $id = $this->request->pass[0];

       $args = array();
       $args['conditions']['Oa4mpClientCoOidcClient.id'] = $id;
       $args['contain'] = 'Oa4mpClientCoAdminClient';

       $found = $this->Oa4mpClientCoOidcClient->find('first', $args);

       if(isset($found['Oa4mpClientCoAdminClient']['co_id'])) {
         $coId = $found['Oa4mpClientCoAdminClient']['co_id']; 
       }
    }

   return $coId; 
  }

  /**
   * Delete an OIDC client.
   *
   * @since COmanage Registry 2.0.1
   * @param Integer OIDC client ID.
   */
  function delete($id) {
    if(!isset($id) || $id < 1) {
      $this->Flash->set(_txt('er.notprov.id', array($this->modelClass)), array('key' => 'error'));
      return;
    }

    // Find the current data.
    $args = array();
    $args['conditions']['Oa4mpClientCoOidcClient.id'] = $id;
    $args['contain'] = 'Oa4mpClientCoAdminClient';

    $client = $this->Oa4mpClientCoOidcClient->find('first', $args);
    if(empty($client)) {
      $this->Flash->set(_txt('er.notfound', array($this->modelClass, $id)), array('key' => 'error'));
      return;
    }

    // Call out to the oa4mp server to delete the client. If unable to delete
    // the client display an error and render index view again.
    
    // The repeat of the argument $client below is correct because the
    // result of the find() above with the contain includes both the
    // OIDC client and admin client objects/arrays.
    if(!$this->oa4mpDeleteClient($client, $client)) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.delete_error'), array('key' => 'error'));

      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];

      $this->redirect($args);
    }

    // Delete the client from the database.
    if($this->Oa4mpClientCoOidcClient->delete($id)) {
      $name = $client['Oa4mpClientCoOidcClient']['name'];
      $this->Flash->set(_txt('er.deleted-a', array(filter_var($name,FILTER_SANITIZE_SPECIAL_CHARS))), array('key' => 'success'));

      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];

      $this->redirect($args);
    }
  }


  /**
   * Edit an OIDC client.
   *
   * @since COmanage Registry 2.0.1
   */

  function edit($id) {
    // Pull the current data.
    $args = array();
    $args['conditions']['Oa4mpClientCoOidcClient.id'] = $id;
    $args['contain'] = $this->edit_contains;

    $curdata = $this->Oa4mpClientCoOidcClient->find('first', $args);

    if(empty($curdata)) {
      $this->Flash->set(_txt('er.notfound', array(_txt('ct.oa4mp_client_co_oidc_clients.1'), $id)), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
    $synchronized = $this->oa4mpVerifyClient($curdata, $curdata);
    if(!$synchronized) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Set the title for the view.
    $this->set('title_for_layout', _txt('op.edit-a', array(filter_var($curdata['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    // PUT request
    if($this->request->is(array('post','put'))) {

      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there any validation 
        // error so just return.
        return;
      }

      // If there are no search attribute mappings then remove entirely the necessary
      // parts of the input data.
      if(empty($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'])) {
        unset($data['Oa4mpClientCoLdapConfig'][0]);
      } 

      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $this->oa4mpEditClient($curdata, $curdata, $data);
      if($ret == 0) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
        return;
      } elseif($ret == 2) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
        return;
      }

      // Make sure the ID is set for the OIDC Client model.
      $data['Oa4mpClientCoOidcClient']['id'] = $curdata['Oa4mpClientCoOidcClient']['id'];

      // saveAssociated will not delete a callback that is no longer
      // in the submitted form data but is in the current data so
      // delete it directly.
      foreach($curdata['Oa4mpClientCoCallback'] as $current_cb) {
        $delete = true;
        foreach($data['Oa4mpClientCoCallback'] as $data_cb) {
          if(!empty($data_cb['id']) && ($data_cb['id'] == $current_cb['id'])) {
            $delete = false;
          }
        }
        if($delete) {
          $this->Oa4mpClientCoOidcClient->Oa4mpClientCoCallback->delete($current_cb['id']);
        }
      }

      // saveAssociated will not delete a scope that is no longer
      // in the submitted form data but is in the current data so
      // delete it directly.
      foreach($curdata['Oa4mpClientCoScope'] as $current_scope) {
        $delete = true;
        foreach($data['Oa4mpClientCoScope'] as $data_scope) {
          if(!empty($data_scope['id']) && ($data_scope['id'] == $current_scope['id'])) {
            $delete = false;
          }
        }
        if($delete) {
          $this->Oa4mpClientCoOidcClient->Oa4mpClientCoScope->delete($current_scope['id']);
        }
      }

      // saveAssociated will not delete LDAP config or search attributes
      // that are not in the submitted form data but are in the current
      // data so we need to delete them directly.

      // To aid in deleting create an array for the current LDAP
      // configs where the key is the current LDAP config database
      // row id and the value is an array whose values are the
      // database row ids for the associated search attributes.
      $curdata_ldap_config = array();
      if(array_key_exists('Oa4mpClientCoLdapConfig', $curdata)) {
        foreach($curdata['Oa4mpClientCoLdapConfig'] as $c) {
          $curdata_ldap_config[$c['id']] = array();
          if(array_key_exists('Oa4mpClientCoSearchAttribute', $c)) {
            foreach($c['Oa4mpClientCoSearchAttribute'] as $sa) {
              $curdata_ldap_config[$c['id']][] = $sa['id'];
            }
          }
        }
      }

      // To aid in deleting create an array for the submitted form
      // LDAP configs where the key is the LDAP config database
      // row id if present (because this is an edit operation), and
      // the value is an array whose values are the database row
      // ids (if present) for the associated search attributes.
      $data_ldap_config = array();
      if(array_key_exists('Oa4mpClientCoLdapConfig', $data)) {
        foreach($data['Oa4mpClientCoLdapConfig'] as $c) {
          if(array_key_exists('id', $c)) {
            $data_ldap_config[$c['id']] = array();
            if(array_key_exists('Oa4mpClientCoSearchAttribute', $c)) {
              foreach($c['Oa4mpClientCoSearchAttribute'] as $sa) {
                if(array_key_exists('id', $sa)) {
                  $data_ldap_config[$c['id']][] = $sa['id'];
                }
              }
            }
          }
        }
      }

      // Compare the current LDAP config and submitted form data
      // LDAP config using the auxiliary arrays created above.
      // Delete any search attributes in the current data that no
      // longer exist in the submitted form data, or the entire
      // LDAP config if necessary.
      foreach($curdata_ldap_config as $i => $c) {
        if(array_key_exists($i, $data_ldap_config)) {
          $sa_to_delete = array_diff($c, $data_ldap_config[$i]);
          foreach($sa_to_delete as $j) {
            $this->Oa4mpClientCoOidcClient->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->delete($j);
          }
        } else {
          $this->Oa4mpClientCoOidcClient->Oa4mpClientCoLdapConfig->delete($i, true);
        }
      }

      // Save the client and associated data. This will create new associated model
      // links for new models in the submitted form data.
      $args = array();
      $args['validate'] = false;
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoOidcClient->saveAssociated($data, $args);

      if(!$ret) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return;
      }

      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);

    } 

    // GET request

    $this->request->data = $curdata;

    // If the current data does not have an LDAP config
    // then add the default LDAP config in case the user
    // wants to add LDAP search attributes. 
    if(empty($curdata['Oa4mpClientCoLdapConfig'])) {
      // Use the CO ID to find the admin client and the default LDAP configuration.
      $args = array();
      $args['conditions'] = array('co_id' => $this->cur_co['Co']['id']);
      $args['contain'] = array('DefaultLdapConfig');

      $ret = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->find('first', $args);
      $defaultLdapConfig = $ret['DefaultLdapConfig'];

      $ldapConfig = array();
      $ldapConfig['enabled'] = true;
      $ldapConfig['authorization_type'] = 'simple';
      $ldapConfig['serverurl'] = $defaultLdapConfig['serverurl'];
      $ldapConfig['binddn'] = $defaultLdapConfig['binddn'];
      $ldapConfig['password'] = $defaultLdapConfig['password'];
      $ldapConfig['basedn'] = $defaultLdapConfig['basedn'];
      $ldapConfig['search_name'] = 'username';

      $this->request->data['Oa4mpClientCoLdapConfig'][0] = $ldapConfig;
    }

    // Need to re-order the scopes to fit our checkbox use of them
    // in the form.
    $newScopes = array();
    foreach($curdata['Oa4mpClientCoScope'] as $s) {
      switch ($s['scope']) {
        case Oa4mpClientScopeEnum::OpenId:
          $newScopes[0] = $s;
          break;
        case Oa4mpClientScopeEnum::Profile:
          $newScopes[1] = $s;
          break;
        case Oa4mpClientScopeEnum::Email:
          $newScopes[2] = $s;
          break;
        case Oa4mpClientScopeEnum::OrgCilogonUserInfo:
          $newScopes[3] = $s;
          break;
        case Oa4mpClientScopeEnum::Getcert:
          $newScopes[4] = $s;
          break;
      }
    }

    $this->request->data['Oa4mpClientCoScope'] = $newScopes;
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry 2.0.1
   * @return Array Permissions
   */
  
  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();
    
    // All operations require platform or CO administrator, or
    // membership in the delegated management group if set.
    $manager = false;
    if(!empty($this->cur_co['Co']['id'])) {
      $args = array();
      $args['conditions']['Oa4mpClientCoAdminClient.co_id'] = $this->cur_co['Co']['id'];
      $args['contain'] = false;
      $adminClient = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->find('first', $args);
      $manageGroupId = $adminClient['Oa4mpClientCoAdminClient']['manage_co_group_id'];

      $coPersonId = $this->Session->read('Auth.User.co_person_id');

      if(!empty($coPersonId)){
        if($this->Role->isCoGroupMember($coPersonId, $manageGroupId)){
          $manager = true;
        }
      }
    }

    // Add a new OIDC client?
    $p['add'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager);

    // Delete an existing OIDC client?
    $p['delete'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager);
    
    // Edit an existing OIDC client?
    $p['edit'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager);

    // View all existing OIDC clients?
    $p['index'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager);
    
    // View an existing OIDC client?
    $p['view'] = ($roles['cmadmin'] || $roles['coadmin'] || $manager); 
    
    $this->set('permissions', $p);
    return $p[$this->action];
  }

  /**
   * Determine if our representation of the client and the Oa4mp server
   * representation of the client is synchronized, in order to detect
   * if the client has been changed outside of this plugin.
   *
   * @since COmanage Registry 3.1.1
   */

  function isClientDataSynchronized($curData, $oa4mpServerData) {

    // Compare basic client details.
    $curClient = $curData['Oa4mpClientCoOidcClient'];
    $oa4mpClient = $oa4mpServerData['Oa4mpClientCoOidcClient'];

    if($curClient['oa4mp_identifier'] !== $oa4mpClient['oa4mp_identifier']) {
      $this->log("Oa4mpClientCoOidcClient oa4mp_identifier is out of sync");
      return false;
    }

    if($curClient['name'] !== $oa4mpClient['name']) {
      $this->log("Oa4mpClientCoOidcClient name is out of sync");
      return false;
    }

    if($curClient['proxy_limited'] != $oa4mpClient['proxy_limited']) {
      $this->log("Oa4mpClientCoOidcClient proxy_limited is out of sync");
      return false;
    }

    // The state where the OA4MP server has a refresh token lifetime of exactly
    // zero and our representation does not have a value is considered to be
    // synchronized.
    if($curClient['refresh_token_lifetime'] !== $oa4mpClient['refresh_token_lifetime']) {
      if(!(is_null($curClient['refresh_token_lifetime']) && ($oa4mpClient['refresh_token_lifetime'] === 0))) {
        $this->log("Oa4mpClientCoOidcClient refresh_token_lifetime is out of sync");
        return false;
      }
    }

    // Compare callbacks.
    $curCallbacks = array();
    $oa4mpCallbacks = array();

    foreach($curData['Oa4mpClientCoCallback'] as $key => $cb) {
      $curCallbacks[] = $cb['url'];
    }

    foreach($oa4mpServerData['Oa4mpClientCoCallback'] as $key => $cb) {
      $oa4mpCallbacks[] = $cb['url'];
    }

    sort($curCallbacks);
    sort($oa4mpCallbacks);

    if($curCallbacks != $oa4mpCallbacks) {
      $this->log("Oa4mpClientCoCallback callbacks are out of sync");
      return false;
    }

    // Compare scopes.
    $curScopes = array();
    $oa4mpScopes = array();

    foreach($curData['Oa4mpClientCoScope'] as $key => $s) {
      $curScopes[] = $s['scope'];
    }

    foreach($oa4mpServerData['Oa4mpClientCoScope'] as $key => $s) {
      $oa4mpScopes[] = $s['scope'];
    }

    sort($curScopes);
    sort($oa4mpScopes);

    if($curScopes != $oa4mpScopes) {
      $this->log("Oa4mpClientCoScope scopes are out of sync");
      return false;
    }

    // Compare LDAP configurations.
    if($curData['Oa4mpClientCoLdapConfig'] && !$oa4mpServerData['Oa4mpClientCoLdapConfig']) {
      $this->log("Oa4mpClientCoLdapConfig plugin has LDAP configuration but Oa4mp server does not");
      return false;
    }

    if(!$curData['Oa4mpClientCoLdapConfig'] && $oa4mpServerData['Oa4mpClientCoLdapConfig']) {
      $this->log("Oa4mpClientCoLdapConfig Oa4mp server has LDAP configuration but plugin does not");
      return false;
    }

    if($curData['Oa4mpClientCoLdapConfig'] && $oa4mpServerData['Oa4mpClientCoLdapConfig']) {
      if(count($curData['Oa4mpClientCoLdapConfig']) > 1) {
        $this->log("Oa4mpClientCoLdapConfig plugin has more than one LDAP configuration");
        return false;
      }
      if(count($oa4mpServerData['Oa4mpClientCoLdapConfig']) > 1) {
        $this->log("Oa4mpClientCoLdapConfig Oa4mp server has more than one LDAP configuration");
        return false;
      }

      $curLdap = $curData['Oa4mpClientCoLdapConfig'][0];
      $serLdap = $oa4mpServerData['Oa4mpClientCoLdapConfig'][0];

      if($curLdap['serverurl'] !== $serLdap['serverurl']) {
        $this->log("Oa4mpClientCoLdapConfig serverurl is out of sync");
        return false;
      }
      if($curLdap['binddn'] !== $serLdap['binddn']) {
        $this->log("Oa4mpClientCoLdapConfig binddn is out of sync");
        return false;
      }
      if($curLdap['password'] !== $serLdap['password']) {
        $this->log("Oa4mpClientCoLdapConfig password is out of sync");
        return false;
      }
      if($curLdap['basedn'] !== $serLdap['basedn']) {
        $this->log("Oa4mpClientCoLdapConfig basedn is out of sync");
        return false;
      }
      if($curLdap['search_name'] !== $serLdap['search_name']) {
        $this->log("Oa4mpClientCoLdapConfig search_name is out of sync");
        return false;
      }
      if($curLdap['authorization_type'] !== $serLdap['authorization_type']) {
        $this->log("Oa4mpClientCoLdapConfig authorization_type is out of sync");
        return false;
      }
      if($curLdap['enabled'] != $serLdap['enabled']) {
        $this->log("Oa4mpClientCoLdapConfig enabled is out of sync");
        return false;
      }

      // Compare search attribute configurations, making sure each of
      // the search attributes in the current data can be found in
      // the search atttributes returned by the Oam4mp server.

      foreach($curLdap['Oa4mpClientCoSearchAttribute'] as $cursa) {
        $found = false;
        foreach($serLdap['Oa4mpClientCoSearchAttribute'] as $sersa) {
          if($sersa['name'] == $cursa['name']) {
            if(($cursa['return_name'] == $sersa['return_name']) && ($cursa['return_as_list'] == $sersa['return_as_list'])) {
              $found = true;
              break;
            }
          }
        }
        if(!$found) {
          $name = $cursa['name'];
          $this->log("Oa4mpClientCoSearchAttribute search attribute $name is out of sync");
          return false;
        }
      }

      // Compare search attribute configurations, making sure each of
      // the search attributes returned by the Oa4mp server
      // can be found in the current data.

      foreach($serLdap['Oa4mpClientCoSearchAttribute'] as $key => $sersa) {
        $found = false;
        foreach($curLdap['Oa4mpClientCoSearchAttribute'] as $key => $cursa) {
          if($sersa['name'] == $cursa['name']) {
            if(($cursa['return_name'] == $sersa['return_name']) && ($cursa['return_as_list'] == $sersa['return_as_list'])) {
              $found = true;
              break;
            }
          }
        }

        if(!$found) {
          $name = $sersa['name'];
          $this->log("Oa4mpClientCoSearchAttribute search attribute $name is out of sync");
          return false;
        }
      }
    }

    return true;
  }

  /**
   * Delete an existing OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * 
   */
  function oa4mpDeleteClient($adminClient, $oidcClient) {
    $ret = false;

    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);
    $request['method'] = 'DELETE';

    $client_id = $oidcClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request['uri']['query'] = array('client_id' => $client_id);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . print_r(null, true));

    $response = $http->request($request);

    $this->log("Response is " . print_r($response, true));

    if($response->code == 204) {
      $ret = true;
    }

    return $ret;
  }

  /**
   * Edit an existing OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @return 1 if edit is successful, 0 if not, and 2 if detect client
   *         modified outside of this plugin
   */

  function oa4mpEditClient($adminClient, $curData, $data) {
    $ret = 0;

    // Check that the current client data is synchronized with the
    // server.
    $synchronized = $this->oa4mpVerifyClient($adminClient, $curData);
    if(!$synchronized) {
      return 2;
    }

    // The current data before edit and the current Oa4mp server respresentation
    // of the client agree so marshall the edited data and submit to
    // the Oa4mp server.
    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);
    $request['method'] = 'PUT';
    $client_id = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request['uri']['query'] = array('client_id' => $client_id);

    $body = $this->oa4mpMarshallContent($data);

    $request['body'] = json_encode($body);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . print_r($request['body'], true));

    $response = $http->request($request);

    $this->log("Response is " . print_r($response, true));

    if($response->code == 200) {
      $ret = 1;
    }

    return $ret;
  }

  /**
   * Initialize request for HttpSocket instance for oa4mp server invocation.
   *
   * @since COmanage Registry 2.0.1
   * @return Array array to be used with HttpSocket request() method.
   */
  function oa4mpInitializeRequest($adminClient) {
    $request = array();
    $request['method'] = 'GET';

    $parsedUrl = parse_url($adminClient['Oa4mpClientCoAdminClient']['serverurl']);
    $request['uri']['scheme'] = $parsedUrl['scheme'];
    $request['uri']['host']   = $parsedUrl['host'];
    $request['uri']['path']   = $parsedUrl['path'];

    $request['header']['Content-Type'] = 'application/json; charset=UTF-8';

    $aclientId = $adminClient['Oa4mpClientCoAdminClient']['admin_identifier'];
    $aclientSecret = $adminClient['Oa4mpClientCoAdminClient']['secret'];
    $bearerToken = base64_encode($aclientId . ":" . $aclientSecret);

    $request['header']['Authorization'] = "Bearer $bearerToken";

    return $request;
  }

  /**
   * Marshall Oa4mpClientCoOidcClient object for oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @return Array
   */
  function oa4mpMarshallContent($data) {
    $content = array();

    // Client metadata per RFC 7591.
    // https://tools.ietf.org/html/rfc7591#section-2
    if(!empty($data['Oa4mpClientCoCallback'])) {
      $content['redirect_uris'] = array();
      foreach($data['Oa4mpClientCoCallback'] as $cb) {
        $content['redirect_uris'][] = $cb['url'];
      }
    }

    $content['token_endpoint_auth_method'] = 'client_secret_basic';
    $content['grant_types'] = array();
    $content['grant_types'][] = 'authorization_code';
    $content['response_types'] = 'code';
    $content['client_name'] = $data['Oa4mpClientCoOidcClient']['name'];
    $content['client_uri']  = $data['Oa4mpClientCoOidcClient']['home_url'];

    // The model validation code will have already run so here
    // we can just use is_numeric() to test if we need to send
    // the refresh_token metadata.
    if(is_numeric($data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'])) {
      $content['grant_types'][] = 'refresh_token';
      $content['rt_lifetime'] = $data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'];
    }

    if(!empty($data['Oa4mpClientCoScope'])) {
      $scopeString = "";

      foreach($data['Oa4mpClientCoScope'] as $s) {
        $scopeString = $scopeString . " " . $s['scope'];
      }

      $scopeString = trim($scopeString);
      $content['scope'] = $scopeString;
    }

    // OA4MP extensions to the metadata not part of RFC 7591.
    $content['comment'] = _txt('pl.oa4mp_client_co_oidc_client.signature');

    if(!empty($data['Oa4mpClientCoLdapConfig'])) {
      $content['cfg'] = array();
      $content['cfg']['config'] = _txt('pl.oa4mp_client_co_oidc_client.signature');
      $content['cfg']['claims'] = array();
      $content['cfg']['claims']['sourceConfig'] = array();

      $ldap = array();

      // Concatenate the LDAP config server URL, the bind DN, and the
      // base DN and then SHA1 hash it to compute a name for the LDAP
      // configuration to be used with the Oa4mp server.
      $id = $data['Oa4mpClientCoLdapConfig'][0]['serverurl'];
      $id = $id . $data['Oa4mpClientCoLdapConfig'][0]['binddn'];
      $id = $id . $data['Oa4mpClientCoLdapConfig'][0]['basedn'];
      $id = sha1($id);

      $ldap['id'] = $id;
      
      if($data['Oa4mpClientCoLdapConfig'][0]['enabled']) {
        $ldap['enabled'] = 'true';
      } else {
        $ldap['enabled'] = 'false';
      }
      $ldap['authorizationType'] = $data['Oa4mpClientCoLdapConfig'][0]['authorization_type'];

      $parsedUrl = parse_url($data['Oa4mpClientCoLdapConfig'][0]['serverurl']);
      $ldap['address'] = $parsedUrl['host'];
      if(!empty($parsedUrl['port'])) {
        $ldap['port'] = $parsedUrl['port'];
      } 
      else if($parsedUrl['scheme'] == 'ldaps') {
        $ldap['port'] = 636;
      } else {
        $ldap['port'] = 389;
      }

      $ldap['principal'] = $data['Oa4mpClientCoLdapConfig'][0]['binddn'];
      $ldap['password'] = $data['Oa4mpClientCoLdapConfig'][0]['password'];
      $ldap['searchBase'] = $data['Oa4mpClientCoLdapConfig'][0]['basedn'];
      $ldap['searchName'] = $data['Oa4mpClientCoLdapConfig'][0]['search_name'];

      $ldap['searchAttributes'] = array();
      foreach($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'] as $sa) {
        $a = array();
        $a['name'] = $sa['name'];
        $a['returnName'] = $sa['return_name'];
        if($sa['return_as_list']) {
          $a['returnAsList'] = 'true';
        } else {
          $a['returnAsList'] = 'false';
        }

        $ldap['searchAttributes'][] = $a;
      }

      $content['cfg']['claims']['sourceConfig'][] = array('ldap' => $ldap);

      $preProcessing = array();
      $preProcessing['$if'] = array('$true');
      $preProcessing['$then'] = array(array('$set_claim_source' => array('LDAP', $id)));

      $content['cfg']['claims']['preProcessing'] = array();
      $content['cfg']['claims']['preProcessing'][] = $preProcessing;
    }

    return $content;
  }

  /**
   * Request a new OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @return Array array containing the new client ID and secret
   */

  function oa4mpNewClient($adminClient, $data) {
    $ret = array();

    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);
    $request['method'] = 'POST';

    $body = $this->oa4mpMarshallContent($data);

    $request['body'] = json_encode($body);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . print_r($request['body'], true));

    $response = $http->request($request);

    $this->log("Response is " . print_r($response, true));

    if($response->code == 200) {
      $body = json_decode($response->body(), true);
      
      $ret['clientId'] = $body['client_id'];
      $ret['secret']   = $body['client_secret'];
    }

    return $ret;
  }

  /**
   * Unmarshall oa4mp server object to Oa4mpClientCoOidcClient object.
   *
   * @since COmanage Registry 3.1.1
   * @return Array
   */
  function oa4mpUnMarshallContent($oa4mpObject) {

    // The input oa4mpObject should already be converted from the
    // JSON returned by the Oa4mp server to an associative array
    // using the call json_decode($json, true).

    $oa4mpClient = array();
    $oa4mpClient['Oa4mpClientCoOidcClient']  = array();
    $oa4mpClient['Oa4mpClientCoAdminClient'] = array();
    $oa4mpClient['Oa4mpClientCoCallback']    = array();
    $oa4mpClient['Oa4mpClientCoLdapConfig']  = array();
    $oa4mpClient['Oa4mpClientCoScope']       = array();

    try {
      // Try to unmarshall the server object and throw exception
      // for any errors.

      // Unmarshall basic client details.
      $oa4mpClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $oa4mpObject['client_id'];
      $oa4mpClient['Oa4mpClientCoOidcClient']['name'] = $oa4mpObject['client_name'];

      if(array_key_exists('rt_lifetime', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoOidcClient']['refresh_token_lifetime'] = $oa4mpObject['rt_lifetime'];
      }

      // For now we set proxy_limited to always be false.
      $oa4mpClient['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

      // Unmarshall the calback URIs.
      foreach ($oa4mpObject['redirect_uris'] as $key => $uri) {
        $oa4mpClient['Oa4mpClientCoCallback'][]['url'] = $uri;
      }

      // Unmarshall the scope details.
      $scopeObject = $oa4mpObject['scope'];
      if(is_string($scopeObject)) {
        $scopeObject = explode(" ", $scopeObject);
      }

      foreach ($scopeObject as $key => $scope) {
        switch ($scope) {
          case Oa4mpClientScopeEnum::OpenId:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::OpenId;
            break;
          case Oa4mpClientScopeEnum::Profile:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::Profile;
            break;
          case Oa4mpClientScopeEnum::Email:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::Email;
            break;
          case Oa4mpClientScopeEnum::OrgCilogonUserInfo:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::OrgCilogonUserInfo;
            break;
          case Oa4mpClientScopeEnum::Getcert:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::Getcert;
            break;
          default:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = $scope;
            break;
        }
      }

      // Unmarshall the cfg object to obtain the LDAP and search attribute details.
      if(isset($oa4mpObject['cfg'])){
        $cfg = $oa4mpObject['cfg'];

        // If the client signature does not match what we expect throw exception.
        if(isset($cfg['config'])) {
          if($cfg['config'] != _txt('pl.oa4mp_client_co_oidc_client.signature')) {
            throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.bad_signature'));
          }
        }

        if(isset($cfg['claims']['sourceConfig'])) {
          foreach($cfg['claims']['sourceConfig'] as $key => $sourceConfig) {
            $ldapConfig = array();

            if(isset($sourceConfig['ldap'])) {
              $ldap = $sourceConfig['ldap'];

              $ldapConfig['authorization_type'] = $ldap['authorizationType'];
              $ldapConfig['enabled'] = $ldap['enabled'];

              $address = $ldap['address'];
              $port = $ldap['port'];
              if($port == 636) {
                $ldapConfig['serverurl'] = 'ldaps://' . $address;
              } else {
                $ldapConfig['serverurl'] = 'ldap://' . $address;
              }

              $ldapConfig['binddn'] = $ldap['principal'];
              $ldapConfig['password'] = $ldap['password'];
              $ldapConfig['basedn'] = $ldap['searchBase'];
              $ldapConfig['search_name'] = $ldap['searchName'];

              if(isset($ldap['searchAttributes'])) {
                $ldapConfig['Oa4mpClientCoSearchAttribute'] = array();

                foreach($ldap['searchAttributes'] as $key => $mapping) {
                  $sa = array();
                  $sa['name'] = $mapping['name'];
                  $sa['return_name'] = $mapping['returnName'];

                  // The Oa4mp server currently returns a string value of
                  // 'true' or 'false'. That should probably be fixed to
                  // return a JSON boolean so detect both here.
                  if(($mapping['returnAsList'] == 'true') || ($mapping['returnAsList'] === true)){
                    $sa['return_as_list'] = true;
                  } else {
                    $sa['return_as_list'] = null;
                  }

                  $ldapConfig['Oa4mpClientCoSearchAttribute'][] = $sa;
                }
              }
            }

            if(!empty($ldapConfig)) {
              $oa4mpClient['Oa4mpClientCoLdapConfig'][] = $ldapConfig;
            }

          }
        }

        // Check the preProcessing block. Currently we should find a sincle claim source
        // of type 'LDAP' and its config identifier should be consistent with the cfg
        // object.
        if(isset($cfg['claims']['preProcessing'])) {
          $preProcessing = $cfg['claims']['preProcessing'];
          if(isset($preProcessing[0]['$then'][0]['$set_claim_source'])) {
            $claim_source = $preProcessing[0]['$then'][0]['$set_claim_source'];
            if($claim_source[0] != 'LDAP') {
              throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.preprocessing'));
            }
            if($claim_source[1] != $cfg['claims']['sourceConfig'][0]['ldap']['id']) {
              throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.preprocessing'));
            }
          }
        }
      }
    }
    catch(Exception $e) {
      $this->log("oa4mpObject: " . print_r($oa4mpObject, true));
      throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.unmarshall') . ': ' . $e->getMessage());
    }

    return $oa4mpClient;
  }

  /**
   * Verify existing OIDC client data is synchronized with the oa4mp server.
   *
   * @since COmanage Registry 3.2.5
   * @param  Array $adminClient admin client
   * @param  Array $curClient current client
   * @return Boolean True if synchronized, else False
   */

  function oa4mpVerifyClient($adminClient, $curClient) {
    $synchronized = False;

    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);

    $client_id = $curClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request['uri']['query'] = array('client_id' => $client_id);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . print_r(null, true));

    $response = $http->request($request);

    $this->log("Response is " . print_r($response, true));

    $oa4mpObject = json_decode($response->body(), true);

    try {
      // Unmarshall the Oa4mp server representation of the client
      // and compare it to the current client to detect if the client
      // has been changed outside of this plugin.
      $oa4mpServerData = $this->oa4mpUnMarshallContent($oa4mpObject);
      $synchronized = $this->isClientDataSynchronized($curClient, $oa4mpServerData);
    }
    catch(Exception $e) {
      $this->log("Caught exception during unmarshall of Oa4mp server object: " . $e->getMessage());
    }

    return $synchronized;
  }

  /**
   * Determine the conditions for pagination of the index view, when rendered via the UI.
   * This overrides the default method in StandardController.
   *
   * @since  COmanage Registry 2.0.1
   * @return Array An array suitable for use in $this->paginate
   */
  
  public function paginationConditions() {

    $ret = array();
    $ret['conditions']['Oa4mpClientCoAdminClient.co_id'] = $this->cur_co['Co']['id'];
    
    return $ret;
  }

  /**
   * Find the provided CO ID.
   * This overrides the method defined in AppController.php
   *
   * - precondition: A coid must be provided in $this->request (params or data)
   *
   * @since  COmanage Registry v2.0.1
   * @param  Array $data Array of data for calculating implied CO ID
   * @return Integer The CO ID if found, or -1 if not
   */
  
  function parseCOID($data = null) {
    // Get a pointer to our model
    $req = $this->modelClass;
    $model = $this->$req;
    $coid = null;
    
    try {
      // First try to look up the CO ID based on the request. 
      $coid = $this->calculateImpliedCoId($data);
    }
    catch(Exception $e) {
      // Most likely no CO found, so just keep going
    }
    
    if(!$coid) {
      $coid = -1;
      
      if($this->action == 'index' || $this->action == 'add') {
        if(isset($this->params['named']['co'])) {
          $coid = $this->params['named']['co'];
        }
        // CO ID can be passed via a form submission
        elseif($this->action != 'index') {
          if(isset($this->request->data['Co']['id'])) {
            $coid = $this->request->data['Co']['id'];
          } elseif(isset($this->request->data[$req]['co_id'])) {
            $coid = $this->request->data[$req]['co_id'];
          }
        }
      }
    }
    
    return $coid;
  }

  /**
   * Validate and clean POST data from an add or edit action.
   *
   * @since  COmanage Registry 2.0.1
   * @return Array of validated data ready for saving or false if not validated.
   */

  private function validatePost() {
      $data = $this->request->data;

      // Trim leading and trailing whitespace from user input.
      array_walk_recursive($data, function (&$value,$key) { 
        if (is_string($value)) { 
          $value = trim($value); 
        } 
      });

      // We validate necessary fields here in the controller so that we can
      // leverage saveAssociated to save the data with validate set to false.
      // When it is set to true and there are multiple rows of associated data
      // validation fails.

      // Validate the OIDC client fields.
      $this->Oa4mpClientCoOidcClient->set($data);

      $fields = array();
      $fields[] = 'name';
      $fields[] = 'home_url';
      $fields[] = 'refresh_token_lifetime';

      $args = array();
      $args['fieldList'] = $fields;

      if(!$this->Oa4mpClientCoOidcClient->validates($args)) {
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
        return false;
      }

      // For now we set proxy_limited to always be false.
      $data['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

      // Validate the callback fields and remove empty values submitted
      // by any hidden input fields from the view.
      $validationErrors = array();
      $validationErrors['url'] = array();

      for ($i = 0; $i < 10; $i++) {
        $cb = $data['Oa4mpClientCoCallback'][$i];
        if(empty($cb['url'])) {
          unset($data['Oa4mpClientCoCallback'][$i]);
          continue; 
        }
        $d = array();
        $d['Oa4mpClientCoCallback'] = $cb;
        $this->Oa4mpClientCoOidcClient->Oa4mpClientCoCallback->set($d);

        $fields = array();
        $fields[] = 'url';

        $args = array();
        $args['fieldList'] = $fields;

        if(!$this->Oa4mpClientCoOidcClient->Oa4mpClientCoCallback->validates($args)) {
          $errors = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoCallback->invalidFields();
          $validationErrors['url'][$i] = $errors['url'][0];
        }
      }

      if($validationErrors['url']) {
        $this->Oa4mpClientCoOidcClient->Oa4mpClientCoCallback->validationErrors = $validationErrors;
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
        return false;
      }

      // Validate the scope field and remove empty values submitted
      // by any hidden input fields from the view.
      for ($i = 0; $i < 5; $i++) {
        $scope = $data['Oa4mpClientCoScope'][$i];
        if(empty($scope['scope'])) {
          unset($data['Oa4mpClientCoScope'][$i]);
          continue; 
        }
        $d = array();
        $d['Oa4mpClientCoScope'] = $scope;
        $this->Oa4mpClientCoOidcClient->Oa4mpClientCoScope->set($d);

        $fields = array();
        $fields[] = 'scope';

        $args = array();
        $args['fieldList'] = $fields;

        if(!$this->Oa4mpClientCoOidcClient->Oa4mpClientCoScope->validates($args)) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return false;
        }
      }

      // Validate the LDAP configs.
      for ($i = 0; $i < 10; $i++) {
        if (empty($data['Oa4mpClientCoLdapConfig'][$i]['serverurl'])) {
          unset($data['Oa4mpClientCoLdapConfig'][$i]);
          continue;
        }

        $d = array();
        $d['Oa4mpClientCoLdapConfig'] = $data['Oa4mpClientCoLdapConfig'][$i];
        $this->Oa4mpClientCoOidcClient->Oa4mpClientCoLdapConfig->set($d);

        $fields = array();
        $fields[] = 'enabled';
        $fields[] = 'authorization_type';
        $fields[] = 'serverurl';
        $fields[] = 'binddn';
        $fields[] = 'password';
        $fields[] = 'basedn';
        $fields[] = 'search_name';

        $args = array();
        $args['fieldList'] = $fields;

        if(!$this->Oa4mpClientCoOidcClient->Oa4mpClientCoLdapConfig->validates($args)) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return false;
        }

        // Validate the search attribute mappings and remove empty values submitted
        // by any hidden input fields from the view.
        for ($j = 0; $j < 10; $j++) {
          if (empty($data['Oa4mpClientCoLdapConfig'][$i]['Oa4mpClientCoSearchAttribute'][$j]['name'])
              && empty($data['Oa4mpClientCoLdapConfig'][$i]['Oa4mpClientCoSearchAttribute'][$j]['return_name'])) {
              unset($data['Oa4mpClientCoLdapConfig'][$i]['Oa4mpClientCoSearchAttribute'][$j]);
              continue;
          }

          $d = array();
          $d['Oa4mpClientCoSearchAttribute'] = $data['Oa4mpClientCoLdapConfig'][$i]['Oa4mpClientCoSearchAttribute'][$j];
          $this->Oa4mpClientCoOidcClient->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->set($d);

          $fields = array();
          $fields[] = 'name';
          $fields[] = 'return_name';
          $fields[] = 'return_as_list';

          $args = array();
          $args['fieldList'] = $fields;

          if(!$this->Oa4mpClientCoOidcClient->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->validates($args)) {
            $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
            return false;
          }
        }
      }

      return $data;
  }

  /**
   * Perform a sanity check on the identifier to verify it is part
   * of the current CO. This overrides method defined in AppController.php.
   *
   * @since  COmanage Registry 2.0.1
   * @return Boolean True if sanity check is successful
   * @throws InvalidArgumentException
   */

  public function verifyRequestedId() {
    if(empty($this->cur_co)) {
      // We shouldn't get here without a CO defined
      throw new LogicException(_txt('er.co.specify'));
    }

    if($this->action != 'index' && $this->action != 'add') {
       $id = $this->request->pass[0];

       $args = array();
       $args['conditions']['Oa4mpClientCoOidcClient.id'] = $id;
       $args['contain'] = false;

       $found = $this->Oa4mpClientCoOidcClient->find('first', $args);

       if(isset($found['Oa4mpClientCoAdminClient']['co_id'])) {
         $coId = $found['Oa4mpClientCoAdminClient']['co_id']; 
         if($coId != $this->cur_co['Co']['id']) {
           return false;
         }
       } else {
         return false;
       }

    }

    return true;
  }
}
