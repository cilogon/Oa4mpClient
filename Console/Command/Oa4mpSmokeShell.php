<?php
/**
 * Thin-runner smoke test for the Oa4mpClient plugin test harness (U1/U2).
 *
 * Proves the CakePHP-shell "thin runner" can bootstrap the app, load a plugin
 * model, and query the real database without the CakePHP 2.x PHPUnit TestSuite
 * (which does not run on PHP 8.x). Run with:
 *
 *   ./Console/cake Oa4mpClient.Oa4mp_smoke
 */

App::uses('AppShell', 'Console/Command');
App::uses('ClassRegistry', 'Utility');
App::uses('CakePlugin', 'Core');

class Oa4mpSmokeShell extends AppShell {
  public function main() {
    if (!CakePlugin::loaded('Oa4mpClient')) {
      CakePlugin::load('Oa4mpClient');
    }
    $model = ClassRegistry::init('Oa4mpClient.Oa4mpClientCoOidcClient');
    $count = $model->find('count');
    $this->out(sprintf('Oa4mpClientCoOidcClient row count = %d', $count));
    $this->out('THIN_RUNNER_OK');
  }
}
