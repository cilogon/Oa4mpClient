<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Secret View
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

  // Add breadcrumbs
  print $this->element("coCrumb");
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'));

  // Add page title
  $params = array();
  $params['title'] = _txt('pl.oa4mp_client_co_oidc_client.secret.title');

  // Add top links
  $params['topLinks'] = array();

  print $this->element("pageTitleAndButtons", $params);

?>

<p>
  <em><?php print _txt('pl.oa4mp_client_co_oidc_client.secret.text'); ?></em>
</p>

<table id="oa4mp_client_co_oidc_clients_secret" class="ui-widget">
  <tbody>
    <tr class="line1">
      <td>
        <?php print _txt('pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name'); ?>
      </td>
      <td>
        <?php print $vv_client_id; ?>
      </td>
    </tr>
    <tr class="line2">
      <td>
        <?php print _txt('pl.oa4mp_client_co_oidc_client.secret.fd.name'); ?>
      </td>
      <td>
        <?php print $vv_client_secret; ?>
      </td>
    </tr>
  </tbody>
  
</table>

<?php 

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['action'] = 'index';
  if(isset($cur_co)) {
    $args['co'] = $cur_co['Co']['id'];
  }
  
  print $this->Html->link(_txt('op.cont'), $args, array('class' => 'forwardbutton')); ?>
