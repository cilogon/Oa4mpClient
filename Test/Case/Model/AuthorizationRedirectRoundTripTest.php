<?php
/**
 * Round trip for the authorization redirect URLs: the plugin's saved row is
 * marshalled into a cfg, the cfg is read back through oa4mpUnMarshallContent()
 * as the server would return it, and the two sides go through the sync
 * comparator.
 *
 * It proves, for all three redirect URLs, that a URL the plugin sends comes
 * back as the same column, and that the comparator notices when the server
 * copy has lost one.
 *
 * See docs/plans/2026-10-12-0712-feat-no-record-redirect-plan.md U3 (R10, R11).
 */

class AuthorizationRedirectRoundTripTest extends Oa4mpTestCase {

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * An authorization row as the database returns it: an id and every column,
   * null when unset. The comparator only counts a row that has an id.
   */
  private function authorizationRow($overrides = array()) {
    return array_merge(array(
      'id' => 55,
      'client_id' => 7,
      'authz_co_group_id' => 12,
      'require_active' => true,
      'authz_group_redirect_url' => 'https://example.org/not-a-member',
      'require_active_redirect_url' => 'https://example.org/not-active',
      'no_record_redirect_url' => 'https://example.org/enroll',
    ), $overrides);
  }

  /**
   * Marshal the client carrying $row, read the cfg back as the server would
   * return it, and compare it with the plugin side carrying $pluginRow.
   *
   * @param array $row The authorization row the cfg is built from.
   * @param array|null $pluginRow The row the plugin side holds; $row when null.
   * @return array list($args, $serverData, $verdict)
   */
  private function roundTrip($row, $pluginRow = null) {
    $server = $this->server();
    $claim = Oa4mpClaimRows::claim();

    $data = Oa4mpClaimRows::data($claim);
    $data['Oa4mpClientAuthorization'] = $row;

    $pluginSide = Oa4mpClaimRows::pluginSide($claim);
    $pluginSide['Oa4mpClientAuthorization'] = ($pluginRow === null) ? $row : $pluginRow;

    $cfg = $server->oa4mpMarshallCfgQdl($data);

    $serverData = $server->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    return array($cfg['tokens']['identity']['qdl']['args'], $serverData,
                 $server->isClientDataSynchronized($pluginSide, $serverData));
  }

  /**
   * All three redirect URLs leave in the cfg, come back as their columns, and
   * the client reads in sync.
   */
  public function testAllThreeRedirectUrlsRoundTripInSync() {
    list($args, $serverData, $verdict) = $this->roundTrip($this->authorizationRow());

    $this->assertEqual('https://example.org/enroll', $args['no_record_redirect_url'] ?? null,
      'the cfg carries the no-record redirect URL');

    $authz = $serverData['Oa4mpClientAuthorization'];
    $this->assertEqual('https://example.org/not-a-member', $authz['authz_group_redirect_url'] ?? null,
      'the group redirect URL reads back as its column');
    $this->assertEqual('https://example.org/not-active', $authz['require_active_redirect_url'] ?? null,
      'the Active Status redirect URL reads back as its column');
    $this->assertEqual('https://example.org/enroll', $authz['no_record_redirect_url'] ?? null,
      'the no-record redirect URL reads back as its column');

    $this->assertTrue($verdict === true, 'a faithful round trip must read in sync');
  }

  /**
   * A server copy that lost the no-record URL is out of sync with a plugin
   * row that has one.
   */
  public function testServerMissingTheNoRecordUrlIsOutOfSync() {
    $withoutUrl = $this->authorizationRow(array('no_record_redirect_url' => null));

    list(, , $verdict) = $this->roundTrip($withoutUrl, $this->authorizationRow());

    $this->assertTrue($verdict === false,
      'the plugin row carries a no-record URL the server copy lacks');
  }

  /**
   * A client with only the two original URLs sends no no-record arg and
   * still reads in sync.
   */
  public function testClientWithoutTheNoRecordUrlSendsNoArgAndStaysInSync() {
    list($args, , $verdict) = $this->roundTrip(
      $this->authorizationRow(array('no_record_redirect_url' => null)));

    $this->assertFalse(array_key_exists('no_record_redirect_url', $args),
      'no no-record arg is sent when the column is empty');
    $this->assertTrue($verdict === true, 'the client reads in sync');
  }

  /**
   * An empty string is not a URL: nothing is sent, and the row still reads
   * in sync against a server that has nothing.
   */
  public function testEmptyStringNoRecordUrlIsNotSent() {
    list($args, , $verdict) = $this->roundTrip(
      $this->authorizationRow(array('no_record_redirect_url' => '')));

    $this->assertFalse(array_key_exists('no_record_redirect_url', $args),
      'an empty no-record URL is not sent');
    $this->assertTrue($verdict === true, 'the client reads in sync');
  }

  /**
   * A plugin row that predates the column, with no no_record_redirect_url
   * key at all, is compared without a PHP warning.
   */
  public function testRowWithoutTheColumnIsComparedWithoutWarning() {
    $row = $this->authorizationRow();
    unset($row['no_record_redirect_url']);

    $warnings = array();
    set_error_handler(function($errno, $errstr) use (&$warnings) {
      $warnings[] = $errstr;
      return true;
    }, E_WARNING | E_NOTICE);

    try {
      list(, , $verdict) = $this->roundTrip($row);
    } finally {
      restore_error_handler();
    }

    $this->assertEqual(array(), $warnings,
      'comparing a row without the column must raise no PHP warning');
    $this->assertTrue($verdict === true, 'the client reads in sync');
  }

  /**
   * A public client is sent no cfg, so a no-record URL on its row never
   * reaches the server.
   */
  public function testPublicClientSendsNoCfg() {
    $data = Oa4mpClaimRows::data(Oa4mpClaimRows::claim(), array('public_client' => true));
    $data['Oa4mpClientAuthorization'] = $this->authorizationRow();

    $content = $this->server()->oa4mpMarshallContent(
      Oa4mpClaimRows::adminClientContext(), $data);

    $this->assertFalse(isset($content['cfg']), 'a public client is sent no cfg');
  }
}
