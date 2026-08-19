<?php
/**
 * Minimal test-case base for the Oa4mpClient thin runner.
 *
 * CakePHP 2.x's PHPUnit-based TestSuite does not run on PHP 8.x, so the suite
 * uses this tiny base plus a runner shell (Console/Command/Oa4mpTestShell.php)
 * instead. A test case extends this class and defines `test*` methods; the
 * runner instantiates it, calls setUp(), each test method, then tearDown(),
 * and counts an assertion exception as a failure.
 */

App::uses('ClassRegistry', 'Utility');

class Oa4mpAssertionError extends Exception {}

class Oa4mpTestCase {

  /** Load a model by (plugin-qualified) name against the real database. */
  protected function model($name) {
    return ClassRegistry::init($name);
  }

  protected function assertTrue($cond, $msg = '') {
    if ($cond !== true && !$cond) {
      $this->fail('assertTrue failed. ' . $msg);
    }
  }

  protected function assertFalse($cond, $msg = '') {
    if ($cond) {
      $this->fail('assertFalse failed. ' . $msg);
    }
  }

  protected function assertEqual($expected, $actual, $msg = '') {
    if ($expected !== $actual) {
      $this->fail('assertEqual failed: expected ' . var_export($expected, true)
        . ' but got ' . var_export($actual, true) . '. ' . $msg);
    }
  }

  protected function assertNull($value, $msg = '') {
    if ($value !== null) {
      $this->fail('assertNull failed: got ' . var_export($value, true) . '. ' . $msg);
    }
  }

  protected function assertNotEmpty($value, $msg = '') {
    if (empty($value)) {
      $this->fail('assertNotEmpty failed. ' . $msg);
    }
  }

  protected function assertEmpty($value, $msg = '') {
    if (!empty($value)) {
      $this->fail('assertEmpty failed: got ' . var_export($value, true) . '. ' . $msg);
    }
  }

  protected function assertContains($needle, $haystack, $msg = '') {
    if (strpos((string)$haystack, (string)$needle) === false) {
      $this->fail('assertContains failed: ' . var_export($needle, true)
        . ' not in ' . var_export($haystack, true) . '. ' . $msg);
    }
  }

  protected function fail($msg) {
    throw new Oa4mpAssertionError($msg);
  }

  /** Override for per-test fixture setup. */
  public function setUp() {}

  /** Override for per-test cleanup (keep the database clean between tests). */
  public function tearDown() {}
}
