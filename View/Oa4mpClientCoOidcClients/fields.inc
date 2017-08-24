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

  print $this->Html->css("Oa4mpClient.oa4mpclient");

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
  $args['action'] = 'index';
  if(isset($cur_co)) {
    $args['co'] = $cur_co['Co']['id'];
  }

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);
  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_oidc_clients.1')));
  $this->Html->addCrumb($crumbTxt);
    
?>

<script type="text/javascript">
$(function() {
    // Explorer menu toggles
    $(".fieldGroupName").click(function(event) {
      event.preventDefault();
      $(this).next(".fields").slideToggle("fast");
      // toggle the +/- icon:
      if ($(this).find(".ui-icon").hasClass("ui-icon-circlesmall-minus")) {
        $(this).find(".ui-icon").removeClass("ui-icon-circlesmall-minus").addClass("ui-icon-circlesmall-plus");
      } else {
        $(this).find(".ui-icon").removeClass("ui-icon-circlesmall-plus").addClass("ui-icon-circlesmall-minus");
      }
    });


  });

function addCallbackInput() {
  $("#oa4mp_client_co_callback").find("tr:hidden:first").show()
    .find("input").attr("required", "required");
}

function addScopeInput() {
  $("#oa4mp_client_co_scope").find("tr:hidden:first").show()
    .find("input").attr("required", "required");
}

function addSearchAttributeInput() {
  $("#oa4mp_client_co_search_attribute").find("tr:hidden:first").show()
    .find("input").attr("required", "required");
  $("#oa4mp_client_co_search_attribute").find("tr:hidden:first").show()
    .find("input").attr("required", "required");
  $("#oa4mp_client_co_search_attribute").find("tr:hidden:first").show();
}
</script>

<div id="oa4mp_client_oidc_client" class="explorerContainer">
  <div id="oidcClientExplorer" class="personExplorer">
    <ul>

      <!-- Begin Basic field group -->
      <li id="fields-attributes" class="fieldGroup">
        <a class="fieldGroupName" href="#tabs-attributes">
          <span class="ui-icon ui-icon-circlesmall-minus"></span>
          Basic
        </a>

        <div class="fields">

          <table id="oa4mp_client_co_oidc_client" class="ui-widget">
            <tbody>
              <tr class="line1">
                <td>
                  <?php print ($e ? $this->Form->label('name', _txt('pl.oa4mp_client_co_oidc_client.name.fd.name')) : _txt('pl.oa4mp_client_co_oidc_client.name.fd.name')); ?><span class="required">*</span>
                </td>
                <td>
                  <?php print ($e ? $this->Form->input('name') : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoOidcClient']['name'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                </td>
              </tr>
              <tr class="line2">
                <td>
                  <?php print ($e ? $this->Form->label('home_url', _txt('pl.oa4mp_client_co_oidc_client.home_url.fd.name')) : _txt('pl.oa4mp_client_co_oidc_client.home_url.fd.name')); ?><span class="required">*</span>
                </td>
                <td>
                  <?php print ($e ? $this->Form->input('home_url') : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoOidcClient']['home_url'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                </td>
              </tr>
              <tr class="line1">
                <td>
                  <?php print ($e ? $this->Form->label('error_url', _txt('pl.oa4mp_client_co_oidc_client.error_url.fd.name')) : _txt('pl.oa4mp_client_co_oidc_client.error_url.fd.name')); ?><span class="required">*</span>
                </td>
                <td>
                  <?php print ($e ? $this->Form->input('error_url') : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoOidcClient']['error_url'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                </td>
              </tr>
            </tbody>
          </table>

           <?php if($e) {
             print $this->Form->hidden('id');
             print $this->Form->hidden('admin_id');
             print $this->Form->hidden('oa4mp_identifier');
           } ?>
        </div>

      </li>
      <!-- End Basic field group -->

      <!-- Begin Callbacks field group -->
      <li id="fields-callback" class="fieldGroup">
        <a class="fieldGroupName" href="#tabs-callback">
          <span class="ui-icon ui-icon-circlesmall-minus"></span>
          Callbacks
        </a>

        <div class="fields">

            <table id="oa4mp_client_co_callback" class="ui-widget">
              <tbody>
            <?php
              for($i = 0; $i < 10; $i++) {
                if($i == 0 && $this->action == 'add') {
                  $display = "";
                  $args = array();
                } elseif (!empty($this->request->data['Oa4mpClientCoCallback'][$i])) {
                  $display = "";
                  $args = array();
                } else {
                  $display = "display:none";
                  $args = array("required" => "0");
                }
                print '<tr class="line' . ($i % 2) . '" style="' . $display . '">'; 
                print '<td>';
                print ($e ? $this->Form->label('Oa4mpClientCoCallback.' . $i . '.url', _txt('pl.oa4mp_client_co_callback.url.fd.name')) : _txt('pl.oa4mp_client_co_callback.url.fd.name')); 
                print '<span class="required">*</span>';
                print '</td>';
                print '<td>';
                print ($e ? $this->Form->input('Oa4mpClientCoCallback.' . $i . '.url', $args) : filter_var($oa4mp_client_co_callbacks[$i+1]['Oa4mpClientCoCallback']['url'],FILTER_SANITIZE_SPECIAL_CHARS));
                print '</td>';
                print '</tr>';
                if($e) {
                  if(!empty($this->request->data['Oa4mpClientCoCallback'][$i])) {
                    print $this->Form->hidden('Oa4mpClientCoCallback.' . $i . '.id');
                    print $this->Form->hidden('Oa4mpClientCoCallback.' . $i . '.client_id');
                  }
                }
              }
            ?>
                <tr>
                  <td>
                  </td>
                  <td>
            <?php
              if($e) {
                print '<div class="coAddEditButtons">';
                $params = array();
                $params['class'] = 'addbutton';
                $params['onclick'] = 'addCallbackInput()';
                print $this->Html->tag('a', _txt('op.add'), $params);
                print '</div>';
              }
              ?>

                  </td>
                </tr>
              </tbody>
            </table>
        </div>

      </li>
      <!-- End Callbacks field group -->

      <!-- Begin Scopes field group -->
      <li id="fields-scope" class="fieldGroup">
        <a class="fieldGroupName" href="#tabs-scope">
          <span class="ui-icon ui-icon-circlesmall-minus"></span>
          Scopes
        </a>

        <div class="fields">

            <table id="oa4mp_client_co_scope" class="ui-widget">
              <tbody>
            <?php
              global $cm_lang, $cm_texts;
              for($i = 0; $i < 4; $i++) {
                if($i == 0 && $this->action == 'add') {
                  $display = "";
                  $args = array();
                } elseif (!empty($this->request->data['Oa4mpClientCoScope'][$i])) {
                  $display = "";
                  $args = array();
                } else {
                  $display = "display:none";
                  $args = array("required" => "0");
                }
                print '<tr class="line' . ($i % 2) . '" style="' . $display . '">'; 
                print '<td>';
                if($e) {
                  print $this->Form->label('Oa4mpClientCoScope.' . $i . '.scope', _txt('pl.oa4mp_client_co_scope.scope.fd.name'));
                } else {
                  print _txt('pl.oa4mp_client_co_scope.scope.fd.name'); 
                }
                print '<span class="required">*</span>';
                print '</td>';
                print '<td>';
                if ($e) {
                  print $this->Form->select('Oa4mpClientCoScope.' . $i . '.scope', $cm_texts[ $cm_lang ]['pl.oa4mp_client.en.scope'], $args); 
                } else {
                  print filter_var($oa4mp_client_co_scopes[0]['Oa4mpClientCoScope']['scope'],FILTER_SANITIZE_SPECIAL_CHARS); 
                }
                print '</td>';
                print '</tr>';
                if($e) {
                  if(!empty($this->request->data['Oa4mpClientCoScope'][$i])) {
                    print $this->Form->hidden('Oa4mpClientCoScope.' . $i . '.id');
                    print $this->Form->hidden('Oa4mpClientCoScope.' . $i . '.client_id');
                  }
                }
              }
            ?>
                <tr>
                  <td>
                  </td>
                  <td>
            <?php
              if($e) {
                print '<div class="coAddEditButtons">';
                $params = array();
                $params['class'] = 'addbutton';
                $params['onclick'] = 'addScopeInput()';
                print $this->Html->tag('a', _txt('op.add'), $params);
                print '</div>';
              }
              ?>

                  </td>
                </tr>
              </tbody>
            </table>
        </div>

      </li>
      <!-- End Scopes field group -->


      <!-- Begin LDAP Claim Mappings field group -->
      <li id="fields-searchattributes" class="fieldGroup">
        <a class="fieldGroupName" href="#tabs-searchattributes">
          <?php if(empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'])): ?>
          <span class="ui-icon ui-icon-circlesmall-plus"></span>
          LDAP Claim Mappings
        </a>

        <div class="fields" style="display: none;">
          <?php else: ?>
          <span class="ui-icon ui-icon-circlesmall-minus"></span>
          LDAP Claim Mappings
        </a>

        <div class="fields">
          <?php endif; ?>


            <table id="oa4mp_client_co_search_attribute" class="ui-widget">
              <tbody>
                <?php
                  if(empty($this->request->data['Oa4mpClientCoLdapConfig'])) {
                    $args = array("required" => "0");
                  }
                ?>
                <tr class="line1">
                  <td>
                    <?php print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.serverurl', _txt('pl.oa4mp_client_co_ldap_config.serverurl.fd.name')) : _txt('pl.oa4mp_client_co_ldap_config.serverurl.fd.name')); ?><span class="required">*</span>
                  </td>
                  <td>
                    <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.serverurl', $args) : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoLdapConfig']['serverurl'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                  </td>
                </tr>
                <tr class="line2">
                  <td>
                    <?php print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.binddn', _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name')) : _txt('pl.oa4mp_client_co_ldap_config.binddn.fd.name')); ?><span class="required">*</span>
                  </td>
                  <td>
                    <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.binddn', $args) : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoLdapConfig']['binddn'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                  </td>
                </tr>
                <tr class="line1">
                  <td>
                    <?php print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.password', _txt('pl.oa4mp_client_co_ldap_config.password.fd.name')) : _txt('pl.oa4mp_client_co_ldap_config.password.fd.name')); ?><span class="required">*</span>
                  </td>
                  <td>
                    <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.password', $args) : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoLdapConfig']['password'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                  </td>
                </tr>
                <tr class="line2">
                  <td>
                    <?php print ($e ? $this->Form->label('Oa4mpClientCoLdapConfig.0.basedn', _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name')) : _txt('pl.oa4mp_client_co_ldap_config.basedn.fd.name')); ?><span class="required">*</span>
                  </td>
                  <td>
                    <?php print ($e ? $this->Form->input('Oa4mpClientCoLdapConfig.0.basedn', $args) : filter_var($oa4mp_client_co_oidc_clients[0]['Oa4mpClientCoLdapConfig']['basedn'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
                  </td>
                </tr>
            <?php
              for($i = 0; $i < 10; $i++) {
                if($i == 0 && $this->action == 'add') {
                  $display = "";
                  $args = array();
                } elseif (!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i])) {
                  $display = "";
                  $args = array();
                } else {
                  $display = "display:none";
                  $args = array("required" => "0");
                }
                print '<tr class="line' . ($i % 2) . '" style="' . $display . '">'; 
                print '<td>';
                if($e) {
                  print $this->Form->label('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.name', _txt('pl.oa4mp_client_co_search_attribute.name.fd.name'));
                } else {
                  print _txt('pl.oa4mp_client_co_search_attribute.name.fd.name'); 
                }
                print '<span class="required">*</span>';
                print '</td>';
                print '<td>';
                if ($e) {
                  print $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.name', $args); 
                } else {
                  print filter_var($oa4mp_client_co_scopes[0]['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute']['name'],FILTER_SANITIZE_SPECIAL_CHARS); 
                }
                print '</td>';
                print '</tr>';
                print '<tr class="line' . ($i % 2) . '" style="' . $display . '">'; 
                print '<td>';
                if($e) {
                  print $this->Form->label('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.return_name', _txt('pl.oa4mp_client_co_search_attribute.return_name.fd.name'));
                } else {
                  print _txt('pl.oa4mp_client_co_search_attribute.return_name.fd.name'); 
                }
                print '<span class="required">*</span>';
                print '</td>';
                print '<td>';
                if ($e) {
                  print $this->Form->input('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.return_name', $args); 
                } else {
                  print filter_var($oa4mp_client_co_scopes[0]['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute']['return_name'],FILTER_SANITIZE_SPECIAL_CHARS); 
                }
                print '</td>';
                print '</tr>';
                print '<tr class="line' . ($i % 2) . '" style="' . $display . '">'; 
                print '<td>';
                if($e) {
                  print $this->Form->label('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.return_as_list', _txt('pl.oa4mp_client_co_search_attribute.return_as_list.fd.name'));
                } else {
                  print _txt('pl.oa4mp_client_co_search_attribute.return_as_list.fd.name'); 
                }
                print '</td>';
                print '<td>';
                if ($e) {
                  print $this->Form->checkbox('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.return_as_list', array("required" => "0")); 
                } else {
                  print filter_var($oa4mp_client_co_scopes[0]['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute']['return_as_list'],FILTER_SANITIZE_SPECIAL_CHARS); 
                }
                print '</td>';
                print '</tr>';
                if($e) {
                  if(!empty($this->request->data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'][$i])) {
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.id');
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.Oa4mpClientCoSearchAttribute.' . $i . '.ldap_id');
                  }
                }
              }
            ?>
                <?php
                  if($e) {
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.id');
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.client_id');
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.enabled');
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.authorization_type');
                    print $this->Form->hidden('Oa4mpClientCoLdapConfig.0.search_name');
                  }
                ?>
                <tr>
                  <td>
                  </td>
                  <td>
            <?php
              if($e) {
                print '<div class="coAddEditButtons">';
                $params = array();
                $params['class'] = 'addbutton';
                $params['onclick'] = 'addSearchAttributeInput()';
                print $this->Html->tag('a', _txt('op.add'), $params);
                print '</div>';
              }
              ?>

                  </td>
                </tr>
              </tbody>
            </table>
        </div>

      </li>
      <!-- End LDAP Claim Mappings field group -->

    </ul>  

    <table id="oa4mp_client_co_oidc_client_submit" class="ui-widget">
      <tbody>
        <tr>
          <td>
            <em><span class="required"><?php print _txt('fd.req'); ?></span></em><br />
          </td>
          <td>
            <?php
              if($e) {
                print $this->Form->submit($submit_label);
                print $this->Form->button(_txt('op.reset'), 
                                          array('type'=>'reset'));
              }
            ?>
          </td>
        </tr>
      </tbody>
    </table>

  </div>
</div>

<script type="text/javascript">
      $("#fields-searchattributes").trigger("click");
</script>
