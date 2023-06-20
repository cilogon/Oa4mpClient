<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Select Admin View
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
 * @since         COmanage Registry v4.2.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;
  
  print $this->element("pageTitleAndButtons", $params);
  
  $submit_label = _txt('op.cont');

  print $this->Form->create(
    'Oa4mpClientCoOidcClient',
    array(
      'inputDefaults' => array(
        'label' => false,
        'div' => false
      )
    )
  );
?>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_client" class="fields form-list">
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

    <li class="fields-submit">
      <div class="field-name">
        <span class="required"><?php print _txt('fd.req'); ?></span>
      </div>
      <div class="field-info">
        <?php print $this->Form->submit($submit_label); ?>
      </div>
    </li>

</ul>
  
<?php
  print $this->Form->end();
?>
