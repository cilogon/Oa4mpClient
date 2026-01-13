<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Admin Client Delegate View
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
 * @since         COmanage Registry v4.5.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

  // Add breadcrumbs
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_admin_clients.pl'));
  $this->Html->addCrumb(_txt('pl.oa4mp_client_co_admin_client.delegate.breadcrumb'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  $params['topLinks'][] = $this->Html->link(
    _txt('op.back'),
    array(
      'plugin' => 'oa4mp_client',
      'controller' => 'oa4mp_client_co_oidc_clients',
      'action' => 'index',
      'co' => $co_id
    ),
    array('class' => 'backbutton')
  );

  print $this->element("pageTitleAndButtons", $params);

?>

<?php
  print $this->Form->create('Oa4mpClientCoAdminClient', array('inputDefaults' => array('label' => false, 'div' => false)));
?>

<ul id="delegate_admin_clients" class="fields form-list form-list-admin">
  <?php foreach($admin_clients as $client): ?>
    <li>
      <div class="field-name">
        <div class="field-title">
          <?php print filter_var($client['Oa4mpClientCoAdminClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS); ?>
        </div>
        <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_admin_client.fd.manage_co_group_id.desc'); ?></div>
      </div>
      <div class="field-info">
        <span class="field-info-prefix">
          <?php
            // Add blank option to allow unsetting the group
            $groupOptions = array('' => _txt('pl.oa4mp_client_co_admin_client.fd.manage_co_group_id.select.empty')) + $available_groups;
            
            $selectedValue = '';
            if(!empty($client['Oa4mpClientCoAdminClient']['manage_co_group_id'])) {
              $selectedValue = $client['Oa4mpClientCoAdminClient']['manage_co_group_id'];
            }
            
            print $this->Form->select(
              'AdminClient.' . $client['Oa4mpClientCoAdminClient']['id'] . '.manage_co_group_id',
              $groupOptions,
              array(
                'value' => $selectedValue,
                'empty' => false
              )
            );
          ?>
        </span>
      </div>
    </li>
  <?php endforeach; ?>
  
  <li class="fields-submit">
    <div class="field-name"></div>
    <div class="field-info">
      <?php print $this->Form->submit(_txt('op.save')); ?>
    </div>
  </li>
</ul>

<?php print $this->Form->end(); ?>
