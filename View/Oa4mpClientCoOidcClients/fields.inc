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

  <li id="callbacks-li">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.callbacks.fd.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.callbacks.fd.description'); ?></div>
    </div>
    <ul class="field-children" id="oa4mp_client_co_callback">

      <?php
        for($i = 0; $i < 50; $i++) {
          if (($i == 0) || !empty($this->request->data['Oa4mpClientCoCallback'][$i]['url'])) {
            $display = "";
            $required = true;
          } else {
            $display = "display:none";
            $required = false;
          }

          if(!empty($this->request->data['Oa4mpClientCoCallback'][$i]['url'])) {
            $viewOnlyValue = $this->request->data['Oa4mpClientCoCallback'][$i]['url'];
          } else {
            $viewOnlyValue = "";
          }

          $params = array();
          $params['required'] = $required;
          if(isset($this->validationErrors['Oa4mpClientCoCallback']['url'][$i])) {
            $params['class'] = 'form-error';
          }
          print '<li ' . 'style="' . $display . '">';
          print '  <div class="field-name">';
          print '    <div class="field-title">';
          print ($e ? $this->Form->label('Oa4mpClientCoCallback.' . $i . '.url', _txt('pl.oa4mp_client_co_callback.url.fd.name')) : _txt('pl.oa4mp_client_co_callback.url.fd.name')); 
          print '    </div>';
          print '  </div>';
          print '  <div class="field-info field-dynamic-actions">';
          print ($e ? $this->Form->input('Oa4mpClientCoCallback.' . $i . '.url', $params) : filter_var($viewOnlyValue,FILTER_SANITIZE_SPECIAL_CHARS));
          if(isset($this->validationErrors['Oa4mpClientCoCallback']['url'][$i])) {
            print '<div class="error-message">';
            print $this->validationErrors['Oa4mpClientCoCallback']['url'][$i];
            print '</div>';
          }
          $params = array();
          $params['class'] = "deletebutton";
          $params['type'] = "button";
          $params['onclick'] = 'removeCallbackInput(this)';
          print $this->Html->tag('button', _txt('op.delete'), $params);
          print '  </div>';
          print '  <div class="field-name callback-error" style="display: none;">';
          print '  </div>';
          print '  <div class="field-info field-dynamic-actions callback-error" style="display: none;">';
          print '    <div class="error-message callback-error" style="display: none;">';
          print _txt('pl.oa4mp_client_co_oidc_client.er.wildcards');
          print '    </div>';
          print '  </div>';
          print '</li>';

          if($e) {
            if(!empty($this->request->data['Oa4mpClientCoCallback'][$i])) {
              print $this->Form->hidden('Oa4mpClientCoCallback.' . $i . '.id');
              print $this->Form->hidden('Oa4mpClientCoCallback.' . $i . '.client_id');
            }
          }
        }

        if($e) {
          print '<div>';
          $params = array();
          $params['class'] = 'addbutton';
          $params['onclick'] = 'addCallbackInput()';
          $params['id'] = 'oa4mp_client_add_callback_input_button';
          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_oidc_client.callbacks.fd.add_button'), $params);
          print '</div>';
        }
      ?>

    </ul>
  </li>

  <?php
    if(!empty($this->request->data['Oa4mpClientCoNamedConfig'])) {
      $display = '';
    } else {
      $display = 'display:none';
    }
  ?>

  <li style="<?php print $display; ?>">
    <div class="field-name">
      <div class="field-title">
        <?php
          print _txt('pl.oa4mp_client_co_oidc_client.named_config.fd.name');
        ?>
      </div>
      <div class="field-desc">
        <?php
          print _txt('pl.oa4mp_client_co_oidc_client.named_config.fd.description');
        ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        $params = array();
        $params['type'] = 'checkbox';
        $params['onclick'] = 'namedConfigClicked()';
        if(!empty($this->request->data['Oa4mpClientCoOidcClient']['named_config_id'])) {
          $params['checked'] = true;
          $params['value'] = $this->request->data['Oa4mpClientCoOidcClient']['named_config_id'];
        } else {
          $params['value'] = 0;
          $params['checked'] = false;
        }
        print $this->Form->input('named_config_id', $params);
      ?>  
    </div>
    <ul id="oa4mp_client_co_named_configs" class="field-children" style="display:none">
      <?php
        $i = 0;
        foreach($this->request->data['Oa4mpClientCoNamedConfig'] as $c) {
          print '<li>';
          print '  <div class="field-info form-check">';
          $args = array();
          $args['type'] = 'checkbox';
          $args['class'] = 'form-check-input';
          $args['onclick'] = 'specificNamedConfigClicked(this)';
          $args['value'] = $c['id'];
          $args['checked'] = false;

          $label = '<div class="named-config">';
          $label = $label . '<span class="named-config-name">' . $c['config_name'] . '</span>';
          $label = $label . '<span class="named-config-required-scopes">';
          $label = $label . ' ' . _txt('pl.oa4mp_client_co_scope.scope.openid.required') . ',';
          $label = $label . ' ' . _txt('pl.oa4mp_client_co_named_config.allowed_scopes') . ' ';

          $scopes = array();
          foreach($c['Oa4mpClientCoScope'] as $s) {
            if($s['scope'] == 'openid') {
              continue;
            }
            $scopes[] = $s['scope'];
          }

          $label = $label . implode(', ', $scopes);
          $label = $label . '</span>';
          $label = $label . '</div>';
          $label = $label . '<div class="named-config-desc">';
          $label = $label . $c['description'];
          $label = $label . '</div>';
  
          $args['label'] = $label;
          if(!empty($this->request->data['Oa4mpClientCoOidcClient']['named_config_id'])) {
            if($this->request->data['Oa4mpClientCoOidcClient']['named_config_id'] == $c['id']) {
            $args['checked'] = true;
            }
          }
          print $this->Form->input('Oa4mpClientCoNamedConfig.' . $i . '.id', $args);
          // We want this to be exempt from SecurityComponent field hashing so it does
          // not have to be included in the POST.
          $this->Form->unlockField('Oa4mpClientCoNamedConfig.' . $i . '.id');
          print '  </div>';
          print '</li>';
          $i = $i + 1;
        }
      ?>
    </ul>
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
        if(!empty($this->request->data['Oa4mpClientCoOidcClient']['public_client'])) {
          $viewOnlyValue = "Yes";
        } else {
          $viewOnlyValue = "No";
        }
        print ($this->Form->input('public_client', $params)); ?>
    </div>
  </li>

  <?php
    if(!empty($this->request->data['Oa4mpClientCoOidcClient']['named_config_id'])) {
      $display = 'display:none';
    } else {
      $display = '';
    }
  ?>

    <li id="oa4mp_client_co_oidc_client_scopes" style="<?php print $display; ?> ">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_scope.scope.fd.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc">
        <?php
          if($is_public_client) {
            print _txt('pl.oa4mp_client_co_scope.scope.fd.description.public');
          } else {
            print _txt('pl.oa4mp_client_co_scope.scope.fd.description');
          }
        ?>
      </div>
    </div>
    <ul class="field-children" id="oa4mp_client_co_scope">
      <li>
        <div class="field-info form-check checkbox-checked-appear-disabled">
            <span class="material-icons-outlined">check_box</span>
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['checked'] = true;
              $args['onclick'] = 'event.preventDefault();';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.openid.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OpenId;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.0.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][0])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.0.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.profile.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Profile;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.1.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][1])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.1.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.email.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Email;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.2.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][2])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.2.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.org.cilogon.userinfo.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OrgCilogonUserInfo;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.3.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][3])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.3.id');
                }
              }
            ?>
        </div>
      </li>

      <?php if($this->action == "edit" && !empty($this->request->data['Oa4mpClientCoScope'][4])): ?>
      <li>
        <div class="field-info form-check">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              if($is_public_client || !$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              //$args['disabled'] = false;
              $args['onclick'] = 'showConfirmGetcert()';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.getcert.fd.name');
              $args['value'] = Oa4mpClientScopeEnum::Getcert;
              $args['class'] = "form-check-input";
              print ($this->Form->input('Oa4mpClientCoScope.4.scope', $args));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][4])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.4.id');
                }
              }
            ?>
        </div>
      </li>
      <?php endif;?>

    </ul>
  </li>


  <li>
    <?php
      $rt_set = false;
      if(!empty($this->request->data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'])) {
        if(!is_null($this->request->data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'])) {
          $rt_set = true;
        }
      }
    ?>

    <div class="field-name">
      <div class="field-title">
        <?php print ($e
                     ? $this->Form->label('refresh_token_lifetime', _txt('pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.name'))
                     : _txt('pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.name')); ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print '<div>';
          $params = array();
          $params['class'] = 'addbutton';
          $params['onclick'] = 'enableRefreshTokens()';
          $params['id'] = 'enable_refresh_tokens';

          // If a refresh token lifetime is already set then hide the button
          // to enable refresh tokens.
          if($rt_set) {
            $params['style'] = 'display:none';
          }

          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.enable_button'), $params);
          print '</div>';

          print '<div>';
          $params = array();
          $params['class'] = 'addbutton';
          $params['onclick'] = 'disableRefreshTokens()';
          $params['id'] = 'disable_refresh_tokens';

          // If a refresh token lifetime is not already set then hide the button
          // to disable refresh tokens.
          if(!$rt_set) {
            $params['style'] = 'display:none';
          }

          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_oidc_client.refresh_token_enable.fd.disable_button'), $params);
          print '</div>';
        }
      ?>
    </div>
    <ul id="oa4mp_client_refresh_token_lifetime" class="field-children" style="<?php print ($rt_set || !$e ? '' : 'display:none')?>">
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
            <?php
              $args = array();
              $args['required'] = false;
              if(!$e) {
                if($rt_set) {
                  $viewOnlyValue = filter_var($this->request->data['Oa4mpClientCoOidcClient']['refresh_token_lifetime'],FILTER_SANITIZE_SPECIAL_CHARS);
                } else {
                  $viewOnlyValue = "Not set";
                }
              }
              print ($e ? $this->Form->input('refresh_token_lifetime', $args) : $viewOnlyValue);
            ?>
        </div>
      </li>
    </ul>
  </li>

    <?php
      if($e) {
        $args = array();
        $args['hidden'] = true;
        $passwdArgs = array();
        $passwdArgs['type'] = 'text';
        $passwdArgs['autocomplete'] = 'new-password';
        print $this->Form->input('Oa4mpClientCoLdapConfig.0.enabled', $args);
        print $this->Form->input('Oa4mpClientCoLdapConfig.0.authorization_type', $args);
        if(!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['id'])) {
          print $this->Form->input('Oa4mpClientCoLdapConfig.0.id', $args);
        }

      }
    ?>

  <?php
    // Determine if any of the LDAP to claim mappings have existing data
    // and so should be displayed.
    $hideLdapDetails = true;
    for($i = 0; $i < 10; $i++) {
      if (!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i])) {
        $searchAttribute = $this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i];
        $name = $searchAttribute['name'];
        $return_name = $searchAttribute['return_name'];
        $return_as_list = $searchAttribute['return_as_list'];
        if (!empty($name) or !empty($return_name) or ($return_as_list != 0)) {
          $hideLdapDetails = false;
          break;
        }
      }
    }
    if(!empty($this->validationErrors['Oa4mpClientCoLdapConfig'])) {
      $hideLdapDetails = false;
    }
  ?>

  <?php if($is_public_client): ?>
  <li id="oa4mp_client_ldap_to_claim_mappings" style="display: none;">
  <?php else: ?>
  <li id="oa4mp_client_ldap_to_claim_mappings">
  <?php endif; ?>

    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_search_attribute.fd.title'); ?>
        <!-- LDAP to Claim Mappings -->
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_search_attribute.fd.description'); ?></div>
    </div>
    <div class="field-info">

      <?php
        if($e) {
          print '<div>';
          $params = array();
          $params['class'] = 'addbutton';
          $params['onclick'] = 'addFirstLdapToClaimMapping()';
          $params['id'] = 'add_first_ldap_to_claim_mapping';
          if(!$hideLdapDetails) {
            $params['style'] = 'display:none';
          }
          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_search_attribute.fd.add_first_button'), $params);
          print '</div>';
        }
      ?>
    </div>
    
    <?php if($hideLdapDetails): ?>
    <ul id="oa4mp_client_ldap_configs" class="field-children" style="display: none;">
    <?php else: ?>
    <ul id="oa4mp_client_ldap_configs" class="field-children">
    <?php endif; ?>

      <div class="explorerContainer">
        <div class="personExplorer">
          <ul>
              <a class="fieldGroupName" href="#connection-details">
                <em class="material-icons">add_box</em>
                <?php print _txt('pl.oa4mp_client_co_ldap_config.explorer.heading.connection'); ?>
              </a>
              <ul class="fields form-list" style="display: none;">

                <li>
                  <div class="field-name">
                    <div class="field-title">
                      <?php print ($e
                                   ?  $this->Form->label('Oa4mpClientCoLdapConfig.0.serverurl', _txt('pl.oa4mp_client_co_ldap_config.serverurl.fd.name'))
                                   : _txt('pl.oa4mp_client_co_ldap_config.serverurl.fd.name')); ?>
                      <span class="required">*</span>
                    </div>
                  </div>
                  <div class="field-info">
                      <?php
                        $params = array();
                        if(isset($this->validationErrors['Oa4mpClientCoLdapConfig']['serverurl'])) {
                          $params['class'] = 'form-error';
                        }
                        print ($e
                               ? $this->Form->input('Oa4mpClientCoLdapConfig.0.serverurl', $params)
                               : filter_var($this->request->data['Oa4mpClientCoLdapConfig'][0]['serverurl']));
                        if(isset($this->validationErrors['Oa4mpClientCoLdapConfig']['serverurl'])) {
                          print '<div class="error-message">';
                          print $this->validationErrors['Oa4mpClientCoLdapConfig']['serverurl'][0];
                          print '</div>';
                        }
                      ?>
                  </div>
                </li>

                <li>
                  <div class="field-name">
                    <div class="field-title">
                      <?php print ($e
                                   ?  $this->Form->label('Oa4mpClientCoLdapConfig.0.binddn', _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name'))
                                   : _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name')); ?>
                      <span class="required">*</span>
                    </div>
                  </div>
                  <div class="field-info">
                      <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.binddn') : filter_var($this->request->data['Oa4mpClientCoLdapConfig'][0]['binddn'])); ?>
                  </div>
                </li>

                <li>
                  <div class="field-name">
                    <div class="field-title">
                      <?php print ($e
                                   ?  $this->Form->label('Oa4mpClientCoLdapConfig.0.password', _txt('pl.oa4mp_client_co_ldap_config.password.fd.name'))
                                   : _txt('pl.oa4mp_client_co_ldap_config.password.fd.name')); ?>
                      <span class="required">*</span>
                    </div>
                  </div>
                  <div class="field-info">
                      <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.password') : filter_var($this->request->data['Oa4mpClientCoLdapConfig'][0]['password'])); ?>
                  </div>
                </li>


              </ul>

              <a class="fieldGroupName" href="#tabs-sync">
                <em class="material-icons">add_box</em>
                <?php print _txt('pl.oa4mp_client_co_ldap_config.explorer.heading.search'); ?>
              </a>
              <ul class="fields form-list" style="display: none;">

                <li>
                  <div class="field-name">
                    <div class="field-title">
                      <?php print ($e
                                   ?  $this->Form->label('Oa4mpClientCoLdapConfig.0.basedn', _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name'))
                                   : _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name')); ?>
                      <span class="required">*</span>
                    </div>
                  </div>
                  <div class="field-info">
                      <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.basedn') : filter_var($this->request->data['Oa4mpClientCoLdapConfig'][0]['basedn'])); ?>
                  </div>
                </li>

                <li>
                  <div class="field-name">
                    <div class="field-title">
                      <?php print ($e
                                   ?  $this->Form->label('Oa4mpClientCoLdapConfig.0.search_name', _txt('pl.oa4mp_client_co_ldap_config.search_name.fd.name'))
                                   : _txt('pl.oa4mp_client_co_ldap_config.search_name.fd.name')); ?>
                      <span class="required">*</span>
                    </div>
                  </div>
                  <div class="field-info">
                      <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.search_name') : filter_var($this->request->data['Oa4mpClientCoLdapConfig'][0]['search_name'])); ?>
                  </div>
                </li>


              </ul>

              <a class="fieldGroupName" href="#tabs-cxn">
                <em class="material-icons">indeterminate_check_box</em>
                <?php print _txt('pl.oa4mp_client_co_ldap_config.explorer.heading.mappings'); ?>
              </a>
              <ul class="fields form-list">

          <?php
            // If any of the LDAP to claim mappings are not hidden because
            // they have data, such as an edit operation or a form resubmit
            // after validation failure, display the button to add another
            // LDAP to claim mapping.

            $displayAddLdapToClaimMappingButton = false;

            for($i = 0; $i < 10; $i++) {
              $display = "display:none";
              $name = "";
              $return_name = "";
              $return_as_list = 0;

              if (!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i])) {
                $searchAttribute = $this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i];
                $name = filter_var($searchAttribute['name'], FILTER_SANITIZE_SPECIAL_CHARS);
                $return_name = filter_var($searchAttribute['return_name'], FILTER_SANITIZE_SPECIAL_CHARS);
                $return_as_list = filter_var($searchAttribute['return_as_list'], FILTER_SANITIZE_SPECIAL_CHARS);
              }

              if (!empty($name) or !empty($return_name) or ($return_as_list != 0)) {
                  $display = "";
                  $displayAddLdapToClaimMappingButton = true;
              } 

              print '<div class="field-children-inline" style="' . $display . '" id="oa4mp_client_co_search_attribute_' . "$i" . '">';
              print '<ul class="field-children field-ldap-to-claim-mapping">';
              print '  <li>';
              print '    <div class="field-name">';
              print '      <div class="field-title">';
              print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.name', _txt('pl.oa4mp_client_co_search_attribute.name.fd.name')) : _txt('pl.oa4mp_client_co_search_attribute.name.fd.name')); 
              print '       <span class="required">*</span>';
              print '     </div>';
              print '   </div>';
              print '   <div class="field-info">';
              $args = array();
              $args['required'] = !empty($name) ? true : false;
              print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.name', $args) : $name);
              print '   </div>';
              print ' </li>';
              print '  <li>';
              print '    <div class="field-name">';
              print '      <div class="field-title">';
              print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.return_name', _txt('pl.oa4mp_client_co_search_attribute.return_name.fd.name')) : _txt('pl.oa4mp_client_co_search_attribute.return_name.fd.name'));
              print '        <span class="required">*</span>';
              print '      </div>';
              print '    </div>';
              print '    <div class="field-info">';
              $args = array();
              $args['required'] = !empty($return_name) ? true : false;
              print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.return_name', $args) : $return_name);
              print '    </div>';
              print '    <div class="standard-claim-warning" style="display: none;">';
              print  '   <div class="field-name">';
              print  '   </div>';
              print '    <div class="field-info">';
              print '<em class="material-icons" aria-hidden="true">info</em>';
              print _txt('pl.oa4mp_client_co_scope.scope.override');
              print '    </div>';
              print '    </div>';
              print '  </li>';
              print '  <li>';
              print '    <div class="field-name">';
              print '      <div class="field-title">';
              print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.return_as_list', _txt('pl.oa4mp_client_co_search_attribute.return_as_list.fd.name')) : _txt('pl.oa4mp_client_co_search_attribute.return_as_list.fd.name'));
              print '      </div>';
              print '   </div>';
              print '   <div class="field-info">';
              $args = array();
              $args['required'] = false;
              $args['type'] = 'checkbox';
              $args['checked'] = empty($return_as_list) ? false : true;
              if(!$e) {
                $args['disabled'] = true;
              } else {
                $args['disabled'] = false;
              }
              print $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.return_as_list', $args);
              print '   </div>';
              print '  </li>';
              print '</ul>';

              if($e) {
                $params = array();
                $params['class'] = "deletebutton";
                $params['type'] = "button";
                $params['onclick'] = 'removeLdapToClaimMappingInput(this)';
                print $this->Html->tag('button', _txt('op.delete'), $params);
                print '</div>';
                if(!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i]['id'])) {
                  print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.id');
                }
              }
            }

            if($e) {
              print '<div>';
              $params = array();
              $params['class'] = 'addbutton';
              $params['onclick'] = 'addLdapToClaimMappingInput()';
              if (!$displayAddLdapToClaimMappingButton) {
                $params['style'] = 'display:none';
              }
              $params['id'] = 'add_another_ldap_to_claim_mapping';
              print $this->Html->tag('a', _txt('pl.oa4mp_client_co_search_attribute.fd.add_another_button'), $params);
              print '</div>';
            }
          ?>
              </ul>
          </ul>
        </div>
      </div> <!-- explorer container -->

    </ul>
  </li>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php
          $params = array();
          print $this->Form->submit($submit_label);
        ?>
      </div>
    </li>
  <?php endif; ?>

</ul>

<div id="confirm-email-scope-dialog" title="<?php print _txt('pl.oa4mp_client_co_scope.scope.email.dialog.title'); ?>">
  <p><?php print _txt('pl.oa4mp_client_co_scope.scope.email.dialog.text'); ?></p>
</div>

<div id="confirm-getcert-scope-dialog" title="<?php print _txt('pl.oa4mp_client_co_scope.scope.getcert.dialog.title'); ?>">
  <p><?php print _txt('pl.oa4mp_client_co_scope.scope.getcert.dialog.text'); ?></p>
</div>

<div id="confirm-profile-scope-dialog" title="<?php print _txt('pl.oa4mp_client_co_scope.scope.profile.dialog.title'); ?>">
  <p><?php print _txt('pl.oa4mp_client_co_scope.scope.profile.dialog.text'); ?></p>
</div>

<div id="confirm-userinfo-scope-dialog" title="<?php print _txt('pl.oa4mp_client_co_scope.scope.userinfo.dialog.title'); ?>">
  <p><?php print _txt('pl.oa4mp_client_co_scope.scope.userinfo.dialog.text'); ?></p>
</div>

<div id="named-config-not-selected-dialog" title="<?php print _txt('pl.oa4mp_client_co_named_config.not_selected.dialog.title'); ?>">
  <p><?php print _txt('pl.oa4mp_client_co_named_config.not_selected.dialog.text'); ?></p>
</div>
