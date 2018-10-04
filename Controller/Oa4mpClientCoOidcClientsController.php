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
      if(!$this->oa4mpEditClient($curdata, $curdata, $data)) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
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
      $args = array();
      $args['contain'] = array();
      $args['contain'] = 'Oa4mpClientCoAdminClient.co_id = "' . $this->cur_co['Co']['id'] . '"';

      $ret = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->DefaultLdapConfig->find('first', $args);
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
    
    // All operations require platform or CO administrator.
    
    // Add a new OIDC client?
    $p['add'] = ($roles['cmadmin'] || $roles['coadmin']);

    // Delete an existing OIDC client?
    $p['delete'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // Edit an existing OIDC client?
    $p['edit'] = ($roles['cmadmin'] || $roles['coadmin']);

    // View all existing OIDC clients?
    $p['index'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // View an existing OIDC client?
    $p['view'] = ($roles['cmadmin'] || $roles['coadmin']); 
    
    $this->set('permissions', $p);
    return $p[$this->action];
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

    $request = array();
    $request['method'] = 'POST';

    $parsedUrl = parse_url($adminClient['Oa4mpClientCoAdminClient']['serverurl']);
    $request['uri']['scheme'] = $parsedUrl['scheme'];
    $request['uri']['host']   = $parsedUrl['host'];
    $request['uri']['path']   = $parsedUrl['path'];

    $request['header']['Content-Type'] = 'application/json; charset=UTF-8';

    $api = array();

    $api['subject']['admin']['admin_id'] = $adminClient['Oa4mpClientCoAdminClient']['admin_identifier'];
    $api['subject']['admin']['secret']   = $adminClient['Oa4mpClientCoAdminClient']['secret'];

    $api['action']['type']   = 'client';
    $api['action']['method'] = 'remove';

    $api['object']['client']['client_id'] = $oidcClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];

    $body = array();
    $body['api'] = $api;

    $request['body'] = json_encode($body);

    $response = $http->request($request);

    if($response->isOk()) {
      $ret = true;
    }

    return $ret;
  }

  /**
   * Edit an existing OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @return Boolean true if edit is successful or false otherwise
   */

  function oa4mpEditClient($adminClient, $curData, $data) {
    $ret = false;

    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);

    $request['body']['api']['action']['type']   = 'attribute';
    $request['body']['api']['action']['method'] = 'get';

    $request['body']['api']['object']['client']['client_id'] = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'];

    $content = array();
    $content[] = "name";
    $content[] = "cfg";
    $content[] = "scopes";
    $content[] = "callback_uri";

    $request['body']['api']['content'] = $content;

    $request['body'] = json_encode($request['body']);

    $response = $http->request($request);

    $request = $this->oa4mpInitializeRequest($adminClient);

    $request['body']['api']['action']['type']   = 'attribute';
    $request['body']['api']['action']['method'] = 'set';

    $request['body']['api']['object']['client']['client_id'] = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'];

    $content = $this->oa4mpMarshallContent($data);

    $request['body']['api']['content'] = $content;

    $request['body'] = json_encode($request['body']);

    $response = $http->request($request);

    if($response->isOk()) {
      $ret = true;
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
    $request['method'] = 'POST';

    $parsedUrl = parse_url($adminClient['Oa4mpClientCoAdminClient']['serverurl']);
    $request['uri']['scheme'] = $parsedUrl['scheme'];
    $request['uri']['host']   = $parsedUrl['host'];
    $request['uri']['path']   = $parsedUrl['path'];

    $request['header']['Content-Type'] = 'application/json; charset=UTF-8';

    $api = array();

    $api['subject']['admin']['admin_id'] = $adminClient['Oa4mpClientCoAdminClient']['admin_identifier'];
    $api['subject']['admin']['secret']   = $adminClient['Oa4mpClientCoAdminClient']['secret'];

    $body = array();
    $body['api'] = $api;

    $request['body'] = $body;

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

    $content['name']          = $data['Oa4mpClientCoOidcClient']['name'];
    $content['home_url']      = $data['Oa4mpClientCoOidcClient']['home_url'];

    if($data['Oa4mpClientCoOidcClient']['proxy_limited']) {
      $content['proxy_limited'] = 'true';
    } else {
      $content['proxy_limited'] = 'false';
    }

    if(!empty($data['Oa4mpClientCoCallback'])) {
      $content['callback_uri'] = array();
      foreach($data['Oa4mpClientCoCallback'] as $cb) {
        $content['callback_uri'][] = $cb['url'];
      }
    }

    if(!empty($data['Oa4mpClientCoScope'])) {
      $content['scopes'] = array();
      foreach($data['Oa4mpClientCoScope'] as $s) {
        $content['scopes'][] = $s['scope'];
      }
    }


    if(!empty($data['Oa4mpClientCoLdapConfig'])) {
      $content['cfg'] = array();
      $content['cfg']['config'] = 'Created by COmanage Oa4mpClient Plugin';
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

    $request['body']['api']['action']['type']   = 'client';
    $request['body']['api']['action']['method'] = 'create';

    $content = $this->oa4mpMarshallContent($data);

    $request['body']['api']['content'] = $content;

    $request['body'] = json_encode($request['body']);

    $response = $http->request($request);

    if($response->isOk()) {
      $body = json_decode($response->body(), true);
      if($body['status'] == 0) {
        $clientId = $body['content']['client']['client_id'];
        $clientSecret = $body['secret'];

        $api = array();
        $api['subject']['admin']['admin_id'] = $adminClient['Oa4mpClientCoAdminClient']['admin_identifier'];
        $api['subject']['admin']['secret']   = $adminClient['Oa4mpClientCoAdminClient']['secret'];

        $api['action']['type']   = 'client';
        $api['action']['method'] = 'approve';

        $api['object']['client']['client_id'] = $clientId;

        $body = array();
        $body['api'] = $api;

        $request['body'] = json_encode($body);

        $response = $http->request($request);

        if($response->isOk()) {
          $body = json_decode($response->body(), true);
          if($body['status'] == 0) {
            $ret['clientId'] = $clientId;
            $ret['secret'] = $clientSecret;
          }
        }
      }
    }

    return $ret;
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
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return false;
        }
      }

      // Validate the scope field and remove empty values submitted
      // by any hidden input fields from the view.
      for ($i = 0; $i < 4; $i++) {
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
