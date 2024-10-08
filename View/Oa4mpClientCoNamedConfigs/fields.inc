<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Named Configs Fields
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
  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_named_configs';
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_named_configs.pl'), $args);
  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_named_configs.1')));
  $this->Html->addCrumb($crumbTxt);

  if(isset($admin_id)) {
    $args = array();
    $args['default'] = $admin_id;
    print $this->Form->hidden('admin_id', $args);
  }
?>

<script type="text/javascript">
function js_local_onload() {
  var visible = $("#oa4mp_co_named_configs_scope_adhoc").find("li:visible").length;
  if (visible == 0) {
    $("#oa4mp_co_named_configs_add_first_additional_scope").show();
  }
}

function addFirstAdditionalScope() {
  $("#oa4mp_client_co_named_configs_scope_5").find("input[type=text]").attr("required", "required");
  $("#oa4mp_client_co_named_configs_scope_5").show();
  $("#oa4mp_co_named_configs_add_first_additional_scope").hide();
  $("#oa4mp_co_named_configs_add_additional_scope").show();
}

function addAdditionalScope() {
  $("#oa4mp_co_named_configs_scope_adhoc").find("li:hidden:first").find("input[type=text]").attr("required", "required");
  $("#oa4mp_co_named_configs_scope_adhoc").find("li:hidden:first").show();
}

function removeAdditionalScope(e) {
  $(e).parent('li').find(':text').val("").removeAttr("required");
  $(e).parent('li').hide();

  var visible = $("#oa4mp_co_named_configs_scope_adhoc").find("li:visible").length;
  if (visible == 0) {
    $("#oa4mp_co_named_configs_add_first_additional_scope").show();
    $("#oa4mp_co_named_configs_add_additional_scope").hide();
  }
}

</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_named_configs" class="fields form-list form-list-admin">

  <!-- CO -->
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('admin_id',_txt('pl.oa4mp_client_co_named_config.admin.fd')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_named_config.admin.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php 
        // Print dropdown selector with CO options
        $options = array();
        foreach($adminClients as $c) {
          $adminId = $c['Oa4mpClientCoAdminClient']['id'];
          $adminName = $c['Oa4mpClientCoAdminClient']['name'];
          $adminIssuer = $c['Oa4mpClientCoAdminClient']['issuer'];
          $coName = $c['Co']['name'];
          $value = $coName . " - " . $adminName . " (" . $adminIssuer . ")";
          $options[$adminId] = $value;
        }
        print $this->Form->select('admin_id', $options);
      ?>
    </div>
  </li>

  <!-- config_name -->
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('config_name',_txt('pl.oa4mp_client_co_named_config.config_name.fd')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_named_config.config_name.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        $args = array();
        print $this->Form->input('config_name', $args);
      ?>
    </div>
  </li>

  <!-- description -->
  <li class="field-stack">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('description',_txt('pl.oa4mp_client_co_named_config.description.fd')); ?>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_named_config.description.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        $args = array();
        print $this->Form->input('description', $args);
      ?>
    </div>
  </li>

  <!-- config -->
  <li class="field-stack">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('config',_txt('pl.oa4mp_client_co_named_config.config.fd')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_named_config.config.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        $args = array();
        print $this->Form->input('config', $args);
      ?>
    </div>
  </li>

  <!-- scopes -->
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_scope.scope.fd.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_named_config.scope.description'); ?>
      </div>
    </div>
  
    <ul class="field-children" id="oa4mp_client_co_scope">
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['checked'] = true;
              $args['onclick'] = 'event.preventDefault();';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.openid.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OpenId;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.0.scope', $args));
              if(!empty($this->request->data['Oa4mpClientCoScope'][0])) {
                print $this->Form->hidden('Oa4mpClientCoScope.0.id');
                print $this->Form->hidden('Oa4mpClientCoScope.0.named_config_id');
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.profile.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Profile;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.1.scope', $args));
              if(!empty($this->request->data['Oa4mpClientCoScope'][1])) {
                print $this->Form->hidden('Oa4mpClientCoScope.1.id');
                print $this->Form->hidden('Oa4mpClientCoScope.1.named_config_id');
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.email.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Email;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.2.scope', $args));
              if(!empty($this->request->data['Oa4mpClientCoScope'][2])) {
                print $this->Form->hidden('Oa4mpClientCoScope.2.id');
                print $this->Form->hidden('Oa4mpClientCoScope.2.named_config_id');
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.org.cilogon.userinfo.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OrgCilogonUserInfo;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.3.scope', $args));
              if(!empty($this->request->data['Oa4mpClientCoScope'][3])) {
                print $this->Form->hidden('Oa4mpClientCoScope.3.id');
                print $this->Form->hidden('Oa4mpClientCoScope.3.named_config_id');
              }
            ?>
        </div>
      </li>
    </ul>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_named_config.additional_scope.fd'); ?>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_named_config.additional_scope.description'); ?>
      </div>
    </div>
    <div class="field-info">

      <?php
          print '<div>';
          $params = array();
          $params['class'] = 'addbutton';
          $params['onclick'] = 'addFirstAdditionalScope()';
          $params['id'] = 'oa4mp_co_named_configs_add_first_additional_scope';
          $params['style'] = 'display:none;';
          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_named_config.add_first_additional_scope'), $params);
          print '</div>';
      ?>
    </div>

    <ul class="field-children" id="oa4mp_co_named_configs_scope_adhoc">

      <?php
        $displayAddScopeButton = false;

        for($i = 5; $i < 50; $i++) {
          if (!empty($this->request->data['Oa4mpClientCoScope'][$i]['scope'])) {
            $display = "";
            $displayAddScopeButton = true;
          } else {
            $display = "display:none";
          }

          print '<li style="' . $display . '" id="oa4mp_client_co_named_configs_scope_' . $i . '" >';
          print '  <div class="field-name">';
          print '    <div class="field-title">';
          print        _txt('pl.oa4mp_client_co_named_config.additional_scope.scope.fd');
          print '    </div>';
          print '  </div>';
          print '  <div class="field-info">';
                      $args = array();
                      $args['type'] = 'text';
                      $args['required'] = false;
          print       $this->Form->input('Oa4mpClientCoScope.' . $i . '.scope', $args);
          print       $this->Form->hidden('Oa4mpClientCoScope.' . $i . '.id');
          print       $this->Form->hidden('Oa4mpClientCoScope.' . $i . '.named_config_id');
          print '  </div>'; 
                   $params = array();
                   $params['class'] = "deletebutton";
                   $params['type'] = "button";
                   $params['onclick'] = 'removeAdditionalScope(this)';
          print    $this->Html->tag('button', _txt('op.delete'), $params);
          print '</li>';
        }
      ?>
      
      <?php
          print '<div>';
          $params = array();
          $params['class'] = 'addbutton';
          $params['onclick'] = 'addAdditionalScope()';
          $params['id'] = 'oa4mp_co_named_configs_add_additional_scope';
          if(!$displayAddScopeButton) {
            $params['style'] = 'display:none';
          }
          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_named_config.add_first_additional_scope'), $params);
          print '</div>';
      ?>



    </ul>
  </li>

  <li class="fields-submit">
    <div class="field-name">
      <span class="required"><?php print _txt('fd.req'); ?></span>
    </div>
    <div class="field-info">
      <?php print $this->Form->submit($submit_label); ?>
    </div>
  </li>
</ul>
