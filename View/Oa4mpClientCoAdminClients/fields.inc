<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Admin Client Fields
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

  // Determine if fields are editable
  $e = false;
  
  if(($this->action == "add" && $permissions['add']) || ($this->action == "edit" && $permissions['edit']))
    $e = true;
    
  // We shouldn't get here if we don't have at least read permission, but check just in case
  
  if(!$e && !$permissions['view'])
    return(false);

  // Add breadcrumbs
  print $this->element("coCrumb");
  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_admin_clients';
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_admin_clients.pl'), $args);
  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_admin_clients.1')));
  $this->Html->addCrumb($crumbTxt);

  if(isset($oa4mp_client_co_admin_clients) && $e) {
    print $this->Form->hidden('DefaultLdapConfig.id', array('default' => $oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['id'])) . "\n";
  }
  
  $l = 1;
?>
<script type="text/javascript">
  <!-- JS specific to these fields -->

  function on_submit() {
    // Determine if the form is valid.
    var isValid = true;
    $('input,textarea,select').filter('[required]:visible').each(function() {
      if($(this).val() == "") {
        isValid = false;
      }
    });

    // If the form is valid then open the dialog box for confirmation.
    if(isValid) {
      $("#on-save-dialog").dialog("open");
    }

    return false;
  }

  function js_local_onload() {
    // Define the dialog box used when save button is clicked for edit action.
    $("#on-save-dialog").dialog({
        autoOpen: false,
        buttons: {
          "<?php print _txt('pl.oa4mp_client_co_admin_client.save.dialog.understand'); ?>": function() {
            $(this).dialog("close");
            // Submit the form.
            $("#Oa4mpClientCoAdminClientEditForm").submit();
          }
        },
        modal: true,
        show: {
          effect: "fade"
        },
        hide: {
          effect: "fade"
        }
      });

    // Decorate the save button. This is a copy of Javascript taken out of the view element file 
    // View/Elements/javascript.ctp which applies for all submit buttons. We need it since our save
    // button is not of type submit.
    $("#oa4mpadminclientsavebutton").addClass("spin submit-button mdl-button mdl-js-button mdl-button--raised mdl-button--colored mdl-js-ripple-effect");
  }
  
</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_admin_client" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('co_id',_txt('co')) : _txt('co')); ?>
        <span class="required">*</span>
      </div>
    </div>
    <div class="field-info">
      <?php 
      if($e) {
        if (!empty($this->viewVars['co_options'] )) {
          // Print dropdown selector with CO options
          print $this->Form->select('co_id', $this->viewVars['co_options']);
        } else {
          // Do not print empty selector
          print _txt('pl.oa4mp_client_co_admin_client.co_id.fd.all_taken');
        }
      }
      else {
        print filter_var($Oa4mpClientCoAdminClient[0]['Co']['name'],FILTER_SANITIZE_SPECIAL_CHARS);
      }
      ?>
    </div>
  </li>

  <?php if($this->action != 'add'): ?>
    <li>
      <div class="field-name">
        <div class="field-title">
          <?php print ($e
                       ? $this->Form->label('manage_co_group_id', _txt('pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.name'))
                       : _txt('pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.name')); ?>
        </div>
        <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.manage_co_group_id.fd.description'); ?></div>
      </div>
      <div class="field-info">
          <?php print ($e
                       ? $this->Form->select('manage_co_group_id', $this->viewVars['vv_available_groups'])
                       : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['manage_co_group_id'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
      </div>
    </li>
  <?php endif; ?>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('serverurl', _txt('pl.oa4mp_client_co_admin_client.serverurl.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.serverurl.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.serverurl.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php print ($e
                     ? $this->Form->input('serverurl')
                     : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['serverurl'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('admin_identifier', _txt('pl.oa4mp_client_co_admin_client.admin_identifier.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.admin_identifier.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.admin_identifier.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php print ($e
                     ? $this->Form->input('admin_identifier')
                     : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['admin_identifier'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('secret', _txt('pl.oa4mp_client_co_admin_client.secret.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.secret.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.secret.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php 
          if($e) { 
            $args = array();
            $args['type'] = 'password';
            $args['maxlength'] = '96';
            $args['style'] = 'width: 80%';
            echo $this->Form->input('secret', $args);
          } else {
            echo filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['secret'],FILTER_SANITIZE_SPECIAL_CHARS); 
          }
        ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.serverurl', _txt('pl.oa4mp_client_co_ldap_config.serverurl.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.serverurl.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.server.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.serverurl')
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['serverurl'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.binddn', _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.binddn.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.binddn')
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['binddn'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.password', _txt('pl.oa4mp_client_co_ldap_config.password.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.password.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.bindpassword.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.password')
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['password'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.basedn', _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.searchbase.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.basedn')
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['basedn'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php 
          if($this->action == "add") {
            print $this->Form->submit($submit_label);
          } elseif ($this->action =="edit") {
            $args = array();
            $args['type'] = 'button';
            $args['class'] = 'submit-button';
            $args['onClick'] = 'on_submit()';
            $args['id'] = 'oa4mpadminclientsavebutton';
            print $this->Form->button($submit_label, $args);
          }
        ?>
      </div>
    </li>
  <?php endif; ?>
</ul>

<div id="on-save-dialog" title="<?php print _txt('pl.oa4mp_client_co_admin_client.save.dialog.title'); ?>" style="display:none">
  <p><?php print _txt('pl.oa4mp_client_co_admin_client.save.dialog.text'); ?></p>
</div>
