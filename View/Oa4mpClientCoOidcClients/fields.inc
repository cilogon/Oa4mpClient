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
    if(!empty($this->request->data['Oa4mpClientCoOidcClient']['oa4mp_identifier'])) {
      print $this->Form->hidden('oa4mp_identifier');
    }
  }

  // Include the admin client ID if it is available.
  if($e) {
    if(!empty($this->request->data['Oa4mpClientCoOidcClient']['admin_id'])) {
      print $this->Form->hidden('admin_id');
    }
    if(!empty($vv_admin_id)) {
      print $this->Form->hidden('admin_id', array('value' => $vv_admin_id));
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
  <!-- JS specific to these fields -->

function addCallbackInput() {
  $("#oa4mp_client_co_callback").find("li:hidden:first").find("input:first").attr("required", "required");
  $("#oa4mp_client_co_callback").find("li:hidden:first").show();
  $("#oa4mp_client_co_callback").find("li:visible").find("button").show();
  hideCallbackDeleteButtons();
}

function disableRefreshTokens() {
  $("#oa4mp_client_refresh_token_lifetime").hide();
  $("#Oa4mpClientCoOidcClientRefreshTokenLifetime").val("");
  $("#enable_refresh_tokens").show();
  $("#disable_refresh_tokens").hide();
}

function enableRefreshTokens() {
  $("#oa4mp_client_refresh_token_lifetime").show();
  $("#enable_refresh_tokens").hide();
  $("#disable_refresh_tokens").show();
}

function removeCallbackInput(e) {
  $(e).prev('input').val("").removeAttr("required");
  $(e).closest('li').hide();
  hideCallbackDeleteButtons();
}

function hideCallbackDeleteButtons() {
  var visible_callback_count = $("#oa4mp_client_co_callback").find("li:visible").length;
  if (visible_callback_count == 1) {
    $("#oa4mp_client_co_callback").find("li:visible").find("button").hide();
  }
}

function addFirstLdapToClaimMapping() {
  $("#oa4mp_client_ldap_configs").show();
  $("#oa4mp_client_co_search_attribute_0").find("input[type=text]").attr("required", "required");
  $("#oa4mp_client_co_search_attribute_0").show();
  $("#add_first_ldap_to_claim_mapping").hide();
  $("#add_another_ldap_to_claim_mapping").show();
}

function addLdapToClaimMappingInput() {
  $("#oa4mp_client_ldap_to_claim_mappings").find("div.field-children-inline:hidden:first").find("input[type=text]").attr("required", "required");
  $("#oa4mp_client_ldap_to_claim_mappings").find("div.field-children-inline:hidden:first").show();
}

function showConfirmGetcert() {
  if($("#Oa4mpClientCoScope4Scope").is(':checked')) {
    $("#confirm-getcert-scope-dialog").dialog("open");
  }
}

function removeLdapToClaimMappingInput(e) {
  $(e).parent('div.field-children-inline').find(':text').val("").removeAttr("required");
  $(e).parent('div.field-children-inline').find(':checkbox').prop("checked", false);
  $(e).closest('div.field-children-inline').hide();

  var visible = $("#oa4mp_client_ldap_to_claim_mappings").find("div.field-children-inline:visible").length;
  if (visible == 0) {
    $("#oa4mp_client_ldap_configs").hide();
    $("#add_first_ldap_to_claim_mapping").show();
    $("#add_another_ldap_to_claim_mapping").hide();
  }
}

function publicClientClicked() {
  if($("#Oa4mpClientCoOidcClientPublicClient").is(":checked")) {
    $("#Oa4mpClientCoScope1Scope").prop("checked", false);
    $("#Oa4mpClientCoScope1Scope").prop("disabled", true);
    $("#Oa4mpClientCoScope2Scope").prop("checked", false);
    $("#Oa4mpClientCoScope2Scope").prop("disabled", true);
    $("#Oa4mpClientCoScope3Scope").prop("checked", false);
    $("#Oa4mpClientCoScope3Scope").prop("disabled", true);
    $("#Oa4mpClientCoScope4Scope").prop("checked", false);
    $("#Oa4mpClientCoScope4Scope").prop("disabled", true);
    $("#oa4mp_client_ldap_to_claim_mappings").hide();

    for (let i=0; i < 10; i++) {
      let name_id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}Name`;
      let return_name_id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}ReturnName`;
      let return_as_list_id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}ReturnAsList`;
      $(name_id).val("");
      $(return_name_id).val("");
      $(return_as_list_id).prop("checked", false);
    }
  } else {
    $("#Oa4mpClientCoScope1Scope").prop("disabled", false);
    $("#Oa4mpClientCoScope2Scope").prop("disabled", false);
    $("#Oa4mpClientCoScope3Scope").prop("disabled", false);
    $("#oa4mp_client_ldap_to_claim_mappings").show();
  }
}

function namedConfigClicked() {
  if($("#Oa4mpClientCoOidcClientNamedConfigId").is(":checked")) {
    $("#Oa4mpClientCoOidcClientPublicClient").prop("checked", false);
    $("#oa4mp_client_co_oidc_client_public_client").hide();
    $("#oa4mp_client_co_oidc_client_scopes").hide();
    $("#oa4mp_client_co_scope").find("input[type='checkbox']:not(:first)").prop("checked", false);
    $("#oa4mp_client_ldap_to_claim_mappings").hide();
    $("#oa4mp_client_co_named_configs").show();

    for (let i=0; i < 10; i++) {
      let name_id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}Name`;
      let return_name_id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}ReturnName`;
      let return_as_list_id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}ReturnAsList`;
      $(name_id).val("");
      $(name_id).removeAttr("required");
      $(return_name_id).val("");
      $(return_name_id).removeAttr("required");
      $(return_as_list_id).prop("checked", false);
    }
  } else {
    $("#oa4mp_client_co_named_configs").find("input[type='checkbox']").prop("checked", false);
    $("#oa4mp_client_co_named_configs").hide();
    $("#oa4mp_client_ldap_to_claim_mappings").show();
    $("#oa4mp_client_co_oidc_client_public_client").show();
    $("#oa4mp_client_co_oidc_client_scopes").show();
  }
}

function specificNamedConfigClicked(e) {
  if($(e).is(":checked")) {
    $("#oa4mp_client_co_named_configs").find("input[type='checkbox']").not(e).prop("checked", false);
    $("#Oa4mpClientCoOidcClientNamedConfigId").val($(e).val());
  } else {
    $("#Oa4mpClientCoOidcClientNamedConfigId").val(0);
  }
}

function js_local_onload() {
  $("form").submit(function(e) {
    if($("#Oa4mpClientCoOidcClientNamedConfigId").is(":checked") && $("#oa4mp_client_co_named_configs input:checkbox:checked").length == 0) {
      e.preventDefault();
      $("#co-loading").hide();
      $("#named-config-not-selected-dialog").dialog("open");
      return false;
    }
  });

  if($("#Oa4mpClientCoOidcClientNamedConfigId").is(":checked")) {
    $("#oa4mp_client_co_named_configs").show();
    $("#oa4mp_client_co_oidc_client_public_client").hide();
    $("#oa4mp_client_ldap_to_claim_mappings").hide();
  }

  hideCallbackDeleteButtons();

  $("#confirm-getcert-scope-dialog").dialog({
      autoOpen: false,
      buttons: {
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.getcert.dialog.button.yes'); ?>": function() {
          $(this).dialog("close");
        },
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.getcert.dialog.button.no'); ?>": function() {
          $("#Oa4mpClientCoScope4Scope").prop("checked", false);
          $(this).dialog("close");
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

  $("#confirm-profile-scope-dialog").dialog({
      autoOpen: false,
      buttons: {
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.profile.dialog.button.yes'); ?>": function() {
          $("#Oa4mpClientCoScope1Scope").prop("checked", true);
          $(this).dialog("close");
        },
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.profile.dialog.button.no'); ?>": function() {
          $("#Oa4mpClientCoScope1Scope").prop("checked", false);
          $(this).dialog("close");
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

  $("#confirm-email-scope-dialog").dialog({
      autoOpen: false,
      buttons: {
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.email.dialog.button.yes'); ?>": function() {
          $("#Oa4mpClientCoScope2Scope").prop("checked", true);
          $(this).dialog("close");
        },
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.email.dialog.button.no'); ?>": function() {
          $("#Oa4mpClientCoScope2Scope").prop("checked", false);
          $(this).dialog("close");
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

  $("#confirm-userinfo-scope-dialog").dialog({
      autoOpen: false,
      buttons: {
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.userinfo.dialog.button.yes'); ?>": function() {
          $("#Oa4mpClientCoScope3Scope").prop("checked", true);
          $(this).dialog("close");
        },
        "<?php print _txt('pl.oa4mp_client_co_scope.scope.userinfo.dialog.button.no'); ?>": function() {
          $("#Oa4mpClientCoScope3Scope").prop("checked", false);
          $(this).dialog("close");
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

  $("#named-config-not-selected-dialog").dialog({
      autoOpen: false,
      modal: true,
      show: {
        effect: "fade"
      },
      hide: {
        effect: "fade"
      }
  });

  window.cilogon_standard_claims = [
    "sub",
    "iss",
    "aud",
    "token_id",
    "email",
    "given_name",
    "family_name",
    "name",
    "idp",
    "idp_name",
    "eppn",
    "eptid",
    "affiliation",
    "ou",
    "oidc",
    "cert_subject_dn"
  ];

  window.email_claims = [
    "email"
    ];

  window.profile_claims = [
    "given_name",
    "family_name",
    "name"
  ];

  window.userinfo_claims = [
    "idp",
    "idp_name",
    "eppn",
    "eptid",
    "affiliation",
    "ou",
    "oidc"
  ];

  var i;
  for (i=0; i < 10; i++) {
    let id = `#Oa4mpClientCoLdapConfig0Oa4mpClientCoSearchAttribute${i}ReturnName`;
    $(id).on("input", function() {
      let claim_name = $(id).val();
      if (cilogon_standard_claims.includes(claim_name)) {
        $(id).closest('div.field-info').next('.standard-claim-warning').show().delay(5000).fadeOut();
      }

      if (profile_claims.includes(claim_name)) {
        if(!$("#Oa4mpClientCoScope1Scope").is(':checked')) {
          $("#confirm-profile-scope-dialog").dialog("open");
        }
      }

      if (email_claims.includes(claim_name)) {
        if(!$("#Oa4mpClientCoScope2Scope").is(':checked')) {
          $("#confirm-email-scope-dialog").dialog("open");
        }
      }

      if (userinfo_claims.includes(claim_name)) {
        if(!$("#Oa4mpClientCoScope3Scope").is(':checked')) {
          $("#confirm-userinfo-scope-dialog").dialog("open");
        }
      }
    });
  }

  var j;
  for (j=0; j < 50; j++) {
    let id = `#Oa4mpClientCoCallback${j}Url`;
    $(id).on("input", function() {
      let url = $(id).val();
      if (url.includes("*")) {
        $(id).closest('li').find('.callback-error').show();
        $(id).addClass('form-error');
      } else {
        $(id).closest('li').find('.callback-error').hide();
        $(id).removeClass('form-error');
      }
    });
  }

  // Explorer menu toggles
  $(".fieldGroupName").click(
    function(event) {
      event.preventDefault();
      $(this).next(".fields").slideToggle("fast");
      // toggle the icon
      if ($(this).find(".material-icons").text() == "indeterminate_check_box") {
        $(this).find(".material-icons").text("add_box");
      } else {
        $(this).find(".material-icons").text("indeterminate_check_box");
      }
    });
}

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
