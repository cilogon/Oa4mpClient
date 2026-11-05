<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Callbacks Controller
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

class Oa4mpClientCoCallbacksController extends StandardController {
  // Class name, used by Cake
  public $name = "Oa4mpClientCoCallbacks";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientCoCallback.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Construct the OA4MP server object the callbacks actions talk to.
   *
   * The single construction point for Oa4mpClientOa4mpServer in this
   * controller. It exists so a test subclass can substitute a fake server and
   * drive an action without reaching the network; production behavior is
   * unchanged. Same shape as the factory in Oa4mpClientClaimsController.
   *
   * @since  COmanage Registry v4.5.0
   * @return Oa4mpClientOa4mpServer
   */

  protected function _oa4mpServer() {
    return new Oa4mpClientOa4mpServer();
  }

  /**
   * Require that a callback id names one of the client's own callbacks.
   *
   * edit() and delete() take the callback id from the URL, and only the
   * client in the URL was authorized, so an id naming another client's
   * callback is refused before anything is sent to the OA4MP server
   * (cilogon/Oa4mpClient#65). The refusal is logged, flashed, and redirected
   * to the callbacks index.
   *
   * @param  array   $client   The stored client, as current() returns it
   * @param  integer $clientId The client id from the URL
   * @param  mixed   $id       The callback id from the URL
   * @return array   The stored callback
   */

  private function _requireClientCallback($client, $clientId, $id) {
    if(is_scalar($id) && !empty($client['Oa4mpClientCoCallback'])) {
      foreach($client['Oa4mpClientCoCallback'] as $c) {
        if((string)$c['id'] === (string)$id) {
          return $c;
        }
      }
    }

    $this->log("Oa4mpClient: refused callback id " . $this->_postedIdForLog($id)
               . " in " . $this->name . "::" . $this->action
               . ", not a callback of OIDC client " . $client['Oa4mpClientCoOidcClient']['id']);

    $this->Flash->set(_txt('pl.oa4mp_client_co_callback.er.not_client_callback'), array('key' => 'error'));

    $args = array();
    $args['plugin'] = 'oa4mp_client';
    $args['controller'] = 'oa4mp_client_co_callbacks';
    $args['action'] = 'index';
    $args['clientid'] = $clientId;

    $this->redirect($args);
  }

  /**
   * Rebuild a posted callback around the stored client.
   *
   * The form carries the callback id and client id as hidden fields, and
   * save() writes whatever row its data names, so a hand-built post could
   * change another client's callback or attach one to another client
   * (cilogon/Oa4mpClient#65). The client id comes from the URL's client, the
   * callback id from the stored callback on edit and none on add, and only
   * the URL is taken from the post. The result is both sent to the OA4MP
   * server and saved, so the two match. A mismatched posted id is logged.
   *
   * @param  array      $client         The stored client, as current() returns it
   * @param  array      $posted         The posted request data
   * @param  array|null $storedCallback The stored callback being edited, or null on add
   * @return array      The data the action validates, sends and saves
   */

  protected function callbackDataForStoredClient($client, $posted, $storedCallback = null) {
    $clientId = $client['Oa4mpClientCoOidcClient']['id'];

    $postedCallback = (isset($posted['Oa4mpClientCoCallback']) && is_array($posted['Oa4mpClientCoCallback']))
                      ? $posted['Oa4mpClientCoCallback']
                      : array();

    $storedId = ($storedCallback === null) ? null : $storedCallback['id'];
    if(isset($postedCallback['id']) && $postedCallback['id'] !== '' && (string)$postedCallback['id'] !== (string)$storedId) {
      $this->log("Oa4mpClient: a callback " . $this->action . " for OIDC client " . $clientId
                 . " posted callback id " . $this->_postedIdForLog($postedCallback['id'])
                 . "; the stored identifiers were used instead");
    }
    if(isset($postedCallback['client_id']) && (string)$postedCallback['client_id'] !== (string)$clientId) {
      $this->log("Oa4mpClient: a callback " . $this->action . " for OIDC client " . $clientId
                 . " posted client id " . $this->_postedIdForLog($postedCallback['client_id'])
                 . "; the client in the URL was used instead");
    }

    $callback = array('client_id' => $clientId);
    if($storedId !== null) {
      $callback['id'] = $storedId;
    }
    if(array_key_exists('url', $postedCallback)) {
      $callback['url'] = $postedCallback['url'];
    }

    return array('Oa4mpClientCoCallback' => $callback);
  }

  /**
   * Render an id taken from a request for a log line, without echoing
   * arbitrary input.
   *
   * @param  mixed  $id The id from the request
   * @return string The id, or a placeholder when it is not a plain integer
   */

  private function _postedIdForLog($id) {
    return (is_int($id) || (is_string($id) && ctype_digit($id))) ? (string)$id : '(not an id)';
  }

  /**
   * Add a callback.
   *
   * @since  COmanage Registry v4.4.2
   * @return null
   */

  function add() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Only the URL is taken from the post; the client is the one in the URL.
      $this->request->data = $this->callbackDataForStoredClient($client, $this->request->data);

      if(!$this->_validatePostedCallback()) {
        // Set flash and fall through to the GET logic, which renders the form
        // again with the validation error. Nothing was sent to the server.
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
      } else {
        $newCallbacks = $client['Oa4mpClientCoCallback'];
        $newCallbacks[] = $this->request->data['Oa4mpClientCoCallback'];

        $newClient = array_replace($client, array('Oa4mpClientCoCallback' => $newCallbacks));

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
          // Update successful so save the new callback. The server has already
          // accepted it, so a failed save here leaves the two sides out of
          // sync and must be reported, not flashed as success.
          if(!$this->Oa4mpClientCoCallback->save($this->request->data)) {
            $this->Flash->set(_txt('pl.oa4mp_client_co_callback.er.add.save'), array('key' => 'error'));
          } else {
            $this->Flash->set(_txt('pl.oa4mp_client_co_callback.callback.add.flash.success'), array('key' => 'success'));
          }

          // Redirect to the index view.
          $args = array();
          $args['plugin'] = 'oa4mp_client';
          $args['controller'] = 'oa4mp_client_co_callbacks';
          $args['action'] = 'index';
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
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.callbacks.add.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));
  }

  /**
   * Delete a callback.
   *
   * @since  COmanage Registry v4.2.2
   * @param  integer $id Oa4mpClientCoCallback ID
   * @return null
   */

  function delete($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // Only the client in the URL was authorized, so the callback must be one
    // of its own (cilogon/Oa4mpClient#65).
    $this->_requireClientCallback($client, $clientId, $id);

    $oa4mpServer = $this->_oa4mpServer();

    $newClient = $client;

    foreach($client['Oa4mpClientCoCallback'] as $i => $c) {
      if($c['id'] == $id) {
        unset($newClient['Oa4mpClientCoCallback'][$i]);
        break;
      }
    } 

    // Call out to oa4mp server.
    // Return value of 0 indicates an error saving the edit.
    // Return value of 2 indicates the plugin representation of the client
    // and the Oa4mp server representation of the client are out of sync.
    $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
    if($ret == 0) {
      // Set flash and fall through to render again.
      $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
    } elseif($ret == 2) {
      // Set flash and fall through to render again.
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
    } else {
      // Update successful so delete the callback.
      $ret = $this->Oa4mpClientCoCallback->delete($id);

      // Set flash successful.
      $this->Flash->set(_txt('pl.oa4mp_client_co_callback.callback.delete.flash.success'), array('key' => 'success'));

      // Redirect to the index view.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_callbacks';
      $args['action'] = 'index';
      $args['clientid'] = $clientId;

      $this->redirect($args);
    }
  }

  /**
   * Edit a callback.
   *
   * @since  COmanage Registry v4.2.2
   * @param  integer $id Oa4mpClientCoCallback ID
   * @return null
   */

  function edit($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // Only the client in the URL was authorized, so the callback must be one
    // of its own (cilogon/Oa4mpClient#65).
    $storedCallback = $this->_requireClientCallback($client, $clientId, $id);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Only the URL is taken from the post; the ids are the stored ones.
      $this->request->data = $this->callbackDataForStoredClient($client, $this->request->data, $storedCallback);

      if(!$this->_validatePostedCallback()) {
        // Set flash and fall through to the GET logic, which renders the form
        // again with the validation error. Nothing was sent to the server.
        $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
      } else {
        $newClient = $client;

        foreach($client['Oa4mpClientCoCallback'] as $i => $c) {
          if($c['id'] == $id) {
            $newClient['Oa4mpClientCoCallback'][$i] = $this->request->data['Oa4mpClientCoCallback'];
            break;
          }
        }

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
          // Update successful so save the edited callback. The server has
          // already accepted it, so a failed save here leaves the two sides
          // out of sync and must be reported, not flashed as success.
          if(!$this->Oa4mpClientCoCallback->save($this->request->data)) {
            $this->Flash->set(_txt('pl.oa4mp_client_co_callback.er.edit.save'), array('key' => 'error'));
          } else {
            $this->Flash->set(_txt('pl.oa4mp_client_co_callback.callback.edit.flash.success'), array('key' => 'success'));
          }

          // Redirect to the index view.
          $args = array();
          $args['plugin'] = 'oa4mp_client';
          $args['controller'] = 'oa4mp_client_co_callbacks';
          $args['action'] = 'index';
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
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.callbacks.edit.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));

    // A posted callback that failed validation stays in the form, so the
    // subscriber can see and correct what was rejected.
    if(!empty($this->Oa4mpClientCoCallback->validationErrors)) {
      return;
    }

    foreach($client['Oa4mpClientCoCallback'] as $i => $c) {
      if($c['id'] == $id) {
        $this->request->data = array('Oa4mpClientCoCallback' => $client['Oa4mpClientCoCallback'][$i]);
        return;
      }
    }
  }

  /**
   * Validate the posted callback before anything is sent to the OA4MP server.
   *
   * The add and edit actions used to send the posted callback to the server
   * first and validate it only when saving it locally afterwards. A callback
   * the plugin rejects -- most often one with a stray space around it -- was
   * then stored by the server and not by the plugin, and the synchronization
   * check blocked every later edit of the client (cilogon/Oa4mpClient#48).
   *
   * On success the posted callback is replaced with the data validation left
   * behind, so the value sent to the server and the value saved are the same
   * normalized (trimmed) URL.
   *
   * @since  COmanage Registry v4.5.0
   * @return boolean True if the posted callback is valid
   */

  protected function _validatePostedCallback() {
    $this->Oa4mpClientCoCallback->create(false);
    $this->Oa4mpClientCoCallback->set($this->request->data);

    if(!$this->Oa4mpClientCoCallback->validates()) {
      return false;
    }

    $this->request->data['Oa4mpClientCoCallback'] = $this->Oa4mpClientCoCallback->data['Oa4mpClientCoCallback'];

    return true;
  }

  /**
   * Obtain all callbacks.
   *
   * @since  COmanage Registry v4.2.2
   * @return null
   */

  function index() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations
    $client = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);
    $admin = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

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
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->request->data = $client;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.callbacks.edit.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));
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

      $oidcClient = $this->Oa4mpClientCoCallback->Oa4mpClientCoOidcClient->current($clientId);

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
