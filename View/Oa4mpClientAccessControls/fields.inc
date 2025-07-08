<?php
  // Determine if fields are editable
  $e = false;
  
  if(($this->action == "add" && $permissions['add']) || ($this->action == "edit" && $permissions['edit']))
    $e = true;
    
  // We shouldn't get here, but if we do, $e should be false
  if(!isset($permissions['add']) && !isset($permissions['edit']))
    $e = false;

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_dashboards';
  $args['action'] = 'configuration';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('me.configuration'), $args);

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['action'] = 'index';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['action'] = 'edit';
  $args['id'] = $vv_client_id;
  $this->Html->addCrumb($vv_client_name, $args);

  if($this->action == 'edit') {
    $this->Html->addCrumb(_txt('op.edit'));
  } else {
    $this->Html->addCrumb(_txt('op.add'));
  }
?>

<div class="co-info-topbox">
  <em class="material-icons">info</em>
  <div class="co-info-topbox-text">
    <?php print _txt('ct.oa4mp_client_access_controls.1'); ?>
  </div>
</div>

<?php if(!empty($vv_client_id)): ?>
  <ul id="<?php print $this->action; ?>_access_control" class="fields form-list">
    <li>
      <div class="field-name">
        <?php print _txt('pl.oa4mp_client_access_control.fd.co_group_id'); ?>
      </div>
      <div class="field-info">
        <?php
          $args = array('empty' => true, 'disabled' => !$e);
          print $this->Form->input('co_group_id', $args);
        ?>
        <div class="field-desc"><?php print _txt('pl.oa4mp_client_access_control.fd.co_group_id.desc'); ?></div>
      </div>
    </li>
    <li>
      <div class="field-name">
        <?php print _txt('pl.oa4mp_client_access_control.fd.cou_id'); ?>
      </div>
      <div class="field-info">
        <?php
          $args = array('empty' => true, 'disabled' => !$e);
          print $this->Form->input('cou_id', $args);
        ?>
        <div class="field-desc"><?php print _txt('pl.oa4mp_client_access_control.fd.cou_id.desc'); ?></div>
      </div>
    </li>
    <li>
      <div class="field-name">
        <?php print _txt('pl.oa4mp_client_access_control.fd.co_person_id'); ?>
      </div>
      <div class="field-info">
        <?php
          $args = array('empty' => true, 'disabled' => !$e);
          print $this->Form->input('co_person_id', $args);
        ?>
        <div class="field-desc"><?php print _txt('pl.oa4mp_client_access_control.fd.co_person_id.desc'); ?></div>
      </div>
    </li>
    <?php if($e): ?>
      <li class="fields-submit">
        <div class="field-name">
          <span class="required"><?php print _txt('fd.req'); ?></span>
        </div>
        <div class="field-info">
          <?php print $this->Form->submit($submit_label); ?>
        </div>
      </li>
    <?php endif; ?>
  </ul>
  <?php if($e) { print $this->Form->hidden('client_id', array('value' => $vv_client_id)); } ?>
<?php endif; ?> 