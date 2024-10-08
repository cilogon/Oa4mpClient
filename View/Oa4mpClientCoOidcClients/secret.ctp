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
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.10/clipboard.min.js"></script>

<script type="text/javascript">
  <!-- JS specific to these fields -->

<?php if(!empty($vv_client_secret)): ?>
function js_local_onload() {
    new ClipboardJS('.copybtn');

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
<?php else: ?>
function js_local_onload() {
    new ClipboardJS('.copybtn');

    $("#client-public-dialog").dialog({
      autoOpen: true,
      buttons: {
        "<?php print _txt('pl.oa4mp_client_co_oidc_client.public.understand'); ?>": function() {
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
<?php endif; ?>

</script>

<ul id="secret_oa4mp_client_co_oidc_client" class="fields form-list form-list-admin">
  <li>
    <div class="field-name" id="oidc-client-id-label">
      <div class="field-title">
        <label><?php print _txt('pl.oa4mp_client_co_oidc_client.oa4mp_identifier.fd.name'); ?></label>
      </div>
    </div>
    <div class="field-info" id="oidc-client-id-value">
        <input type="text" value="<?php print $vv_client_id; ?>" readonly="readonly" id="oidc-client-id">
        <button class="co-button btn btn-primary copybtn" type="button" data-clipboard-target="#oidc-client-id" id="oidc-client-id-btn">Copy</button>
    </div>
  </li>

  <?php if(!empty($vv_client_secret)): ?>
  <li>
    <div class="field-name" id="oidc-client-secret-label">
      <div class="field-title">
        <label><?php print _txt('pl.oa4mp_client_co_oidc_client.secret.fd.name'); ?></label>
      </div>
    </div>
    <div class="field-info" id="oidc-client-secret-value">
        <input type="text" value="<?php print $vv_client_secret; ?>" readonly="readonly" id="oidc-client-secret">
        <button class="co-button btn btn-primary copybtn" type="button" data-clipboard-target="#oidc-client-secret" id="oidc-client-secret-btn">Copy</button>
    </div>
  </li>
  <?php endif; ?>

  <?php 

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['action'] = 'index';
  if(isset($cur_co)) {
    $args['co'] = $cur_co['Co']['id'];
  }
  
  print $this->Html->link(_txt('op.cont'), $args, array('class' => 'co-button btn btn-primary', 'id'=> 'oidc-client-continue'));
  ?>

</ul>

<?php if(!empty($vv_client_secret)): ?>
<div id="client-secret-dialog" title="<?php print _txt('pl.oa4mp_client_co_oidc_client.secret.title'); ?>" style="display:none">
  <p><?php print _txt('pl.oa4mp_client_co_oidc_client.secret.text'); ?></p>
</div>
<?php else: ?>
<div id="client-public-dialog" title="<?php print _txt('pl.oa4mp_client_co_oidc_client.public.title'); ?>" style="display:none">
  <p><?php print _txt('pl.oa4mp_client_co_oidc_client.public.text'); ?></p>
</div>
<?php endif; ?>

