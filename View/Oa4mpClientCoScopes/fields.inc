<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Scope Fields
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

  // Determine if fields are editable
  $e = false;
  
  if($this->action == "edit_scopes" && $permissions['edit_scopes'])
    $e = true;

  // We shouldn't get here if we don't have at least read permission, but check just in case

  if(!$e && !$permissions['view'])
    return(false);

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

  print $this->Html->css("Oa4mpClient.oa4mpclient");

  if($this->request->data['Oa4mpClientCoOidcClient']['public_client']) {
    $is_public_client = true;
  } else {
    $is_public_client = false;
  }

  $l = 1;
?>
<script type="text/javascript">
  <!-- JS specific to these fields -->
</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_scope" class="fields form-list form-list-admin">

    <li id="oa4mp_client_co_oidc_client_scopes">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_scope.scope.fd.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php
          if($is_public_client) {
            print _txt('pl.oa4mp_client_co_scope.scope.fd.description.public');
          } else {
            print _txt('pl.oa4mp_client_co_scope.scope.fd.description');
          }
        ?>
      </div>
    </div>
    <ul class="field-children" id="oa4mp_client_co_scope">
      <li>
        <div class="field-info form-check checkbox-checked-appear-disabled">
            <span class="material-icons-outlined">check_box</span>
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['checked'] = true;
              $args['onclick'] = 'event.preventDefault();';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.openid.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OpenId;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.0.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][0])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.0.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.profile.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Profile;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.1.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][1])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.1.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.email.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Email;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.2.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][2])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.2.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.org.cilogon.userinfo.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OrgCilogonUserInfo;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.3.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][3])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.3.id');
                }
              }
            ?>
        </div>
      </li>

      <?php if($this->action == "edit" && !empty($this->request->data['Oa4mpClientCoScope'][4])): ?>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['onclick'] = 'showConfirmGetcert()';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.getcert.fd.name');
              $args['value'] = Oa4mpClientScopeEnum::Getcert;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.4.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][4])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.4.id');
                }
              }
            ?>
        </div>
      </li>
      <?php endif;?>

    </ul>
  </li>

  <?php if(!$is_public_client): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php
          print $this->Form->submit(_txt('op.save'));
        ?>
      </div>
    </li>
  <?php endif; ?>

</ul>
