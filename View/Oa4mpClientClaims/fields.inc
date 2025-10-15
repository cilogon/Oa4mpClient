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
  function group_ownership_change_constraint_0_value() {
    console.log("group_ownership_change_constraint_0_value");
    if($("#Oa4mpClientClaimConstraint0Value").prop("checked")) {
      $("#MultipleValueFormatSelector_json_array").prop("checked", true);
      $("#MultipleValueFormatSelector_delimited_string").prop("checked", false);
      $("#MultipleValueFormatSelector_delimited_string").prop("disabled", true);
      $("#Oa4mpClientClaimValueStringD").val("");
      $("#Oa4mpClientClaimValueStringD_li").hide();
    } else {
      $("#MultipleValueFormatSelector_delimited_string").prop("disabled", false);
    }
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

    let parent = $("#Oa4mpClientClaimConstraint0Value");
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));

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

    let parent = $("#Oa4mpClientClaimConstraint0Value");
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));

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
    parent.empty();
    parent.append($("<option></option>").attr("value", "").text(""));

    options.forEach((value, key) => {
      if (parent.children('option[value="' + key + '"]').length === 0) {
        parent.append($("<option></option>").attr("value", key).text(value));
      }
    });
  }

  // Reset the claim constraint 0 value field to the empty default.
  function reset_claim_constraint_0_value() {
    $("#Oa4mpClientClaimConstraint0Value_li").empty();
    $("#Oa4mpClientClaimConstraint0Value_li").append('<div class="field-name"></div>');
    $("#Oa4mpClientClaimConstraint0Value_li .field-name").append('<div class="field-title"></div>');
    $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-title").append('<label for="Oa4mpClientClaimConstraint0Value"></label>');
    $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-title").append(' <span class="required">*</span>');
    $("#Oa4mpClientClaimConstraint0Value_li .field-name").append('<div class="field-desc"></div>');
    $("#Oa4mpClientClaimConstraint0Value_li").append('<div class="field-info"></div>');
    $("#Oa4mpClientClaimConstraint0Value_li .field-info").append('<span class="field-info-prefix"></span>');
    $("#Oa4mpClientClaimConstraint0Value_li .field-info .field-info-prefix").append('<input name="data[Oa4mpClientClaimConstraint][0][value]" id="Oa4mpClientClaimConstraint0Value" required="required"></input>');
  }

  // Reset the claim constraint 1 value field to the default.
  function reset_claim_constraint_1_value() {
    $("#Oa4mpClientClaimConstraint1Value_li").empty();
    $("#Oa4mpClientClaimConstraint1Value_li").html('<div class="field-name"><div class="field-title"><label for="Oa4mpClientClaimConstraint1Value"></label></div><div class="field-desc"></div></div><div class="field-info"><span class="field-info-prefix"><input name="data[Oa4mpClientClaimConstraint][1][value]" id="Oa4mpClientClaimConstraint1Value" value="" maxlength="64" type="text"></span></div>');
  }

  // Show or hide
  function update_primary_name_value_req() {
    console.log("update_primary_name_value_req");
    if($("#Oa4mpClientClaimConstraint1Value").prop("checked")) {
      $("#Oa4mpClientClaimValueReq").val("first");
      $("#Oa4mpClientClaimValueReq_li").hide();
      $("#MultipleValueFormatSelector_li").hide();
      $("#Oa4mpClientClaimValueStringD_li").hide();
      $("#Oa4mpClientClaimValueStringD").val("");
    } else {
      $("#Oa4mpClientClaimValueReq").val("all");
      $("#Oa4mpClientClaimValueReq_li").show();
      $("#MultipleValueFormatSelector_li").show();
    }
  }

  // Update the string delimiter select field to be shown or hidden based on the value of the multiple value format selector field.
  function update_string_d_select(value) {
    console.log("update_string_d_select");
    console.log(value);

    if(value == "delimited_string") {
      if($("#Oa4mpClientClaimValueStringD").val() == "") {
        $("#Oa4mpClientClaimValueStringD").val(",");
      }
      $("#Oa4mpClientClaimValueStringD_li").show();
    } else {
      $("#Oa4mpClientClaimValueStringD_li").hide();
      $("#Oa4mpClientClaimValueStringD").val("");
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
    $("#Oa4mpClientClaimConstraint1Value_li").hide();
    $("#Oa4mpClientClaimValueField").val("");
    reset_claim_constraint_0_value();
    reset_claim_constraint_1_value();

    let label;
    let description;

    let $old;
    let attrs;
    let $new;

    switch (element.value) {
      case "Identifier":
        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0Value");
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
        $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0Value_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimValueReq_li").show();

        // Show the claim value format field with choices of "JSON string" and "JSON number" since
        // an Identifier value can be a string or a number.
        $("#Oa4mpClientClaimValueFormat_li").show();

        // Set the claim value field to "identifier" and the constraint 0 field to "type".
        $("#Oa4mpClientClaimValueField").val("identifier");
        $("#Oa4mpClientClaimConstraint0Field").val("type");
      break;

      case "EmailAddress":
        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0Value");
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
        $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0Value_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimValueReq_li").show();

        // Hide the claim value format field and set the value to "JSON string" since EmailAddress values are always strings
        // and never numbers.
        $("#Oa4mpClientClaimValueFormat_li").hide();
        $("#Oa4mpClientClaimValueFormat").val("string");

        // Set the claim value field to "mail" and the constraint 0 field to "type".
        $("#Oa4mpClientClaimValueField").val("mail");
        $("#Oa4mpClientClaimConstraint0Field").val("type");

        // Set the value of the claim constraint 1 field to "verified" to
        // allow selection of whether only verified EmailAddress values are included in the claim.
        $("#Oa4mpClientClaimConstraint1Field").val("verified");

        // Cast the claim constraint 1 value to a checkbox.
        $("#Oa4mpClientClaimConstraint1Value").attr("type", "checkbox");

        // If the value is not set, set it to "true" to default to only verified EmailAddress values being included in the claim.
        if($("#Oa4mpClientClaimConstraint1Value").val() == "") {
          $("#Oa4mpClientClaimConstraint1Value").val("true");
          $("#Oa4mpClientClaimConstraint1Value").prop("checked", true);
        } else if ($("#ClaimConstraint1Value").val() == "false") {
          $("#Oa4mpClientClaimConstraint1Value").prop("checked", false);
        } else if ($("#Oa4mpClientClaimConstraint1Value").val() == "true") {
          $("#Oa4mpClientClaimConstraint1Value").prop("checked", true);
        }

        // Also, add a hidden field to the form with the value set to "false" so that if the box is
        // unchecked, the value is set to "false" when the form is submitted.
        // Then set the name and description of the claim constraint 1 value field appropriately
        // for managing the verified EmailAddress values, and finally display the claim constraint 1 value field.

        $("#Oa4mpClientClaimConstraint1Value").removeAttr("maxlength");
        $("#Oa4mpClientClaimConstraint1Value").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][1][value]\" value=\"false\">"));
        $("#Oa4mpClientClaimConstraint1Value_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.name.email'); ?>");
        $("#Oa4mpClientClaimConstraint1Value_li").find(".field-title").append(" <span class=\"required\">*</span>");
        $("#Oa4mpClientClaimConstraint1Value_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.email'); ?>");
        $("#Oa4mpClientClaimConstraint1Value_li").show();
      break;

      case "CoGroupMember":
        // If Oa4mpClientClaimValueField is not set, set it to "member" as the default.
        if($("#Oa4mpClientClaimValueField").val() == "") {
          $("#Oa4mpClientClaimValueField").val("member");
        }

        // Set the claim value_req field to "all" as the default and hide the value_req field.
        $("#Oa4mpClientClaimValueReq").val("all");
        $("#Oa4mpClientClaimValueReq_li").hide();

        // Set the constraint 0 field to "owner" to allow selection of whether group ownership is included in the claim.
        $("#Oa4mpClientClaimConstraint0Field").val("owner");

        // Cast the claim constraint 0 value to a checkbox.
        $("#Oa4mpClientClaimConstraint0Value").replaceWith($("<input type=\"checkbox\" name=\"data[Oa4mpClientClaimConstraint][0][value]\" id=\"ClaimConstraint0Value\" value=\"false\"\">"));

        // If the value is not set, set it to "false" by default to not include group ownership in the claim.
        if($("#Oa4mpClientClaimConstraint0Value").val() == "") {
          $("#Oa4mpClientClaimConstraint0Value").val("false");
          $("#Oa4mpClientClaimConstraint0Value").prop("checked", false);
        } else if ($("#Oa4mpClientClaimConstraint0Value").val() == "false") {
          $("#Oa4mpClientClaimConstraint0Value").prop("checked", false);
        } else if ($("#Oa4mpClientClaimConstraint0Value").val() == "true") {
          $("#Oa4mpClientClaimConstraint0Value").prop("checked", true);
        }

        // Then set the name and description of the claim constraint 0 value field appropriately
        // for managing the group ownership values, and finally display the claim constraint 0 value field.

        $("#Oa4mpClientClaimConstraint0Value").removeAttr("maxlength");
        $("#Oa4mpClientClaimConstraint0Value").on("change", group_ownership_change_constraint_0_value);
        $("#Oa4mpClientClaimConstraint0Value_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.co_group_member'); ?>");
        $("#Oa4mpClientClaimConstraint0Value_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.co_group_member'); ?>");
        $("#Oa4mpClientClaimConstraint0Value_li").show();

      break;

      case "Name":
        // Cast the claim constraint 0 value to a select.
        $old = $("#Oa4mpClientClaimConstraint0Value");
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

        // If ClaimValueField is not set, set it to "all" as the default.
        if($("#Oa4mpClientClaimValueField").val() == "") {
          $("#Oa4mpClientClaimValueField").val("all");
        }

        // Show the name field selector field.
        $("#NameFieldSelector_li").show();

        // Set the label for the claim constraint 0 value with text appropriate for the Name type
        // and display the claim constraint 0 value field.
        label = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.name'); ?>"
        description = "<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.name'); ?>"
        $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-title label").text(label);
        $("#Oa4mpClientClaimConstraint0Value_li .field-name .field-desc").text(description);
        $("#Oa4mpClientClaimConstraint0Value_li").show();

        // Show the claim value requirement field with choices of "First value found" and "All values found".
        $("#Oa4mpClientClaimValueReq_li").show();

        // Hide the claim value format field and set the value to "JSON string" since Name values are always strings
        // and never numbers.
        $("#Oa4mpClientClaimValueFormat_li").hide();
        $("#Oa4mpClientClaimValueFormat").val("string");

        // Set the constraint 0 field to "type".
        $("#Oa4mpClientClaimConstraint0Field").val("type");

        // Set the value of the claim constraint 1 field to "primary" to
        // allow selection of whether only the primary Name value is included in the claim.
        $("#Oa4mpClientClaimConstraint1Field").val("primary");

        // Cast the claim constraint 1 value to a checkbox.
        $("#Oa4mpClientClaimConstraint1Value").attr("type", "checkbox");

        // If the value is not set, set it to "true" to default to only primary Name values being included in the claim.
        if($("#Oa4mpClientClaimConstraint1Value").val() == "") {
          $("#Oa4mpClientClaimConstraint1Value").val("true");
          $("#Oa4mpClientClaimConstraint1Value").prop("checked", true);
        } else if ($("#Oa4mpClientClaimConstraint1Value").val() == "false") {
          $("#Oa4mpClientClaimConstraint1Value").prop("checked", false);
        } else if ($("#Oa4mpClientClaimConstraint1Value").val() == "true") {
          $("#Oa4mpClientClaimConstraint1Value").prop("checked", true);
        }

        // Also, add a hidden field to the form with the value set to "false" so that if the box is
        // unchecked, the value is set to "false" when the form is submitted.
        // Then set the name and description of the claim constraint 1 value field appropriately
        // for managing the primary Name values, and finally display the claim constraint 1 value field.

        $("#Oa4mpClientClaimConstraint1Value").removeAttr("maxlength");
        $("#Oa4mpClientClaimConstraint1Value").before($("<input type=\"hidden\" name=\"data[Oa4mpClientClaimConstraint][1][value]\" value=\"false\">"));
        $("#Oa4mpClientClaimConstraint1Value_li").find(".field-title").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.name.name'); ?>");
        $("#Oa4mpClientClaimConstraint1Value_li").find(".field-title").append(" <span class=\"required\">*</span>");
        $("#Oa4mpClientClaimConstraint1Value_li").find(".field-desc").text("<?php print _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.name'); ?>");
        $("#Oa4mpClientClaimConstraint1Value").on("change", update_primary_name_value_req);
        $("#Oa4mpClientClaimConstraint1Value_li").show();

        // Since the default is to only assert the single primary name value set value_req to "first" and
        // hide value_req field and the multiple value format selector field and the claim value string delimiter field.
        $("#Oa4mpClientClaimValueReq").val("first");
        $("#Oa4mpClientClaimValueReq_li").hide();
        $("#MultipleValueFormatSelector_li").hide();
        $("#Oa4mpClientClaimValueStringD_li").hide();
        $("#Oa4mpClientClaimValueStringD").val("");
      break;
    }

    // If Oa4mpClientClaimValueReq is set to "all", show the multiple value format selector field.
    if($("#Oa4mpClientClaimValueReq").val() == "all") {
      $("#MultipleValueFormatSelector_li").show();
    } else {
      $("#MultipleValueFormatSelector_li").hide();
      $("#Oa4mpClientClaimValueStringD_li").hide();
      $("#Oa4mpClientClaimValueStringD").val("");
    }

    // If Oa4mpClientClaimValueStringD is set, show the claim value string delimiter field and
    // show the multiple value format selector field with the value set to "delimited_string".
    if($("#Oa4mpClientClaimValueStringD").val() != "") {
      $("#Oa4mpClientClaimValueStringD_li").show();
      $("#MultipleValueFormatSelector_json_array").prop("checked", false);
      $("#MultipleValueFormatSelector_delimited_string").prop("checked", true);
      $("#MultipleValueFormatSelector_li").show();
    } else {
      $("#Oa4mpClientClaimValueStringD_li").hide();
    }

  }

  // Update the value field after a name field selector is changed.
  function update_value_field_for_name(value) {
    console.log("update_value_field_for_name");
    console.log(value);
    $("#Oa4mpClientClaimValueField").val(value);
  }

  // Update the multiple value format selector field to be shown or hidden based on the value of the value requirement field.
  function update_value_req_select(element) {
    console.log("update_value_req_select");

    if(element.value == "all") {
      console.log("all");
      $("#MultipleValueFormatSelector_li").show();
      if($("#MultipleValueFormatSelector_delimited_string").prop("checked")) {
        $("#Oa4mpClientClaimValueStringD_li").show();
      }
    } else {
      console.log("first");
      $("#MultipleValueFormatSelector_li").hide();
      $("#Oa4mpClientClaimValueStringD_li").hide();
      $("#Oa4mpClientClaimValueStringD").val("");
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
      // Remove multiple_value_format radio buttons from form data before submission
      // since this is a UI control field and not part of the model
      $('input[name="multiple_value_format"]').remove();

      // Remove name_field_selector radio buttons from form data before submission
      // since this is a UI control field and not part of the model
      $('input[name="name_field_selector"]').remove();
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

  <!-- Oa4mpClientClaim['value_field'] -->
  <li style="display:none;">
    <?php
      $attrs = array();
      $attrs['id'] = 'Oa4mpClientClaimValueField';
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
      $attrs['id'] = 'Oa4mpClientClaimConstraint0Field';
      $attrs['value'] = $constraint0['field'] ?? "";

      print $this->Form->input('Oa4mpClientClaimConstraint.0.field', $attrs);
    ?>
  </li>

  <!-- Oa4mpClientClaimConstraint.0.value -->
  <?php
    $style = $this->action == 'edit' && !empty($claim['source_model']) ? 'display:block;' : 'display:none;';
  ?>
  <li id="Oa4mpClientClaimConstraint0Value_li" style="<?php echo $style; ?>">
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
        $attrs['id'] = 'Oa4mpClientClaimConstraint0Value';

        if(!empty($constraint0['value'])) {
          $attrs['value'] = $constraint0['value'];
        }

        switch($claim['source_model'] ?? "") {
          case "Identifier":
            $options = $vv_identifier_types;
            print $this->Form->select('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
            break;
          case "EmailAddress":
            $options = $vv_email_types;
            print $this->Form->select('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
            break;
          case "Name":
            $options = $vv_name_types;
            print $this->Form->select('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
            break;
          case "CoGroupMember":
            print $this->Form->input('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
            break;
          default:
            print $this->Form->input('Oa4mpClientClaimConstraint.0.value', $options, $attrs);
            break;
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
        $attrs['id'] = 'Oa4mpClientClaimConstraint1Field';
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
  <li id="Oa4mpClientClaimConstraint1Value_li" style="display:none;">
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
      $attrs['id'] = 'Oa4mpClientClaimConstraint1Value';
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
  <li id="Oa4mpClientClaimValueReq_li" style="<?php echo $style; ?>">
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
        $attrs['id'] = 'Oa4mpClientClaimValueReq';

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
  <li id="Oa4mpClientClaimValueStringD_li" style="<?php echo $style; ?>">
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
        $attrs['id'] = 'Oa4mpClientClaimValueStringD';

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
  <li id="Oa4mpClientClaimValueFormat_li" style="<?php echo $style; ?>">
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
        $attrs['id'] = 'Oa4mpClientClaimValueFormat';

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
