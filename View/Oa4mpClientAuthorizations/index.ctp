<?php
  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

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

  $this->Html->addCrumb(_txt('ct.oa4mp_client_authorizations.pl'));

  // Add top links
  $params['topLinks'] = array();

  if($permissions['add']) {
    $params['topLinks'][] = $this->Html->link(
      _txt('op.add'),
      array(
        'plugin' => 'oa4mp_client',
        'controller' => 'oa4mp_client_authorizations',
        'action' => 'add',
        'clientid' => $vv_client_id
      ),
      array('class' => 'addbutton')
    );
  }

  print $this->element("pageTitleAndButtons", $params);

  // Include the tabs
  include("tabs.inc");
?>

<div class="table-container">
  <table id="authorizations">
    <thead>
      <tr>
        <th><?php print _txt('pl.oa4mp_client_authorization.fd.authz_co_group_id'); ?></th>
        <th class="thinActionButtonsCol"><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>

    <tbody>
      <?php $i = 0; ?>
      <?php foreach ($authorizations as $a): ?>
        <tr class="line<?php print ($i % 2)+1; ?>">
          <td>
            <?php print h($a['Oa4mpClientAuthorization']['authz_co_group_id']); ?>
          </td>
          <td>
            <?php
              if($permissions['edit']) {
                print $this->Html->link(
                  _txt('op.edit'),
                  array(
                    'plugin' => 'oa4mp_client',
                    'controller' => 'oa4mp_client_authorizations',
                    'action' => 'edit',
                    $a['Oa4mpClientAuthorization']['id']
                  ),
                  array('class' => 'editbutton')
                );
              }
              if($permissions['delete']) {
                print $this->Html->link(
                  _txt('op.delete'),
                  array(
                    'plugin' => 'oa4mp_client',
                    'controller' => 'oa4mp_client_authorizations',
                    'action' => 'delete',
                    $a['Oa4mpClientAuthorization']['id']
                  ),
                  array('class' => 'deletebutton')
                );
              }
            ?>
          </td>
        </tr>
        <?php $i++; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div> 