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
  if(!empty($vv_client_id)) {
    print $this->Form->hidden('client_id', array('value' => $vv_client_id));
  }

  // Include the current Oa4mpClientClaim ID if it is available.
  if(!empty($this->request->data['Oa4mpClientClaim']['id'])) {
    print $this->Form->hidden('id');
  }

  // Include the current Oa4mpClientClaimConstraint[0].id if it is available.
  if(!empty($this->request->data['Oa4mpClientClaimConstraint'][0]['id'])) {
    print $this->Form->hidden('Oa4mpClientClaimConstraint.0.id');
  }

  // Include the current Oa4mpClientClaimConstraint[1].id if it is available.
  if(!empty($this->request->data['Oa4mpClientClaimConstraint'][1]['id'])) {
    print $this->Form->hidden('Oa4mpClientClaimConstraint.1.id');
  }

  $l = 1;
?>
<script type="text/javascript">
  // JS specific to these fields

  // If the group ownership checkbox is checked then set the value format to JSON array
  // and disable the radio buttons for the value format so it cannot be changed.
  function group_ownership_change_constraint_0_value(element) {
    console.log("group_ownership_change_constraint_0_value");
    if($("#Oa4mpClientClaimConstraint0ConstraintValue").prop("checked")) {
      $("#Oa4mpClientClaimClaimMultipleValueSerializationJsonArray").prop("checked", true);
      $("#Oa4mpClientClaimClaimMultipleValueSerializationDelimitedString").prop("checked", false);
      $("#Oa4mpClientClaimClaimMultipleValueSerializationDelimitedString").prop("disabled", true);
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val("");
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
    } else {
      $("#Oa4mpClientClaimClaimMultipleValueSerializationDelimitedString").prop("disabled", false);
    }
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

    let parent = $("#Oa4mpClientClaimConstraint0ConstraintValue");
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));
    parent.append($("<option></option>").attr("value", "all").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.email.all'); ?>"));

    options.forEach((value, key) => {
      if (parent.children('option[value="' + key + '"]').length === 0) {
        parent.append($("<option></option>").attr("value", key).text(value));
      }
    });
  }

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

    let parent = $("#Oa4mpClientClaimConstraint0ConstraintValue");
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));
    parent.append($("<option></option>").attr("value", "all").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.identifier.all'); ?>"));

    options.forEach((value, key) => {
     if (parent.children('option[value="' + key + '"]').length === 0) {
      parent.append($("<option></option>").attr("value", key).text(value));
     }
    });
  }

  // Set the options for the claim constraint 0 value appropriately
  // for the Name type.
  function name_set_options_claim_constraint_0_value() {
    console.log("name_set_options_claim_constraint_0_value");
    let options = new Map();

    <?php
      foreach($vv_name_types as $type => $label) {
        print "options.set('" . $type . "', '" . $label . "');";
        print "\n";
      }
    ?>

    let parent = $("#Oa4mpClientClaimConstraint0ConstraintValue");
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));
    parent.append($("<option></option>").attr("value", "all").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.name.all'); ?>"));

    options.forEach((value, key) => {
      if (parent.children('option[value="' + key + '"]').length === 0) {
        parent.append($("<option></option>").attr("value", key).text(value));
      }
    });
  }


  // Set the options for the claim constraint 0 value appropriately
  // for the SshKey type.
  function ssh_key_set_options_claim_constraint_0_value() {
    console.log("ssh_key_set_options_claim_constraint_0_value");
    let options = new Map();
    <?php
      foreach($vv_ssh_key_types as $type => $label) {
        print "options.set('" . $type . "', '" . $label . "');";
        print "\n";
      }
    ?>

    let parent = $("#Oa4mpClientClaimConstraint0ConstraintValue");
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));
    parent.append($("<option></option>").attr("value", "all").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.ssh_key.all'); ?>"));

    options.forEach((value, key) => {
      if (parent.children('option[value="' + key + '"]').length === 0) {
        parent.append($("<option></option>").attr("value", key).text(value));
      }
    });
  }

  // Reset the claim constraint 0 value field to the empty default.
  function reset_claim_constraint_0_value() {
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li").empty();
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li").append('<div class="field-name"></div>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name").append('<div class="field-title"></div>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-title").append('<label for="Oa4mpClientClaimConstraint0ConstraintValue"></label>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-title").append(' <span class="required">*</span>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name").append('<div class="field-desc"></div>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li").append('<div class="field-info"></div>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-info").append('<span class="field-info-prefix"></span>');
    $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-info .field-info-prefix").append('<input name="data[Oa4mpClientClaimConstraint][0][constraint_value]" id="Oa4mpClientClaimConstraint0ConstraintValue" required="required"></input>');
  }

  // Reset the claim constraint 1 value field to the default.
  function reset_claim_constraint_1_value() {
    $("#Oa4mpClientClaimConstraint1ConstraintValue_li").empty();
    $("#Oa4mpClientClaimConstraint1ConstraintValue_li").html('<div class="field-name"><div class="field-title"><label for="Oa4mpClientClaimConstraint1ConstraintValue"></label></div><div class="field-desc"></div></div><div class="field-info"><span class="field-info-prefix"><input name="data[Oa4mpClientClaimConstraint][1][constraint_value]" id="Oa4mpClientClaimConstraint1ConstraintValue" value="" maxlength="64" type="text"></span></div>');
  }

  // Show or hide
  function update_primary_name_value_req() {
    console.log("update_primary_name_value_req");
    if($("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked")) {
      $("#Oa4mpClientClaimClaimValueSelection").val("first");
      $("#Oa4mpClientClaimClaimValueSelection_li").hide();
      $("#Oa4mpClientClaimClaimMultipleValueSerialization_li").hide();
      $("#Oa4mpClientClaimClaimMultipleValueSerializationJsonArray").prop("checked", false);
      $("#Oa4mpClientClaimClaimMultipleValueSerializationDelimitedString").prop("checked", false);
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val("");
    } else {
      $("#Oa4mpClientClaimClaimValueSelection").val("all");
      $("#Oa4mpClientClaimClaimValueSelection_li").show();
      $("#Oa4mpClientClaimClaimMultipleValueSerializationJsonArray").prop("checked", true);
      $("#Oa4mpClientClaimClaimMultipleValueSerialization_li").show();
    }
  }

  // Update the string delimiter select field to be shown or hidden based on the value of the multiple value format selector field.
  function update_string_d_select(value) {
    console.log("update_string_d_select");
    console.log(value);

    if(value == "delimited_string") {
      if($("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val() == "") {
        $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val(",");
      }
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").show();
    } else {
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val("");
    }
  }

  // Update the fields to show and hide based on the value of the source model select field
  // and whether the user has selected Identifier, EmailAddress, Name, or so on.
  function update_source_model_select(element) {
    // TODO remove console logging
    console.log(element.value);

    // Since the user has selected or changed the source model, we hide fields 
    // and remove customizations here that were specific to the previous source model
    // and let the case statement below show and hide the appropriate fields.
    $("#NameFieldSelector_li").hide();
    $("#SshKeyFieldSelector_li").hide();
    $("#Oa4mpClientClaimConstraint1ConstraintValue_li").hide();
    $("#Oa4mpClientClaimSourceModelClaimValueField").val("");
    reset_claim_constraint_0_value();
    reset_claim_constraint_1_value();

    let label;
    let description;

    let $old;
    let attrs;
    let $new;

    switch (element.value) {
      case "CoPersonRole":
        // If Oa4mpClientClaimSourceModelClaimValueField is not set, set it to "all" as the default.
        if($("#Oa4mpClientClaimSourceModelClaimValueField").val() == "") {
          $("#Oa4mpClientClaimSourceModelClaimValueField").val("all");
        }

        // Show the CoPersonRole field selector field.
        $("#CoPersonRoleFieldSelector_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimClaimValueSelection_li").show();

        // Hide the claim value JSON format field and set the value to "JSON string" since CoPersonRole values are always strings
        // and never numbers.
        $("#Oa4mpClientClaimClaimValueJsonFormat_li").hide();
        $("#Oa4mpClientClaimClaimValueJsonFormat").val("string");

        // Set the constraint 0 constraint_field to "status".
        $("#Oa4mpClientClaimConstraint0ConstraintField").val("status");
        
        // Cast the claim constraint 0 value to a checkbox.
        $("#Oa4mpClientClaimConstraint0ConstraintValue").attr("type", "checkbox");

        // If the value is not set, set it to "active" to default to only active CoPersonRole values being included in the claim.
        if($("#Oa4mpClientClaimConstraint0ConstraintValue").val() == "" || $("#Oa4mpClientClaimConstraint0ConstraintValue").val() == "on") {
          $("#Oa4mpClientClaimConstraint0ConstraintValue").val("active");
          $("#Oa4mpClientClaimConstraint0ConstraintValue").prop("checked", true);
        } else if ($("#Oa4mpClientClaimConstraint0ConstraintValue").val() == "active") {
          $("#Oa4mpClientClaimConstraint0ConstraintValue").prop("checked", true);
        } else if ($("#Oa4mpClientClaimConstraint0ConstraintValue").val() != "active") {
          $("#Oa4mpClientClaimConstraint0ConstraintValue").prop("checked", false);
        }

        // Also, add a hidden field to the form with the value set to "any" so that if the box is
        // unchecked, the value is set to "any" when the form is submitted.
        // Then set the name and description of the claim constraint 0 value field appropriately
        // for managing the active CoPersonRole values, and finally display the claim constraint 0 value field.

        $("#Oa4mpClientClaimConstraint0ConstraintValue").removeAttr("maxlength");
        $("#Oa4mpClientClaimConstraint0ConstraintValue").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][0][constraint_value]\" value=\"any\">"));
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.co_person_role'); ?>");
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").find(".field-title").append(" <span class=\"required\">*</span>");
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.co_person_role'); ?>");
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").show();
        break;

      case "EmailAddress":
        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0ConstraintValue");
        attrs = {
          id: $old.attr("id"),
          name: $old.attr("name"),
          required: $old.attr("required")
        }
        $new = $("<select></select>", attrs);
        $old.replaceWith($new);

        // Set the options for the claim constraint 0 value appropriately
        // for the EmailAddress type.
        email_set_options_claim_constraint_0_value();

        // Set the label for the claim constraint 0 value with text appropriate for the EmailAddress type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.email'); ?>"
        description = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.email'); ?>"
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimClaimValueSelection_li").show();

        // Hide the claim value JSON format field and set the value to "JSON string" since EmailAddress values are always strings
        // and never numbers.
        $("#Oa4mpClientClaimClaimValueJsonFormat_li").hide();
        $("#Oa4mpClientClaimClaimValueJsonFormat").val("string");

        // Set the claim source_model_claim_value_field to "mail" and the constraint 0 constraint_field to "type".
        $("#Oa4mpClientClaimSourceModelClaimValueField").val("mail");
        $("#Oa4mpClientClaimConstraint0ConstraintField").val("type");

        // Set the value of the claim constraint 1 constraint_field to "verified" to
        // allow selection of whether only verified EmailAddress values are included in the claim.
        $("#Oa4mpClientClaimConstraint1ConstraintField").val("verified");

        // Cast the claim constraint 1 value to a checkbox.
        $("#Oa4mpClientClaimConstraint1ConstraintValue").attr("type", "checkbox");

        // If the value is not set, set it to "true" to default to only verified EmailAddress values being included in the claim.
        if($("#Oa4mpClientClaimConstraint1ConstraintValue").val() == "") {
          $("#Oa4mpClientClaimConstraint1ConstraintValue").val("true");
          $("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked", true);
        } else if ($("#ClaimConstraint1ConstraintValue").val() == "false") {
          $("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked", false);
        } else if ($("#Oa4mpClientClaimConstraint1ConstraintValue").val() == "true") {
          $("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked", true);
        }

        // Also, add a hidden field to the form with the value set to "false" so that if the box is
        // unchecked, the value is set to "false" when the form is submitted.
        // Then set the name and description of the claim constraint 1 value field appropriately
        // for managing the verified EmailAddress values, and finally display the claim constraint 1 value field.

        $("#Oa4mpClientClaimConstraint1ConstraintValue").removeAttr("maxlength");
        $("#Oa4mpClientClaimConstraint1ConstraintValue").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][1][constraint_value]\" value=\"false\">"));
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.constraint_value.name.email'); ?>");
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").find(".field-title").append(" <span class=\"required\">*</span>");
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.email'); ?>");
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").show();
        break;

      case "CoGroupMember":
        // If Oa4mpClientClaimSourceModelClaimValueField is not set, set it to "member" as the default.
        if($("#Oa4mpClientClaimSourceModelClaimValueField").val() == "") {
          $("#Oa4mpClientClaimSourceModelClaimValueField").val("member");
        }

        // Group names are always strings, so set the claim value JSON format field to "string".
        $("#Oa4mpClientClaimClaimValueJsonFormat").val("string");
        $("#Oa4mpClientClaimClaimValueJsonFormat_li").hide();

        // Set the claim claim_value_selection field to "all" as the default and hide the claim_value_selection field
        // since a choice of "first" is not applicable for CoGroupMember.
        $("#Oa4mpClientClaimClaimValueSelection").val("all");
        $("#Oa4mpClientClaimClaimValueSelection_li").hide();

        // Show the claim multiple value serialization field with choices of "JSON array" and "Delimited string"
        // with the value set to "JSON array" by default.
        $("#Oa4mpClientClaimClaimMultipleValueSerializationJsonArray").prop("checked", true);
        $("#Oa4mpClientClaimClaimMultipleValueSerializationDelimitedString").prop("checked", false);
        $("#Oa4mpClientClaimClaimMultipleValueSerialization_li").show();

        // Set the constraint 0 constraint_field to "owner" to allow selection of whether group ownership is included in the claim.
        $("#Oa4mpClientClaimConstraint0ConstraintField").val("owner");

        // Cast the claim constraint 0 value to a checkbox and add a hidden field to the form with the value set to "false" so that if the box is
        // unchecked, the value is set to "false" when the form is submitted.
        $old = $("#Oa4mpClientClaimConstraint0ConstraintValue");
        attrs = {
          id: $old.attr("id"),
          name: $old.attr("name"),
          type: "checkbox",
          value: "true",
          onclick: "group_ownership_change_constraint_0_value(this)"
        }
        $new = $("<input></input>", attrs);
        $old.replaceWith($new);
        $("#Oa4mpClientClaimConstraint0ConstraintValue").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][0][constraint_value]\" value=\"false\">"));

        // Then set the name and description of the claim constraint 0 value field appropriately
        // for managing the group ownership values, and finally display the claim constraint 0 value field.

        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.co_group_member'); ?>");
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.co_group_member'); ?>");
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").show();
        break;

      case "Identifier":
        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0ConstraintValue");
        attrs = {
          id: $old.attr("id"),
          name: $old.attr("name"),
          required: $old.attr("required")
        }
        $new = $("<select></select>", attrs);
        $old.replaceWith($new);

        // Set the options for the claim constraint 0 value appropriately
        // for the Identifier type.
        identifier_set_options_claim_constraint_0_value();

        // Set the label for the claim constraint 0 value with text appropriate for the Identifier type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.identifier'); ?>"
        description = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.identifier'); ?>"
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimClaimValueSelection_li").show();

        // Show the claim value JSON format field with choices of "JSON string" and "JSON number" since
        // an Identifier value can be a string or a number.
        $("#Oa4mpClientClaimClaimValueJsonFormat_li").show();

        // Set the claim source_model_claim_value_field to "identifier" and the constraint 0 constraint_field to "type".
        $("#Oa4mpClientClaimSourceModelClaimValueField").val("identifier");
        $("#Oa4mpClientClaimConstraint0ConstraintField").val("type");
        break;


      case "Name":
        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0ConstraintValue");
        attrs = {
          id: $old.attr("id"),
          name: $old.attr("name"),
          required: $old.attr("required")
        }
        $new = $("<select></select>", attrs);
        $old.replaceWith($new);

        // Set the options for the claim constraint 0 value appropriately
        // for the Name type.
        name_set_options_claim_constraint_0_value();

        // If ClaimSourceModelClaimValueField is not set, set it to "all" as the default.
        if($("#Oa4mpClientClaimSourceModelClaimValueField").val() == "") {
          $("#Oa4mpClientClaimSourceModelClaimValueField").val("all");
        }

        // Show the name field selector field.
        $("#NameFieldSelector_li").show();

        // Set the label for the claim constraint 0 value with text appropriate for the Name type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.name'); ?>"
        description = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.name'); ?>"
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimClaimValueSelection_li").show();

        // Hide the claim value JSON format field and set the value to "JSON string" since Name values are always strings
        // and never numbers.
        $("#Oa4mpClientClaimClaimValueJsonFormat_li").hide();
        $("#Oa4mpClientClaimClaimValueJsonFormat").val("string");

        // Set the constraint 0 constraint_field to "type".
        $("#Oa4mpClientClaimConstraint0ConstraintField").val("type");

        // Set the value of the claim constraint 1 constraint_field to "primary" to
        // allow selection of whether only the primary Name value is included in the claim.
        $("#Oa4mpClientClaimConstraint1ConstraintField").val("primary");

        // Cast the claim constraint 1 value to a checkbox.
        $("#Oa4mpClientClaimConstraint1ConstraintValue").attr("type", "checkbox");

        // If the value is not set, set it to "true" to default to only primary Name values being included in the claim.
        if($("#Oa4mpClientClaimConstraint1ConstraintValue").val() == "") {
          $("#Oa4mpClientClaimConstraint1ConstraintValue").val("true");
          $("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked", true);
        } else if ($("#Oa4mpClientClaimConstraint1ConstraintValue").val() == "false") {
          $("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked", false);
        } else if ($("#Oa4mpClientClaimConstraint1ConstraintValue").val() == "true") {
          $("#Oa4mpClientClaimConstraint1ConstraintValue").prop("checked", true);
        }

        // Also, add a hidden field to the form with the value set to "false" so that if the box is
        // unchecked, the value is set to "false" when the form is submitted.
        // Then set the name and description of the claim constraint 1 value field appropriately
        // for managing the primary Name values, and finally display the claim constraint 1 value field.

        $("#Oa4mpClientClaimConstraint1ConstraintValue").removeAttr("maxlength");
        $("#Oa4mpClientClaimConstraint1ConstraintValue").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][1][constraint_value]\" value=\"false\">"));
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.constraint_value.name.name'); ?>");
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").find(".field-title").append(" <span class=\"required\">*</span>");
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.name'); ?>");
        $("#Oa4mpClientClaimConstraint1ConstraintValue").on("change", update_primary_name_value_req);
        $("#Oa4mpClientClaimConstraint1ConstraintValue_li").show();

        // Since the default is to only assert the single primary name value set claim_value_selection to "first" and
        // hide claim_value_selection field and the multiple value format selector field and the claim value string delimiter field.
        $("#Oa4mpClientClaimClaimValueSelection").val("first");
        $("#Oa4mpClientClaimClaimValueSelection_li").hide();
        $("#MultipleValueFormatSelector_li").hide();
        $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
        $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val("");
        break;

      case "SshKey":
        // Hide the claim value JSON format field and set the value to "JSON string" since all components of
        // the SshKey model are strings.
        $("#Oa4mpClientClaimClaimValueJsonFormat_li").hide();
        $("#Oa4mpClientClaimClaimValueJsonFormat").val("string");

        // Show the SSH key field selector field.
        $("#SshKeyFieldSelector_li").show();

        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0ConstraintValue");
        attrs = {
          id: $old.attr("id"),
          name: $old.attr("name"),
          required: $old.attr("required")
        }
        $new = $("<select></select>", attrs);
        $old.replaceWith($new);

        // Set the options for the claim constraint 0 value appropriately
        // for the SshKey type.
        ssh_key_set_options_claim_constraint_0_value();

        // If ClaimSourceModelClaimValueField is not set, set it to "all" as the default.
        if($("#Oa4mpClientClaimSourceModelClaimValueField").val() == "") {
          $("#Oa4mpClientClaimSourceModelClaimValueField").val("all");
        }

        // Set the label for the claim constraint 0 value with text appropriate for the SshKey type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.ssh_key'); ?>"
        description = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.ssh_key'); ?>"
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0ConstraintValue_li").show();

        // Set the constraint 0 constraint_field to "type".
        $("#Oa4mpClientClaimConstraint0ConstraintField").val("type");

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimClaimValueSelection_li").show();
        break;
    }

    // If Oa4mpClientClaimClaimValueSelection is set to "all", show the multiple value format selector field.
    if($("#Oa4mpClientClaimClaimValueSelection").val() == "all") {
      $("#MultipleValueFormatSelector_li").show();
    } else {
      $("#MultipleValueFormatSelector_li").hide();
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val("");
    }

    // If Oa4mpClientClaimClaimValueStringSerializationDelimiter is set, show the claim value string delimiter field and
    // show the multiple value format selector field with the value set to "delimited_string".
    if($("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val() != "") {
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").show();
      $("#MultipleValueFormatSelector_json_array").prop("checked", false);
      $("#MultipleValueFormatSelector_delimited_string").prop("checked", true);
      $("#MultipleValueFormatSelector_li").show();
    } else {
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
    }

  }

  // Update the value field after a name field selector is changed.
  function update_value_field_for_name(value) {
    console.log("update_value_field_for_name");
    console.log(value);
    $("#Oa4mpClientClaimSourceModelClaimValueField").val(value);
  }

  // Update the value field after a SSH key field selector is changed.
  function update_value_field_for_ssh_key(value) {
    console.log("update_value_field_for_ssh_key");
    console.log(value);
    $("#Oa4mpClientClaimSourceModelClaimValueField").val(value);
  }

  // Update the multiple value format selector field to be shown or hidden based on the value of the claim value selection field.
  function update_claim_value_selection_select(element) {
    console.log("update_claim_value_selection_select");

    if(element.value == "all") {
      console.log("all");
      $("#Oa4mpClientClaimClaimMultipleValueSerialization_li").show();
      if($("#Oa4mpClientClaimClaimMultipleValueSerialization_delimited_string").prop("checked")) {
        $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").show();
      }
    } else {
      console.log("first");
      $("#Oa4mpClientClaimClaimMultipleValueSerialization_li").hide();
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li").hide();
      $("#Oa4mpClientClaimClaimValueStringSerializationDelimiter").val("");
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

    // Add form submit handler to remove the multiple_value_format and name_field_selector fields before submission.
    $('form').on('submit', function(e) {
      // Remove name_field_selector radio buttons from form data before submission
      // since this is a UI control field and not part of the model
      $('input[name="name_field_selector"]').remove();

      // Remove ssh_key_field_selector radio buttons from form data before submission
      // since this is a UI control field and not part of the model
      $('input[name="ssh_key_field_selector"]').remove();

      // Remove co_person_role_field_selector select from form data before submission
      // since this is a UI control field and not part of the model
      $('select[name="co_person_role_field_selector"]').remove();
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
        $options['CoGroupMember'] = 'Groups';
        $options['Identifier'] = 'Identifier';
        $options['Name'] = 'Name';
        $options['CoPersonRole'] = 'Role';
        $options['SshKey'] = 'SSH Key';


        if($e) {
          print $this->Form->select('source_model', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- Name field selector -->
  <li id="NameFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <input type="radio"
               id="NameFieldSelector_all"
               name="name_field_selector"
               value="all" 
               onclick="update_value_field_for_name(this.value)"
               checked
               />
        <label for="NameFieldSelector_all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.all'); ?></label>

        <input type="radio"
               id="NameFieldSelector_given"
               name="name_field_selector"
               value="given"
               onclick="update_value_field_for_name(this.value)"
               />
        <label for="NameFieldSelector_given"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.given'); ?></label>

        <input type="radio"
               id="NameFieldSelector_middle"
               name="name_field_selector"
               value="middle" 
               onclick="update_value_field_for_name(this.value)"
               />
        <label for="NameFieldSelector_middle"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.middle'); ?></label>

        <input type="radio"
               id="NameFieldSelector_family"
               name="name_field_selector"
               value="family" 
               onclick="update_value_field_for_name(this.value)"
               />
        <label for="NameFieldSelector_family"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.family'); ?></label>

      </span>
    </div>
  </li>

  <!-- SSH Key field selector -->
  <li id="SshKeyFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <input type="radio"
               id="SshKeyFieldSelector_all"
               name="ssh_key_field_selector"
               value="all"
               onclick="update_value_field_for_ssh_key(this.value)"
               checked
               />
        <label for="SshKeyFieldSelector_all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.all'); ?></label>

        <input type="radio"
               id="SshKeyFieldSelector_skey"
               name="ssh_key_field_selector"
               value="skey"
               onclick="update_value_field_for_ssh_key(this.value)"
               />
        <label for="SshKeyFieldSelector_skey"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.skey'); ?></label>

        <input type="radio"
               id="SshKeyFieldSelector_comment"
               name="ssh_key_field_selector"
               value="comment"
               onclick="update_value_field_for_ssh_key(this.value)"
               />
        <label for="SshKeyFieldSelector_comment"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.comment'); ?></label>


        <input type="radio"
               id="SshKeyFieldSelector_type"
               name="ssh_key_field_selector"
               value="type"
               onclick="update_value_field_for_ssh_key(this.value)"
               />
        <label for="SshKeyFieldSelector_type"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.type'); ?></label>
      </span>
    </div>
  </li>

  <!-- CoPersonRole field selector -->
   <li id="CoPersonRoleFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <select 
               id="CoPersonRoleFieldSelector"
               name="co_person_role_field_selector"
               onclick="update_value_field_for_co_person_role(this.value)"
               >
               <option value=""><?php print ""; ?></option>
               <option value="all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.all'); ?></option>
               <option value="affiliation"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.affiliation'); ?></option>
               <option value="cou"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.cou'); ?></option>
               <option value="title"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.title'); ?></option>
               <option value="o"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.o'); ?></option>
               <option value="ou"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.ou'); ?></option>
        </select>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaim['source_model_claim_value_field'] -->
  <li style="display:none;">
    <?php
      $attrs = array();
      $attrs['value'] = $claim['source_model_claim_value_field'] ?? "";

      $options = array();
      $options['hidden'] = 'hidden';

      print $this->Form->input('source_model_claim_value_field', $attrs, $options);
    ?>
  </li>

  <!-- Oa4mpClientClaimConstraint.0.constraint_field -->
  <li style="display:none;">
    <?php
      $attrs = array();
      $attrs['value'] = $constraint0['constraint_field'] ?? "";

      print $this->Form->input('Oa4mpClientClaimConstraint.0.constraint_field', $attrs);
    ?>
  </li>

  <!-- Oa4mpClientClaimConstraint.0.value -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="Oa4mpClientClaimConstraint0ConstraintValue_li" style="<?php echo $style; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php 
          switch($claim['source_model'] ?? "") {
            case "Identifier":
              $label = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.identifier');
              break;
            case "EmailAddress":
              $label = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.email');
              break;
            case "Name":
              $label = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.name');
              break;
            case "CoGroupMember":
              $label = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.co_group_member');
              break;
            default:
              $label = "";
              break;
          }
          print $this->Form->label('Oa4mpClientClaimConstraint.0.value', $label);
        ?>
        <span class="required">*</span>
      </div>
      <?php
        switch($claim['source_model'] ?? "") {
          case "Identifier":
            $description = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.identifier');
            break;
          case "EmailAddress":
            $description = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.email');
            break;
          case "Name":
            $description = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.name');
            break;
          case "CoGroupMember":
            $description = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.co_group_member');
            break;
          default:
            $description = "";
            break;
        }
      ?>
      <div class="field-desc"><?php print $description; ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $options = array();
        $attrs = array();

        if(!empty($constraint0['constraint_value'])) {
          $attrs['value'] = $constraint0['constraint_value'];
        }

        switch($claim['source_model'] ?? "") {
          case "Identifier":
            $options = $vv_identifier_types;
            print $this->Form->select('Oa4mpClientClaimConstraint.0.constraint_value', $options, $attrs);
            break;
          case "EmailAddress":
            $options = $vv_email_types;
            print $this->Form->select('Oa4mpClientClaimConstraint.0.constraint_value', $options, $attrs);
            break;
          case "Name":
            $options = $vv_name_types;
            print $this->Form->select('Oa4mpClientClaimConstraint.0.constraint_value', $options, $attrs);
            break;
          case "CoGroupMember":
            print $this->Form->input('Oa4mpClientClaimConstraint.0.constraint_value', $attrs);
            break;
          case "CoPersonRole":
            print $this->Form->input('Oa4mpClientClaimConstraint.0.constraint_value', $attrs);
            break;
          default:
            print $this->Form->input('Oa4mpClientClaimConstraint.0.constraint_value', $attrs);
            break;
        }

      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaimConstraint.1.constraint_field -->
  <li style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.1.constraint_field', _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.constraint_field.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint.fd.constraint_field.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['value'] = $constraint1['constraint_field'] ?? "";
        $attrs['required'] = false;

        $options = array();
        $options['hidden'] = 'hidden';

        print $this->Form->input('Oa4mpClientClaimConstraint.1.constraint_field', $attrs, $options);
      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaimConstraint.1.constraint_value -->
  <li id="Oa4mpClientClaimConstraint1ConstraintValue_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.1.constraint_value', "") ?>
      </div>
      <div class="field-desc"><?php print ""; ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
      $attrs = array();
      $attrs['value'] = $constraint1['constraint_value'] ?? "";
      $attrs['required'] = false;

      $options = array();
      $options['hidden'] = 'hidden';

        print $this->Form->input('Oa4mpClientClaimConstraint.1.constraint_value', $attrs, $options);
      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaim['claim_value_selection'] -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="Oa4mpClientClaimClaimValueSelection_li" style="<?php echo $style; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_value_selection', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_selection.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_selection.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $options = array();
        $options['first'] = 'First value found';
        $options['all']   = 'All values found';

        $attrs = array();
        $attrs['value'] = $claim['claim_value_selection'] ?? "";
        $attrs['empty'] = true;
        $attrs['onChange'] = 'update_claim_value_selection_select(this);';

        if($e) {
          print $this->Form->select('claim_value_selection', $options, $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaim['claim_multiple_value_serialization'] -->
  <li id="Oa4mpClientClaimClaimMultipleValueSerialization_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_multiple_value_serialization', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.description'); ?>
      </div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          $options = array();
          $options['json_array'] = _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.json_array');
          $options['delimited_string'] = _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.delimited_string');

          $attrs = array();
          $attrs['legend'] = false;
          $attrs['separator'] = ' ';
          $attrs['value'] = $claim['claim_multiple_value_serialization'] ?? '';
          $attrs['onclick'] = 'update_string_d_select(this.value)';
          print $this->Form->radio('claim_multiple_value_serialization', $options, $attrs);
        ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaim['claim_value_string_serialization_delimiter'] -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['claim_value_string_serialization_delimiter']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="Oa4mpClientClaimClaimValueStringSerializationDelimiter_li" style="<?php echo $style; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_value_string_serialization_delimiter', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_string_serialization_delimiter.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_string_serialization_delimiter.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['value'] = $claim['claim_value_string_serialization_delimiter'] ?? null;

        if($e) {
          print $this->Form->input('claim_value_string_serialization_delimiter', $attrs);
        }
      ?>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaim['claim_value_json_format'] -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="Oa4mpClientClaimClaimValueJsonFormat_li" style="<?php echo $style; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_value_json_format', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_json_format.name')) ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_json_format.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
      <?php
        $attrs = array();
        $attrs['value'] = $claim['claim_value_json_format'] ?? "";
        $attrs['empty'] = true;

        $options = array();
        $options['string'] = 'JSON string';
        $options['number']   = 'JSON number';
        
        if($e) {
          print $this->Form->select('claim_value_json_format', $options, $attrs);
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
