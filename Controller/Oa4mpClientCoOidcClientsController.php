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

App::uses("Oa4mpClientOa4mpServer", "Oa4mpClient.Model");

class Oa4mpClientCoOidcClientsController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoOidcClients";

  public $uses = array('Oa4mpClient.Oa4mpClientCoOidcClient');

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

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
    'Oa4mpClientCoAdminClient' => array(
      'Oa4mpClientCoNamedConfig' => array('Oa4mpClientCoScope'),
      'Oa4mpClientCoEmailAddress',
      'DefaultLdapConfig',
      'DefaultDynamoConfig'
    ),
    'Oa4mpClientCoCallback' => array(
      'order' => 'Oa4mpClientCoCallback.id'
    ),
    'Oa4mpClientCoEmailAddress' => array(
      'order' => 'Oa4mpClientCoEmailAddress.id'
    ),
    'Oa4mpClientCoScope' => array(
      'order' => 'Oa4mpClientCoScope.id'
    ),
    'Oa4mpClientCoLdapConfig' => array(
      'Oa4mpClientCoSearchAttribute' => array(
        'order' => 'Oa4mpClientCoSearchAttribute.id'
      )
    ),
    'Oa4mpClientDynamoConfig',
    'Oa4mpClientCoNamedConfig'
  );

  /**
   * Add an OIDC client.
   *
   * @since COmanage Registry 2.0.1
   */

  function add() {

    $this->set('title_for_layout', _txt('op.add.new', array(_txt('ct.oa4mp_client_co_oidc_clients.1'))));

    // Use the CO ID to find the admin clients, one of which is needed in the call to
    // the Oa4mp server.
    $args = array();
    $args['conditions']['Oa4mpClientCoAdminClient.co_id'] = $this->cur_co['Co']['id'];
    $args['contain'] = array();
    $args['contain'][] = 'Oa4mpClientCoEmailAddress';
    $args['contain']['Oa4mpClientCoNamedConfig'] = 'Oa4mpClientCoScope';
    $args['contain'][] = 'DefaultLdapConfig';
    $args['contain'][] = 'DefaultDynamoConfig';
    $adminClients = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->find('all', $args);

    $this->set('adminClients', $adminClients);

    $adminIds = array();
    foreach($adminClients as $c) {
      $adminIds[] = $c['Oa4mpClientCoAdminClient']['id'];
    }

    // Process POST data
    if($this->request->is('post')) {
      $data = & $this->request->data;
      
      // Verify that the admin client is available for this CO.
      if(!in_array($data['Oa4mpClientCoOidcClient']['admin_id'], $adminIds)) {
          $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_admin_id'), array('key' => 'error'));
          $args = array();
          $args['plugin'] = 'oa4mp_client';
          $args['controller'] = 'oa4mp_client_co_oidc_clients';
          $args['action'] = 'index';
          $args['co'] = $this->cur_co['Co']['id'];
          $this->redirect($args);
      }

      foreach($adminClients as $c) {
        if($c['Oa4mpClientCoAdminClient']['id'] == $data['Oa4mpClientCoOidcClient']['admin_id']) {
            $adminClient = $c;
        }
      }

      // For all new clients the only scope set initially is openid. OA4MP does
      // not require this, but without the openid scope set we cannot determine
      // from what OA4MP returns whether or not the client is a public client.
      // We can only infer it and that inference requires at least the 
      // openid scope be set.
      $data['Oa4mpClientCoScope'] = array();
      $data['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::OpenId;

      // Call out to Oa4mp server to create the new client.
      $oa4mpServer = new Oa4mpClientOa4mpServer();
      $newClient = $oa4mpServer->oa4mpNewClient($adminClient, $data);

      if(empty($newClient)) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.create_error'), array('key' => 'error'));
        return;
      }
      
      // Set the client ID returned by the oa4mp server so it is saved and also
      // set a view variable so it can be displayed. The client secret, if provided, is also
      // set as a view variable so it can be displayed but it is NOT saved.
      $data['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $newClient['clientId'];
      $this->set('vv_client_id', $newClient['clientId']);

      if(!empty($newClient['secret'])) {
        $this->set('vv_client_secret', $newClient['secret']);
      }

      // For now we set proxy_limited to always be false.
      $data['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

      // For now we set the DynamoDB configuration to the default.
      $data['Oa4mpClientDynamoConfig'] = $adminClient['DefaultDynamoConfig'];
      unset($data['Oa4mpClientDynamoConfig']['id']);
      unset($data['Oa4mpClientDynamoConfig']['admin_id']);

      // Save the client and associated data.
      $args = array();
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoOidcClient->saveAssociated($data, $args);

      if(!$ret) {
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
        return;
      }

      $this->set('vv_id', $this->Oa4mpClientCoOidcClient->id);

      // Render the view to show the new client ID and secret (if available).
      $this->render('secret');
    } else {
      // Process GET request.

      // If an admin_id is passed in as a named parameter verify it is
      // one of the admin IDs matched to the CO.
      if(!empty($this->request->named['admin_id'])) {
        $selectedAdminId = $this->request->named['admin_id'];

        if(!in_array($selectedAdminId, $adminIds)) {
          $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_admin_id'), array('key' => 'error'));
          $args = array();
          $args['plugin'] = 'oa4mp_client';
          $args['controller'] = 'oa4mp_client_co_oidc_clients';
          $args['action'] = 'index';
          $args['co'] = $this->cur_co['Co']['id'];
          $this->redirect($args);
        }

        // Set the adminClient to be used for choosing default DynamoDB or LDAP
        // config and email address as the one passed in or the only
        // admin client if there is only the single choice.
        foreach($adminClients as $c) {
          if($c['Oa4mpClientCoAdminClient']['id'] == $selectedAdminId) {
            $adminClient = $c;
          }
        }
      } else {
        $adminClient = $adminClients[0];
      }

      $this->set('vv_admin_id', $adminClient['Oa4mpClientCoAdminClient']['id']);
      $this->set('vv_admin_issuer', $adminClient['Oa4mpClientCoAdminClient']['issuer']);

      // Construct the default contact email address.
      $mail = null;

      $roles = $this->Role->calculateCMRoles();

      // If actor is member of the CO get email address from CoPerson record.
      if($roles['comember'] && !empty($roles['copersonid'])) {
        $args = array();
        $args['conditions']['EmailAddress.co_person_id'] = $roles['copersonid'];
        $args['conditions']['EmailAddress.deleted'] = false;
        $args['contain'] = false;

        $emails = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->Co->CoPerson->EmailAddress->find('all', $args);

        if(!empty($emails)) {
          foreach($emails as $e) {
            // Prefer the official email address.
            if($e['EmailAddress']['type'] == EmailAddressEnum::Official) {
              $mail = $e['EmailAddress']['mail'];
              break;
            }
          }
          // No official email address so take whatever is first.
          if(empty($mail)) {
            $mail = $emails[0]['EmailAddress']['mail'];
          }
        }
      }

      // If actor is not a member of the CO or could not find email then
      // find the first email of any type from any CO admin.
      if(empty($mail)) {
        $adminCoGroupId = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->Co->CoGroup->adminCoGroupId($this->cur_co['Co']['id']);

        $args = array();
        $args['conditions']['CoGroupMember.co_group_id'] = $adminCoGroupId;
        $args['conditions']['CoGroupMember.member'] = true;
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
        $args['contain']['CoPerson'] = 'EmailAddress';

        $admins = $this->Oa4mpClientCoOidcClient->Oa4mpClientCoAdminClient->Co->CoGroup->CoGroupMember->find('all', $args);

        if(!empty($admins[0]['CoPerson']['EmailAddress'][0]['mail'])) {
          $mail = $admins[0]['CoPerson']['EmailAddress'][0]['mail'];
        }
      }

      // If still no email then fallback to the contact email for the
      // associated admin client.
      if(empty($mail)) {
        if(!empty($adminClient['Oa4mpClientCoEmailAddress'][0]['mail'])) {
          $mail = $adminClient['Oa4mpClientCoEmailAddress'][0]['mail'];
        }
      }

      $this->set('vv_default_contact_email', $mail);

      if(!empty($adminClient['Oa4mpClientCoNamedConfig'])) {
        $this->request->data['Oa4mpClientCoNamedConfig'] = $adminClient['Oa4mpClientCoNamedConfig'];
      } else {
        $this->request->data['Oa4mpClientCoNamedConfig'] = array();
      }

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
    $oa4mpServer = new Oa4mpClientOa4mpServer();
    if(!$oa4mpServer->oa4mpDeleteClient($client, $client)) {
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
   * @param Integer $id
   */

  function edit($id) {
    // Get the current state of the client.
    $client = $this->Oa4mpClientCoOidcClient->current($id);

    if(empty($client)) {
      $this->Flash->set(_txt('er.notfound', array(_txt('ct.oa4mp_client_co_oidc_clients.1'), $id)), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    $this->set('vv_client_id', $id);

    // Get the admin client. 
    $admin = $this->Oa4mpClientCoOidcClient->admin($id);

    // Instantiate Oa4mpServer since it will be used for both
    // GET and POST.
    $oa4mpServer = new Oa4mpClientOa4mpServer();

    // POST or PUT request
    if($this->request->is(array('post','put'))) {

      // Copy the current client to a new client and update it with
      // the values input through the form.
      $newClient = $client;
      $newClient['Oa4mpClientCoOidcClient']['name'] = $this->request->data['Oa4mpClientCoOidcClient']['name'];
      $newClient['Oa4mpClientCoOidcClient']['home_url'] = $this->request->data['Oa4mpClientCoOidcClient']['home_url'];

      if(!empty($this->request->data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'])) {
        $newClient['Oa4mpClientCoOidcClient']['refresh_token_lifetime'] = $this->request->data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'];
      }

      $newClient['Oa4mpClientCoEmailAddress'][0]['mail'] = $this->request->data['Oa4mpClientCoEmailAddress'][0]['mail'];

      // Call out to oa4mp server.
      // Return value of 0 indicates an error saving the edit.
      // Return value of 2 indicates the plugin representation of the client
      // and the Oa4mp server representation of the client are out of sync.
      $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
      if($ret == 0) {
        $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
        // Set flash and fall through to the GET logic.
      } elseif($ret == 2) {
        // Set flash and fall through to the GET logic.
        $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      } else {
        // Save the client.
        
        // For now we set proxy_limited to always be false.
        $this->request->data['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

        // We use saveAssociated since we want to update the Oa4mpClientCoEmailAddress object.
        $ret = $this->Oa4mpClientCoOidcClient->saveAssociated($this->request->data);

        if($ret) {
          $clientName = $newClient['Oa4mpClientCoOidcClient']['name'];
          $this->Flash->set(_txt('rs.updated', array(filter_var($clientName,FILTER_SANITIZE_SPECIAL_CHARS))), array('key' => 'success'));
          
          // Read the client state again and fall through to the GET.
          $client = $this->Oa4mpClientCoOidcClient->current($id);
        } else {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
        }
      }
    } 

    // GET request
    
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

    // Set the title for the view.
    $this->set('title_for_layout', _txt('op.edit-a', array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

    $this->request->data = $client;
  }

  /**
   * View existing OIDC clients.
   */

   function index() {
    // Set page title
    $this->set('title_for_layout', _txt('ct.oa4mp_client_co_oidc_clients.pl'));

    // Configure server side pagination. This leverages code from the parent
    // class that sets the current CO ID.
    $local = $this->paginationConditions();
    $this->paginate['conditions'] = $local['conditions'];
    $this->Paginator->settings = $this->paginate;

    // Find all the clients for the current CO.
    $clients = $this->Paginator->paginate('Oa4mpClientCoOidcClient', array(), array());

    // If the user is not a platform admin or CO admin, remove the clients for which they are not an editor.
    $roles = $this->Role->calculateCMRoles();
    if(empty($roles['cmadmin']) && empty($roles['coadmin'])) {
      $coPersonId = $this->Session->read('Auth.User.co_person_id');
      foreach($clients as $key => $client) {
        if(!empty($client['Oa4mpClientAccessControl']['co_group_id'])) {
          if(!$this->Role->isCoGroupMember($coPersonId, $client['Oa4mpClientAccessControl']['co_group_id'])) {
            unset($clients[$key]);
          }
        } else {
          // No access control so filter out clients if the user is not in the delegated management group.
          $coId = $this->cur_co['Co']['id'];
          if(!$this->Oa4mpClientAuthz->isManager($coId, $coPersonId)) {
            unset($clients[$key]);
          }
        }
      }
    }

    $this->set('oa4mp_client_co_oidc_clients', $clients);

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
      
      $action = $this->action;
      // Only allow the named parameter for index and add and not edit.
      if(in_array($action, array('index', 'add'))) {
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

    if(!in_array($this->action, array('index', 'add'))) {
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
