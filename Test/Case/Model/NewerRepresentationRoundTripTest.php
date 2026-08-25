<?php
/**
 * The round trip for keys only the newer client representation reports
 * (U3, R3, R4, R5, R7; covers AE2 and AE3).
 *
 * Asking the OA4MP server for its newest representation makes it report
 * settings the older one omitted entirely -- rt_grace_period above all, plus
 * an ID-token lifetime, three server-owned ceilings, and an ersatz-support
 * flag. None of them are modelled by the plugin, so they land in the extras
 * blob and are handed back on the next update. That is the whole point: before
 * this change they were never reported, so an edit silently dropped whatever
 * grace period an administrator had configured out of band.
 *
 * The fixtures are the same client read twice from a real server, with and
 * without the version parameter, so these tests lock the plugin against the
 * server's actual behavior rather than a hand-authored guess. Their DynamoDB
 * credentials are placeholders; the key sets are untouched.
 *
 * See docs/plans/2026-08-25-0459-feat-oa4mp-client-read-api-version-latest-plan.md U3.
 */

class NewerRepresentationRoundTripTest extends Oa4mpTestCase {

  /** Settings that only the newer representation reports. */
  const NEWLY_VISIBLE = array('rt_grace_period', 'id_token_lifetime', 'ea_support',
                              'max_at_lifetime', 'max_id_token_lifetime',
                              'max_rt_lifetime');

  /** The server-owned ceilings among them, which this work deliberately echoes. */
  const CEILINGS = array('max_at_lifetime', 'max_id_token_lifetime', 'max_rt_lifetime');

  /** Settings the older representation reported that the newer one does not. */
  const NO_LONGER_REPORTED = array('proxy_claims_list', 'proxy_request_scopes');

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function adminClient() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  private function newerRepresentation() {
    return Oa4mpServerStub::response('client-read-api-version-latest');
  }

  private function olderRepresentation() {
    return Oa4mpServerStub::response('client-read-no-api-version');
  }

  private function unmarshal($serverObject) {
    return $this->server()->oa4mpUnMarshallContent($serverObject, $this->adminClient());
  }

  private function extrasFrom($serverObject) {
    $unmarshalled = $this->unmarshal($serverObject);
    $extra = $unmarshalled['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $this->assertNotEmpty($extra, 'the response must yield extra keys');

    return json_decode($extra, true);
  }

  // ------------------------------------------------------------------
  // The fixtures themselves. If these drift, every assertion below is
  // measuring something other than what it claims to.

  public function testTheFixturePairDiffersOnlyInTheExpectedKeys() {
    $newer = array_keys($this->newerRepresentation());
    $older = array_keys($this->olderRepresentation());

    $added = array_values(array_diff($newer, $older));
    $removed = array_values(array_diff($older, $newer));
    sort($added);
    sort($removed);

    $expectedAdded = self::NEWLY_VISIBLE;
    sort($expectedAdded);
    $expectedRemoved = self::NO_LONGER_REPORTED;
    sort($expectedRemoved);

    $this->assertEqual($expectedAdded, $added,
      'the newer representation must add exactly the settings these tests are about');
    $this->assertEqual($expectedRemoved, $removed,
      'the newer representation must drop exactly the settings R5 is about');
  }

  public function testNoModelledKeyChangedBetweenTheTwoRepresentations() {
    $newer = $this->newerRepresentation();
    $older = $this->olderRepresentation();

    foreach (array('client_id', 'client_name', 'client_uri', 'rt_lifetime', 'comment',
                   'redirect_uris', 'scope', 'grant_types', 'response_types', 'cfg') as $key) {
      if (!array_key_exists($key, $older)) {
        continue;
      }
      $this->assertEqual($older[$key], $newer[$key],
        "$key is modelled by the plugin and is compared for drift; the newer"
        . ' representation must report it identically or clients go out of sync');
    }
  }

  // ------------------------------------------------------------------
  // Capture: R3, R4.

  public function testTheGracePeriodIsCapturedFromTheNewerRepresentation() {
    $extras = $this->extrasFrom($this->newerRepresentation());

    $this->assertTrue(array_key_exists('rt_grace_period', $extras),
      'rt_grace_period must be captured, or an edit drops the administrator-set'
      . ' grace period exactly as it does today');
  }

  public function testTheGracePeriodIsAbsentFromTheOlderRepresentation() {
    $extras = $this->extrasFrom($this->olderRepresentation());

    $this->assertFalse(array_key_exists('rt_grace_period', $extras),
      'the older representation does not report it at all -- this is the defect,'
      . ' and it is what makes the test above meaningful rather than vacuous');
  }

  public function testEveryNewlyVisibleSettingIsCaptured() {
    $extras = $this->extrasFrom($this->newerRepresentation());

    foreach (self::NEWLY_VISIBLE as $key) {
      $this->assertTrue(array_key_exists($key, $extras),
        "$key is not modelled by the plugin and must be captured");
    }
  }

  public function testTheServerOwnedCeilingsAreCapturedNotSuppressed() {
    $extras = $this->extrasFrom($this->newerRepresentation());

    foreach (self::CEILINGS as $key) {
      $this->assertTrue(array_key_exists($key, $extras),
        "$key is echoed deliberately rather than suppressed. If the live check"
        . ' shows the server honours a client-submitted ceiling, this decision'
        . ' returns to the product owner rather than flipping here');
    }
  }

  public function testModelledKeysAreStillNotCapturedUnderTheNewerRepresentation() {
    $extras = $this->extrasFrom($this->newerRepresentation());

    foreach (array('client_id', 'client_name', 'redirect_uris', 'scope', 'cfg',
                   'comment', 'client_id_issued_at', 'registration_client_uri') as $key) {
      $this->assertFalse(array_key_exists($key, $extras),
        "$key must not be captured as an extra under the newer representation");
    }
  }

  // ------------------------------------------------------------------
  // Hand-back: the other half of R3.

  public function testAnUpdateCarriesTheCapturedGracePeriodUnchanged() {
    $extras = $this->extrasFrom($this->newerRepresentation());
    $expected = $extras['rt_grace_period'];

    $data = array(
      'Oa4mpClientCoOidcClient' => array(
        'name' => 'round-trip-client',
        'home_url' => 'https://example.org/',
        'public_client' => false,
        'oa4mp_identifier' => $this->newerRepresentation()['client_id'],
        'oa4mp_server_extra' => json_encode($extras),
      ),
      'Oa4mpClientCoCallback' => array(array('url' => 'https://example.org/callback')),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid')),
    );

    $content = $this->server()->oa4mpMarshallContent($this->adminClient(), $data);

    $this->assertTrue(array_key_exists('rt_grace_period', $content),
      'the update must carry the grace period back, or capturing it achieved nothing');
    $this->assertEqual($expected, $content['rt_grace_period'],
      'the grace period must go back unchanged, not normalised or defaulted');

    foreach (self::CEILINGS as $key) {
      $this->assertTrue(array_key_exists($key, $content),
        "$key must be carried back on the update");
    }
  }

  // ------------------------------------------------------------------
  // R5: a key the newer representation no longer reports.

  public function testKeysTheNewerRepresentationDropsAreNotCaptured() {
    $extras = $this->extrasFrom($this->newerRepresentation());

    foreach (self::NO_LONGER_REPORTED as $key) {
      $this->assertFalse(array_key_exists($key, $extras),
        "$key is not reported by the newer representation and must not appear");
    }
  }

  public function testThoseSameKeysWereCapturedFromTheOlderRepresentation() {
    $extras = $this->extrasFrom($this->olderRepresentation());

    foreach (self::NO_LONGER_REPORTED as $key) {
      $this->assertTrue(array_key_exists($key, $extras),
        "$key was captured before this change. Without this control the test"
        . ' above passes merely because the key is absent from one fixture,'
        . ' proving nothing about what the plugin does');
    }
  }

  // ------------------------------------------------------------------
  // R7 / AE2: the drift verdict must not move.

  /**
   * Note what this compares, and what it therefore does not prove. Both sides
   * are unmarshalled server representations, so this shows the newer
   * representation does not perturb the compared fields -- which is the risk
   * this change introduces. It is not the full plugin-row-versus-server
   * comparison R7 ultimately rests on; that one runs against a real client in
   * the live tier.
   */
  public function testAClientStillComparesAsSynchronizedUnderTheNewerRepresentation() {
    $fromOlder = $this->unmarshal($this->olderRepresentation());
    $fromNewer = $this->unmarshal($this->newerRepresentation());

    $this->assertTrue(
      $this->server()->isClientDataSynchronized($fromOlder, $fromNewer),
      'a client unmarshalled from the older representation must still compare as'
      . ' synchronized against the newer one. If this fails, every client in the'
      . ' CO reports as modified outside the Registry and becomes uneditable');
  }

  public function testTheComparisonIsNotVacuouslyTrue() {
    $fromNewer = $this->unmarshal($this->newerRepresentation());
    $altered = $fromNewer;
    $altered['Oa4mpClientCoOidcClient']['name'] = 'a different client name';

    $this->assertFalse(
      $this->server()->isClientDataSynchronized($altered, $fromNewer),
      'the comparison must still detect a real difference, or the assertion'
      . ' above proves nothing about drift');
  }
}
