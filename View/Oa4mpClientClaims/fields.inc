<?php
/**
 * COmanage Registry Oa4mp Client Plugin Claim Fields
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
  $args['controller'] = 'oa4mp_client_claims';
  $args['action'] = 'index';
  $args['clientid'] = $vv_client_id;
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_claims.pl'), $args);

  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_claims.1')));
  $this->Html->addCrumb($crumbTxt);

  print $this->Html->css("Oa4mpClient.oa4mpclient");

  $claim = $this->request->data['Oa4mpClientClaim'] ?? null;

  // Include the current client ID if it is available.
  if($e) {
    if(!empty($vv_client_id)) {
      print $this->Form->hidden('client_id', array('value' => $vv_client_id));
    }
  }


  // Include the current ID if it is available.
  if($e) {
    if(!empty($this->request->data['Oa4mpClientClaim']['id'])) {
      print $this->Form->hidden('id');
    }
  }

  // These form fields are inserted dynamically using Javascript based
  // on other selections so we need to unlock them to prevent the
  // form submission from being blackholed.
  $this->Form->unlockField("value_field");
  $this->Form->unlockField("Oa4mpClientClaimConstraint.0.field");

  $l = 1;
?>
<script type="text/javascript">
  // JS specific to these fields
  
  function update_source_model_select(element) {
    console.log(element.value);

    

    switch (element.value) {
    case "Identifier":
      $("#Identifier_ClaimConstraint0Value").show();
      $("#Identifier_value_req").show();
      $("#Identifier_value_format").show();

      $("#Oa4mpClientClaimClientId").after('<input id="Oa4mpClientClaimValueField" type="hidden" name="data[Oa4mpClientClaim][value_field]" value="identifier">');
      $("#Oa4mpClientClaimValueField").after('<input id="Oa4mpClientClaimConstraint0Field" type="hidden" name="data[Oa4mpClientClaimConstraint][0][field]" value="type">');
      break;
    }

  }

  // For edit mode, ensure proper visibility based on existing data
  function js_local_onload() {
    <?php if($this->action == 'edit' && !empty($claim['source_model'])): ?>
      // Call the update function to show appropriate fields for existing data
      var sourceModelElement = document.getElementById('Oa4mpClientClaimSourceModel');
      if(sourceModelElement) {
        update_source_model_select(sourceModelElement);
      }

      // For edit mode, ensure hidden fields are set if they don't already exist
      <?php if($claim['source_model'] == 'Identifier'): ?>
        if(!document.getElementById('Oa4mpClientClaimValueField')) {
          $("#Oa4mpClientClaimClientId").after('<input id="Oa4mpClientClaimValueField" type="hidden" name="data[Oa4mpClientClaim][value_field]" value="identifier">');
        }
        if(!document.getElementById('Oa4mpClientClaimConstraint0Field')) {
          $("#Oa4mpClientClaimValueField").after('<input id="Oa4mpClientClaimConstraint0Field" type="hidden" name="data[Oa4mpClientClaimConstraint][0][field]" value="type">');
        }
      <?php endif; ?>
    <?php endif; ?>
  }

</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_claim" class="fields form-list form-list-admin">

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_name', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_name.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_name.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        print $this->Form->input('claim_name');
      ?>
      </span>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('source_model', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.source_model.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.source_model.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['value'] = $claim['source_model'] ?? "";
        $attrs['empty'] = true;
        $attrs['onChange'] = 'update_source_model_select(this);';

        $options = array();
        $options['EmailAddress'] = 'EmailAddress';
        $options['Identifier'] = 'Identifier';
        $options['Name'] = 'Name';


        if($e) {
          print $this->Form->select('source_model', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- Identifier -->
  <li id="Identifier_ClaimConstraint0Value" style="<?php echo ($this->action == 'edit' && !empty($claim['source_model']) && $claim['source_model'] == 'Identifier') ? 'display:block;' : 'display:none;'; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.0.value', _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['id'] = 'Oa4mpClientClaimConstraint0Value_Identifier';

        // Handle constraint value from either the claim's constraints or from direct request data
        $constraintValue = "";
        if(!empty($claim['Oa4mpClientClaimConstraint'][0]['value'])) {
          $constraintValue = $claim['Oa4mpClientClaimConstraint'][0]['value'];
        } elseif(!empty($this->request->data['Oa4mpClientClaimConstraint'][0]['value'])) {
          $constraintValue = $this->request->data['Oa4mpClientClaimConstraint'][0]['value'];
        }

        $attrs['value'] = $constraintValue;
        $attrs['empty'] = true;
        $attrs['onChange'] = 'claim_constraint_0_value_identifier_select();';

        $options = $vv_identifier_types;
        
        if($e) {
          print $this->Form->select('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <li id="Identifier_value_req" style="<?php echo ($this->action == 'edit' && !empty($claim['source_model']) && $claim['source_model'] == 'Identifier') ? 'display:block;' : 'display:none;'; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('value_req', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.value_req.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.value_req.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['id'] = 'Oa4mpClientClaimValueReq_Identifier';
        $attrs['value'] = $claim['value_req'] ?? "";
        $attrs['empty'] = true;
        $attrs['onChange'] = 'value_req_identifier_select();';

        $options = array();
        $options['first'] = 'First value found';
        $options['all']   = 'All values found';
        
        if($e) {
          print $this->Form->select('value_req', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <li id="Identifier_value_format" style="<?php echo ($this->action == 'edit' && !empty($claim['source_model']) && $claim['source_model'] == 'Identifier') ? 'display:block;' : 'display:none;'; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('value_format', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.value_format.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.value_format.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['id'] = 'Oa4mpClientClaimValueFormat_Identifier';
        $attrs['value'] = $claim['value_format'] ?? "";
        $attrs['empty'] = true;
        $attrs['onChange'] = 'value_format_identifier_select();';

        $options = array();
        $options['string'] = 'JSON string';
        $options['number']   = 'JSON number';
        
        if($e) {
          print $this->Form->select('value_format', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <li id="Identifier_value_string_d" style="<?php echo ($this->action == 'edit' && !empty($claim['value_string_d'])) ? 'display:block;' : 'display:none;'; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('value_string_d', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.value_string_d.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.value_string_d.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['id'] = 'Oa4mpClientClaimValueStringD_Identifier';
        $attrs['value'] = $claim['value_string_d'] ?? null;
        $attrs['onChange'] = 'value_format_string_d_identifier_select();';

        if($e) {
          print $this->Form->input('value_string_d', $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- EmailAddress -->

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
