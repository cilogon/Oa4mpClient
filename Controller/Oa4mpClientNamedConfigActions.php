<?php
/**
 * COmanage Registry Oa4mp Client Plugin Named Configuration client actions
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
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

/**
 * What every client action does about a Named configuration
 * (cilogon/Oa4mpClient#58).
 *
 * A Named configuration takes the place of the client's own scopes, claims,
 * authorization settings and access token settings. The client controllers
 * share no base class of their own, so the two steps they all take live
 * here: loading the client with its never-sent leftovers deleted, and
 * refusing the pages a Named configuration replaces.
 *
 * The using controller supplies _oa4mpServer(), Flash, log() and redirect(),
 * as every client controller does. The deletes are not placed in
 * beforeFilter(), which the test harnesses never run, or in current(), which
 * authorization also calls and so must not write.
 */

trait Oa4mpClientNamedConfigActions {

  /**
   * Load a client, first deleting the rows its Named configuration replaces.
   *
   * A confidential client that had a Named configuration applied before the
   * replaced rows were deleted on apply still holds them. They were never
   * sent, so deleting them changes nothing on the OA4MP server, but kept,
   * they would come back into force when the Named configuration is
   * removed. They are deleted the next time any page of the client is
   * opened. The access token row is kept, since it is still sent, and a
   * public client is left for the maintainer to clear by hand.
   *
   * A failed delete is logged and reported but does not block the page: the
   * rows are not sent, and the next visit tries again.
   *
   * @param  Model   $clientModel The Oa4mpClientCoOidcClient model
   * @param  integer $clientId    Oa4mpClientCoOidcClient ID
   * @return array   The client as current() returns it, after the deletes
   */

  protected function _currentClientPruned($clientModel, $clientId) {
    $client = $clientModel->current($clientId);

    $oa4mpServer = $this->_oa4mpServer();

    // Only such a client can have anything deleted, so only it is read again.
    if(!$oa4mpServer->usesNamedConfig($client) || $oa4mpServer->isPublicClient($client)) {
      return $client;
    }

    if(!$clientModel->pruneNamedConfigLeftovers($clientId)) {
      $this->log("Oa4mpClient: could not delete every setting the Named configuration replaces for OIDC client "
                 . ($client['Oa4mpClientCoOidcClient']['oa4mp_identifier'] ?? '?')
                 . " in " . $this->name . "::" . $this->action);

      $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.prune'), array('key' => 'error'));
    }

    // Later code must see what is left, not what was deleted.
    return $clientModel->current($clientId);
  }

  /**
   * Refuse a page a Named configuration replaces: Scopes, Claims, Access
   * Tokens and Authorization. Called on every request, GET and POST, before
   * any server call or write.
   *
   * A confidential client is sent to its Named Configuration page, where the
   * Named configuration can be removed. A public client cannot open that
   * page, so it is sent to its edit page. Neither is sent back to the
   * refused page, which would loop.
   *
   * @param  array   $client   Client data, as current() returns it
   * @param  integer $clientId Oa4mpClientCoOidcClient ID
   * @return void    Redirects and exits while a Named configuration is applied
   */

  protected function _refuseWhileNamedConfigApplied($client, $clientId) {
    $oa4mpServer = $this->_oa4mpServer();

    if(!$oa4mpServer->usesNamedConfig($client)) {
      return;
    }

    $this->Flash->set(_txt('pl.oa4mp_client_co_named_config.er.tab_replaced',
                           array($oa4mpServer->namedConfigLabel($client))),
                      array('key' => 'error'));

    $args = array();
    $args['plugin'] = 'oa4mp_client';

    if($oa4mpServer->isPublicClient($client)) {
      $args['controller'] = 'oa4mp_client_co_oidc_clients';
      $args['action'] = 'edit';
      $args[] = $clientId;
    } else {
      $args['controller'] = 'oa4mp_client_co_named_configs';
      $args['action'] = 'manage';
      $args['clientid'] = $clientId;
    }

    $this->redirect($args);
  }
}
