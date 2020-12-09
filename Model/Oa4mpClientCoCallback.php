<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Callback Model
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

class Oa4mpClientCoCallback extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoCallback";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp client callback is attached to an OIDC client
    "Oa4mpClient.Oa4mpClientCoOidcClient" => array(
      'foreignKey' => 'client_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'client_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
    ),
    'url' => array(
      'rule' => 'validCallbackUri',
      'required' => true,
      'allowEmpty' => false
    )
  );

  public function validCallbackUri($check) {
    $url = $check['url'];

    $invalid_schemes = array();
    $invalid_schemes[] = 'file';
    $invalid_schemes[] = 'ftp';
    $invalid_schemes[] = 'gopher';
    $invalid_schemes[] = 'ldap';
    $invalid_schemes[] = 'ldaps';
    $invalid_schemes[] = 'mailto';
    $invalid_schemes[] = 'news';
    $invalid_schemes[] = 'telnet';
    $invalid_schemes[] = 'ssh';

    // Wildcards are never allowed.
    if(preg_match('/\*/', $url)) {
      return _txt('pl.oa4mp_client_co_oidc_client.er.wildcards');
    }

    // Try to have the PHP filter_var with FILTER_VALIDATE_URL do
    // most of the checking, but continue with other constraints.
    if(filter_var($check['url'], FILTER_VALIDATE_URL)) {

      // Do not allow invalid schemes.
      $scheme = parse_url($url, PHP_URL_SCHEME);
      if(in_array($scheme, $invalid_schemes)) {
        return _txt('pl.oa4mp_client_co_oidc_client.er.invalid_scheme');
      }

      return true;
    }

    // See https://tools.ietf.org/html/rfc8252#section-7
    // regarding private-use URI schemes.
    //
    // "When choosing a URI scheme to associate with the app, apps MUST use a
    // URI scheme based on a domain name under their control, expressed in
    // reverse order, as recommended by Section 3.8 of [RFC7595] for
    // private-use URI schemes."
    $exploded = explode(':/', $check['url'], 2);
    if(count($exploded) != 2) {
      return _txt('pl.oa4mp_client_co_oidc_client.er.valid_domain');
    }

    $reverseDomain = $exploded[0];
    $path = $exploded[1];

    $reverseDomainPattern = "/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/";
    if(!preg_match($reverseDomainPattern, $reverseDomain)) {
      return _txt('pl.oa4mp_client_co_oidc_client.er.valid_domain');
    }

    // If the path prefixed with http://localhost/ otherwise is valid
    // then accept the URL with the private-use URI scheme.
    if(filter_var('http://localhost/' . $path, FILTER_VALIDATE_URL)) {
      return true;
    }

    // Default invalid.
    return _txt('pl.oa4mp_client_co_oidc_client.er.callback_default');
  }
}
