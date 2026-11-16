<?php
/**
 * COmanage Registry Oa4mp Client Plugin Claims Controller
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
App::uses("Oa4mpClientNamedConfigActions", "Oa4mpClient.Controller");
App::uses("StandardController", "Controller");

class Oa4mpClientClaimsController extends StandardController {
  use Oa4mpClientNamedConfigActions;

  // Class name, used by Cake
  public $name = "Oa4mpClientClaims";

  public $components = array('Oa4mpClient.Oa4mpClientAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'Oa4mpClientClaim.id' => 'asc'
    )
  );

  // This controller requires a CO to be set.
  public $requires_co = true;

  /**
   * Construct the OA4MP server object the claims actions talk to.
   *
   * The single construction point for Oa4mpClientOa4mpServer in this
   * controller. It exists so a test subclass can substitute a fake server and
   * drive an action without reaching the network; production behavior is
   * unchanged. Nothing in configuration or the request selects the class --
   * substitution is only possible by subclassing in PHP.
   *
   * @since  COmanage Registry v4.4.2
   * @return Oa4mpClientOa4mpServer
   */

  protected function _oa4mpServer() {
    return new Oa4mpClientOa4mpServer();
  }

  /**
   * Redirect to the claims index with an error when the client is a public
   * client. Public clients release only the standard sub claim, so claim
   * configuration (add, edit, delete) is not permitted for them. Guards the
   * write paths even against a hand-crafted POST or a direct edit/delete URL.
   *
   * @since  COmanage Registry v4.4.2
   * @param  array   $client   Current client data (with Oa4mpClientCoOidcClient)
   * @param  integer $clientId Oa4mpClientCoOidcClient ID
   * @return void    Redirects and exits when the client is a public client
   */

  private function _blockIfPublicClient($client, $clientId) {
    if(!empty($client['Oa4mpClientCoOidcClient']['public_client'])) {
      $this->Flash->set(_txt('pl.oa4mp_client_claim.er.public_client'), array('key' => 'error'));

      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_claims';
      $args['action'] = 'index';
      $args['clientid'] = $clientId;

      $this->redirect($args);
    }
  }

  /**
   * Return the client's own claim with the given id, or redirect to the
   * claims index with an error when the id is not one of the client's claims.
   *
   * Authorization covers the client in the URL, not the claim id in the
   * route, so without this a request could edit or delete another client's
   * claim through a client it may manage (cilogon/Oa4mpClient#56).
   *
   * @param  array   $client   Current client data (with Oa4mpClientClaim)
   * @param  integer $clientId Oa4mpClientCoOidcClient ID
   * @param  mixed   $id       Oa4mpClientClaim ID from the route
   * @return array   The stored claim, with its Oa4mpClientClaimConstraint rows
   */

  private function _requireClientClaim($client, $clientId, $id) {
    if(is_scalar($id) && !empty($client['Oa4mpClientClaim'])) {
      foreach($client['Oa4mpClientClaim'] as $c) {
        if((string)$c['id'] === (string)$id) {
          return $c;
        }
      }
    }

    $this->log("Oa4mpClient: refused claim id " . $this->_postedIdForLog($id)
               . " in " . $this->name . "::" . $this->action
               . ", not a claim of OIDC client " . $client['Oa4mpClientCoOidcClient']['id']);

    $this->Flash->set(_txt('pl.oa4mp_client_claim.er.not_client_claim'), array('key' => 'error'));

    $args = array();
    $args['plugin'] = 'oa4mp_client';
    $args['controller'] = 'oa4mp_client_claims';
    $args['action'] = 'index';
    $args['clientid'] = $clientId;

    $this->redirect($args);
  }

  /**
   * Redirect to the claims index with an error when a claim is given the
   * client's reserved claim name.
   *
   * The DynamoDB claims script keeps the original value of the client's key
   * claim in a claim named cilogon_<key claim> and finds the user by it, so a
   * configured claim of that name is refused before it is sent to the OA4MP
   * server (cilogon/Oa4mpClient#67). The comparison is exact, case included,
   * as the script's is. The form warns about the name and disables its
   * submit button first, so this is reached only when that warning was
   * bypassed, which is why the posted values are not kept for a re-render.
   *
   * @param  string|null $reservedName The client's reserved claim name, or null for none
   * @param  array       $claim        The claim about to be sent and saved
   * @param  integer     $clientId     Oa4mpClientCoOidcClient ID
   * @return void        Redirects and exits when the claim has the reserved name
   */

  private function _refuseReservedClaimName($reservedName, $claim, $clientId) {
    if($reservedName === null
       || !isset($claim['claim_name'])
       || $claim['claim_name'] !== $reservedName) {
      return;
    }

    $this->Flash->set(_txt('pl.oa4mp_client_claim.er.reserved_name', array($reservedName)), array('key' => 'error'));

    $args = array();
    $args['plugin'] = 'oa4mp_client';
    $args['controller'] = 'oa4mp_client_claims';
    $args['action'] = 'index';
    $args['clientid'] = $clientId;

    $this->redirect($args);
  }

  /**
   * Rebuild a posted claim and its constraints around the stored client.
   *
   * The form carries the claim id, its client id and the constraint ids as
   * hidden fields, and saveAssociated() writes whatever rows its data names,
   * so a hand-built post could change or move another client's claim or
   * constraint (cilogon/Oa4mpClient#56). The ids come from the stored client
   * and claim and only the editable fields are taken from the post. The
   * result is both sent to the OA4MP server and saved, so the two match.
   *
   * @param  array      $client      The stored client, as current() returns it
   * @param  array      $posted      The posted request data
   * @param  array|null $storedClaim The stored claim being edited, or null on add
   * @return array      The data the action sends and saves
   */

  protected function claimDataForStoredClient($client, $posted, $storedClaim = null) {
    $clientId = $client['Oa4mpClientCoOidcClient']['id'];
    $claimId = ($storedClaim === null) ? null : $storedClaim['id'];

    $postedClaim = (isset($posted['Oa4mpClientClaim']) && is_array($posted['Oa4mpClientClaim']))
                   ? $posted['Oa4mpClientClaim']
                   : array();
    $postedConstraints = (isset($posted['Oa4mpClientClaimConstraint']) && is_array($posted['Oa4mpClientClaimConstraint']))
                         ? $posted['Oa4mpClientClaimConstraint']
                         : array();

    // Ids posted that did not belong to this client or claim, for the log.
    $foreign = array();

    $claim = array();

    // On add there is no id, so the save inserts a new claim.
    if($claimId !== null) {
      $claim['id'] = $claimId;
    }
    $claim['client_id'] = $clientId;

    if(!$this->_postedIdMatches($postedClaim, 'id', $claimId)) {
      $foreign[] = "claim id " . $this->_postedIdForLog($postedClaim['id']);
    }
    if(!$this->_postedIdMatches($postedClaim, 'client_id', $clientId)) {
      $foreign[] = "client id " . $this->_postedIdForLog($postedClaim['client_id']);
    }

    $claimFields = array(
      'claim_name',
      'source_model',
      'source_model_claim_value_field',
      'claim_value_selection',
      'claim_value_json_format',
      'claim_multiple_value_serialization',
      'claim_value_string_serialization_delimiter'
    );

    foreach($claimFields as $f) {
      if(array_key_exists($f, $postedClaim)) {
        $claim[$f] = $postedClaim[$f];
      }
    }

    $storedConstraintIds = array();
    if(!empty($storedClaim['Oa4mpClientClaimConstraint'])) {
      foreach($storedClaim['Oa4mpClientClaimConstraint'] as $sc) {
        $storedConstraintIds[] = (string)$sc['id'];
      }
    }

    // Keys are kept: the form posts constraints at fixed indexes.
    $constraints = array();

    foreach($postedConstraints as $k => $pc) {
      if(!is_array($pc)) {
        continue;
      }

      $constraint = array();

      // A posted constraint id is kept only when it is one of the edited
      // claim's own constraints; otherwise the save adds a new constraint.
      if(isset($pc['id']) && $pc['id'] !== '') {
        if(is_scalar($pc['id']) && in_array((string)$pc['id'], $storedConstraintIds, true)) {
          $constraint['id'] = $pc['id'];
        } else {
          $foreign[] = "constraint id " . $this->_postedIdForLog($pc['id']);
        }
      }

      if(array_key_exists('claim_id', $pc)) {
        if(!$this->_postedIdMatches($pc, 'claim_id', $claimId)) {
          $foreign[] = "constraint claim id " . $this->_postedIdForLog($pc['claim_id']);
        }
        // On add saveAssociated() sets it to the new claim's id.
        if($claimId !== null) {
          $constraint['claim_id'] = $claimId;
        }
      }

      foreach(array('constraint_field', 'constraint_value') as $f) {
        if(array_key_exists($f, $pc)) {
          $constraint[$f] = $pc[$f];
        }
      }

      $constraints[$k] = $constraint;
    }

    if(!empty($foreign)) {
      $this->log("Oa4mpClient: ignored posted " . implode(", ", $foreign)
                 . " in " . $this->name . "::" . $this->action
                 . " for OIDC client " . $clientId
                 . ($claimId === null ? "" : " claim " . $claimId));
    }

    return array(
      'Oa4mpClientClaim' => $claim,
      'Oa4mpClientClaimConstraint' => $constraints
    );
  }

  /**
   * Whether a posted id is absent, blank, or equal to the expected id.
   *
   * @param  array $posted   Posted row
   * @param  string $key     Key of the id in the row
   * @param  mixed $expected The id the row must carry, or null for none
   * @return boolean True if the posted id does not name some other row
   */

  private function _postedIdMatches($posted, $key, $expected) {
    if(!isset($posted[$key]) || $posted[$key] === '') {
      return true;
    }

    return $expected !== null
           && is_scalar($posted[$key])
           && (string)$posted[$key] === (string)$expected;
  }

  /**
   * An id from the request as it may be written to the log.
   *
   * @param  mixed $id Posted or route id
   * @return string The id, or a placeholder for a value that is not an id
   */

  private function _postedIdForLog($id) {
    return (is_int($id) || (is_string($id) && ctype_digit($id))) ? (string)$id : '(not an id)';
  }

  /**
   * Add a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @return null
   */

  function add() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations. Loading the client
    // deletes any rows its Named configuration replaces.
    $client = $this->_currentClientPruned($this->Oa4mpClientClaim->Oa4mpClientCoOidcClient, $clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // The claim name the DynamoDB claims script keeps for itself, which a
    // save refuses and the form warns about (cilogon/Oa4mpClient#67).
    $reservedClaimName = $oa4mpServer->reservedClaimName($client);
    $this->set('vv_reserved_claim_name', $reservedClaimName);

    // While a Named configuration is applied it supplies the client's
    // claims, so this page is refused, GET and POST alike, before anything is
    // sent (cilogon/Oa4mpClient#58). Checked before the public-client guard,
    // whose redirect to the claims index would be refused in turn.
    $this->_refuseWhileNamedConfigApplied($client, $clientId);

    // Public clients cannot have claims configured.
    $this->_blockIfPublicClient($client, $clientId);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Send and save only a new claim of this client, whatever ids the post
      // carries (cilogon/Oa4mpClient#56).
      $this->request->data = $this->claimDataForStoredClient($client, $this->request->data);

      $this->_refuseReservedClaimName($reservedClaimName, $this->request->data['Oa4mpClientClaim'], $clientId);

      $claims = $client['Oa4mpClientClaim'];

      $newClaim = $this->request->data['Oa4mpClientClaim'];
      $newClaim['Oa4mpClientClaimConstraint'] = $this->request->data['Oa4mpClientClaimConstraint'];

      $claims[] = $newClaim;

      $newClient = array_replace($client, array('Oa4mpClientClaim' => $claims));

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
        // Update successful so save the claim and related constraints.

        // Remove any empty claim constraints.
        $this->request->data['Oa4mpClientClaimConstraint'] = array_filter($this->request->data['Oa4mpClientClaimConstraint'], function($c) {
          return !empty($c['constraint_field']) && !empty($c['constraint_value']);
        });

        // The OA4MP server has already accepted the new claim, so this save is
        // the second half of a pair that must both land. Discarding its result
        // reports success while the plugin and the server disagree, and the
        // synchronization guard then blocks every later edit of this client.
        if(!$this->Oa4mpClientClaim->saveAssociated($this->request->data)) {
          // Set flash and redirect below. This branch must not fall through to
          // the GET logic the way the branches above do:
          //
          //  - that tail clears $this->request->data, so there would be no
          //    posted claim left for the add form to render;
          //  - it re-verifies against the OA4MP server, finds the drift this
          //    failure just created and redirects anyway -- to the OIDC client
          //    list, which is further from this client than the claims index
          //    below;
          //  - and reaching that verification costs a third round trip to the
          //    OA4MP server, on top of the two oa4mpEditClient() has already
          //    made.
          $this->Flash->set(_txt('pl.oa4mp_client_claim.er.add.save'), array('key' => 'error'));
        } else {
          // Set flash successful.
          $this->Flash->set(_txt('pl.oa4mp_client_claim.add.flash.success'), array('key' => 'success'));
        }

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_claims';
        $args['action'] = 'index';
        $args['clientid'] = $clientId;

        $this->redirect($args);
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

      // Name the plugin and the controller. Without them Router::url resolves
      // the target relative to the current request, which lands back on this
      // controller's index with no clientid named parameter -- an action that
      // cannot run. The OIDC client list is the nearest page that can still
      // show this client; the claims index cannot, because it re-verifies on
      // every request and would bounce the user straight back here.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Update oa4mp_server_extra if the OA4MP server returned different extra keys.
    $currentExtra = $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $serverExtra = $verifyResult['oa4mp_server_extra'] ?? null;
    if($serverExtra !== $currentExtra) {
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    // Clear any request data that may have been set during a failed POST/PUT request.
    $this->request->data = null;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.add.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));

    // Set the identifier types for the view
    $this->set('vv_identifier_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Identifier
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the email types for the view
    $this->set('vv_email_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->EmailAddress
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the name types for the view
    $this->set('vv_name_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Name
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the SSH key types for the view
    $sshClass = new ReflectionClass(SshKeyTypeEnum::class);
    $sshConstants = $sshClass->getConstants();
    $this->set('vv_ssh_key_types', array_flip($sshConstants));
  }

  /**
   * Delete a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @param  integer $id Oa4mpClientClaim ID
   * @return null
   */

  function delete($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    // Get the current client and admin configurations. Loading the client
    // deletes any rows its Named configuration replaces.
    $client = $this->_currentClientPruned($this->Oa4mpClientClaim->Oa4mpClientCoOidcClient, $clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    // While a Named configuration is applied it supplies the client's
    // claims, so this page is refused, GET and POST alike, before anything is
    // sent (cilogon/Oa4mpClient#58). Checked before the public-client guard,
    // whose redirect to the claims index would be refused in turn.
    $this->_refuseWhileNamedConfigApplied($client, $clientId);

    // Public clients cannot have claims configured.
    $this->_blockIfPublicClient($client, $clientId);

    // Only one of this client's own claims may be deleted through its URL.
    // Without this the server was sent this client's claims unchanged and
    // then another client's claim was deleted (cilogon/Oa4mpClient#56).
    $this->_requireClientClaim($client, $clientId, $id);

    $oa4mpServer = $this->_oa4mpServer();

    $newClient = $client;

    foreach($client['Oa4mpClientClaim'] as $i => $c) {
      if($c['id'] == $id) {
        unset($newClient['Oa4mpClientClaim'][$i]);
        break;
      }
    }

    // Call out to oa4mp server.
    // Return value of 0 indicates an error saving the edit.
    // Return value of 2 indicates the plugin representation of the client
    // and the Oa4mp server representation of the client are out of sync.
    $ret = $oa4mpServer->oa4mpEditClient($admin, $client, $newClient);
    if($ret == 0) {
      // Set flash and redirect to the claims index. This branch cannot fall
      // through the way its counterparts in add() and edit() do: those actions
      // have a view to render and delete() has none, so falling off the end
      // here raises a missing view error in place of the message.
      $this->Flash->set(_txt('pl.oa4mp_client_co_admin_client.er.edit_error'), array('key' => 'error'));

      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_claims';
      $args['action'] = 'index';
      $args['clientid'] = $clientId;

      $this->redirect($args);
    } elseif($ret == 2) {
      // Set flash and redirect. Same missing view problem as the branch above,
      // but the claims index is not the place to send an out-of-sync client:
      // it re-verifies and would bounce straight back out. Use the target
      // add() and edit() use for this verdict.
      $this->Flash->set(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), array('key' => 'error'));

      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];

      $this->redirect($args);
    } else {
      // Update successful so delete the claim.
      //
      // The OA4MP server has already dropped the claim, so this delete is the
      // second half of a pair that must both land. Discarding its result
      // reports success while the plugin and the server disagree, and the
      // synchronization guard then blocks every later edit of this client.
      if(!$this->Oa4mpClientClaim->delete($id)) {
        // Set flash and redirect below. Falling off the end of this action
        // renders nothing: there is no delete view in this plugin and none in
        // Registry core, so it raises a missing view error in place of this
        // message. The error branches above redirect for the same reason.
        $this->Flash->set(_txt('pl.oa4mp_client_claim.er.delete.remove'), array('key' => 'error'));
      } else {
        // Set flash successful.
        $this->Flash->set(_txt('pl.oa4mp_client_claim.delete.flash.success'), array('key' => 'success'));
      }

      // Redirect to the index view.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_claims';
      $args['action'] = 'index';
      $args['clientid'] = $clientId;

      $this->redirect($args);
    }
  }

  /**
   * Edit a claim.
   *
   * @since  COmanage Registry v4.4.2
   * @param  integer $id Oa4mpClientClaim ID
   * @return null
   */

  function edit($id) {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations. Loading the client
    // deletes any rows its Named configuration replaces.
    $client = $this->_currentClientPruned($this->Oa4mpClientClaim->Oa4mpClientCoOidcClient, $clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // The claim name the DynamoDB claims script keeps for itself, which a
    // save refuses and the form warns about (cilogon/Oa4mpClient#67).
    $reservedClaimName = $oa4mpServer->reservedClaimName($client);
    $this->set('vv_reserved_claim_name', $reservedClaimName);

    // While a Named configuration is applied it supplies the client's
    // claims, so this page is refused, GET and POST alike, before anything is
    // sent (cilogon/Oa4mpClient#58). Checked before the public-client guard,
    // whose redirect to the claims index would be refused in turn.
    $this->_refuseWhileNamedConfigApplied($client, $clientId);

    // Public clients cannot have claims configured.
    $this->_blockIfPublicClient($client, $clientId);

    // Only one of this client's own claims may be edited through its URL
    // (cilogon/Oa4mpClient#56).
    $storedClaim = $this->_requireClientClaim($client, $clientId, $id);

    // POST or PUT request
    if($this->request->is(array('post','put'))) {
      // Send and save only this claim and its own constraints, whatever ids
      // the post carries (cilogon/Oa4mpClient#56).
      $this->request->data = $this->claimDataForStoredClient($client, $this->request->data, $storedClaim);

      $this->_refuseReservedClaimName($reservedClaimName, $this->request->data['Oa4mpClientClaim'], $clientId);

      $newClient = $client;

      foreach($client['Oa4mpClientClaim'] as $i => $c) {
        if($c['id'] == $id) {
          $newClient['Oa4mpClientClaim'][$i] = $this->request->data['Oa4mpClientClaim'];
          if(!empty($this->request->data['Oa4mpClientClaimConstraint'])) {
            $newClient['Oa4mpClientClaim'][$i]['Oa4mpClientClaimConstraint'] = $this->request->data['Oa4mpClientClaimConstraint'];
          }
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
        // Update successful so save the edited claim and associated constraints.

        // Remove any empty claim constraints.
        $this->request->data['Oa4mpClientClaimConstraint'] = array_filter($this->request->data['Oa4mpClientClaimConstraint'], function($c) {
          return !empty($c['constraint_field']) && !empty($c['constraint_value']);
        });

        // The OA4MP server has already accepted the edited claim, so this save
        // is the second half of a pair that must both land. Discarding its
        // result reports success while the plugin and the server disagree, and
        // the synchronization guard then blocks every later edit of this
        // client.
        if(!$this->Oa4mpClientClaim->saveAssociated($this->request->data)) {
          // Set flash and redirect below. This branch must not fall through to
          // the GET logic the way the branches above do:
          //
          //  - that tail reloads the claim from the database into
          //    $this->request->data, so the edit form would come back showing
          //    the stored values rather than the rejected ones;
          //  - it re-verifies against the OA4MP server, finds the drift this
          //    failure just created and redirects anyway -- to the OIDC client
          //    list, which is further from this client than the claims index
          //    below;
          //  - and reaching that verification costs a third round trip to the
          //    OA4MP server, on top of the two oa4mpEditClient() has already
          //    made.
          $this->Flash->set(_txt('pl.oa4mp_client_claim.er.edit.save'), array('key' => 'error'));
        } else {
          // Set flash successful.
          $this->Flash->set(_txt('pl.oa4mp_client_claim.edit.flash.success'), array('key' => 'success'));
        }

        // Redirect to the index view.
        $args = array();
        $args['plugin'] = 'oa4mp_client';
        $args['controller'] = 'oa4mp_client_claims';
        $args['action'] = 'index';
        $args['clientid'] = $clientId;

        $this->redirect($args);
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

      // Name the plugin and the controller. Without them Router::url resolves
      // the target relative to the current request, which lands back on this
      // controller's index with no clientid named parameter -- an action that
      // cannot run. The OIDC client list is the nearest page that can still
      // show this client; the claims index cannot, because it re-verifies on
      // every request and would bounce the user straight back here.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    // Update oa4mp_server_extra if the OA4MP server returned different extra keys.
    $currentExtra = $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $serverExtra = $verifyResult['oa4mp_server_extra'] ?? null;
    if($serverExtra !== $currentExtra) {
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->id = $clientId;
      $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->saveField('oa4mp_server_extra', $serverExtra);
      $client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $serverExtra;
    }

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.edit.name',
               array($client['Oa4mpClientCoOidcClient']['name'])));

    // Set the identifier types for the view
    $this->set('vv_identifier_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Identifier
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the email types for the view
    $this->set('vv_email_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->EmailAddress
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the name types for the view
    $this->set('vv_name_types', $this
                                      ->Oa4mpClientClaim
                                      ->Oa4mpClientCoOidcClient
                                      ->Oa4mpClientCoAdminClient
                                      ->Co
                                      ->CoPerson
                                      ->Name
                                      ->types($this->cur_co['Co']['id'], 'type'));

    // Set the SSH key types for the view
    $sshClass = new ReflectionClass(SshKeyTypeEnum::class);
    $sshConstants = $sshClass->getConstants();
    $this->set('vv_ssh_key_types', array_flip($sshConstants));

    foreach($client['Oa4mpClientClaim'] as $i => $c) {
      if($c['id'] == $id) {
        // For edit mode, we need to load the claim with its constraints
        $args = array();
        $args['conditions']['Oa4mpClientClaim.id'] = $id;
        $args['contain'] = array('Oa4mpClientClaimConstraint');

        $claimWithConstraints = $this->Oa4mpClientClaim->find('first', $args);

        if(!empty($claimWithConstraints)) {
          $this->request->data = $claimWithConstraints;
        } else {
          $this->request->data = array('Oa4mpClientClaim' => $client['Oa4mpClientClaim'][$i]);
        }
        return;
      }
    }
  }

  /**
   * Obtain all claims.
   *
   * @since  COmanage Registry v4.2.2
   * @return null
   */

  function index() {
    $clientId = $this->request->params['named']['clientid'];
    $this->set('vv_client_id', $clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Get the current client and admin configurations. Loading the client
    // deletes any rows its Named configuration replaces.
    $client = $this->_currentClientPruned($this->Oa4mpClientClaim->Oa4mpClientCoOidcClient, $clientId);
    $admin = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->admin($clientId);

    // The tab bar hides the tabs that do not apply to this client.
    $this->set($oa4mpServer->clientTabViewVars($client));

    // While a Named configuration is applied it supplies the client's
    // claims, so this page is refused, GET and POST alike, before anything is
    // sent (cilogon/Oa4mpClient#58).
    $this->_refuseWhileNamedConfigApplied($client, $clientId);

    // Verify that this plugin and the OA4MP server representations
    // of the current client before the edit are synchronized.
    // The array form, as at every other guard. This action used the bare
    // two-argument form, which reduced "the comparison did not run" to the
    // same false a real mismatch produces; reading the same signal as the
    // other twelve is what lets it tell the two apart.
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

      // As in add() and edit(): name the plugin and the controller so the
      // target does not resolve relative to this request. Here it also has to
      // be a different controller, since redirecting this action to its own
      // index would loop.
      $args = array();
      $args['plugin'] = 'oa4mp_client';
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'index';
      $args['co'] = $this->cur_co['Co']['id'];
      $this->redirect($args);
    }

    $this->request->data = $client;

    $this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.edit.name',
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

      $oidcClient = $this->Oa4mpClientClaim->Oa4mpClientCoOidcClient->current($clientId);

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