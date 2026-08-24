<?php
/**
 * What a pre-flight guard tells the user when its synchronization check
 * cannot run.
 *
 * Thirteen call sites across nine controllers verify that the plugin and the
 * OA4MP server agree before rendering a page. Every one of them flashed
 * pl.oa4mp_client_co_oidc_client.er.bad_client -- "This client has been
 * modified outside of the Registry" -- on a false verdict, and the verdict is
 * false for two unrelated reasons: a real mismatch, or any failure inside the
 * check itself. The cfg capability contract made the second reason routinely
 * reachable, so an unreadable cfg_contract.json bounced a user off every
 * claims tab, callback list and scope page for every client while telling them
 * their client had been tampered with.
 *
 * These tests drive the two claims guards that the U8 harness
 * (Test/lib/Oa4mpClaimsControllerHarness.php) can reach -- add()'s GET tail,
 * an array-form guard, and index(), the one site that used the bare
 * two-argument form -- and assert both halves at each: a failed check says so
 * and still redirects, and a genuine mismatch is untouched.
 *
 * The other eleven guards are not harness-drivable (their controllers have no
 * harness), so testNoGuardFlashesTheTamperingMessageWithoutFirstTestingTheErrorKey
 * locks the whole set by source scan instead. It is deliberately structural:
 * what it prevents is a guard added later reaching for er.bad_client without
 * testing the error key first, which is exactly how the conflation arose.
 *
 * See docs/plans/2026-08-24-0537-fix-preflight-internal-error-verdict-plan.md
 * U2 and U3.
 */

class PreflightVerdictTest extends Oa4mpClaimsControllerTestCase {

  protected function fixtureTagPrefix() {
    return 'oa4mppreflight';
  }

  // ==========================================================================
  // add() -- a representative array-form guard.
  // ==========================================================================

  /**
   * The check could not run. The user is told that, not that their client was
   * modified, and the guard still redirects.
   *
   * Covers AE1: the flash names the failed check, and the redirect target is
   * the OIDC client list, exactly as it was before this branch existed.
   */
  public function testAFailedCheckOnAGuardedGetDoesNotAccuseTheClient() {
    $harness = $this->harness();
    $harness->harnessServer->verifyError = true;

    // Deliberately true: a failed comparison leaves the verdict at its initial
    // false in production, but pinning it true here proves the branch is
    // selected by the error key alone and not by the verdict riding along.
    $harness->harnessServer->verifySynchronized = true;

    $redirect = $harness->harnessInvoke('add');

    $this->assertTrue($harness->harnessStopped, 'the guard stopped the action');
    $this->assertTrue($this->flashed($harness,
      _txt('pl.oa4mp_client_co_oidc_client.er.verify_failed')),
      'the user is told the check could not be completed');
    $this->assertFalse($this->flashed($harness,
      _txt('pl.oa4mp_client_co_oidc_client.er.bad_client')),
      'and is NOT told their client was modified outside the Registry');

    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'],
      'the redirect target is unchanged: the OIDC client list');
    $this->assertEqual('index', $redirect['action'], 'its index action');
  }

  /**
   * A genuine mismatch still reports the client modified outside the Registry.
   *
   * Covers AE2. Without this the change would read as a fix and be a
   * suppression: er.bad_client is correct for the case it names.
   */
  public function testAGenuineMismatchStillReportsTheClientModified() {
    $harness = $this->harness();
    $harness->harnessServer->verifyError = false;
    $harness->harnessServer->verifySynchronized = false;

    $redirect = $harness->harnessInvoke('add');

    $this->assertTrue($harness->harnessStopped, 'the guard stopped the action');
    $this->assertTrue($this->flashed($harness,
      _txt('pl.oa4mp_client_co_oidc_client.er.bad_client')),
      'a comparison that ran and found a difference still says so');
    $this->assertFalse($this->flashed($harness,
      _txt('pl.oa4mp_client_co_oidc_client.er.verify_failed')),
      'and does not report an internal failure that did not happen');

    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'],
      'same redirect target as the failed-check branch');
  }

  /**
   * The failed-check branch leaves a record naming the client and the action.
   *
   * A deployment fault hits every client; a client-specific fault hits one. In
   * the user-facing message those look identical on purpose, so the log line
   * is the only thing that separates them for whoever reads the report.
   */
  public function testAFailedCheckIsLoggedWithTheClientIdentifier() {
    $harness = $this->harness();
    $harness->harnessServer->verifyError = true;

    $harness->harnessInvoke('add');

    // The identifier is asserted by value. Matching only on 'did not
    // complete' would pass against a line that named no client at all, which
    // is precisely the line that would be useless to whoever reads the report.
    $identifier = $this->seededClientIdentifier();
    $this->assertTrue($identifier !== '', 'the fixture client has an identifier');

    $found = false;
    foreach ($harness->harnessLogged as $line) {
      if (strpos($line, 'did not complete') !== false
          && strpos($line, $identifier) !== false
          && strpos($line, '::add') !== false) {
        $found = true;
        break;
      }
    }

    $this->assertTrue($found,
      'the internal-error branch logs that the check did not complete, and'
      . " names the client ($identifier) and the guarded action");
  }

  // ==========================================================================
  // index() -- the site that used the bare two-argument form.
  // ==========================================================================

  /**
   * The claims index reports a failed check and does not bounce the user back
   * into itself.
   *
   * Covers AE4. This action re-verifies on every request, so redirecting it to
   * its own index would loop; its target is the OIDC client list and this
   * asserts the conversion to the array form did not disturb that.
   */
  public function testTheClaimsIndexReportsAFailedCheckWithoutLooping() {
    $harness = $this->harness();
    $harness->harnessServer->verifyError = true;

    $redirect = $harness->harnessInvoke('index');

    $this->assertTrue($harness->harnessStopped,
      'the page is not rendered as though the client were in sync');
    $this->assertTrue($this->flashed($harness,
      _txt('pl.oa4mp_client_co_oidc_client.er.verify_failed')),
      'the user is told the check could not be completed');
    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'],
      'the redirect leaves this controller, so it cannot loop');
    $this->assertEqual('index', $redirect['action'], 'to the OIDC client list');
  }

  /** A genuine mismatch on the claims index is unchanged. */
  public function testTheClaimsIndexStillReportsAGenuineMismatch() {
    $harness = $this->harness();
    $harness->harnessServer->verifyError = false;
    $harness->harnessServer->verifySynchronized = false;

    $redirect = $harness->harnessInvoke('index');

    $this->assertTrue($this->flashed($harness,
      _txt('pl.oa4mp_client_co_oidc_client.er.bad_client')),
      'the tampering message is still what a real mismatch produces');
    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'],
      'with its redirect target unchanged');
  }

  /** An in-sync client still renders: neither branch fires. */
  public function testASynchronizedClientStillRendersTheClaimsIndex() {
    $harness = $this->harness();
    $harness->harnessServer->verifyError = false;
    $harness->harnessServer->verifySynchronized = true;

    $redirect = $harness->harnessInvoke('index');

    $this->assertNull($redirect, 'a healthy check does not redirect');
    $this->assertEqual('', $harness->Flash->last(), 'and flashes nothing');
  }

  // ==========================================================================
  // The other eleven guards.
  // ==========================================================================

  /**
   * No guard reaches the tampering message without first testing the error
   * key.
   *
   * Scans every controller. For each oa4mpVerifyClient() call site, the region
   * that follows it up to the next call site must not flash er.bad_client
   * unless the error key was tested earlier in that same region. A guard
   * added later that skips the test reddens here rather than shipping the
   * accusation this change exists to remove.
   */
  public function testNoGuardFlashesTheTamperingMessageWithoutFirstTestingTheErrorKey() {
    $dir = App::pluginPath('Oa4mpClient') . 'Controller';
    $files = glob($dir . DS . '*.php');
    $this->assertTrue(count($files) > 0, "controllers found under $dir");

    $sites = 0;

    foreach ($files as $path) {
      $source = file_get_contents($path);
      $name = basename($path);

      // Match the call, not the name: '->oa4mpVerifyClient(' cannot be
      // satisfied by a comment or a docblock mentioning the method, so a guard
      // deleted and replaced by prose cannot keep the site count up.
      $offsets = array();
      $at = 0;
      while (($at = strpos($source, '->oa4mpVerifyClient(', $at)) !== false) {
        $offsets[] = $at;
        $at += 1;
      }

      foreach ($offsets as $i => $start) {
        $end = isset($offsets[$i + 1]) ? $offsets[$i + 1] : strlen($source);
        $region = substr($source, $start, $end - $start);
        $sites++;

        $bad = strpos($region, 'er.bad_client');
        if ($bad === false) {
          continue;
        }

        $tested = strpos($region, "\$verifyResult['error']");

        $this->assertTrue($tested !== false && $tested < $bad,
          "$name: the guard at offset $start flashes er.bad_client without"
          . ' first testing the error key, so a failed check would be'
          . ' reported as client tampering');

        // Ordering alone is a weak lock: a guard could read the error key and
        // still flash the tampering message on that branch. Require the
        // failed-check message to be present in the same region and to be
        // selected no later than the tampering message, so a guard that reads
        // the key and ignores it cannot pass.
        $failedMessage = strpos($region, 'er.verify_failed');

        $this->assertTrue($failedMessage !== false && $failedMessage < $bad,
          "$name: the guard at offset $start reads the error key but never"
          . ' reaches er.verify_failed before er.bad_client, so a failed check'
          . ' would still be reported as client tampering');

        // And the key has to be doing the selecting. A dead read followed by
        // an unconditional tampering flash satisfies both checks above.
        $this->assertTrue(strpos($region, '$verifyFailed') !== false,
          "$name: the guard at offset $start does not branch on the"
          . ' failed-check flag, so the error key is read but not acted on');
      }
    }

    // The scan is only worth anything if it actually found the guards.
    $this->assertTrue($sites >= 13,
      "the scan reached every verify call site (found $sites, expected at"
      . ' least 13)');
  }

  // ==========================================================================
  // Helpers.
  // ==========================================================================

  /**
   * The seeded client's oa4mp_identifier, read from the database.
   *
   * Read rather than reconstructed: the log assertion is only worth anything
   * if it matches the value the controller actually handled.
   */
  private function seededClientIdentifier() {
    $db = ConnectionManager::getDataSource('default');
    $db->flushQueryCache();
    $rows = $db->fetchAll('SELECT oa4mp_identifier FROM cm_oa4mp_client_co_oidc_clients WHERE id = '
      . (int)$this->clientId);

    if (empty($rows)) {
      return '';
    }

    $row = $rows[0];
    $first = reset($row);

    return isset($first['oa4mp_identifier']) ? (string)$first['oa4mp_identifier'] : '';
  }

  /** Whether $message is among the flashes this harness recorded. */
  private function flashed($harness, $message) {
    foreach ($harness->Flash->messages as $flash) {
      if ((string)$flash['message'] === (string)$message) {
        return true;
      }
    }

    return false;
  }
}
