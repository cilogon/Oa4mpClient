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

  // Set the options for the claim constraint 0 value appropriately
  // for the Identifier type.
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

  // Set the options for the claim constraint 0 value appropriately
  // for the EmailAddress type.
  function email_set_options_claim_constraint_0_value() {
    console.log("email_set_options_claim_constraint_0_value");
    let options = new Map();

    <?php
      foreach($vv_email_types as $type => $label) {
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

  // Update the string delimiter select field to be shown or hidden based on the value of the multiple value format selector field.
  function update_string_d_select(value) {
    console.log("update_string_d_select");
    console.log(value);

    if(value == "delimited_string") {
      if($("#Oa4mpClientClaimValueStringD").val() == "") {
        $("#Oa4mpClientClaimValueStringD").val(",");
      }
      $("#ClaimValueStringD_li").show();
    } else {
      $("#ClaimValueStringD_li").hide();
      $("#Oa4mpClientClaimValueStringD").val("");
    }
  }

  // Update the fields to show and hide based on the value of the source model select field
  // and whether the user has selected Identifier, EmailAddress, Name, or so on.
  function update_source_model_select(element) {
    // TODO
    console.log(element.value);

    let label = "";
    switch (element.value) {
      case "Identifier":
        // Set the options for the claim constraint 0 value appropriately
        // for the Identifier type.
        identifier_set_options_claim_constraint_0_value();

        // Set the label for the claim constraint 0 value with text appropriate for the Identifier type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.description.identifier'); ?>"
        $("#ClaimConstraint0Value_li").find(".field-desc").text(label);
        $("#ClaimConstraint0Value_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#ClaimValueReq_li").show();

        // Show the claim value format field with choices of "JSON string" and "JSON number" since
        // an Identifier value can be a string or a number.
        $("#ClaimValueFormat_li").show();

        // Set the claim value field to "identifier" and the constraint 0 field to "type".
        $("#ClaimValueField").val("identifier");
        $("#ClaimConstraint0Field").val("type");
      break;

      case "EmailAddress":
        // Set the options for the claim constraint 0 value appropriately
        // for the EmailAddress type.
        email_set_options_claim_constraint_0_value();

        // Set the label for the claim constraint 0 value with text appropriate for the EmailAddress type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.description.email'); ?>"
        $("#ClaimConstraint0Value_li").find(".field-desc").text(label);
        $("#ClaimConstraint0Value_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#ClaimValueReq_li").show();

        // Hide the claim value format field and set the value to "JSON string" since EmailAddress values are always strings
        // and never numbers.
        $("#ClaimValueFormat_li").hide();
        $("#Oa4mpClientClaimValueFormat").val("string");

        // Set the claim value field to "mail" and the constraint 0 field to "type".
        $("#ClaimValueField").val("mail");
        $("#ClaimConstraint0Field").val("type");

        // Set the value of the claim constraint 1 field to "verified" to
        // allow selection of whether only verified EmailAddress values are included in the claim.
        $("#ClaimConstraint1Field").val("verified");

        // Cast the claim constraint 1 value to a checkbox and set the value to "true" to
        // default to only verified EmailAddress values being included in the claim.
        // Also, add a hidden field to the form with the value set to "false" so that if the box is
        // unchecked, the value is set to "false" when the form is submitted.
        // Then set the name and description of the claim constraint 1 value field appropriately
        // for managing the verified EmailAddress values, and finally display the claim constraint 1 value field.
        $("#ClaimConstraint1Value").val("true");
        $("#ClaimConstraint1Value").attr("type", "checkbox");
        $("#ClaimConstraint1Value").prop("checked", true);
        $("#ClaimConstraint1Value").removeAttr("maxlength");
        $("#ClaimConstraint1Value").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][1][value]\" value=\"false\">"));
        $("#ClaimConstraint1Value_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.name.email'); ?>");
        $("#ClaimConstraint1Value_li").find(".field-title").append(" <span class=\"required\">*</span>");
        $("#ClaimConstraint1Value_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.email'); ?>");
        $("#ClaimConstraint1Value_li").show();
      break;
    }

  }

  // Update the multiple value format selector field to be shown or hidden based on the value of the value requirement field.
  function update_value_req_select(element) {
    console.log("update_value_req_select");

    if(element.value == "all") {
      console.log("all");
      $("#MultipleValueFormatSelector_li").show();
    } else {
      console.log("first");
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

    // Add form submit handler to remove multiple_value_format field before submission
    $('form').on('submit', function(e) {
      // Remove multiple_value_format radio buttons from form data before submission
      // since this is a UI control field and not part of the model
      $('input[name="multiple_value_format"]').remove();
    });
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
      <?php
        if(!empty($claim['source_model'])) {
          $description = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.value.description.' . $claim['source_model']);
        } else {
          $description = "";
        }
      ?>
      <div class="field-desc"><?php print $description; ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $options = array();
        $attrs = array();

        if(!empty($claim['source_model']) && $claim['source_model'] == "Identifier") {
          $options = $vv_identifier_types;
        } else if(!empty($claim['source_model']) && $claim['source_model'] == "EmailAddress") {
          $options = $vv_email_types;
        } else if(!empty($claim['source_model']) && $claim['source_model'] == "Name") {
          $options = $vv_name_types;
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
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.1.field', _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.field.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.field.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['id'] = 'ClaimConstraint1Field';
        $attrs['value'] = $constraint1['field'] ?? "";
        $attrs['required'] = false;

        $options = array();
        $options['hidden'] = 'hidden';

        print $this->Form->input('Oa4mpClientClaimConstraint.1.field', $attrs, $options);
      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaimConstraint.1.value -->
  <li id="ClaimConstraint1Value_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.1.value', "") ?>
      </div>
      <div class="field-desc"><?php print ""; ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
      $attrs = array();
      $attrs['id'] = 'ClaimConstraint1Value';
      $attrs['value'] = $constraint1['value'] ?? "";
      $attrs['required'] = false;

      $options = array();
      $options['hidden'] = 'hidden';

        print $this->Form->input('Oa4mpClientClaimConstraint.1.value', $attrs, $options);
      ?>
      </span>
    </div>
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
        $options = array();
        $options['first'] = 'First value found';
        $options['all']   = 'All values found';

        $attrs = array();
        $attrs['value'] = $claim['value_req'] ?? "";
        $attrs['empty'] = true;
        $attrs['onChange'] = 'update_value_req_select(this);';
        
        if($e) {
          print $this->Form->select('value_req', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- Multiple value format selector -->
  <li id="MultipleValueFormatSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_format.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_format.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <input type="radio"
               id="MultipleValueFormatSelector_json_array"
               name="multiple_value_format"
               value="json_array"
               onclick="update_string_d_select(this.value)"
               checked
               />
        <label for="MultipleValueFormatSelector_json_array"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_format.json_array'); ?></label>

        <input type="radio"
               id="MultipleValueFormatSelector_delimited_string"
               name="multiple_value_format"
               value="delimited_string" 
               onclick="update_string_d_select(this.value)"
               />
        <label for="MultipleValueFormatSelector_delimited_string"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_format.delimited_string'); ?></label>
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
