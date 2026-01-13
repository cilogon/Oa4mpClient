<?php
/**
 * COmanage Registry Oa4mp Client Plugin Refresh Token View
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
 * @since         COmanage Registry v4.5.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

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

  print $this->element("pageTitleAndButtons", $params);

  $refresh_token = $this->request->data['Oa4mpClientRefreshToken'];

  if(file_exists(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc")) {
    include(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc");
  } elseif(file_exists(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc")) {
    include(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc");
  }

?>

<script type="text/javascript">
</script>

<?php
print $this->Form->create('Oa4mpClientRefreshToken', array('inputDefaults' => array('label' => false, 'div' => false)));

print $this->Form->hidden('client_id', array('value' => $this->request->data['Oa4mpClientCoOidcClient']['id']));

if(!empty($refresh_token['id'])) {
  print $this->Form->hidden('id');
} 
?>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_refresh_token" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('token_lifetime', _txt('pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.name')) ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php print $this->Form->input('token_lifetime') ?>
      </span>
    </div>
  </li> 

<li class="fields-submit">
  <div class="field-name"></div>
  <div class="field-info">
    <?php
      $submitText = ($this->action == "add") ? _txt('op.add') : _txt('op.save');
      print $this->Form->submit($submitText);
    ?>
  </div>
</li>

</ul>

<?php
print $this->Form->end();
?>

