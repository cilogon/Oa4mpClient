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

<?php if($this->action == 'edit' || $this->action == 'edit_scopes'): ?>

  <nav id="cm-group-subnav-tabs" class="cm-subnav-tabs" aria-label="<?php print _txt('me.menu.subnavigation'); ?>">
    <ul class="nav nav-tabs">

      <li class="nav-item">
        <?php
          // TODO
          //$this->log("FOO permissions is " . print_r($permissions, true));
          //$this->log("FOO controller is " . print_r($this->request->params['controller'], true));
          //$this->log("FOO pass is " . print_r($this->request->params['pass'], true));
          // Properties
          if ($this->action == 'edit' || $this->action == 'view') {
            print '<span class="nav-link active">' . _txt('me.properties') . '</span>';
          } else {
            if ($permissions['edit']) {
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
            } else {
              print $this->Html->link(
                _txt('me.properties'),
                array(
                  'plugin' => 'oa4mp_client',
                  'controller' => 'oa4mp_client_co_oidc_clients',
                  'action' => 'view'
                ),
                array('class' => 'nav-link spin')
              );
            }
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Scopes
          $controller_name = $this->request->params['controller'];
          if ($controller_name == 'oa4mp_client_co_scopes') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_co_scope.scope.tab.name') . '</span>';
          } else {
            if ($permissions['edit']) {
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
            } else {
              print $this->Html->link(
                _txt('pl.oa4mp_client_co_scope.scope.tab.name'),
                array(
                  'plugin' => 'oa4mp_client',
                  'controller' => 'oa4mp_client_co_scopes',
                  'action' => 'view'
                ),
                array('class' => 'nav-link spin')
              );
            }
          }
        ?>
      </li>

      <li class="nav-item">
        <?php
          // Callbacks
          $controller_name = $this->request->params['controller'];
          if ($controller_name == 'oa4mp_client_co_callbacks') {
            print '<span class="nav-link active">' . _txt('pl.oa4mp_client_co_oidc_client.callbacks.tab.name') . '</span>';
          } else {
            if ($permissions['edit']) {
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
            } else {
              print $this->Html->link(
                _txt('pl.oa4mp_client_co_oidc_client.callbacks.tab.name'),
                array(
                  'plugin' => 'oa4mp_client',
                  'controller' => 'oa4mp_client_co_scopes',
                  'action' => 'view'
                ),
                array('class' => 'nav-link spin')
              );
            }
          }
        ?>
      </li>

    </ul>
  </nav>

<?php endif; ?>

