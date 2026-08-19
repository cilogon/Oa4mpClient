<?php
/**
 * Thin-runner for the Oa4mpClient test suite.
 *
 * Discovers test cases under the plugin's Test/Case tree, runs their `test*`
 * methods against the real (overlaid) Registry + database, and exits non-zero
 * if any assertion fails. Replaces CakePHP 2.x's PHPUnit TestSuite, which does
 * not run on PHP 8.x. Run with:
 *
 *   ./Console/cake Oa4mpClient.Oa4mp_test
 */

App::uses('AppShell', 'Console/Command');
App::uses('CakePlugin', 'Core');

class Oa4mpTestShell extends AppShell {

  public function main() {
    if (!CakePlugin::loaded('Oa4mpClient')) {
      CakePlugin::load('Oa4mpClient');
    }

    $testDir = App::pluginPath('Oa4mpClient') . 'Test';
    require_once $testDir . DS . 'lib' . DS . 'Oa4mpTestCase.php';

    $files = $this->_discover($testDir . DS . 'Case');
    if (empty($files)) {
      $this->out('<warning>No test cases found under Test/Case.</warning>');
      return;
    }

    $total = 0;
    $failed = 0;
    $failures = array();

    foreach ($files as $file) {
      require_once $file;
      $class = basename($file, '.php');
      if (!class_exists($class)) {
        continue;
      }
      $case = new $class();
      foreach (get_class_methods($case) as $method) {
        if (strpos($method, 'test') !== 0) {
          continue;
        }
        $total++;
        try {
          $case->setUp();
          $case->$method();
          $case->tearDown();
          $this->out("  <success>PASS</success> $class::$method");
        } catch (Exception $e) {
          $failed++;
          $failures[] = "$class::$method -> " . $e->getMessage();
          $this->out("  <error>FAIL</error> $class::$method");
          // Best-effort cleanup even on failure.
          try { $case->tearDown(); } catch (Exception $ignored) {}
        }
      }
    }

    $this->out('');
    $this->out(sprintf('%d tests run, %d failed.', $total, $failed));
    foreach ($failures as $f) {
      $this->out('  - ' . $f);
    }

    if ($failed > 0) {
      $this->_stop(1);
    }
    $this->out('ALL_TESTS_PASSED');
  }

  /** Return all *Test.php files under $dir, one level of subdirectories deep. */
  protected function _discover($dir) {
    $files = glob($dir . DS . '*Test.php') ?: array();
    foreach (glob($dir . DS . '*', GLOB_ONLYDIR) ?: array() as $sub) {
      $files = array_merge($files, glob($sub . DS . '*Test.php') ?: array());
    }
    sort($files);
    return $files;
  }
}
