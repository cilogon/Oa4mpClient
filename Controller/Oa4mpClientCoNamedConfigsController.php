<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Named Configs Controller
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
 * @since         COmanage Registry v4.0.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("StandardController", "Controller");
App::uses("Oa4mpClientOa4mpServer", "Oa4mpClient.Model");
App::uses("Oa4mpClientNamedConfigActions", "Oa4mpClient.Controller");

class Oa4mpClientCoNamedConfigsController extends StandardController {
  use Oa4mpClientNamedConfigActions;

  // Class name, used by Cake
  public $name = "Oa4mpClientCoNamedConfigs";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'fields' => array(
      'Oa4mpClientCoNamedConfig.id',
      'Oa4mpClientCoNamedConfig.config_name',
      'Co.name',
    ),
    'limit' => 25,
    'joins' => array(
      array(
        'table' => 'cm_cos',
        'alias' => 'Co',
        'type' => 'LEFT',
        'conditions' => array(
          'Co.id = Oa4mpClientCoAdminClient.co_id'
        )
      )
    ),
    'order' => 'Co.name ASC, Oa4mpClientCoNamedConfig.config_name ASC'
  );

  // This controller does not need a CO to be set
  public $requires_co = false;

  // Add models to contain when querying for data
  // as part of an edit action.
  public $edit_contains = array(
    'Oa4mpClientCoAdminClient',
    'Oa4mpClientCoScope' => array(
      'order' => 'Oa4mpClientCoScope.id'
    ),
  );

  // Add models to contain when querying for data to be
  // used in views populated by the StandardController.
  public $view_contains = array(
    'Oa4mpClientCoAdminClient.name',
    'Oa4mpClientCoAdminClient.issuer',
  );

  /**
   * Construct the OA4MP server object the manage action talks to.
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
   * Add a named configuration
   *
   * @since COmanage Registry 4.0.2
   */

  function add() {
    // Process POST data
    if($this->request->is('post')) {
      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there any validation 
        // error so just return.
        return;
      }

      // Save the named configuration and associated data.
      $args = array();
      $args['validate'] = false;
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoNamedConfig->saveAssociated($data, $args);

      if(!$ret) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return;
      }

      // Redirect to the index view
      $this->Flash->set(_txt('rs.added-a', array(filter_var($this->generateDisplayKey(),FILTER_SANITIZE_SPECIAL_CHARS))), array('key' => 'success'));
      $this->performRedirect();
    }

    // GET
    parent::add();
  }

  /**
   * Callback before other controller methods are invoked or views are rendered.
   * - precondition:
   * - postcondition: Auth component is configured 
   * - postcondition:
   *
   * @since  COmanage Registry v4.0.2
   */ 
  function beforeFilter() {
    parent::beforeFilter();

    // Circumvent the StandardController and AppController logic for determining
    // the current CO since we do not need it here and want to return to the
    // controller index view after an add() without a co: parameter added
    // automatically.
    $this->cur_co = null;
  }

  /**
   * Perform filtering of CO options for dropdown.
   * - postcondition: co_options set
   *
   * @since  COmanage Registry v4.0.2
   */
 
  function beforeRender() {
    // Compute the available CO options when adding or
    // editing an named configuration.
    
    $args = array();
    $args['contain'] = 'Co';

    $adminClients = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->find('all', $args);

    $this->set('adminClients', $adminClients);

    parent::beforeRender();
  }

  /**
   * Edit a Named Config.
   *
   * @since COmanage Registry v4.0.2
   */
  function edit($id) {
    // Pull the current data.
    $args = array();
    $args['conditions']['Oa4mpClientCoNamedConfig.id'] = $id;
    $args['contain'] = $this->edit_contains;

    $curdata = $this->Oa4mpClientCoNamedConfig->find('first', $args);

    if(empty($curdata)) {
      $this->Flash->set(_txt('er.notfound', array(_txt('ct.oa4mp_client_co_named_configs.1'), $id)), array('key' => 'error'));
      $args = array();
      $args['action'] = 'index';
      $this->redirect($args);
    }

    // Set the title for the view.
    $this->set('title_for_layout', _txt('op.edit-a', array($curdata['Oa4mpClientCoNamedConfig']['config_name'])));

    // PUT request
    if($this->request->is(array('post','put'))) {

      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there any validation 
        // error so just return.
        return;
      }

      // Make sure the ID is set for the Named Config model.
      $data['Oa4mpClientCoNamedConfig']['id'] = $curdata['Oa4mpClientCoNamedConfig']['id'];

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
          $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoScope->delete($current_scope['id']);
        }
      }

      // Save the named configuration and associated data.
      $args = array();
      $args['validate'] = false;
      $args['deep'] = true;
      $ret = $this->Oa4mpClientCoNamedConfig->saveAssociated($data, $args);

      if(!$ret) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return;
      }

      // Redirect to the index view
      $this->Flash->set(_txt('rs.updated', array(filter_var($this->generateDisplayKey(),FILTER_SANITIZE_SPECIAL_CHARS))), array('key' => 'success'));
      $this->performRedirect();
    }

    // GET request
    $this->request->data = $curdata;

    // Need to re-order the scopes to fit our checkbox use of them
    // in the form and handle any adhoc attributes.
    $newScopes = array();
    $i = 5;
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
        default:
          $newScopes[$i] = $s;
          $i = $i + 1;
          break;
      }
    }

    $this->request->data['Oa4mpClientCoScope'] = $newScopes;
  }

  /**
   * Manage named configuration for an OIDC client.
   *
   * @since COmanage Registry v4.5.0
   */
  function manage() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    $clientModel = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient;

    // Get the current client and admin configurations. Loading the client
    // also deletes the rows a Named configuration replaces that an older
    // client still holds, before anything is sent.
    $client = $this->_currentClientPruned($clientModel, $clientId);
    $admin = $clientModel->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // A selection that is refused, kept for the form the GET logic renders.
    $rejected = null;
    $rejectedErrors = array();

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      $selected = $this->request->data['Oa4mpClientCoNamedConfig']['selected_config_id'] ?? null;

      // The selection must be no named configuration or one of the admin
      // client's own. No model rule covers it, since saveField() below skips
      // validation, so check it here before anything is sent to the OA4MP
      // server. A selection the server accepted and the plugin could not
      // store used to leave the two out of sync. The confirmation page posts
      // the selection again, and it is checked again here, never trusted.
      $allowed = ($selected === 'none');
      $selectedConfig = null;
      if(!$allowed && is_scalar($selected)) {
        foreach($admin['Oa4mpClientCoNamedConfig'] ?? array() as $config) {
          if((string)$config['id'] === (string)$selected) {
            $allowed = true;
            $selectedConfig = $config;
            break;
          }
        }
      }

      if(!$allowed) {
        // Set flash and fall through to the GET logic, which renders the form
        // again with the error. Nothing was sent to the server. The flash
        // carries the specific message because the view draws the choices
        // with Form->radio(), which prints no field errors.
        $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.selection'), array('key' => 'error'));

        // Keep the selection and error now: the GET logic replaces the request
        // data and may save the client, which clears validation errors.
        $rejected = $this->request->data['Oa4mpClientCoNamedConfig'] ?? array();
        $rejectedErrors = array(
          'selected_config_id' => array(_txt('pl.oa4mp_client_co_named_config.er.selection'))
        );
      } else {
        // A refused or out-of-sync server edit falls through to the GET
        // logic; everything else has redirected or rendered.
        if($this->_manageSelection($oa4mpServer, $clientModel, $clientId, $client, $admin, $selectedConfig)) {
          return;
        }
      }
    }

    // GET

    // Check that the current state of the client before the edit are synchronized.
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
      $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('vv_available_configs', $admin['Oa4mpClientCoNamedConfig'] ?? array());

    $this->request->data = $client;

    // A refused selection stays in the form over the stored client, which the
    // view still reads for the client id and name, with its error on the
    // selection field.
    if($rejected !== null) {
      $this->request->data['Oa4mpClientCoNamedConfig'] = $rejected;
      $this->Oa4mpClientCoNamedConfig->validationErrors = $rejectedErrors;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_named_config.manage.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));
  }

  /**
   * Act on a manage() selection that passed the allow-list: refuse a public
   * client, do nothing when the client is already in the chosen state, ask
   * for confirmation, or send the confirmed change and then bring the
   * Registry rows in line with it (cilogon/Oa4mpClient#58).
   *
   * A Named configuration takes the place of the client's own scopes,
   * claims, authorization settings, access token settings, LDAP
   * configuration and DynamoDB configuration. Applying one deletes them;
   * removing one starts the client over with the settings a new client gets,
   * so nothing kept from before can come back into force unreviewed.
   *
   * @param  Oa4mpClientOa4mpServer $oa4mpServer    OA4MP server object
   * @param  Model                  $clientModel    The Oa4mpClientCoOidcClient model
   * @param  integer                $clientId       Oa4mpClientCoOidcClient ID
   * @param  array                  $client         The client, as current() returns it
   * @param  array                  $admin          The admin client, as admin() returns it
   * @param  array|null             $selectedConfig The chosen Named configuration, or null for none
   * @return boolean true once the request has rendered or redirected; false
   *                 when the server refused the change and the form is shown again
   */

  protected function _manageSelection($oa4mpServer, $clientModel, $clientId, $client, $admin, $selectedConfig) {
    $removing = ($selectedConfig === null);

    // A public client gets no cfg, so a Named configuration has nothing to
    // supply. The tab is not offered to it, so only a hand-built request
    // gets here.
    if($oa4mpServer->isPublicClient($client)) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.public'), array('key' => 'error'));

      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'edit';
      $args[] = $clientId;
      $this->redirect($args);
      return true;
    }

    // Choosing the state the client is already in sends nothing. The one
    // exception is re-saving the applied Named configuration on a client
    // that still holds an access token row, which an older client kept and
    // still sends: that runs the confirmed apply, which deletes it.
    $currentId = $client['Oa4mpClientCoOidcClient']['named_config_id'] ?? null;
    $unchanged = $removing
                 ? empty($currentId)
                 : ((string)$currentId === (string)$selectedConfig['id']
                    && empty($client['Oa4mpClientAccessToken']['id']));

    if($unchanged) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.flash.unchanged'), array('key' => 'information'));
      $this->_redirectToManage($clientId);
      return true;
    }

    // Nothing is sent until the user has seen what the change deletes and
    // confirmed it. The confirmation page posts the selection back with the
    // flag, and it passes the allow-list above again.
    if(empty($this->request->data['Oa4mpClientCoNamedConfig']['confirmed'])) {
      $this->_renderConfirmation($oa4mpServer, $client, $selectedConfig);
      return true;
    }

    // Once the Named configuration is cleared, no later visit deletes the
    // rows it replaced, and the reset below would bring them back into
    // force. So a removal is not sent while any are left.
    if($removing && $this->namedConfigLeftoversRemain) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.remove_blocked'), array('key' => 'error'));
      $this->_redirectToManage($clientId);
      return true;
    }

    $defaults = null;
    if($removing) {
      $defaults = $oa4mpServer->newClientDefaults($admin['DefaultDynamoConfig'] ?? array(), false);
      $updatedClient = $this->_removedClient($client, $defaults);
    } else {
      $updatedClient = $this->_appliedClient($client, $selectedConfig);
    }

    // Call out to oa4mp server.
    // Return value of 0 indicates an error saving the edit.
    // Return value of 2 indicates the plugin representation of the client
    // and the Oa4mp server representation of the client are out of sync.
    $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $updatedClient);

    if($ret == 0) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));
      return false;
    }

    if($ret == 2) {
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));
      return false;
    }

    // The server accepted the change, so nothing here undoes it. A failed
    // write is reported as an error, never as success.
    if($removing) {
      $this->_writeRemoval($clientModel, $clientId, $client, $defaults);
    } else {
      $this->_writeApply($clientModel, $clientId, $client, $selectedConfig);
    }

    $this->_redirectToManage($clientId);
    return true;
  }

  /**
   * The client as sent when a Named configuration is applied: the Named
   * configuration, and none of the client's own sections it replaces.
   *
   * The sections are emptied rather than left out: current() reads a missing
   * hasOne row as an array of null-valued fields, which the marshaller would
   * still see as present.
   *
   * @param  array $client The client, as current() returns it
   * @param  array $config The Named configuration row, from the admin client
   * @return array
   */

  protected function _appliedClient($client, $config) {
    $updated = $client;
    $updated['Oa4mpClientCoOidcClient']['named_config_id'] = $config['id'];
    // The cfg names the Named configuration's edit page from this row, which
    // must be the one being applied, not the one being replaced.
    $updated['Oa4mpClientCoNamedConfig'] = $config;

    foreach(array('Oa4mpClientCoScope', 'Oa4mpClientClaim', 'Oa4mpClientCoLdapConfig',
                  'Oa4mpClientAuthorization', 'Oa4mpClientAccessToken', 'Oa4mpClientDynamoConfig') as $m) {
      $updated[$m] = array();
    }

    return $updated;
  }

  /**
   * The client as sent when its Named configuration is removed: no Named
   * configuration, and the settings a new confidential client starts with in
   * place of anything it held before.
   *
   * @param  array $client   The client, as current() returns it
   * @param  array $defaults From Oa4mpClientOa4mpServer::newClientDefaults()
   * @return array
   */

  protected function _removedClient($client, $defaults) {
    $updated = $client;
    $updated['Oa4mpClientCoOidcClient']['named_config_id'] = null;
    $updated['Oa4mpClientCoNamedConfig'] = array();
    $updated['Oa4mpClientClaim'] = array();
    $updated['Oa4mpClientCoLdapConfig'] = array();
    $updated['Oa4mpClientAccessToken'] = array();
    $updated['Oa4mpClientCoScope'] = $defaults['Oa4mpClientCoScope'];
    $updated['Oa4mpClientAuthorization'] = $defaults['Oa4mpClientAuthorization'];
    $updated['Oa4mpClientDynamoConfig'] = $defaults['Oa4mpClientDynamoConfig'];

    return $updated;
  }

  /**
   * After the server accepted an apply: store the Named configuration, then
   * delete the client's own rows it replaces, and the access token row last.
   *
   * The rows were not sent, so a delete that fails leaves nothing wrong on the
   * server and the next visit tries again. The access token row is the
   * exception: an older client's row is still sent, so a failure there asks
   * the user to apply the Named configuration again.
   *
   * @param  Model   $clientModel The Oa4mpClientCoOidcClient model
   * @param  integer $clientId    Oa4mpClientCoOidcClient ID
   * @param  array   $client      The client, as current() returns it
   * @param  array   $config      The Named configuration applied
   * @return void
   */

  protected function _writeApply($clientModel, $clientId, $client, $config) {
    $clientModel->id = $clientId;
    if(!$clientModel->saveField('named_config_id', $config['id'])) {
      // Nothing else is written: the Registry still describes the client as
      // it was before, which the out-of-sync message tells the user to restore.
      $this->_logWriteFailure($client, 'store the Named configuration');
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.edit.save'), array('key' => 'error'));
      return;
    }

    // With the Named configuration stored, the prune deletes exactly the rows
    // it replaces, LDAP before claims, found by the stored client id.
    $pruned = $clientModel->pruneNamedConfigLeftovers($clientId);
    $tokenDeleted = $this->_deleteClientRows($clientModel->Oa4mpClientAccessToken, $clientId);

    if(!$tokenDeleted) {
      $this->_logWriteFailure($client, 'delete the access token setting');
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.cleanup.access_token',
                             array($config['config_name'])),
                        array('key' => 'error'));
    } elseif(!$pruned) {
      $this->_logWriteFailure($client, 'delete the settings the Named configuration replaces');
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.cleanup.apply'), array('key' => 'error'));
    } else {
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'), array('key' => 'success'));
    }
  }

  /**
   * After the server accepted a removal: delete the access token row, clear
   * the Named configuration, then create the new-client settings.
   *
   * The access token row goes first and a failure stops there: it is still
   * sent while the Named configuration stays stored, but once that is
   * cleared nothing would delete it, and the next edit would send it again.
   *
   * @param  Model   $clientModel The Oa4mpClientCoOidcClient model
   * @param  integer $clientId    Oa4mpClientCoOidcClient ID
   * @param  array   $client      The client, as current() returns it
   * @param  array   $defaults    From Oa4mpClientOa4mpServer::newClientDefaults()
   * @return void
   */

  protected function _writeRemoval($clientModel, $clientId, $client, $defaults) {
    if(!$this->_deleteClientRows($clientModel->Oa4mpClientAccessToken, $clientId)) {
      $this->_logWriteFailure($client, 'delete the access token setting');
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.edit.save'), array('key' => 'error'));
      return;
    }

    $clientModel->id = $clientId;
    if(!$clientModel->saveField('named_config_id', null)) {
      $this->_logWriteFailure($client, 'clear the Named configuration');
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.edit.save'), array('key' => 'error'));
      return;
    }

    // One row at a time, and every one attempted, so a single failure leaves
    // as little as possible missing. Validation is skipped: these are the
    // plugin's own defaults and the server has already accepted them.
    $rows = array();
    foreach($defaults['Oa4mpClientCoScope'] as $scope) {
      $rows[] = array($clientModel->Oa4mpClientCoScope, $scope);
    }
    $rows[] = array($clientModel->Oa4mpClientAuthorization, $defaults['Oa4mpClientAuthorization']);
    // An admin client with no default DynamoDB configuration has nothing to
    // copy, and the client then reads the admin client's default.
    if(!empty($defaults['Oa4mpClientDynamoConfig']['aws_region'])) {
      $rows[] = array($clientModel->Oa4mpClientDynamoConfig, $defaults['Oa4mpClientDynamoConfig']);
    }

    $created = true;
    foreach($rows as $r) {
      list($model, $fields) = $r;
      $fields['client_id'] = $clientId;

      $model->create();
      if(!$model->save(array($model->alias => $fields), false)) {
        $created = false;
      }
    }

    if(!$created) {
      $this->_logWriteFailure($client, 'create the settings a new client starts with');
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.cleanup.remove'), array('key' => 'error'));
    } else {
      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'), array('key' => 'success'));
    }
  }

  /**
   * Delete every row of $model that belongs to the client, one at a time,
   * found by the stored client id: some clients hold more than one row of a
   * hasOne model.
   *
   * @param  Model   $model    A model with a client_id column
   * @param  integer $clientId Oa4mpClientCoOidcClient ID
   * @return boolean true if every row was deleted
   */

  protected function _deleteClientRows($model, $clientId) {
    $args = array();
    $args['conditions'][$model->alias . '.client_id'] = $clientId;
    $args['fields'] = array($model->alias . '.id');
    $args['order'] = array($model->alias . '.id ASC');
    $args['contain'] = false;

    $deleted = true;

    foreach($model->find('all', $args) as $row) {
      if(!$model->delete($row[$model->alias]['id'], true)) {
        $deleted = false;
      }
    }

    return $deleted;
  }

  /**
   * Log a Registry write that failed after the OA4MP server accepted a
   * Named configuration change.
   *
   * @param  array  $client The client, as current() returns it
   * @param  string $what   What could not be done
   * @return void
   */

  protected function _logWriteFailure($client, $what) {
    $this->log("Oa4mpClient: the OA4MP server accepted the Named configuration change for OIDC client "
               . ($client['Oa4mpClientCoOidcClient']['oa4mp_identifier'] ?? '?')
               . " but Registry could not " . $what);
  }

  /**
   * Show the confirmation for applying or removing a Named configuration.
   * Nothing has been sent or written.
   *
   * @param  Oa4mpClientOa4mpServer $oa4mpServer    OA4MP server object
   * @param  array                  $client         The client, as current() returns it
   * @param  array|null             $selectedConfig The chosen Named configuration, or null for none
   * @return void
   */

  protected function _renderConfirmation($oa4mpServer, $client, $selectedConfig) {
    $text = array();

    if($selectedConfig === null) {
      $label = $oa4mpServer->namedConfigLabel($client);
      $title = _txt('pl.oa4mp_client_co_named_config.confirm.remove.title', array($label));
      $text[] = _txt('pl.oa4mp_client_co_named_config.confirm.remove.text', array($label));
      $selected = 'none';
    } else {
      $label = $selectedConfig['config_name'];
      $title = _txt('pl.oa4mp_client_co_named_config.confirm.apply.title', array($label));
      $text[] = _txt('pl.oa4mp_client_co_named_config.confirm.apply.text', array($label));
      // The client's authorization settings are not sent while a Named
      // configuration is applied (cilogon/Oa4mpClient#59).
      $text[] = _txt('pl.oa4mp_client_co_named_config.confirm.apply.authz');
      if(!empty($client['Oa4mpClientAccessToken']['is_jwt'])) {
        $text[] = _txt('pl.oa4mp_client_co_named_config.confirm.apply.jwt');
      }
      $selected = (string)$selectedConfig['id'];
    }

    $this->set('vv_confirm_text', $text);
    $this->set('vv_selected_config_id', $selected);
    $this->set('title_for_layout', $title);

    $this->render('confirm');
  }

  /**
   * Redirect to the client's Named Configuration page.
   *
   * @param  integer $clientId Oa4mpClientCoOidcClient ID
   * @return void
   */

  protected function _redirectToManage($clientId) {
    $args = array();
    $args['plugin'] = 'oa4mp_client';
    $args['controller'] = 'oa4mp_client_co_named_configs';
    $args['action'] = 'manage';
    $args['clientid'] = $clientId;
    $this->redirect($args);
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry v4.0.2
   * @return Array Permissions
   */

  function isAuthorized() {
    // For the manage action, use the standard authorization component
    if($this->action == 'manage') {
      $roles = $this->Role->calculateCMRoles();
      $coId = $this->parseCOID();
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

    // For other actions, use the original admin-only authorization
    // Only authenticated users are authorized.
    if($this->Session->check('Auth.User.username')) {
        $username = $this->Session->read('Auth.User.username');
    } else {
      return false;
    }

    $allowedUsernames = array();

    // If defined read a comma separated list of authorized usernames
    // from environmemt variable.
    $allowedUsernamesString = getenv('COMANAGE_REGISTRY_OA4MP_ADMIN_USERS');

    if($allowedUsernamesString) {
      $allowedUsernames = explode(',', $allowedUsernamesString);
    } else {
      $allowedUsernames[] = $username;
    }

    if(in_array($username, $allowedUsernames)) {
      // Authorized usernames must have the platform admin role.
      $roles = $this->Role->calculateCMRoles();

      $p = array();

      // Add a new admin client?
      $p['add'] = $roles['cmadmin'];

      // Delete an existing admin client?
      $p['delete'] = $roles['cmadmin'];
    
      // Edit an existing admin client?
      $p['edit'] = $roles['cmadmin'];

      // View all existing admin clients?
      $p['index'] = $roles['cmadmin'];
    
      // View an existing admin client?
      $p['view'] = $roles['cmadmin'];

      $this->set('permissions', $p);
      return $p[$this->action];
    } else {
      return false;
    }
  }

  /**
   * Find the provided CO ID.
   * This overrides the method defined in AppController.php
   *
   * @since  COmanage Registry v4.5.0
   * @param  Array $data Array of data for calculating implied CO ID
   * @return Integer The CO ID if found, or -1 if not
   */

  function parseCOID($data = null) {
    // This controller requires passing the OIDC client ID as
    // a named parameter. We can use it to infer the CO ID.
    // The isAuthorized function will enforce that the authenticated
    // user is authorized within that CO to edit the client.
    try {
      // The manage action requires passing the OIDC client ID as
      // a named parameter, but the other actions do not, so if it
      // is not present, just return -1.
      $clientId = $this->request->params['named']['clientid'] ?? null;

      if(empty($clientId)) {
        return -1;
      }

      $oidcClient = $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoAdminClient->Oa4mpClientCoOidcClient->current($clientId);

      if(empty($oidcClient)) {
        throw new RuntimeException(_txt('pl.oa4mp_client_co_oidc_client.er.id'));
      }
    } catch (Exception $e) {
        throw new RuntimeException($e);
      }

      $coid = $oidcClient['Oa4mpClientCoAdminClient']['co_id'];

      return $coid;
  }

  /**
   * Validate and clean POST data from an add or edit action.
   *
   * @since  COmanage Registry v4.0.2
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
    
    // Validate the Named Config client fields.
    $this->Oa4mpClientCoNamedConfig->set($data);

    $fields = array();
    $fields[] = 'admin_id';
    $fields[] = 'config_name';
    $fields[] = 'description';
    $fields[] = 'config';

    $args = array();
    $args['fieldList'] = $fields;

    if(!$this->Oa4mpClientCoNamedConfig->validates($args)) {
      $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
      return false;
    }

    // Validate the standard scope fields and remove empty values.
    for ($i = 0; $i < 50; $i++) {
      if(!isset($data['Oa4mpClientCoScope'][$i])) {
        continue;
      }

      $scope = $data['Oa4mpClientCoScope'][$i];
      if(empty($scope['scope'])) {
        unset($data['Oa4mpClientCoScope'][$i]);
        continue; 
      }

      // We only validate the standard scopes.
      if($i < 5) {
        $d = array();
        $d['Oa4mpClientCoScope'] = $scope;
        $this->Oa4mpClientCoNamedConfig->Oa4mpClientCoScope->set($d);

        $fields = array();
        $fields[] = 'scope';

        $args = array();
        $args['fieldList'] = $fields;

        if(!$this->Oa4mpClientCoNamedConfig->Oa4mpClientCoScope->validates($args)) {
          $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
          return false;
        }
      }
    }

    return $data;
  }
}
