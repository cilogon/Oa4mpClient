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
  $constraint0 = $this->request->data['Oa4mpClientClaimConstraint'][0] ?? null;
  $constraint1 = $this->request->data['Oa4mpClientClaimConstraint'][1] ?? null;

  $this->log("FOO request data is " . print_r($this->request->data, true));
  $this->log("FOO claim is " . print_r($claim, true));
  $this->log("FOO constraint0 is " . print_r($constraint0, true));
  $this->log("FOO constraint1 is " . print_r($constraint1, true));

  // Unlock the Oa4mpClientClaimConstraint.0.value field since it is dynamically populated.
  print $this->Form->unlockField('Oa4mpClientClaimConstraint.0.value');

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

  $l = 1;
?>
<script type="text/javascript">
  // JS specific to these fields

  function identifier_set_options_claim_constraint_0_value() {
    console.log("identifier_set_options_claim_constraint_0_value");
    let options = new Map();

    <?php
      foreach($vv_identifier_types as $type => $label) {
        print "options.set('" . $type . "', '" . $label . "');";
        print "\n";
      }
    ?>

    let parent = $("#Oa4mpClientClaimConstraint0Value");

    options.forEach((value, key) => {
     if (parent.children('option[value="' + key + '"]').length === 0) {
      parent.append($("<option></option>").attr("value", key).text(value));
     }
    });
  }
  
  function update_source_model_select(element) {
    // TODO
    console.log(element.value);

    switch (element.value) {
      case "Identifier":
        identifier_set_options_claim_constraint_0_value();

        $("#ClaimConstraint0Value_li").show();
        $("#ClaimValueReq_li").show();
        $("#ClaimValueFormat_li").show();

        $("#ClaimValueField").val("identifier");
        $("#ClaimConstraint0Field").val("type");
      break;

      case "EmailAddress":
        $("#ClaimConstraint0Value_li").show();
        $("#ClaimValueReq_li").show();
        $("#ClaimValueFormat_li").show();

        $("#ClaimValueField").val("mail");
        $("#ClaimConstraint0Field").val("type");

        $("#ClaimConstraint1Field").val("verified");
        $("#ClaimConstraint1Value").val("true");

        $("#ClaimConstraint1Value").attr("type", "checkbox");

        $("#ClaimConstraint1Value_li").show();
      break;
    }

  }

  function js_local_onload() {
    <?php if($this->action == 'edit' && !empty($claim['source_model'])): ?>
      // Call the update function to show appropriate fields for existing data
      var sourceModelElement = document.getElementById('Oa4mpClientClaimSourceModel');
      if(sourceModelElement) {
        update_source_model_select(sourceModelElement);
      }

    <?php endif; ?>
  }

</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_claim" class="fields form-list form-list-admin">

  <!-- Oa4mpClientClaim['claim_name'] -->
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

  <!-- Oa4mpClientClaim['source_model'] -->
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

  <!-- Oa4mpClientClaim['value_field'] -->
  <li style="display:none;">
    <?php
      $attrs = array();
      $attrs['id'] = 'ClaimValueField';
      $attrs['value'] = $claim['value_field'] ?? "";

      $options = array();
      $options['hidden'] = 'hidden';

      print $this->Form->input('value_field', $attrs, $options);
    ?>
  </li>

  <!-- Oa4mpClientClaimConstraint.0.field -->
  <li style="display:none;">
    <?php
      $attrs = array();
      $attrs['id'] = 'ClaimConstraint0Field';
      $attrs['value'] = $constraint0['field'] ?? "";

      print $this->Form->input('Oa4mpClientClaimConstraint.0.field', $attrs);
    ?>
  </li>

  <!-- Oa4mpClientClaimConstraint.0.value -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="ClaimConstraint0Value_li" style="<?php echo $style; ?>">
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
        $options = array();
        $attrs = array();

        if(!empty($claim['source_model']) && $claim['source_model'] == "Identifier") {
          $options = $vv_identifier_types;
        }

        if(!empty($constraint0['value'])) {
          $attrs['value'] = $constraint0['value'];
        }

        if($e) {
          print $this->Form->select('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaimConstraint.1.field -->
  <li style="display:none;">
    <?php
      $attrs = array();
      $attrs['id'] = 'ClaimConstraint1Field';
      $attrs['value'] = $constraint1['field'] ?? "";
      $attrs['required'] = false;

      $options = array();
      $options['hidden'] = 'hidden';

      print $this->Form->input('Oa4mpClientClaimConstraint.1.field', $attrs, $options);
    ?>
  </li>

  <!-- Oa4mpClientClaimConstraint.1.value -->
  <li id="ClaimConstraint1Value_li" style="display:none;">
    <?php
      $attrs = array();
      $attrs['id'] = 'ClaimConstraint1Value';
      $attrs['value'] = $constraint1['value'] ?? "";
      $attrs['required'] = false;

      $options = array();
      $options['hidden'] = 'hidden';

      print $this->Form->input('Oa4mpClientClaimConstraint.1.value', $attrs, $options);
    ?>
  </li>

  <!-- Oa4mpClientClaim['value_req'] -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="ClaimValueReq_li" style="<?php echo $style; ?>">
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
        $attrs['value'] = $claim['value_req'] ?? "";
        $attrs['empty'] = true;

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

  <!-- Oa4mpClientClaim['value_format'] -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="ClaimValueFormat_li" style="<?php echo $style; ?>">
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
        $attrs['value'] = $claim['value_format'] ?? "";
        $attrs['empty'] = true;

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

  <!-- Oa4mpClientClaim['value_string_d'] -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['value_string_d']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="ClaimValueStringD_li" style="<?php echo $style; ?>">
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
        $attrs['value'] = $claim['value_string_d'] ?? null;

        if($e) {
          print $this->Form->input('value_string_d', $attrs);
        }
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
