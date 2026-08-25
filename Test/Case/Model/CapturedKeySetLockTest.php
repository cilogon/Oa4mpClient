<?php
/**
 * A lock on exactly which keys the plugin captures into the extras blob
 * (U4, R9).
 *
 * Whatever the OA4MP server reports outside the known-keys list is written to
 * oa4mp_server_extra, logged, and handed back on the next update. Asking for
 * the newest representation widens what the server reports -- and
 * api_version=latest is an unpinned alias, so the server can widen it again
 * later with no change in this repository and no review. The blob is exactly
 * where an unmodelled credential would arrive.
 *
 * A name-based screening test cannot guard that. Log redaction matches on
 * name, so "every captured key is absent from the redaction list, or masked"
 * is satisfied by both branches and is a tautology -- and the gap worth
 * guarding is a credential arriving under a name nothing declares, which no
 * name list can recognise. Adding a credential-NAMED key to the fixture as a
 * red-proof is the one case the redactor definitely masks, so it would leave
 * such a test green.
 *
 * So this locks the key set instead. It does not try to decide whether an
 * arriving key is a credential; it detects the arrival and makes a human look.
 *
 * Be precise about what that buys, because the obvious overstatement is wrong:
 * this reads a committed fixture, not the live server. A server that widens its
 * representation tomorrow does not redden anything here until someone
 * re-captures the fixture. What the lock actually enforces is that the captured
 * set cannot drift silently -- re-capture a widened response, or edit either
 * fixture, and the suite goes red until a human writes the new key into the
 * list below. The live half of R9 has no hermetic proxy and belongs to the
 * pre-deploy check in docs/runbooks/oa4mp-extras-pre-deploy-snapshot.md.
 *
 * See docs/plans/2026-08-25-0459-feat-oa4mp-client-read-api-version-latest-plan.md U4.
 */

class CapturedKeySetLockTest extends Oa4mpTestCase {

  /**
   * Every key the plugin captures from the newer representation.
   *
   * Adding a name here is a deliberate act: it says someone looked at what the
   * server started reporting and confirmed it carries no credential. Do not
   * add one to make a red run green.
   */
  const EXPECTED_CAPTURED_KEYS = array(
    'at_lifetime',
    'ea_support',
    'ersatz_client',
    'ersatz_inherit_id_token',
    'extends_provisioners',
    'forward_scopes_to_proxy',
    'id_token_lifetime',
    'is_service_client',
    'max_at_lifetime',
    'max_id_token_lifetime',
    'max_rt_lifetime',
    'rt_grace_period',
    'service_client_users',
    'skip_server_scripts',
    'strict_scopes',
    'use_server_scopes',
  );

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function adminClient() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  private function capturedKeysFrom($serverObject) {
    $unmarshalled = $this->server()->oa4mpUnMarshallContent(
      $serverObject, $this->adminClient());

    $extra = $unmarshalled['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $this->assertNotEmpty($extra, 'the response must yield extra keys');

    $keys = array_keys(json_decode($extra, true));
    sort($keys);

    return $keys;
  }

  // ------------------------------------------------------------------

  /**
   * The lock itself.
   */
  public function testTheCapturedKeySetIsExactlyWhatWasReviewed() {
    $expected = self::EXPECTED_CAPTURED_KEYS;
    sort($expected);

    $actual = $this->capturedKeysFrom(
      Oa4mpServerStub::response('client-read-api-version-latest'));

    $this->assertEqual($expected, $actual,
      'the set of keys captured into the extras blob has changed. Every key here'
      . ' was reviewed for credential-bearing content; a new one has not been.'
      . ' Review what the server started reporting, confirm it carries no'
      . ' credential, then add it above -- do not widen the list to clear a red run');
  }

  /**
   * Red-proof for the lock, run as a real assertion rather than left as a
   * comment: an unreviewed key arriving from the server must be detected. This
   * is the case a name-based screening test cannot see, because the arriving
   * key's name is not on any list.
   */
  public function testAnUnreviewedKeyArrivingFromTheServerIsDetected() {
    $widened = Oa4mpServerStub::response('client-read-api-version-latest');
    $widened['some_setting_nobody_reviewed'] = 'an arbitrary value';

    $actual = $this->capturedKeysFrom($widened);
    $expected = self::EXPECTED_CAPTURED_KEYS;
    sort($expected);

    $this->assertFalse($expected === $actual,
      'a key the server newly reports must change the captured set, or the lock'
      . ' above is structurally unable to fail and R9 is unenforced');
    $this->assertTrue(in_array('some_setting_nobody_reviewed', $actual, true),
      'the newly-reported key must be the one that shows up in the captured set');
  }

  /**
   * The lock must also catch a key that stops being captured -- otherwise it is
   * one-directional and a silently-dropped setting reads as green.
   */
  public function testAKeyThatStopsBeingReportedIsAlsoDetected() {
    $narrowed = Oa4mpServerStub::response('client-read-api-version-latest');
    unset($narrowed['rt_grace_period']);

    $actual = $this->capturedKeysFrom($narrowed);
    $expected = self::EXPECTED_CAPTURED_KEYS;
    sort($expected);

    $this->assertFalse($expected === $actual,
      'a key that stops being reported must change the captured set');
    $this->assertFalse(in_array('rt_grace_period', $actual, true),
      'the dropped key must be the one missing from the captured set');
  }

  /**
   * The older representation captures a different, smaller set. Without this,
   * the lock could be measuring a fixture that was never the point.
   */
  public function testTheOlderRepresentationCapturesADifferentSet() {
    $older = $this->capturedKeysFrom(
      Oa4mpServerStub::response('client-read-no-api-version'));
    $expected = self::EXPECTED_CAPTURED_KEYS;
    sort($expected);

    $this->assertFalse($expected === $older,
      'the two representations must capture different key sets, or the version'
      . ' parameter is not doing anything');
    $this->assertFalse(in_array('rt_grace_period', $older, true),
      'the older representation does not report the grace period at all');
  }

  /**
   * The narrow claim a name-based check can honestly make about this blob.
   *
   * client_secret is deliberately NOT screened here. The model leaves it out of
   * the known-keys list on purpose, so a client-read that carries one captures
   * it into this blob and echoes it back on the next update -- see the comment
   * on $knownKeys in Model/Oa4mpClientOa4mpServer.php, which records why, and
   * the masking that covers its log line. Asserting its absence would pass only
   * because these two fixtures happen not to carry one, and would fail the day
   * a fixture did -- against behavior the plugin intends.
   *
   * registration_access_token IS screened, because that one the model does
   * exclude by name.
   */
  public function testNoCapturedKeyIsAKnownCredentialName() {
    $actual = $this->capturedKeysFrom(
      Oa4mpServerStub::response('client-read-api-version-latest'));

    foreach (array('access_key_id', 'secret_access_key', 'password',
                   'registration_access_token') as $credentialName) {
      $this->assertFalse(in_array($credentialName, $actual, true),
        "$credentialName must not be among the captured keys");
    }
  }
}
