<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Admin Client Fields
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
  $args['controller'] = 'oa4mp_client_co_admin_clients';
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_admin_clients.pl'), $args);
  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_admin_clients.1')));
  $this->Html->addCrumb($crumbTxt);
    
?>

<table id="<?php print $this->action; ?>_co" class="ui-widget">
  <tbody>
    <tr class="line1">
      <td>
        <?php
        if($e) {
          if (!empty($this->viewVars['co_options'] )) {
            print $this->Form->label('co_id', _txt('co'));
          } else {
            print _txt('co');
          }
        }
        ?>
      </td>
      <td>
        <?php
          if($e) {
            if (!empty($this->viewVars['co_options'] )) {
              // Print dropdown selector with CO options
              print $this->Form->select('co_id', $this->viewVars['co_options']);
            } else {
              // Do not print empty selector
              print _txt('pl.oa4mp_client_admin_co_client.co_id.fd.all_taken');
            }
          }
          else {
            print filter_var($Oa4mpClientCoAdminClient[0]['Co']['name'],FILTER_SANITIZE_SPECIAL_CHARS);
          }

          if($this->Form->isFieldError('status')) {
            print $this->Form->error('status');
          }
        ?>
      </td>
    </tr>
    <tr class="line2">
      <td>
        <?php print ($e ? $this->Form->label('serverurl', _txt('pl.oa4mp_client_co_admin_client.serverurl.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.serverurl.fd.name')); ?><span class="required">*</span>
      </td>
      <td>
        <?php print ($e ? $this->Form->input('serverurl') : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['serverurl'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
      </td>
    </tr>
    <tr class="line1">
      <td>
        <?php print ($e ? $this->Form->label('admin_identifier', _txt('pl.oa4mp_client_co_admin_client.admin_identifier.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.admin_identifier.fd.name')); ?><span class="required">*</span>
      </td>
      <td>
        <?php print ($e ? $this->Form->input('admin_identifier') : filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['admin_identifier'],FILTER_SANITIZE_SPECIAL_CHARS)); ?>
      </td>
    </tr>
    <tr class="line2">
      <td>
        <?php print ($e ? $this->Form->label('secret', _txt('pl.oa4mp_client_co_admin_client.secret.fd.name')) : _txt('pl.oa4mp_client_co_admin_client.secret.fd.name')); ?><span class="required">*</span>
      </td>
      <td>
        <?php 
          if($e) { 
            $args = array();
            $args['type'] = 'password';
            $args['maxlength'] = '96';
            $args['style'] = 'width: 80%';
            echo $this->Form->input('secret', $args);
          } else {
            echo filter_var($oa4mp_client_co_admin_clients[0]['Oa4mpClientCoAdminClient']['secret'],FILTER_SANITIZE_SPECIAL_CHARS); 
          }
        ?>

      </td>
    </tr>
    <tr>
      <td>
        <em><span class="required"><?php print _txt('fd.req'); ?></span></em><br />
      </td>
      <td>
        <?php
          if($e) {
            if(!empty($this->viewVars['co_options'])) {
              print $this->Form->submit($submit_label);
            }
            print $this->Form->button(_txt('op.reset'), 
                                      array('type'=>'reset'));
          }
        ?>
      </td>
    </tr>
  </tbody>
</table>
