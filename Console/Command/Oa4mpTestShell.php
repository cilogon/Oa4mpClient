<?php
/**
 * Thin-runner for the Oa4mpClient test suite.
 *
 * Discovers test cases under the plugin's Test/Case tree, runs their `test*`
 * methods against the real (overlaid) Registry + database, and exits non-zero
 * if any assertion fails. Replaces CakePHP 2.x's PHPUnit TestSuite, which does
 * not run on PHP 8.x. Run with:
 *
 *   ./Console/cake Oa4mpClient.Oa4mp_test          # hermetic tier
 *   ./Console/cake Oa4mpClient.Oa4mp_test live     # live-server tier only
 *
 * The hermetic tier deliberately skips Test/Case/LiveServer: those tests need
 * the dev.cilogon.org credential and create real clients, so they must never
 * run as part of the per-pull-request merge gate.
 */

App::uses('AppShell', 'Console/Command');
App::uses('CakePlugin', 'Core');

class Oa4mpTestShell extends AppShell {

  public function main() {
    if (!CakePlugin::loaded('Oa4mpClient')) {
      CakePlugin::load('Oa4mpClient');
    }

    // Merge the plugin's Lib/lang.php texts into the translation table.
    //
    // Registry does this from AppController::beforeFilter(), so it happens on
    // every web request but never in a console context -- core's own shells
    // (JobShell, UpgradeVersionShell) each call it for themselves. Without it
    // _txt() returns the key it was given, so the plugin sends OA4MP a client
    // comment reading "pl.oa4mp_client_co_oidc_client.signature: ..." instead
    // of its signature. The sync comparison calls the same _txt(), so both
    // sides agree on the broken value and the comment assertion passes while
    // proving nothing; the live-server tier created three real clients with
    // that comment on 2026-08-23 before this was found.
    _bootstrap_plugin_txt();

    $testDir = App::pluginPath('Oa4mpClient') . 'Test';
    // The test-case base first, then every other shared lib (fixture helpers,
    // stubs) so a test case can rely on them without its own requires.
    require_once $testDir . DS . 'lib' . DS . 'Oa4mpTestCase.php';
    foreach (glob($testDir . DS . 'lib' . DS . '*.php') ?: array() as $lib) {
      require_once $lib;
    }
    foreach (glob($testDir . DS . 'Stub' . DS . '*.php') ?: array() as $stub) {
      require_once $stub;
    }

    $live = !empty($this->args[0]) && $this->args[0] === 'live';

    if ($live) {
      $this->out('<info>Running the LIVE-SERVER tier (creates real clients).</info>');
      $files = $this->_discover($testDir . DS . 'Case' . DS . 'LiveServer');
    } else {
      $files = $this->_discover($testDir . DS . 'Case', array('LiveServer'));
    }

    if (empty($files)) {
      // Discovering nothing is a broken gate, not a pass: exit non-zero so
      // Test/run.sh (and CI with it) goes red instead of silently green.
      $this->out('<error>No test cases found.</error>');
      $this->_stop(1);
    }

    $total = 0;
    $failed = 0;
    $failures = array();

    foreach ($files as $file) {
      require_once $file;
      $class = basename($file, '.php');
      if (!class_exists($class)) {
        // The file loaded but defines no class named after it. Skipping in
        // silence would retire a whole test file unnoticed, so fail instead.
        $failed++;
        $failures[] = "$file -> expected class $class is not defined";
        $this->out("  <error>FAIL</error> $class (no such class in $file)");
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
        } catch (Throwable $e) {
          // Throwable, not Exception: a PHP 8 Error (missing class, type
          // error) in one test must fail that test, not abort the suite.
          $failed++;
          $failures[] = "$class::$method -> " . get_class($e) . ': ' . $e->getMessage();
          $this->out("  <error>FAIL</error> $class::$method");
          // Best-effort cleanup even on failure.
          try { $case->tearDown(); } catch (Throwable $ignored) {}
        }
      }
    }

    $this->out('');
    $this->out(sprintf('%d tests run, %d failed.', $total, $failed));
    foreach ($failures as $f) {
      $this->out('  - ' . $f);
    }

    // Same floor after the run: files may load yet contribute no test method,
    // and a run of zero tests must never be reported as success.
    if ($total === 0) {
      $this->out('<error>No tests were executed.</error>');
      $this->_stop(1);
    }

    if ($failed > 0) {
      $this->_stop(1);
    }
    $this->out('ALL_TESTS_PASSED');
  }

  /**
   * Return all *Test.php files under $dir, at any depth.
   *
   * @param array $skipDirs base names of subdirectories to leave out.
   */
  protected function _discover($dir, $skipDirs = array()) {
    $files = glob($dir . DS . '*Test.php') ?: array();
    foreach (glob($dir . DS . '*', GLOB_ONLYDIR) ?: array() as $sub) {
      if (in_array(basename($sub), $skipDirs, true)) {
        continue;
      }
      $files = array_merge($files, $this->_discover($sub, $skipDirs));
    }
    sort($files);
    return $files;
  }
}
