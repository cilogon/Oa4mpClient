<?php
/**
 * COmanage Registry Oa4mp Client Plugin Authorization View
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

  $authorization = $this->request->data['Oa4mpClientAuthorization'];

  if(file_exists(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientAuthorizations/tabs.inc")) {
    include(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientAuthorizations/tabs.inc");
  } elseif(file_exists(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientAuthorizations/tabs.inc")) {
    include(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientAuthorizations/tabs.inc");
  }

?>

<script type="text/javascript">
function js_local_onload() {
  // Initialize the visibility of both conditional fields
  toggleGroupRedirectUrlField();
  toggleRequireActiveRedirectUrlField();
  
  // Add change event listener to the group dropdown
  $("#Oa4mpClientAuthorizationAuthzCoGroupId").change(function() {
    toggleGroupRedirectUrlField();
  });
  
  // Add change event listener to the require active checkbox
  $("#Oa4mpClientAuthorizationRequireActive").change(function() {
    toggleRequireActiveRedirectUrlField();
  });
}

function toggleGroupRedirectUrlField() {
  var selectedGroup = $("#Oa4mpClientAuthorizationAuthzCoGroupId").val();
  var groupRedirectField = $("#authz_group_redirect_url_field");
  var groupRedirectInput = $("#Oa4mpClientAuthorizationAuthzGroupRedirectUrl");
  
  if (selectedGroup && selectedGroup !== '') {
    // Group is selected, show the redirect URL field
    groupRedirectField.show();
  } else {
    // No group selected, hide the field and clear its value
    groupRedirectField.hide();
    groupRedirectInput.val('');
  }
}

function toggleRequireActiveRedirectUrlField() {
  var requireActiveChecked = $("#Oa4mpClientAuthorizationRequireActive").is(':checked');
  var requireActiveRedirectField = $("#require_active_redirect_url_field");
  var requireActiveRedirectInput = $("#Oa4mpClientAuthorizationRequireActiveRedirectUrl");
  
  if (requireActiveChecked) {
    // Require active is checked, show the redirect URL field
    requireActiveRedirectField.show();
  } else {
    // Require active is not checked, hide the field and clear its value
    requireActiveRedirectField.hide();
    requireActiveRedirectInput.val('');
  }
}
</script>

<?php
print $this->Form->create('Oa4mpClientAuthorization', array('inputDefaults' => array('label' => false, 'div' => false)));

print $this->Form->hidden('client_id', array('value' => $this->request->data['Oa4mpClientCoOidcClient']['id']));

if(!empty($authorization['id'])) {
  print $this->Form->hidden('id');
} 
?>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_authorization" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('authz_co_group_id', _txt('pl.oa4mp_client_authorization.fd.authz_co_group_id')) ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_authorization.fd.authz_co_group_id.desc'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php print $this->Form->select('authz_co_group_id', $this->viewVars['vv_available_groups'], array('empty' => false)); ?>
      </span>
    </div>
  </li>

  <li id="authz_group_redirect_url_field">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('authz_group_redirect_url', _txt('pl.oa4mp_client_authorization.fd.authz_group_redirect_url')) ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_authorization.fd.authz_group_redirect_url.desc'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php print $this->Form->input('authz_group_redirect_url', array('type' => 'text', 'size' => 80)) ?>
      </span>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('require_active', _txt('pl.oa4mp_client_authorization.fd.require_active')) ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_authorization.fd.require_active.desc'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php 
          $checked = isset($authorization['require_active']) ? $authorization['require_active'] : true;
          print $this->Form->input('require_active', array('type' => 'checkbox', 'value' => 1, 'checked' => $checked)) 
        ?>
      </span>
    </div>
  </li>

  <li id="require_active_redirect_url_field">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('require_active_redirect_url', _txt('pl.oa4mp_client_authorization.fd.require_active_redirect_url')) ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_authorization.fd.require_active_redirect_url.desc'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php print $this->Form->input('require_active_redirect_url', array('type' => 'text', 'size' => 80)) ?>
      </span>
    </div>
  </li>

<li class="fields-submit">
  <div class="field-name">
    <span class="required"><?php print _txt('fd.req'); ?></span>
  </div>
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
