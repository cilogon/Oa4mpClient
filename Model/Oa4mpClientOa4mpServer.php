<?php
/**
 * COmanage Registry Oa4mp Client Plugin OA4MP Server Model
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
 * @since         COmanage Registry v4.2.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("HttpSocket", "Network/Http");

class Oa4mpClientOa4mpServer extends AppModel {
  public $useTable = false;

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

    if($curClient['public_client'] != $oa4mpClient['public_client']) {
      $this->log("Oa4mpClientCoOidcClient public_client is out of sync");
      return false;
    }

    // Compare refresh token lifetime.
    //
    // The state where the OA4MP server has a refresh token lifetime of exactly
    // zero and our representation does not have a value is considered to be
    // synchronized.

    $curRefreshToken = $curData['Oa4mpClientRefreshToken'];
    $oa4mpRefreshToken = $oa4mpServerData['Oa4mpClientRefreshToken'];

    if($curRefreshToken['token_lifetime'] != $oa4mpRefreshToken['token_lifetime']) {
      if(!(is_null($curRefreshToken['token_lifetime']) && ($oa4mpRefreshToken['token_lifetime'] === 0))) {
        $this->log("Oa4mpClientRefreshToken token_lifetime is out of sync");
        return false;
      }
    }

    // Compare email addresses.
    $curEmails = array();
    $oa4mpEmails = array();

    foreach(($curData['Oa4mpClientCoEmailAddress'] ?? array()) as $key => $e) {
      $curEmails[] = $e['mail'];
    }

    foreach(($oa4mpServerData['Oa4mpClientCoEmailAddress'] ?? array()) as $key => $e) {
      $oa4mpEmails[] = $e['mail'];
    }

    sort($curEmails);
    sort($oa4mpEmails);

    if($curEmails != $oa4mpEmails) {
      $this->log("Oa4mpClientCoEmailAddress emails are out of sync");
      return false;
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

    // Does this client used a named configuration?
    if(!empty($curData['Oa4mpClientCoOidcClient']['named_config_id'])) {
      $usesNamedConfig = true;
    } else {
      $usesNamedConfig = false;
    }

    // Compare scopes.
    $curScopes = array();
    $oa4mpScopes = array();

    if($usesNamedConfig) {
      // Compare the scopes sent by the OA4MP server to the scopes
      // specified as part of the named configuration.
      $usedNamedConfigId = $curData['Oa4mpClientCoOidcClient']['named_config_id'];
      foreach($curData['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] as $config) {
        if($config['id'] == $usedNamedConfigId) {
          foreach($config['Oa4mpClientCoScope'] as $s) {
            if(in_array($s['scope'], Oa4mpClientScopeEnum::$allScopesArray)) {
              $curScopes[] = $s['scope'];
            }
          }
          break;
        }
      }
    } else {
      // Compare the scopes sent by the OA4MP server to the scopes
      // linked to this OIDC client instance.
      foreach($curData['Oa4mpClientCoScope'] as $key => $s) {
        $curScopes[] = $s['scope'];
      }
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

    // Compare the comment.
    if(empty($oa4mpClient['comment'])) {
      $this->log("The OA4MP server representation of the client does not include a comment");
      return false;
    }

    if(!str_starts_with($oa4mpClient['comment'], _txt('pl.oa4mp_client_co_oidc_client.signature'))) {
      $this->log("The OA4MP server respresentation of the client has comment");
      $this->log($oa4mpClient['comment']);
      $this->log("but the comment should start with");
      $this->log(_txt('pl.oa4mp_client_co_oidc_client.signature'));
      return false;
    }

    // If this client uses a named configuration than return true here,
    // else continue with more detailed comparison.
    if($usesNamedConfig) {
      return true;
    }

    // Compare access token configuration.
    if($curData['Oa4mpClientAccessToken'] && $curData['Oa4mpClientAccessToken']['is_jwt'] && !$oa4mpServerData['Oa4mpClientAccessToken']) {
      $this->log("Oa4mpClientAccessToken plugin has access token configuration but Oa4mp server does not");
      return false;
    }

    if(!$curData['Oa4mpClientAccessToken'] && $oa4mpServerData['Oa4mpClientAccessToken']) {
      $this->log("Oa4mpClientAccessToken Oa4mp server has access token configuration but plugin does not");
      return false;
    }

    if($curData['Oa4mpClientAccessToken'] && $oa4mpServerData['Oa4mpClientAccessToken']) {
      if($curData['Oa4mpClientAccessToken']['is_jwt'] != $oa4mpServerData['Oa4mpClientAccessToken']['is_jwt']) {
        $this->log("Oa4mpClientAccessToken is_jwt is out of sync");
        return false;
      }
    }

    // Compare client authorization configuration.
    if(!empty($curData['Oa4mpClientAuthorization']['id']) && empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      $this->log("Oa4mpClientAuthorization plugin has authorization configuration but Oa4mp server does not");
      return false;
    }

    if(empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      $this->log("Oa4mpClientAuthorization Oa4mp server has authorization configuration but plugin does not");
      return false;
    }

    if(!empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      if($curData['Oa4mpClientAuthorization']['require_active'] != ($oa4mpServerData['Oa4mpClientAuthorization']['require_active'] ?? null)) {
        $this->log("Oa4mpClientAuthorization require_active is out of sync");
        return false;
      }
    }

    if(!empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      if($curData['Oa4mpClientAuthorization']['authz_co_group_id'] != ($oa4mpServerData['Oa4mpClientAuthorization']['authz_co_group_id'] ?? null)) {
        $this->log("Oa4mpClientAuthorization authz_co_group_id is out of sync");
        return false;
      }
    }

    // TODO: Remove these LDAP comparisons.
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
        // We accept 'username' is the same as 'uid' so normalize and
        // then compare again.
        $cur = $curLdap['search_name'];
        $ser = $serLdap['search_name'];
        if($cur == 'username') {
          $cur = 'uid';
        }
        if($ser == 'username') {
          $ser = 'uid';
        }
        if($cur !== $ser) {
          $this->log("Oa4mpClientCoLdapConfig search_name is out of sync");
          return false;
        }
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

    $body = $this->oa4mpMarshallContent($adminClient, $data);

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
   * Marshall Oa4mpClientCoLdapConfig object for oa4mp server using deprecated syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $data Posted client data after validation
   * @return array cfg object to be sent to oa4mp server
   */
  function oa4mpMarshallCfgDeprecated($data) {
    $cfg = array();

    $cfg['config'] = _txt('pl.oa4mp_client_co_oidc_client.signature');
    $cfg['claims'] = array();
    $cfg['claims']['sourceConfig'] = array();

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

    $cfg['claims']['sourceConfig'][] = array('ldap' => $ldap);

    $preProcessing = array();
    $preProcessing['$if'] = array('$true');
    $preProcessing['$then'] = array(array('$set_claim_source' => array('LDAP', $id)));

    $cfg['claims']['preProcessing'] = array();
    $cfg['claims']['preProcessing'][] = $preProcessing;

    return $cfg;
  }

  /**
   * Marshall Oa4mpClientCoLdapConfig object for oa4mp server using QDL syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $data Posted client data after validation
   * @return array cfg object to be sent to oa4mp server
   */
  function oa4mpMarshallCfgQdl($data) {
    // If using a named configuration then just return the cfg for that 
    // named configuration.
    if(!empty($data['Oa4mpClientCoNamedConfig']['id'])) {
      $jsonString = $c['config'];
      $cfg = json_decode($jsonString, true);

      // Add metadata with URL to the named configuration if not already present.
      if($cfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] ?? true) {

        $routingArray = array();
        $routingArray['plugin'] = 'oa4mp_client';
        $routingArray['controller'] = 'oa4mp_client_co_named_configs';
        $routingArray['action'] = 'edit';
        $routingArray[] = $data['Oa4mpClientCoNamedConfig']['id'];

        $cfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] = Router::url($routingArray, true);
      }

      return $cfg;
    }

    // Older admin clients may not have the QDL path set so use the configured
    // default, or a hard-coded default as a last resort.
    if(!empty($data['Oa4mpClientCoAdminClient']['qdl_claim_source'])) {
      $qdlClaimSourcePath = $data['Oa4mpClientCoAdminClient']['qdl_claim_source'];
    } elseif(!empty(getenv('COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT'))) {
      $qdlClaimSourcePath = getenv('COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT');
    } else{
      $qdlClaimSourcePath = 'COmanageRegistry/default/ldap_claims.qdl';
    }

    // Now construct the OA4MP cfg object.
    $cfg = array();

    // Access token configuration.
    if(!empty($data['Oa4mpClientAccessToken']) && $data['Oa4mpClientAccessToken']['is_jwt']) {
      $cfg['tokens']['access']['type'] = 'access';
    }

    // Identity token configuration.
    $cfg['tokens']['identity']['type'] = 'identity';

    $qdl = array();

    $qdl['load'] = $qdlClaimSourcePath;

    $qdl['xmd'] = array();
    $qdl['xmd']['exec_phase'] = array();
    $qdl['xmd']['exec_phase'][] = 'post_auth';
    $qdl['xmd']['exec_phase'][] = 'post_refresh';
    $qdl['xmd']['exec_phase'][] = 'post_token';
    $qdl['xmd']['exec_phase'][] = 'post_user_info';

    $qdl['args'] = array();

    // Client authorization configuration.
    if(!empty($data['Oa4mpClientAuthorization']) && $data['Oa4mpClientAuthorization']['require_active']) {
      $qdl['args']['require_active_status'] = $data['Oa4mpClientAuthorization']['require_active'];
    }

    if(!empty($data['Oa4mpClientAuthorization']) && !empty($data['Oa4mpClientAuthorization']['authz_co_group_id'])) {
      $qdl['args']['authorization_group_id'] = $data['Oa4mpClientAuthorization']['authz_co_group_id'];
    }

    $cfg['tokens']['identity']['qdl'] = $qdl;


//    $qdl['args']['server_fqdn'] = parse_url($data['Oa4mpClientCoLdapConfig'][0]['serverurl'], PHP_URL_HOST);
//
//    $server_port = parse_url($data['Oa4mpClientCoLdapConfig'][0]['serverurl'], PHP_URL_PORT);
//
//    if(empty($server_port)) {
//      $scheme = parse_url($data['Oa4mpClientCoLdapConfig'][0]['serverurl'], PHP_URL_SCHEME);
//      if($scheme == 'ldap') {
//        $server_port = 389;
//      } elseif($scheme == 'ldaps') {
//        $server_port = 636;
//      }
//    }
//
//    if(empty($server_port)) {
//      throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.marshall'));
//    }
//
//    $qdl['args']['server_port'] = $server_port;
//
//    $qdl['args']['bind_dn'] = $data['Oa4mpClientCoLdapConfig'][0]['binddn'];
//    $qdl['args']['bind_password'] = $data['Oa4mpClientCoLdapConfig'][0]['password'];
//    $qdl['args']['search_base'] = $data['Oa4mpClientCoLdapConfig'][0]['basedn'];
//    $qdl['args']['search_attribute'] = $data['Oa4mpClientCoLdapConfig'][0]['search_name'];
//
//    $qdl['args']['return_attributes'] = array();
//    $qdl['args']['list_attributes'] = array();
//
//    if(!empty($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'])) {
//      foreach($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'] as $sa) {
//        $qdl['args']['return_attributes'][] = $sa['name'];
//
//        if($sa['return_as_list']) {
//          $qdl['args']['list_attributes'][] = $sa['name'];
//        }
//      }
//    }
//
//
//    $qdl['args']['ldap_to_claim_mappings'] = array();
//    if(!empty($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'])) {
//      foreach($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'] as $sa) {
//        $qdl['args']['ldap_to_claim_mappings'][$sa['name']] = $sa['return_name'];
//      }
//    }
//
//    $cfg['tokens']['identity']['qdl'][] = $qdl;

    return $cfg;
  }

  /**
   * Marshall Oa4mpClientCoOidcClient object for oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @param array $data Posted client data after validation
   * @return array Content to be sent to Oa4mp server after JSON encoding
   */
  function oa4mpMarshallContent($adminClient, $data) {
    $content = array();

    // Default is a non-public client.
    $content['token_endpoint_auth_method'] = 'client_secret_basic';

    if(!empty($data['Oa4mpClientCoOidcClient']['public_client'])) {
      if($data['Oa4mpClientCoOidcClient']['public_client']) {
        $content['token_endpoint_auth_method'] = 'none';
      }
    }

    $content['grant_types'] = array();
    $content['grant_types'][] = 'authorization_code';
    $content['response_types'] = 'code';
    $content['client_name'] = $data['Oa4mpClientCoOidcClient']['name'];
    $content['client_uri']  = $data['Oa4mpClientCoOidcClient']['home_url'];

    // Client metadata per RFC 7591.
    // https://tools.ietf.org/html/rfc7591#section-2
    if(!empty($data['Oa4mpClientCoCallback'])) {
      $content['redirect_uris'] = array();
      foreach($data['Oa4mpClientCoCallback'] as $cb) {
        $content['redirect_uris'][] = $cb['url'];
      }
    }

    if(!empty($data['Oa4mpClientRefreshToken']['token_lifetime'])) {
      if(is_numeric($data['Oa4mpClientRefreshToken']['token_lifetime'])) {
        $content['grant_types'][] = 'refresh_token';
        $content['rt_lifetime'] = $data['Oa4mpClientRefreshToken']['token_lifetime'];
      }
    }

    // Determine if the client uses a named configuration.
    if(!empty($data['Oa4mpClientCoOidcClient']['named_config_id'])) {
      $usesNamedConfig = true;
    } else {
      $usesNamedConfig = false;
    }

    $scopeString = "";
    $strictScopes = true;

    if($usesNamedConfig) {
      // If this client uses a named configuration then create the scope
      // string from the named configuration.
      $usedNamedConfigId = $data['Oa4mpClientCoOidcClient']['named_config_id'];

      foreach($adminClient['Oa4mpClientCoNamedConfig'] as $config) {
        if($usedNamedConfigId == $config['id']) {
          foreach($config['Oa4mpClientCoScope'] as $s) {
            if(!in_array($s['scope'], Oa4mpClientScopeEnum::$allScopesArray)) {
              $strictScopes = false;
            } else {
              $scopeString = $scopeString . " " . $s['scope'];
            }
          }
          break;
        }
      }
    } else {
      // If this client does not used a named configuration then create
      // the scope string from the scopes associated with this client.
      if(!empty($data['Oa4mpClientCoScope'])) {
        $scopeString = "";

        foreach($data['Oa4mpClientCoScope'] as $s) {
          $scopeString = $scopeString . " " . $s['scope'];
        }
      }
    }

    if(!empty($scopeString)) {
      $scopeString = trim($scopeString);
      $content['scope'] = $scopeString;
    }

    // TODO is this still correct? Can we do better?
    //$content['strict_scopes'] = $strictScopes;

    // Today OA4MP only supports a single contact though we send
    // it in a JSON list.
    if(!empty($data['Oa4mpClientCoEmailAddress'][0])) {
      $content['contacts'] = array();
      $content['contacts'][] = $data['Oa4mpClientCoEmailAddress'][0]['mail'];
    }

    // Include a comment that begins with a constant static string
    // appended with a URL to the index view for the clients since we
    // do not yet know that ID for the new client.
    $indexRoutingArray = array();
    $indexRoutingArray['plugin'] = 'oa4mp_client';
    $indexRoutingArray['controller'] = 'oa4mp_client_co_oidc_clients';
    $indexRoutingArray['action'] = 'index';
    $indexRoutingArray['co'] = $adminClient['Oa4mpClientCoAdminClient']['co_id'];

    $indexUrl = Router::url($indexRoutingArray, true);

    $content['comment'] = _txt('pl.oa4mp_client_co_oidc_client.signature') . ': ' . $indexUrl;

    if(!empty($data['Oa4mpClientCoLdapConfig']) || 
       !empty($data['Oa4mpClientCoOidcClient']['named_config_id']) ||
       !empty($data['Oa4mpClientAccessToken']) ||
       !empty($data['Oa4mpClientAuthorization'])) {
      $cfg = $this->oa4mpMarshallCfgQdl($data);
      if(!empty($cfg)) {
        $content['cfg'] = $cfg;
      }
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

    $body = $this->oa4mpMarshallContent($adminClient, $data);

    $request['body'] = json_encode($body);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . print_r($request['body'], true));

    $response = $http->request($request);

    $this->log("Response is " . print_r($response, true));

    # During OA4MP server evolution accept both 200 and 201 as
    # return code when creating a new client.
    if(($response->code == 200) || ($response->code == 201)) {
      $body = json_decode($response->body(), true);
      
      $ret['clientId'] = $body['client_id'];

      if(!empty($body['client_secret'])) {
        $ret['secret']   = $body['client_secret'];
      }
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
    $oa4mpClient['Oa4mpClientRefreshToken']  = array();
    $oa4mpClient['Oa4mpClientAccessToken']   = array();

    try {
      // Try to unmarshall the server object and throw exception
      // for any errors.

      // Unmarshall basic client details.
      $oa4mpClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $oa4mpObject['client_id'];
      $oa4mpClient['Oa4mpClientCoOidcClient']['name'] = $oa4mpObject['client_name'];

      if(array_key_exists('rt_lifetime', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientRefreshToken']['token_lifetime'] = $oa4mpObject['rt_lifetime'];
      }

      if(array_key_exists('comment', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoOidcClient']['comment'] = $oa4mpObject['comment'];
      }

      if(array_key_exists('contacts', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoEmailAddress'] = array();
        foreach ($oa4mpObject['contacts'] as $mail) {
          $oa4mpClient['Oa4mpClientCoEmailAddress'][] = array('mail' => $mail);
        }
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

      // If and only if the server object has token_endpoint_auth_method value none
      // and the single scope openid then this is a public client.
      $oa4mpClient['Oa4mpClientCoOidcClient']['public_client'] = false;
      if(!empty($oa4mpObject['token_endpoint_auth_method'])) {
        if($oa4mpObject['token_endpoint_auth_method'] == 'none') {
          if((count($oa4mpClient['Oa4mpClientCoScope']) == 1) && ($oa4mpClient['Oa4mpClientCoScope'][0]['scope'] == Oa4mpClientScopeEnum::OpenId)) {
            $oa4mpClient['Oa4mpClientCoOidcClient']['public_client'] = true;
          }
        }
      }

      // Unmarshall the cfg object, if any.

      // If no cfg object then we are done.
      if(!isset($oa4mpObject['cfg'])){
        $this->log("No cfg object found in oa4mpObject");
        return $oa4mpClient;
      }
  
      $cfg = $oa4mpObject['cfg'];
      $this->log("Cast JSON cfg from OA4MP server to " . print_r($cfg, true));

      // Try cfg format 3 first.
      $configs = $this->oa4mpUnMarshallCfgQdlv3($cfg);

      if(!empty($configs)) {
        $this->log("Unmarshalled cfg QDLv3 syntax to " . print_r($configs, true));
        $oa4mpClient = array_merge($oa4mpClient, $configs);
          
        return $oa4mpClient;
      }

      // Try cfg format 2 next.
      $ldapConfigs = $this->oa4mpUnMarshallCfgQdlv2($cfg);

      if(!empty($ldapConfigs)) {
        $this->log("Unmarshalled cfg QDL syntax to " . print_r($ldapConfigs, true));
        foreach($ldapConfigs as $ldapConfig) {
          $oa4mpClient['Oa4mpClientCoLdapConfig'][] = $ldapConfig;
        }

        return $oa4mpClient;
      } 

      // If QDL syntax did not work try assuming older deprecated syntax.
      $ldapConfigs = $this->oa4mpUnMarshallCfgDeprecated($cfg);

      if(!empty($ldapConfigs)) {
        $this->log("Unmarshalled deprecated cfg to " . print_r($ldapConfigs, true));
        foreach($ldapConfigs as $ldapConfig) {
          $oa4mpClient['Oa4mpClientCoLdapConfig'][] = $ldapConfig;
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
      
      // cfg is set but we are not able to unmarshall it as a defined cfg format
      // that uses QDL or the deprecated cfg syntax. That is ok, however, since it
      // may now be a Named Configuration.
      return $oa4mpClient;

    } catch(Exception $e) {
      $this->log("oa4mpObject: " . print_r($oa4mpObject, true));
      throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.unmarshall') . ': ' . $e->getMessage());
    }
  }

  /**
   * Unmarshall oa4mp cfg object to oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   * assuming the deprecated cfg syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $cfg oa4mp cfg object
   * @return array of oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   */
  function oa4mpUnMarshallCfgDeprecated($cfg) {
    if(isset($cfg['config'])) {
      if($cfg['config'] != _txt('pl.oa4mp_client_co_oidc_client.signature')) {
        throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.bad_signature'));
      }
    }

    // Initialize empty array. We return an empty array if the oa4mp cfg object
    // does not contain the deprecated syntax.
    $ldapConfigs = array();

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
          $ldapConfigs[] = $ldapConfig;
        }
      }
    }

    return $ldapConfigs;
  }

  /**
   * Unmarshall oa4mp cfg object to oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   * assuming QDL syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $cfg oa4mp cfg object
   * @return array of oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   */
  function oa4mpUnMarshallCfgQdlv2($cfg) {
    // TODO add code to try and verify that the cfg we retrieved
    // is what we expected.

    // Initialize empty array. We return an empty array if the oa4mp cfg object
    // does not contain the expected QDL syntax.
    $ldapConfigs = array();

    // Try to parse the cfg as a defined format. See
    // https://github.com/cilogon/Oa4mpClient/blob/main/cfg_format.md
    try {
      if(!empty($cfg['tokens']['identity']['qdl'])) {
        $qdl_pre_auth = $cfg['tokens']['identity']['qdl'][0];

        if(!empty($qdl_pre_auth['args'])){ 
          $qdl_args = $qdl_pre_auth['args'];

          $ldapConfig = array();

          // This is required by the current schema but is deprecated after
          // the transition to QDL.
          $ldapConfig['authorization_type'] = 'simple';
          $ldapConfig['enabled'] = true;

          $address = $qdl_args['server_fqdn'];
          $port = $qdl_args['server_port'];
          if($port == 636) {
            $ldapConfig['serverurl'] = 'ldaps://' . $address;
          } else {
            $ldapConfig['serverurl'] = 'ldap://' . $address;
          }

          $ldapConfig['binddn'] = $qdl_args['bind_dn'];
          $ldapConfig['password'] = $qdl_args['bind_password'];
          $ldapConfig['basedn'] = $qdl_args['search_base'];
          $ldapConfig['search_name'] = $qdl_args['search_attribute'];

          $listAttributes = $qdl_args['list_attributes'];

          // Initialize the LDAP to claim mappings as empty.
          $ldapToClaimMappings = array();

          if(array_key_exists('ldap_to_claim_mappings', $qdl_args)) {
            // COmanage Registry OA4MP plugin cfg format 2.0.0.
            $ldapToClaimMappings = $qdl_args['ldap_to_claim_mappings'];
          } else {
            // COmanage Registry OA4MP plugin cfg format 1.0.0.
            if(count($cfg['tokens']['identity']['qdl']) == 2) {
              if(array_key_exists('args', $cfg['tokens']['identity']['qdl'][1])){
                $ldapToClaimMappings = $cfg['tokens']['identity']['qdl'][1]['args'];
              }
            }
          }

          $ldapConfig['Oa4mpClientCoSearchAttribute'] = array();

          foreach($ldapToClaimMappings as $key => $mapping) {
            $sa = array();
            $sa['name'] = $key;
            $sa['return_name'] = $mapping;

            if(in_array($key, $listAttributes)) {
              $sa['return_as_list'] = true;
            } else {
              $sa['return_as_list'] = false;
            }

            $ldapConfig['Oa4mpClientCoSearchAttribute'][] = $sa;
          }

          // At this time we assume a single LDAP configuration in the QDL.
          $ldapConfigs[] = $ldapConfig;
        }
      }
    } catch (Exception $e) {
      $this->log("Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration");
      return array();
    } catch (TypeError $e) {
      $this->log("Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration");
      return array();
    }

    return $ldapConfigs;
  }

  /**
   * TODO: fix this description.
   * Unmarshall oa4mp cfg object to oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   * assuming QDL syntax.
   *
   * @since COmanage Registry 4.5.0
   * @param array $cfg oa4mp cfg object
   * @return array of oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   */
  function oa4mpUnMarshallCfgQdlv3($cfg) {
    $oa4mpClient = array();

    // Unmarshall access token configuration.
    if(!empty($cfg['tokens']['access']['type'])) {
      if($cfg['tokens']['access']['type'] == 'access') {
        $oa4mpClient['Oa4mpClientAccessToken'] = array();
        $oa4mpClient['Oa4mpClientAccessToken']['is_jwt'] = true;
      }
    }

    // Unmarshall client authorization configuration.
    if(!empty($cfg['tokens']['identity']['qdl']['args'])) {
      $qdlArgs = $cfg['tokens']['identity']['qdl']['args'];

      $authz = array();
      if(!empty($qdlArgs['require_active_status'])) {
        $authz['require_active'] = $qdlArgs['require_active_status'];
      }
      if(!empty($qdlArgs['authorization_group_id'])) {
        $authz['authz_co_group_id'] = $qdlArgs['authorization_group_id'];
      }

      if(!empty($authz)) {
        $oa4mpClient['Oa4mpClientAuthorization'] = $authz;
      }
    }

    // TODO: implement more of this.

    // This is a placeholder for the claims configuration.
    $oa4mpClient['Oa4mpClient']['Oa4mpClaim'] = array();

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
}
