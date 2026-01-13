<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Tabs
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
 * @since         COmanage Registry v4.4.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */
?>

<?php 
  $controller = $this->request->params['controller'];
  $action = $this->action;

  $renderTabs = true;

  // Do not render tabs for the add action on the OIDC clients controller.
  if($controller == 'oa4mp_client_co_oidc_clients' && $action == 'add') {
    $renderTabs = false;
  }

  // Do not render tabs for the add or edit action on the named configurations controller.
  if($controller == 'oa4mp_client_co_named_configs' && ($action == 'add' || $action == 'edit')) {
    $renderTabs = false;
  }

  if($renderTabs): ?>

  <nav id="cm-group-subnav-tabs" class="cm-subnav-tabs" aria-label="<?php print _txt('me.menu.subnavigation'); ?>">
    <ul class="nav nav-tabs">

      <li class="nav-item">
        <?php
          // Properties
          if ($controller == 'oa4mp_client_co_oidc_clients' && $this->action == 'edit') {
            print '<span class="nav-link active">' . _txt('me.properties') . '</span>';
          } else {
            print $this->Html->link(
              _txt('me.properties'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_co_oidc_clients',
                'action' => 'edit',
                $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Scopes
          if ($controller == 'oa4mp_client_co_scopes') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_co_scope.scope.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_co_scope.scope.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_co_scopes',
                'action' => 'edit_scopes',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Callbacks
          if ($controller == 'oa4mp_client_co_callbacks') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_co_oidc_client.callbacks.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_co_oidc_client.callbacks.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_co_callbacks',
                'action' => 'index',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Claims
          if ($controller == 'oa4mp_client_claims') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_co_oidc_client.claims.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_co_oidc_client.claims.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_claims',
                'action' => 'index',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Refresh Tokens
          if ($controller == 'oa4mp_client_refresh_tokens') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_refresh_token.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_refresh_token.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_refresh_tokens',
                'action' => 'manage',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>


      <li class="nav-item">
        <?php
          // Access Tokens
          if ($controller == 'oa4mp_client_access_tokens') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_access_token.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_access_token.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_access_tokens',
                'action' => 'manage',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Authorizations
          if ($controller == 'oa4mp_client_authorizations') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_authorization.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_authorization.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_authorizations',
                'action' => 'manage',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Access Control
          if (
            $controller == 'oa4mp_client_access_controls'
          ) {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_access_control.tab.name') . '</span>';
          } else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_access_control.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_access_controls',
                'action' => 'manage',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Named Configurations
          if ($controller == 'oa4mp_client_co_named_configs') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_co_named_config.tab.name') . '</span>';
          }
          else {
            print $this->Html->link(
              _txt('pl.oa4mp_client_co_named_config.tab.name'),
              array(
                'plugin' => 'oa4mp_client',
                'controller' => 'oa4mp_client_co_named_configs',
                'action' => 'manage',
                'clientid' => $vv_client_id
              ),
              array('class' => 'nav-link spin')
            );
          }
        ?>
      </li>

    </ul>
  </nav>

<?php endif; ?>

