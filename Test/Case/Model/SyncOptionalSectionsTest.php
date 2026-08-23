<?php
/**
 * Regression tests for the optional sections of isClientDataSynchronized()
 * in Model/Oa4mpClientOa4mpServer.php.
 *
 * The comparator read $curData['Oa4mpClientRefreshToken'] and
 * $curData['Oa4mpClientAccessToken'] unconditionally while using ?? for its
 * other sections. A caller that omits them -- the live-server tier's payload
 * does, and so may a controller -- reached the comparison through PHP 8
 * "Undefined array key" and "array offset on null" warnings. The first real
 * live-server run (2026-08-23) emitted 40 such lines while still returning the
 * right verdict, which is exactly the shape of a defect that stops being right
 * after the next edit.
 *
 * These tests pin both halves: no warnings, and the same verdicts as before.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U4 (R3).
 */

class SyncOptionalSectionsTest extends Oa4mpTestCase {

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * The client payload the live-server tier builds: no Oa4mpClientRefreshToken
   * and no Oa4mpClientAccessToken section at all.
   */
  private function minimalData() {
    return array(
      'Oa4mpClientCoOidcClient' => array(
        'oa4mp_identifier' => 'cilogon:oa4mp,2012:/client_id/abc',
        'name' => 'oa4mp-live-test-confidential',
        'proxy_limited' => false,
        'public_client' => false,
        'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': https://example.org/',
      ),
      'Oa4mpClientCoCallback' => array(array('url' => 'https://example.org/callback')),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid')),
      'Oa4mpClientClaim' => array(),
    );
  }

  /**
   * Run $callable with warnings collected rather than reported, and return
   * them. Cake installs its own error handler, so this swaps in a recorder for
   * the duration of the call and restores it unconditionally.
   *
   * @return array list($result, $warnings)
   */
  private function captureWarnings($callable) {
    $warnings = array();

    set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$warnings) {
      $warnings[] = "$errstr in $errfile line $errline";
      return true;
    }, E_ALL);

    try {
      $result = $callable();
    } catch (Exception $e) {
      restore_error_handler();
      throw $e;
    }

    restore_error_handler();

    return array($result, $warnings);
  }

  /**
   * The capture helper is only meaningful if it actually sees a warning, so
   * prove it does. Without this, every assertion below could be passing
   * because nothing is ever recorded.
   */
  public function testWarningCaptureItselfWorks() {
    list($guarded, $noWarnings) = $this->captureWarnings(function() {
      $empty = array();
      return $empty['no_such_key'] ?? 'fallback';
    });

    $this->assertEqual('fallback', $guarded, 'the callable result must come back');
    $this->assertEqual(array(), $noWarnings, 'a guarded read must record nothing');

    list($unguarded, $warnings) = $this->captureWarnings(function() {
      $empty = array();
      return $empty['no_such_key'];
    });

    $this->assertNotEmpty($warnings,
      'the capture helper must record an undefined-key warning');
  }

  /**
   * Identical minimal payloads on both sides: in sync, and silent.
   */
  public function testMinimalPayloadComparesWithoutWarnings() {
    $data = $this->minimalData();
    $server = $this->server();

    list($synchronized, $warnings) = $this->captureWarnings(
      function() use ($server, $data) {
        return $server->isClientDataSynchronized($data, $data);
      });

    $this->assertTrue($synchronized,
      'identical payloads without the optional sections must report in-sync');
    $this->assertEqual(array(), $warnings,
      'comparing a payload without the optional sections must raise no warnings');
  }

  /**
   * The null-vs-zero rule: a plugin side with no refresh-token section and a
   * server side reporting exactly zero is in sync, not drift. This is the
   * behaviour the pre-existing special case encodes, and the reason the fix
   * resolves to null rather than to an empty array.
   */
  public function testMissingRefreshTokenMatchesAServerLifetimeOfZero() {
    $cur = $this->minimalData();
    $server = $this->minimalData();
    $server['Oa4mpClientRefreshToken'] = array('token_lifetime' => 0);

    list($synchronized, $warnings) = $this->captureWarnings(
      function() use ($cur, $server) {
        return $this->server()->isClientDataSynchronized($cur, $server);
      });

    $this->assertTrue($synchronized,
      'an absent plugin refresh token and a server lifetime of 0 are in sync');
    $this->assertEqual(array(), $warnings, 'and it must stay silent');
  }

  /**
   * A real difference must still be caught: the guard makes the comparison
   * quiet, not blind.
   */
  public function testMissingRefreshTokenStillDetectsANonZeroServerLifetime() {
    $cur = $this->minimalData();
    $server = $this->minimalData();
    $server['Oa4mpClientRefreshToken'] = array('token_lifetime' => 3600);

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $server),
      'an absent plugin refresh token and a server lifetime of 3600 are out of sync');
  }

  /**
   * Same for the access-token section: absent on the plugin side and present
   * on the server side is drift, and must be reported as such.
   */
  public function testMissingAccessTokenSectionStillDetectsAServerConfiguration() {
    $cur = $this->minimalData();
    $server = $this->minimalData();
    $server['Oa4mpClientAccessToken'] = array('is_jwt' => true);

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $server),
      'a server access token configuration the plugin does not have is drift');
  }
}
