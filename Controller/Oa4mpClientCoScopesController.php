<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Scopes Controller
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
 * @since         COmanage Registry v4.4.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("Oa4mpClientOa4mpServer", "Oa4mpClient.Model");
App::uses("StandardController", "Controller");

class Oa4mpClientCoScopesController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoScopes";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientCoScope.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Construct the OA4MP server object the edit_scopes action talks to.
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
   * Edit the scopes for the client.
   *
   * @since COmanage Registry 4.4.2
   */

  function edit_scopes() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->admin($clientId);

    // A post that fails validation, kept for the form the GET logic renders.
    $rejected = null;
    $rejectedErrors = array();

    // PUT request
    if($this->request->is(array('post','put'))) {

      $selectedScopes = array_filter(
        $this->request->data['Oa4mpClientCoScope'],
        function($s) {
          return $s['scope'] != 0;
        }
      );

      // Validate before anything is sent to the OA4MP server. A scope the
      // plugin rejects, or a row whose id names a different stored scope, used
      // to reach the server as posted while the save skipped it, leaving the
      // two out of sync.
      $scopeErrors = $this->validateSelectedScopes($client, $selectedScopes);

      if(!empty($scopeErrors)) {
        // Set flash and fall through to the GET logic, which renders the form
        // again with the validation errors. Nothing was sent to the server.
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));

        // Keep the input and errors now: the GET logic replaces the request
        // data and may save the client, so they cannot be read back later.
        $rejected = $this->request->data['Oa4mpClientCoScope'];
        $rejectedErrors = $scopeErrors;
      } else {
        $newClient = array_replace($client, array('Oa4mpClientCoScope' => $selectedScopes));

        // Call out to oa4mp server.
        // Return value of 0 indicates an error saving the edit.
        // Return value of 2 indicates the plugin representation of the client
        // and the Oa4mp server representation of the client are out of sync.
        $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
        if($ret == 0) {
          // Set flash and fall through to the GET logic.
          $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));

        } elseif($ret == 2) {
          // Set flash and fall through to the GET logic.
          $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));

        } else {
          // Update successful so save the new scope state. The server has
          // already accepted it, so any row that fails to save or delete
          // leaves the two sides out of sync and must be reported, not
          // flashed as success. Every row is still processed.
          $saveFailed = false;

          // First create any new scopes for this client.
          foreach($selectedScopes as $s) {
            if(empty($s['id'])) {
              $this->Oa4mpClientCoScope->clear();

              $data = array();
              $data['Oa4mpClientCoScope']['client_id'] = $client['Oa4mpClientCoOidcClient']['id'];
              $data['Oa4mpClientCoScope']['scope'] = $s['scope'];

              if(!$this->Oa4mpClientCoScope->save($data)) {
                $saveFailed = true;
              }
            }
          }

          // Next delete any scopes that were deselected.
          foreach($client['Oa4mpClientCoScope'] as $previous) {
            $delete = true;
            foreach($selectedScopes as $s) {
              if(!empty($s['id']) && ($s['id'] == $previous['id'])) {
                $delete = false;
              }
            }
            if($delete) {
              if(!$this->Oa4mpClientCoScope->delete($previous['id'])) {
                $saveFailed = true;
              }
            }
          }

          if($saveFailed) {
            $this->Flash->set(_txt('pl.oa4mp_client_co_scope.er.edit.save'), array('key' => 'error'));
          } else {
            // Set flash successful.
            $this->Flash->set(_txt('pl.oa4mp_client_co_scope.scope.flash.success'), array('key' => 'success'));
          }

          // If no callbacks are configured redirect to the callbacks add view.
          if(empty($client['Oa4mpClientCoCallback'])) {
            $args = array();
            $args['plugin'] = 'oa4mp_client';
            $args['controller'] = 'oa4mp_client_co_callbacks';
            $args['action'] = 'add';
            $args['clientid'] = $clientId;
            $this->redirect($args);
          }

          // Read the client state again and fall through to the GET.
          $client = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->current($clientId);
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
      $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->request->data = $client;

    // A post that failed validation stays in the form over the stored client,
    // which the view still reads for the client name and type, so the
    // subscriber can see and correct what was rejected.
    if($rejected !== null) {
      $this->request->data['Oa4mpClientCoScope'] = $rejected;
      $this->Oa4mpClientCoScope->validationErrors = $rejectedErrors;
    }

    // Set the title for the view.
    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_scope.scope.edit.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));
  }

  /**
   * Validate the selected scope rows of a posted scopes form.
   *
   * Each row is checked with the model's rules against the data the save
   * would write. A row carrying an id must name one of this client's stored
   * scope rows and keep its stored value: the save skips rows with an id, so
   * a changed value would reach the OA4MP server and never be stored.
   *
   * @since  COmanage Registry v4.5.0
   * @param  Array $client         Current client, as returned by current()
   * @param  Array $selectedScopes Posted scope rows that are checked, keyed by form row
   * @return Array Validation errors keyed by form row, empty if all rows are valid
   */

  private function validateSelectedScopes($client, $selectedScopes) {
    $storedScopes = array();
    foreach($client['Oa4mpClientCoScope'] as $stored) {
      $storedScopes[(string)$stored['id']] = $stored['scope'];
    }

    $errors = array();

    foreach($selectedScopes as $i => $s) {
      $data = array();
      $data['Oa4mpClientCoScope']['client_id'] = $client['Oa4mpClientCoOidcClient']['id'];
      $data['Oa4mpClientCoScope']['scope'] = $s['scope'];

      $this->Oa4mpClientCoScope->create(false);
      $this->Oa4mpClientCoScope->set($data);

      if(!$this->Oa4mpClientCoScope->validates()) {
        $errors[$i] = $this->Oa4mpClientCoScope->validationErrors;
        continue;
      }

      if(!empty($s['id'])) {
        $id = (string)$s['id'];
        if(!isset($storedScopes[$id]) || $storedScopes[$id] !== $s['scope']) {
          $errors[$i]['scope'][] = _txt('pl.oa4mp_client_co_scope.er.id');
        }
      }
    }

    $this->Oa4mpClientCoScope->create(false);

    return $errors;
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry 4.4.2
   * @return Array Permissions
   */
  
  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();

    $coId = $this->cur_co['Co']['id'];

    $coPersonId = $this->Session->read('Auth.User.co_person_id');

    // If the user is not logged in, return false.
    if(empty($coPersonId)) {
      $this->set('permissions', $p);
      return false;
    }

    $p = $this->Oa4mpClientAuthz->permissionSet($coId, $coPersonId, $roles, $this->request->params);

    $this->set('permissions', $p);
    return $p[$this->action];
  }
  
  /**
   * Find the provided CO ID.
   * This overrides the method defined in AppController.php
   *
   * @since  COmanage Registry v4.4.2
   * @param  Array $data Array of data for calculating implied CO ID
   * @return Integer The CO ID if found, or -1 if not
   */
  
  function parseCOID($data = null) {
    // This controller requires passing the OIDC client ID as
    // a named parameter. We can use it to infer the CO ID.
    // The isAuthorized function will enforce that the authenticated
    // user is authorized within that CO to edit the client.
    try {
      $clientId = $this->request->params['named']['clientid'];

      $args = array();
      $args['conditions']['Oa4mpClientCoOidcClient.id'] = $clientId;
      $args['contain'] = 'Oa4mpClientCoAdminClient';

      $oidcClient = $this->Oa4mpClientCoScope->Oa4mpClientCoOidcClient->find('first', $args);

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
