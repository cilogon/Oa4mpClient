<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Named Config Manage View
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
  print $this->element("coCrumb");
  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['co'] = $cur_co['Co']['id'];
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);
  $crumbTxt = _txt('op.edit-a', array(_txt('ct.oa4mp_client_co_oidc_clients.1')));
  $this->Html->addCrumb($crumbTxt);

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  if(!empty($vv_client_id)) {
    if(file_exists(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc")) {
      include(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc");
    } elseif(file_exists(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc")) {
      include(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientCoOidcClients/tabs.inc");
    }
  }

  print $this->element("pageTitleAndButtons", $params);
?>

<?php
  print $this->Form->create('Oa4mpClientCoNamedConfig', array('inputDefaults' => array('label' => false, 'div' => false)));
?>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_named_config" class="fields form-list form-list-admin">
  
  <!-- Option to use no named configuration -->
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_named_config.fd.no_config'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_named_config.fd.no_config.desc'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php 
          $options = array('none' => '');
          $attributes = array(
            'legend' => false,
            'separator' => ''
          );

          if(empty($this->request->data['Oa4mpClientCoOidcClient']['named_config_id'])) {
            $attributes['value'] = 'none';
          }
          print $this->Form->radio('selected_config_id', $options, $attributes);
        ?>
      </span>
    </div>
  </li>


  <?php if(!empty($vv_available_configs)): ?>
    <?php foreach($vv_available_configs as $i => $config): ?>
      <li>
        <div class="field-name">
          <div class="field-title">
            <?php print filter_var($config['config_name'], FILTER_SANITIZE_SPECIAL_CHARS); ?>
          </div>
          <div class="field-desc">
            <?php 
              $description = _txt('pl.oa4mp_client_co_named_config.fd.no_description');
              if(isset($config['description'])) {
                $description = filter_var($config['description'], FILTER_SANITIZE_SPECIAL_CHARS);
              }
              print $description;
            ?>
          </div>
        </div>
        <div class="field-info">
          <span class="field-info-prefix">
            <?php 
              $options = array($config['id'] => '');
              $attributes = array(
                'legend' => false,
                'separator' => ''
              );

              if(!empty($this->request->data['Oa4mpClientCoOidcClient']['named_config_id']) && 
                 $this->request->data['Oa4mpClientCoOidcClient']['named_config_id'] == $config['id']) {
                $attributes['value'] = $config['id'];
              } else {
                $attributes['value'] = false;
              }

              print $this->Form->radio('selected_config_id', $options, $attributes);
            ?>
          </span>
        </div>
      </li>
    <?php endforeach; ?>
  <?php else: ?>
    <li>
      <div class="field-name">
        <div class="field-title">
          <?php print _txt('pl.oa4mp_client_co_named_config.fd.no_configs_available'); ?>
        </div>
        <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_named_config.fd.no_configs_available.desc'); ?></div>
      </div>
      <div class="field-info">
        <span class="field-info-prefix">
          <em><?php print _txt('pl.oa4mp_client_co_named_config.fd.contact_admin'); ?></em>
        </span>
      </div>
    </li>
  <?php endif; ?>

  <li class="fields-submit">
    <div class="field-name"></div>
    <div class="field-info">
      <?php
        $submitText = ($this->action == "add") ? _txt('op.add') : _txt('op.save');
        print $this->Form->submit($submitText);
      ?>
    </div>
  </li>
</ul>

<?php print $this->Form->end(); ?>
