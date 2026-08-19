<?php
/**
 * Harness self-test: proves the thin runner can load a plugin model and query
 * the real (overlaid) database, and that the assertion helpers work. Not a
 * feature regression test -- it exists so `Test/run.sh` has something to run and
 * so the runner's pass/fail plumbing is exercised on every invocation.
 */

class HarnessSelfTest extends Oa4mpTestCase {

  public function testPluginModelLoadsAndQueriesRealDatabase() {
    $client = $this->model('Oa4mpClient.Oa4mpClientCoOidcClient');
    $this->assertNotEmpty($client, 'the plugin model should load');
    $count = $client->find('count');
    $this->assertTrue(is_int($count), 'a count query should return an int from the real DB');
  }

  public function testAssertionHelpersWork() {
    $this->assertEqual(2, 1 + 1);
    $this->assertTrue(true, 'true is true');
    $this->assertFalse(false, 'false is false');
    $this->assertNull(null);
    $this->assertContains('bar', 'foobarbaz');
  }
}
