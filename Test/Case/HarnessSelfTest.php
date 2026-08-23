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

  /**
   * The runner must merge the plugin's Lib/lang.php texts (Registry does this
   * from AppController, which no console context reaches). Without it every
   * _txt('pl.*') returns its own key, and because both sides of the sync
   * comparison call _txt() they agree on the broken value -- so the comment
   * contract silently stops being tested, in the hermetic tier and in the
   * live-server tier alike. Asserting resolution here is what makes those
   * comparisons mean something.
   */
  public function testPluginTextsAreLoadedInTheConsoleContext() {
    $key = 'pl.oa4mp_client_co_oidc_client.signature';
    $signature = _txt($key);

    $this->assertFalse($signature === $key,
      '_txt() returned its own key: the plugin texts were never bootstrapped');
    $this->assertNotEmpty($signature, 'the client signature text must be non-empty');
  }

  public function testAssertionHelpersWork() {
    $this->assertEqual(2, 1 + 1);
    $this->assertTrue(true, 'true is true');
    $this->assertFalse(false, 'false is false');
    $this->assertNull(null);
    $this->assertContains('bar', 'foobarbaz');
  }

  /**
   * The test above only ever calls assertions with inputs that pass. If a
   * helper silently stopped throwing, every test in the suite would keep
   * reporting PASS while asserting nothing, and that would go unnoticed.
   * Prove the failure path actually throws Oa4mpAssertionError.
   */
  public function testAssertionFailureActuallyThrows() {
    $threw = false;
    try {
      $this->assertEqual(1, 2);
    } catch (Oa4mpAssertionError $e) {
      $threw = true;
    }
    $this->assertTrue($threw, 'assertEqual(1, 2) must throw Oa4mpAssertionError');
  }

  /**
   * If assertEqual regressed from strict (!==) to loose (!=) comparison,
   * type-juggled values that are == but not === would silently pass.
   * '1' and 1 must still be treated as unequal.
   */
  public function testAssertionFailureStaysStrict() {
    $threw = false;
    try {
      $this->assertEqual('1', 1);
    } catch (Oa4mpAssertionError $e) {
      $threw = true;
    }
    $this->assertTrue($threw, "assertEqual('1', 1) must throw -- comparison must stay strict");
  }
}
