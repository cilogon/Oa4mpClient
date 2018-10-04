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
?>
<script type="text/javascript">
  <!-- JS specific to these fields -->

function addCallbackInput() {
  $("#oa4mp_client_co_callback").find("li:hidden:first").show();
  $("#oa4mp_client_co_callback").find("li:visible").find("button").show();
  hideCallbackDeleteButtons();
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
  $("#oa4mp_client_co_search_attribute_0").show();
  $("#add_first_ldap_to_claim_mapping").hide();
  $("#add_another_ldap_to_claim_mapping").show();
}

function addLdapToClaimMappingInput() {
  $("#oa4mp_client_ldap_to_claim_mappings").find("div:hidden:first").show();
}

function removeLdapToClaimMappingInput(e) {
  $(e).parent('div.field-children-inline').find(':text').val("").removeAttr("required");
  $(e).parent('div.field-children-inline').find(':checkbox').prop("checked", false);
  $(e).closest('div.field-children-inline').hide();

  var visible = $("#oa4mp_client_ldap_to_claim_mappings").find("div.field-children-inline:visible").length;
  if (visible == 0) {
    $("#add_first_ldap_to_claim_mapping").show();
    $("#add_another_ldap_to_claim_mapping").hide();
  }
}

function js_local_onload() {
  hideCallbackDeleteButtons();
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
                   : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoOidcClient']['name'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
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
                   : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoOidcClient']['home_url'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
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
        for($i = 0; $i < 10; $i++) {
          if (($i == 0) || !empty($this->request->data['Oa4mpClientCoCallback'][$i]['url'])) {
            $display = "";
            $required = true;
          } else {
            $display = "display:none";
            $required = false;
          }

          $params = array();
          $params['required'] = $required;
          print '<li ' . 'style="' . $display . '">';
          print '  <div class="field-name">';
          print '    <div class="field-title">';
          print ($e ? $this->Form->label('Oa4mpClientCoCallback.' . $i . '.url', _txt('pl.oa4mp_client_co_callback.url.fd.name')) : _txt('pl.oa4mp_client_co_callback.url.fd.name')); 
          print '    </div>';
          print '  </div>';
          print '  <div class="field-info field-dynamic-actions">';
          print ($e ? $this->Form->input('Oa4mpClientCoCallback.' . $i . '.url', $params) : filter_var($oa4mp_client_co_callbacks[$i+1]['Oa4mpClientCoCallback']['url'],FILTER_SANITIZE_SPECIAL_CHARS)); 
          $params = array();
          $params['class'] = "deletebutton";
          $params['type'] = "button";
          $params['onclick'] = 'removeCallbackInput(this)';
          print $this->Html->tag('button', _txt('op.delete'), $params);
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


  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_scope.scope.fd.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_scope.scope.fd.description'); ?></div>
    </div>
    <ul class="field-children" id="oa4mp_client_co_scope">
      <li>
        <div class="field-info">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['checked'] = true;
              $args['onclick'] = 'event.preventDefault();';
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.openid.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OpenId;
              print ($e
                     ? $this->Form->input('Oa4mpClientCoScope.0.scope', $args)
                     : filter_var($oa4mp_client_co_scopes[0]['Oa4mpClientCoScope']['scope'],FILTER_SANITIZE_SPECIAL_CHARS));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][0])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.0.id');
                }
              }
            ?>
        </div>
      </li>


      <li>
        <div class="field-info">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['disabled'] = false;
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.profile.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Profile;
              print ($e
                     ? $this->Form->input('Oa4mpClientCoScope.1.scope', $args)
                     : filter_var($oa4mp_client_co_scopes[1]['Oa4mpClientCoScope']['scope'],FILTER_SANITIZE_SPECIAL_CHARS));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][1])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.1.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['disabled'] = false;
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.email.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::Email;
              print ($e
                     ? $this->Form->input('Oa4mpClientCoScope.2.scope', $args)
                     : filter_var($oa4mp_client_co_scopes[2]['Oa4mpClientCoScope']['scope'],FILTER_SANITIZE_SPECIAL_CHARS));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][2])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.2.id');
                }
              }
            ?>
        </div>
      </li>
      <li>
        <div class="field-info">
            <?php
              $args = array();
              $args['type'] = 'checkbox';
              $args['disabled'] = false;
              $args['label'] = _txt('pl.oa4mp_client_co_scope.scope.org.cilogon.userinfo.fd.name'); 
              $args['value'] = Oa4mpClientScopeEnum::OrgCilogonUserInfo;
              print ($e
                     ? $this->Form->input('Oa4mpClientCoScope.3.scope', $args)
                     : filter_var($oa4mp_client_co_scopes[3]['Oa4mpClientCoScope']['scope'],FILTER_SANITIZE_SPECIAL_CHARS));
              if($e) {
                if(!empty($this->request->data['Oa4mpClientCoScope'][3])) {
                  print $this->Form->hidden('Oa4mpClientCoScope.3.id');
                }
              }
            ?>
        </div>
      </li>
    </ul>
  </li>

  <?php
    if($e) {
      $args = array();
      $args['hidden'] = true;
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.enabled', $args);
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.authorization_type', $args);
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.serverurl', $args);
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.binddn', $args);
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.password', $args);
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.basedn', $args);
      print $this->Form->input('Oa4mpClientCoLdapConfig.0.search_name', $args);
      if(!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['id'])) {
        print $this->Form->input('Oa4mpClientCoLdapConfig.0.id', $args);
      }

    }
  ?>

  <li id="oa4mp_client_ldap_to_claim_mappings">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_search_attribute.fd.title'); ?>
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

          // If any of the LDAP to claim mappings are not hidden because
          // they have data, such as an edit operation or a form resubmit
          // after validation failure, hide the button to add the first
          // LDAP to claim mapping.
          for($i = 0; $i < 10; $i++) {
            if (!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i])) {
              $searchAttribute = $this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i];
              $name = $searchAttribute['name'];
              $return_name = $searchAttribute['return_name'];
              $return_as_list = $searchAttribute['return_as_list'];
              if (!empty($name) or !empty($return_name) or ($return_as_list != 0)) {
                $params['style'] = 'display:none';
                break;
              }
            }
          }

          print $this->Html->tag('a', _txt('pl.oa4mp_client_co_search_attribute.fd.add_first_button'), $params);
          print '</div>';
        }
      ?>
    </div>

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
          $name = $searchAttribute['name'];
          $return_name = $searchAttribute['return_name'];
          $return_as_list = $searchAttribute['return_as_list'];
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
        print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.name', $args) : filter_var($oa4mp_client_co_search_attributes[$i]['Oa4mpClientCoSearchAttribute']['name'],FILTER_SANITIZE_SPECIAL_CHARS));
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
        print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.return_name', $args) : filter_var($oa4mp_client_co_search_attributes[$i]['Oa4mpClientCoSearchAttribute']['return_name'],FILTER_SANITIZE_SPECIAL_CHARS));
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
        $args['checked'] = ($return_as_list !=0) ? true : false;
        $args['disabled'] = false;
        print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . "$i" . '.return_as_list', $args) : filter_var($oa4mp_client_co_search_attributes[$i]['Oa4mpClientCoSearchAttribute']['return_as_list'],FILTER_SANITIZE_SPECIAL_CHARS));
        print '   </div>';
        print '  </li>';
        print '</ul>';
        $params = array();
        $params['class'] = "deletebutton";
        $params['type'] = "button";
        $params['onclick'] = 'removeLdapToClaimMappingInput(this)';
        print $this->Html->tag('button', _txt('op.delete'), $params);

        print '</div>';

        if($e) {
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
  </li>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php print $this->Form->submit($submit_label); ?>
      </div>
    </li>
  <?php endif; ?>
</ul>
