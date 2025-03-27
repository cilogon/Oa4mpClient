<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Index View
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

  // Add breadcrumbs
  print $this->element("coCrumb");
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  // TODO
  // The link to add a new client uses a different action
  // depending on how many admin clients the CO has available.
  //$addAction = $this->viewVars['vv_next_action'];

  if($permissions['add']) {
    $params['topLinks'][] = $this->Html->link(
      _txt('op.add.new', array(_txt('ct.oa4mp_client_co_oidc_clients.1'))),
      array(
        'plugin' => 'oa4mp_client',
        'controller' => 'oa4mp_client_co_oidc_clients',
        'action' => 'add',
        'co' => $this->params['named']['co']
      ),
      array('class' => 'addbutton')
    );
  }

  print $this->element("pageTitleAndButtons", $params);
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.10/clipboard.min.js"></script>

<script type="text/javascript">

function js_local_onload() {
    new ClipboardJS('.copybtn');
}

</script>

<table id="oa4mp_client_co_oidc_clients" class="ui-widget">
  <thead>
    <tr class="ui-widget-header">
      <th><?php print $this->Paginator->sort('name', _txt('pl.oa4mp_client_co_oidc_client.name.fd.name'), array('model' => 'Oa4mpClientCoOidcClient')); ?></th>
      <th><?php print $this->Paginator->sort('name', _txt('pl.oa4mp_client_co_oidc_client.admin_id.fd.name'), array('model' => 'Oa4mpClientCoOidcAdminClient')); ?></th>
      <th><?php print $this->Paginator->sort('oa4mp_identifier', _txt('pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name')); ?></th>
      <th class="thinActionButtonsCol"><?php print _txt('fd.actions'); ?></th>
    </tr>
  </thead>
  
  <tbody>
    <?php $i = 0; ?>
    <?php foreach ($oa4mp_client_co_oidc_clients as $c): ?>
    <tr class="line<?php print ($i % 2)+1; ?>">
      <td>
        <?php
          print $this->Html->link(
            $c['Oa4mpClientCoOidcClient']['name'],
            array(
              'plugin' => 'oa4mp_client',
              'controller' => 'oa4mp_client_co_oidc_clients',
              'action' => ($permissions['edit'] ? 'edit' : ($permissions['view'] ? 'view' : '')),
              $c['Oa4mpClientCoOidcClient']['id']
            )
          );
        ?>
      </td>
      <td>
        <?php
          print $this->Html->link(
            $c['Oa4mpClientCoAdminClient']['name'] . " - " . $c['Oa4mpClientCoAdminClient']['issuer'],
            array(
              'plugin' => 'oa4mp_client',
              'controller' => 'oa4mp_client_co_oidc_clients',
              'action' => ($permissions['edit'] ? 'edit' : ($permissions['view'] ? 'view' : '')),
              $c['Oa4mpClientCoOidcClient']['id']
            )
          );
        ?>
      </td>
      <td>
        <div style="display:inline-block;width:70%;">
        <?php
          print $this->Html->link(
            $c['Oa4mpClientCoOidcClient']['oa4mp_identifier'],
            array(
              'plugin' => 'oa4mp_client',
              'controller' => 'oa4mp_client_co_oidc_clients',
              'action' => ($permissions['edit'] ? 'edit' : ($permissions['view'] ? 'view' : '')),
              $c['Oa4mpClientCoOidcClient']['id']
            )
          );
        ?>
        </div>
        <button class="ui-button ui-corner-all ui-widget copybtn" data-clipboard-text="<?php print $c['Oa4mpClientCoOidcClient']['oa4mp_identifier']; ?>">
          <span class="ui-button-icon ui-icon ui-icon-copy"></span>
          <span class="ui-button-icon-space"></span>
          Copy ID
        </button>
      </td>
      <td>
        <?php
          if($permissions['edit']) {
            print $this->Html->link(
                _txt('op.edit'),
                array(
                  'plugin' => 'oa4mp_client',
                  'controller' => 'oa4mp_client_co_oidc_clients',
                  'action' => 'edit', $c['Oa4mpClientCoOidcClient']['id']
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
                  'controller' => 'oa4mp_client_co_oidc_clients',
                  'action' => 'delete',
                  $c['Oa4mpClientCoOidcClient']['id']
                )
              ) . '\',\''
              . _txt('op.remove') . '\',\''    // dialog confirm button
              . _txt('op.cancel') . '\',\''    // dialog cancel button
              . _txt('op.remove') . '\',[\''   // dialog title
              . filter_var(_jtxt($c['Oa4mpClientCoOidcClient']['name']),FILTER_SANITIZE_STRING)  // dialog body text replacement strings
              . '\']);">'
              . _txt('op.delete')
              . '</button>';
          }
        ?>
        <?php ; ?>
      </td>
    </tr>
    <?php $i++; ?>
    <?php endforeach; ?>
  </tbody>
  
  <tfoot>
    <tr class="ui-widget-header">
      <th colspan="4">
        <?php print $this->element("pagination"); ?>
      </th>
    </tr>
  </tfoot>
</table>
