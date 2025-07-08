<?php
/**
 * COmanage Registry Oa4mp Client Plugin Refresh Token View
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
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  if($permissions['add']) {
    $params['topLinks'][] = $this->Html->link(
      _txt('op.add.new', array(_txt('ct.oa4mp_client_refresh_tokens.1'))),
      array(
        'plugin' => 'oa4mp_client',
        'controller' => 'oa4mp_client_refresh_tokens',
        'action' => 'add',
        'clientid' => $this->params['named']['clientid']
      ),
      array('class' => 'addbutton')
    );
  }

  print $this->element("pageTitleAndButtons", $params);

  if(file_exists(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc")) {
    include(APP . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc");
  } elseif(file_exists(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc")) {
    include(LOCAL . "Plugin/" . $this->plugin . "/View/Oa4mpClientRefreshTokens/tabs.inc");
  }
?>

<script type="text/javascript">
</script>

<table id="oa4mp_client_refresh_tokens" class="ui-widget">
  <thead>
    <tr class="ui-widget-header">
      <th><?php print _txt('pl.oa4mp_client_refresh_token.fd.token_lifetime'); ?></th>
      <th class="thinActionButtonsCol"><?php print _txt('fd.actions'); ?></th>
    </tr>
  </thead>
  
  <tbody>
    <?php $i = 0; ?>
    <?php foreach ($refresh_tokens as $t): ?>
    <tr class="line<?php print ($i % 2)+1; ?>">
      <td>
        <?php
          print $this->Html->link(
            $t['Oa4mpClientRefreshToken']['token_lifetime'],
            array(
              'plugin' => 'oa4mp_client',
              'controller' => 'oa4mp_client_refresh_tokens',
              'action' => ($permissions['edit'] ? 'edit' : ($permissions['view'] ? 'view' : '')),
              $t['Oa4mpClientRefreshToken']['id']
            )
          );
        ?>
      </td>
      <td>
        <?php
          if($permissions['edit']) {
            print $this->Html->link(
                _txt('op.edit'),
                array(
                  'plugin' => 'oa4mp_client',
                  'controller' => 'oa4mp_client_refresh_tokens',
                  'action' => 'edit',
                  $t['Oa4mpClientRefreshToken']['id'],
                  'clientid' => $this->params['named']['clientid']
                ),
                array('class' => 'editbutton')) . "\n";
          }
          if($permissions['delete']) {
            print '<button type="button" class="deletebutton" title="' . _txt('op.delete')
              . '" onclick="javascript:js_confirm_generic(\''
              . _txt('js.remove') . '\',\''    // dialog body text
              . $this->Html->url(              // dialog confirm URL
                array(
                  'plugin' => 'oa4mp_client',
                  'controller' => 'oa4mp_client_refresh_tokens',
                  'action' => 'delete',
                  $t['Oa4mpClientRefreshToken']['id'],
                  'clientid' => $this->params['named']['clientid']
                )
              ) . '\',\''
              . _txt('op.remove') . '\',\''    // dialog confirm button
              . _txt('op.cancel') . '\',\''    // dialog cancel button
              . _txt('op.remove') . '\',[\''   // dialog title
              . filter_var(_jtxt($t['Oa4mpClientRefreshToken']['token_lifetime']),FILTER_SANITIZE_STRING)  // dialog body text replacement strings
              . '\']);">'
              . _txt('op.delete')
              . '</button>';
          }
        ?>
      </td>
    </tr>
    <?php $i++; ?>
    <?php endforeach; ?>
  </tbody>
</table> 