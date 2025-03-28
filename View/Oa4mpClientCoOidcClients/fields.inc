<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Fields
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
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['co'] = $cur_co['Co']['id'];
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);
  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_oidc_clients.1')));
  $this->Html->addCrumb($crumbTxt);

  print $this->Html->css("Oa4mpClient.oa4mpclient");

  $l = 1;

  // Include the current client ID if it is available.
  if($e) {
    if(!empty($this->request->data['Oa4mpClientCoOidcClient']['id'])) {
      print $this->Form->hidden('id');
    }
  }

  // Include the current admin client ID if it is available.
  if($e) {
    if(!empty($this->request->data['Oa4mpClientCoOidcClient']['admin_id'])) {
      print $this->Form->hidden('admin_id');
    }
  }

  // Include the current client OA4MP identifier if it is available.
  if($e) {
    if(!empty($this->request->data['Oa4mpClientCoOidcClient']['oa4mp_identifier'])) {
      print $this->Form->hidden('oa4mp_identifier');
    }
  }

  // Is this a public client?
  if(($this->action == "edit") && $this->request->data['Oa4mpClientCoOidcClient']['public_client']) {
    $is_public_client = true;
  } else {
    $is_public_client = false;
  }
?>
<script type="text/javascript">
</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_client" class="fields form-list form-list-admin">

  <?php if($this->action == "add"): ?>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('admin_id', _txt('pl.oa4mp_client_co_oidc_client.admin_id.fd.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.admin_id.fd.warn'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $options = array();
        foreach($adminClients as $c) {
          $value = $c['Oa4mpClientCoAdminClient']['id'];
          $label = $c['Oa4mpClientCoAdminClient']['name'] . " - " . $c['Oa4mpClientCoAdminClient']['issuer'];
          $options[$value] = $label;
        }
        print $this->Form->select('admin_id', $options);
      ?>
      </span>
    </div>
  </li>
  <?php endif; ?>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('name', _txt('pl.oa4mp_client_co_oidc_client.name.fd.name'))
                     : _txt('pl.oa4mp_client_co_oidc_client.name.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.name.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('name')
                   : filter_var($this->request->data['Oa4mpClientCoOidcClient']['name'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <?php if($this->action == "edit"): ?>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('name', _txt('pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name')); ?>
        <span class="required">*</span>
      </div>
    </div>
    <div class="field-info">
      <?php
        $params = array();
        $params['default'] = $this->request->data['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
        $params['disabled'] = 'disabled';
        print $this->Form->text('oa4mp_identifier', $params);
      ?>
    </div>
  </li>
  <?php endif; ?>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('home_url', _txt('pl.oa4mp_client_co_oidc_client.home_url.fd.name'))
                     : _txt('pl.oa4mp_client_co_oidc_client.home_url.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.home_url.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('home_url')
                   : filter_var($this->request->data['Oa4mpClientCoOidcClient']['home_url'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('Oa4mpClientCoEmailAddress.mail', _txt('pl.oa4mp_client_co_oidc_client.mail.fd.name'))
                     : _txt('pl.oa4mp_client_co_oidc_client.mail.fd.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.mail.fd.description'); ?></div>
    </div>
    <div class="field-info">
        <?php
          $params = array();
          if(!empty($vv_default_contact_email)) {
            $params['default'] = $vv_default_contact_email;
          }
          if(isset($this->validationErrors['Oa4mpClientCoEmailAddress']['mail'][0])) {
            $params['class'] = 'form-error';
          }
          print ($e
                 ? $this->Form->input('Oa4mpClientCoEmailAddress.0.mail', $params)
                 : filter_var($this->request->data['Oa4mpClientCoEmailAddress'][0]['mail'],FILTER_SANITIZE_SPECIAL_CHARS));
          if($e) {
            if(!empty($this->request->data['Oa4mpClientCoEmailAddress'][0]['mail'])) {
              print $this->Form->hidden('Oa4mpClientCoEmailAddress.0.id');
              print $this->Form->hidden('Oa4mpClientCoEmailAddress.0.client_id');
            }
          }
        ?>
    </div>
  </li>

  <li id="oa4mp_client_co_oidc_client_public_client">
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('public_client', _txt('pl.oa4mp_client_co_oidc_client.public_client.fd.name'))
                     : _txt('pl.oa4mp_client_co_oidc_client.public_client.fd.name')); ?>
      </div>
      <div class="field-desc">
      <?php
        if($this->action == 'add') {
          print _txt('pl.oa4mp_client_co_oidc_client.public_client.fd.description.add');
        } else {
          print _txt('pl.oa4mp_client_co_oidc_client.public_client.fd.description.edit');
        }
      ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        $params = array();
        if($this->action == "add") {
          $params['onclick'] = 'publicClientClicked()';
        } else {
          $params['disabled'] = 'disabled';
        }
        print ($this->Form->input('public_client', $params)); ?>
    </div>
  </li>

  <?php if($this->action == "edit"): ?>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('refresh_token_lifetime', _txt('pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.name'))
                     : _txt('pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.name')); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.refresh_token_lifetime.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <?php print ($e
                   ? $this->Form->input('refresh_token_lifetime')
                   : filter_var($this->request->data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
    </div>
  </li>
  <?php endif; ?>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php
          $submitText = ($this->action == "add") ? _txt('op.next') : _txt('op.save');
          print $this->Form->submit($submitText);
        ?>
      </div>
    </li>
  <?php endif; ?>

</ul>
