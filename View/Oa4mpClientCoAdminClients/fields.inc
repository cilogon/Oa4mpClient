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
    print $this->Form->hidden('Oa4mpClientCoEmailAddress.0.id', array('default' => $oa4mp_client_co_admin_clients[0]['Oa4mpClientCoEmailAddress'][0]['id'])) . "\n";
    print $this->Form->hidden('Oa4mpClientCoEmailAddress.0.admin_id', array('default' => $oa4mp_client_co_admin_clients[0]['Oa4mpClientCoEmailAddress'][0]['admin_id'])) . "\n";
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

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('Oa4mpClientCoEmailAddress.mail', _txt('pl.oa4mp_client_co_admin_client.mail.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.mail.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.mail.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('Oa4mpClientCoEmailAddress.0.mail')
                   : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoEmailAddress'][0]['mail'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <?php if($this->action != 'add'): ?>
    <?php
      // Pass manage_co_group_id as hidden field - this should be managed via delegate action
      if($e) {
        print $this->Form->hidden('manage_co_group_id');
      }
    ?>
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
                     ? $this->Form->label('name', _txt('pl.oa4mp_client_co_admin_client.name.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.name.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.name.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php print ($e
                     ? $this->Form->input('name')
                     : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['name'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('name', _txt('pl.oa4mp_client_co_admin_client.issuer.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.issuer.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.issuer.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php print ($e
                     ? $this->Form->input('issuer')
                     : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['issuer'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
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
                     ? $this->Form->label('qdl_claim_source', _txt('pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.name'))
                     : _txt('pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.qdl_claim_source.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php 
          if($e) { 
            $args = array();
            if(empty($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['qdl_claim_source'])) {
              if($qdlClaimDefault) {
                $args['default'] = $qdlClaimDefault;
              }
            }
            echo $this->Form->input('qdl_claim_source', $args);
          } else {
            echo filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['qdl_claim_source'],FILTER_SANITIZE_SPECIAL_CHARS); 
          }
        ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.aws_region', _txt('pl.oa4mp_client_co_admin_client.dynamo.region.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.region.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.region.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        $attrs = array();
        $attrs['value'] = (isset($oa4mp_client_dynamo_configs[0]['DefaultDynamoConfig']['aws_region']) 
                           ? $oa4mp_client_dynamo_configs[0]['DefaultDynamoConfig']['aws_region']
                           : null);
        // Since required=true, this will render blank by default but not allow save until a region is selected
        $attrs['empty'] = true;

        if($e) {
          print $this->Form->select('DefaultDynamoConfig.aws_region',
                                    $vv_aws_regions,
                                    $attrs);

          if($this->Form->isFieldError('aws_region')) {
            print $this->Form->error('aws_region');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['aws_region'])) {
            print filter_var($vv_servers[ $oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['aws_region'] ], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.table_name', _txt('pl.oa4mp_client_co_admin_client.dynamo.table_name.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.table_name.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.table_name.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->input('DefaultDynamoConfig.table_name');

          if($this->Form->isFieldError('table_name')) {
            print $this->Form->error('table_name');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['table_name'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['table_name'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.aws_access_key_id', _txt('pl.oa4mp_client_co_admin_client.dynamo.aws_access_key_id.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.aws_access_key_id.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.aws_access_key_id.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->text('DefaultDynamoConfig.aws_access_key_id');

          if($this->Form->isFieldError('aws_access_key_id')) {
            print $this->Form->error('aws_access_key_id');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['aws_access_key_id'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['aws_access_key_id'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.aws_secret_access_key', _txt('pl.oa4mp_client_co_admin_client.dynamo.aws_secret_access_key.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.aws_secret_access_key.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.aws_secret_access_key.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->text('DefaultDynamoConfig.aws_secret_access_key');

          if($this->Form->isFieldError('aws_secret_access_key')) {
            print $this->Form->error('aws_secret_access_key');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['aws_secret_access_key'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['aws_secret_access_key'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.partition_key', _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          $args = array();
          $args['default'] = 'PartitionKey';
          print $this->Form->text('DefaultDynamoConfig.partition_key', $args);

          if($this->Form->isFieldError('partition_key')) {
            print $this->Form->error('partition_key');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['partition_key'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['partition_key'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.partition_key_template', _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key_template.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key_template.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key_template.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          $args = array();
          $args['default'] = "identifier#oidcsub#%%CLAIM_VALUE%%";
          print $this->Form->text('DefaultDynamoConfig.partition_key_template', $args);

          if($this->Form->isFieldError('partition_key_template')) {
            print $this->Form->error('partition_key_template');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['partition_key_template'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['partition_key_template'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.partition_key_claim_name', _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key_claim_name.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key_claim_name.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.partition_key_claim_name.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          $args = array();
          $args['default'] = "sub";
          print $this->Form->text('DefaultDynamoConfig.partition_key_claim_name', $args);

          if($this->Form->isFieldError('partition_key_claim_name')) {
            print $this->Form->error('partition_key_claim_name');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['partition_key_claim_name'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['partition_key_claim_name'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.sort_key', _txt('pl.oa4mp_client_co_admin_client.dynamo.sort_key.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.sort_key.fd.name')); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.sort_key.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->text('DefaultDynamoConfig.sort_key');

          if($this->Form->isFieldError('sort_key')) {
            print $this->Form->error('sort_key');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['sort_key'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['sort_key'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
        }
      ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e ? $this->Form->label('DefaultDynamoConfig.sort_key_template', _txt('pl.oa4mp_client_co_admin_client.dynamo.sort_key_template.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.dynamo.sort_key_template.fd.name')); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.dynamo.sort_key_template.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->text('DefaultDynamoConfig.sort_key_template');

          if($this->Form->isFieldError('sort_key_template')) {
            print $this->Form->error('sort_key_template');
          }
        } else {
          if(!empty($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['sort_key_template'])) {
            print filter_var($oa4mp_client_co_admin_clients[0]['DefaultDynamoConfig']['sort_key_template'], FILTER_SANITIZE_SPECIAL_CHARS);
          }
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
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.server.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.serverurl', array('required' => false))
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['serverurl'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.binddn', _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name')); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.binddn.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.binddn', array('required' => false))
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['binddn'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.password', _txt('pl.oa4mp_client_co_ldap_config.password.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.password.fd.name')); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.bindpassword.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.password', array('required' => false))
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['password'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('DefaultLdapConfig.basedn', _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name'))
                     : _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name')); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.ldap.searchbase.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('DefaultLdapConfig.basedn', array('required' => false))
                   : filter_var($oa4mp_client_co_admin_clients[0]['DefaultLdapConfig']['basedn'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
        <?php 
          if($this->action == "add") {
            print $this->Form->submit($submit_label);
          } elseif ($this->action =="edit") {
            $args = array();
            $args['type'] = 'button';
            $args['class'] = 'btn btn-primary';
            $args['onClick'] = 'on_submit()';
            $args['id'] = 'oa4mpadminclientsavebutton';
            print $this->Form->button($submit_label, $args);
          }
        ?>
    </li>
  <?php endif; ?>
</ul>

<div id="on-save-dialog" title="<?php print _txt('pl.oa4mp_client_co_admin_client.save.dialog.title'); ?>" style="display:none">
  <p><?php print _txt('pl.oa4mp_client_co_admin_client.save.dialog.text'); ?></p>
</div>
