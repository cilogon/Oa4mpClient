<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO OIDC Client Secret View
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
  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);
  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_oidc_clients.1')));
  $this->Html->addCrumb($crumbTxt);

  print $this->Html->css("Oa4mpClient.oa4mpclient");

  $l = 1;

  // Add page title
  $params = array();
  $params['title'] = _txt('pl.oa4mp_client_co_oidc_client.secret.title');

  // Add top links
  $params['topLinks'] = array();

  print $this->element("pageTitleAndButtons", $params);

?>
<script type="text/javascript">
  <!-- JS specific to these fields -->

function js_local_onload() {
    $("#client-secret-dialog").dialog({
      autoOpen: true,
      buttons: {
        "<?php print _txt('pl.oa4mp_client_co_oidc_client.secret.understand'); ?>": function() {
          $(this).dialog("close");
        }
      },
      modal: true,
      show: {
        effect: "fade"
      },
      hide: {
        effect: "fade"
      }
    });

}

</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_client" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name'); ?>
      </div>
    </div>
    <div class="field-info">
        <?php print $vv_client_id; ?>
    </div>
  </li>

  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.secret.fd.name'); ?>
      </div>
    </div>
    <div class="field-info">
        <?php print $vv_client_secret; ?>
    </div>
  </li>

  <?php 

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['action'] = 'index';
  if(isset($cur_co)) {
    $args['co'] = $cur_co['Co']['id'];
  }
  
  print $this->Html->link(_txt('op.cont'), $args, array('class' => 'forwardbutton'));
  ?>

</ul>

<div id="client-secret-dialog" title="<?php print _txt('pl.oa4mp_client_co_oidc_client.secret.title'); ?>" style="display:none">
  <p><?php print _txt('pl.oa4mp_client_co_oidc_client.secret.text'); ?></p>
</div>
