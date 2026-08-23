<?php
/**
 * Regression tests for the "extra keys" round trip in
 * Model/Oa4mpClientOa4mpServer.php.
 *
 * Keys the OA4MP server returns that the plugin does not model are captured
 * into oa4mp_server_extra and merged back into the content of a later edit, so
 * configuration the plugin cannot represent is not silently dropped. That is
 * right for server state the client owns and wrong for state the server owns:
 * registration_client_uri is built by the server from its own endpoint and the
 * client_id, and was being echoed back on every edit.
 *
 * The fixture is the real response dev.cilogon.org returned on the first
 * live-server run (2026-08-23), which is where this was found.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U4 (R3).
 */

class UnmarshallExtraKeysTest extends Oa4mpTestCase {

  const CLIENT_ID = 'cilogon:oa4mp,2012:/client_id/267d3c291b982f51bcac74766962785';

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function adminClient() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  /** A real dev.cilogon.org GET response for a confidential client. */
  private function serverObject() {
    return array(
      'registration_client_uri' => 'https://dev.cilogon.org/oauth2/oidc-cm?client_id='
        . self::CLIENT_ID,
      'client_id' => self::CLIENT_ID,
      'client_name' => 'oa4mp-live-test-confidential',
      'redirect_uris' => array('https://example.org/callback'),
      'grant_types' => array('authorization_code'),
      'response_types' => array('code'),
      'rt_lifetime' => 0,
      'at_lifetime' => 0,
      'scope' => array('openid'),
      'client_uri' => 'https://example.org/',
      'strict_scopes' => true,
      'use_server_scopes' => true,
      'skip_server_scripts' => false,
      'is_service_client' => false,
      'service_client_users' => array('*'),
      'ersatz_client' => false,
      'client_id_issued_at' => 1787484004,
      'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': https://example.org/',
    );
  }

  private function extras() {
    $unmarshalled = $this->server()->oa4mpUnMarshallContent(
      $this->serverObject(), $this->adminClient());

    $extra = $unmarshalled['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;
    $this->assertNotEmpty($extra, 'the server response must yield extra keys');

    return json_decode($extra, true);
  }

  /**
   * The server owns registration_client_uri, so it must be treated as a known
   * read-only key and never captured.
   */
  public function testRegistrationClientUriIsNotCapturedAsAnExtra() {
    $extras = $this->extras();

    $this->assertFalse(array_key_exists('registration_client_uri', $extras),
      'registration_client_uri is server-owned and must not be captured');
  }

  /**
   * Positive control: the capture itself must still work, or the assertion
   * above would pass simply because nothing is captured any more.
   */
  public function testUnmodelledServerKeysAreStillCaptured() {
    $extras = $this->extras();

    foreach (array('at_lifetime', 'strict_scopes', 'use_server_scopes',
                   'service_client_users', 'ersatz_client') as $key) {
      $this->assertTrue(array_key_exists($key, $extras),
        "$key is not modelled by the plugin and must be captured");
    }
  }

  /**
   * Keys the plugin does model must not be duplicated into the extras blob.
   */
  public function testModelledKeysAreNotCapturedAsExtras() {
    $extras = $this->extras();

    foreach (array('client_id', 'client_name', 'redirect_uris', 'scope',
                   'comment', 'client_id_issued_at') as $key) {
      $this->assertFalse(array_key_exists($key, $extras),
        "$key is modelled by the plugin and must not be captured as an extra");
    }
  }

  /**
   * The end of the round trip: an edit built from a client carrying these
   * extras must not send registration_client_uri back to the server, while
   * still sending the extras that are genuinely the client's.
   */
  public function testAnEditDoesNotSendRegistrationClientUriBack() {
    $data = array(
      'Oa4mpClientCoOidcClient' => array(
        'name' => 'oa4mp-live-test-confidential',
        'home_url' => 'https://example.org/',
        'public_client' => false,
        'oa4mp_identifier' => self::CLIENT_ID,
        'oa4mp_server_extra' => json_encode($this->extras()),
      ),
      'Oa4mpClientCoCallback' => array(array('url' => 'https://example.org/callback')),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid')),
    );

    $content = $this->server()->oa4mpMarshallContent($this->adminClient(), $data);

    $this->assertFalse(array_key_exists('registration_client_uri', $content),
      'an edit must not send the server-owned registration_client_uri back');
    $this->assertTrue(array_key_exists('at_lifetime', $content),
      'an edit must still carry the extras the plugin does not model');
  }
}
