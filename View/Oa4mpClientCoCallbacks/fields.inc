<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Callback Fields
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

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_callbacks';
  $args['action'] = 'index';
  $args['clientid'] = $vv_client_id;
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_callbacks.pl'), $args);

  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_callbacks.1')));
  $this->Html->addCrumb($crumbTxt);

  print $this->Html->css("Oa4mpClient.oa4mpclient");

  // Include the current client ID if it is available.
  if($e) {
    if(!empty($vv_client_id)) {
      print $this->Form->hidden('client_id', array('value' => $vv_client_id));
    }
  }

  // Include the current ID if it is available.
  if($e) {
    if(!empty($this->request->data['Oa4mpClientCoCallback']['id'])) {
      print $this->Form->hidden('id');
    }
  }

  $l = 1;
?>
<script type="text/javascript">
</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_callback" class="fields form-list form-list-admin">

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('url', _txt('pl.oa4mp_client_co_oidc_client.callbacks.fd.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.callbacks.fd.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        print $this->Form->input('url');
      ?>
      </span>
    </div>
  </li>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php
          $submitText = ($this->action == "add") ? _txt('op.add') : _txt('op.save');
          print $this->Form->submit($submitText);
        ?>
      </div>
    </li>
  <?php endif; ?>

</ul>
