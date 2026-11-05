<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Named Config Confirmation View
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

  // Applying or removing a Named configuration deletes or resets the
  // client's own settings, so the manage action shows this page before
  // anything is sent (cilogon/Oa4mpClient#58). Confirming posts the choice
  // back to the manage action, which checks it again; Cancel is only a link
  // back, so it cannot change anything.

  // Add breadcrumbs
  print $this->element("coCrumb");
  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['co'] = $cur_co['Co']['id'];
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);
  $crumbTxt = _txt('op.edit-a', array(_txt('ct.oa4mp_client_co_oidc_clients.1')));
  $this->Html->addCrumb($crumbTxt);

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  if(!empty($vv_client_id)) {
    if(file_exists(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc")) {
      include(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc");
    } elseif(file_exists(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc")) {
      include(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc");
    }
  }

  print $this->element("pageTitleAndButtons", $params);

  $manageUrl = array(
    'plugin' => 'oa4mp_client',
    'controller' => 'oa4mp_client_co_named_configs',
    'action' => 'manage',
    'clientid' => $vv_client_id
  );
?>

<div class="co-info-topbox">
  <?php foreach($vv_confirm_text as $paragraph): ?>
    <p><?php print filter_var($paragraph, FILTER_SANITIZE_SPECIAL_CHARS); ?></p>
  <?php endforeach; ?>
</div>

<?php
  print $this->Form->create('Oa4mpClientCoNamedConfig', array('url' => $manageUrl));
  print $this->Form->hidden('selected_config_id', array('value' => $vv_selected_config_id));
  print $this->Form->hidden('confirmed', array('value' => '1'));
?>

<ul class="fields form-list form-list-admin">
  <li class="fields-submit">
    <div class="field-name"></div>
    <div class="field-info">
      <?php
        print $this->Form->submit(_txt('pl.oa4mp_client_co_named_config.confirm.button'));
        print $this->Html->link(_txt('op.cancel'), $manageUrl, array('class' => 'cancelbutton'));
      ?>
    </div>
  </li>
</ul>

<?php print $this->Form->end(); ?>
