<?php
/**
 * Regression tests for sync verification (isClientDataSynchronized) in
 * Model/Oa4mpClientOa4mpServer.php. Covers:
 *  - oa4mp-unmarshall-claim-comparator-drift-2026-05-05 (#7): the comparator
 *    reported false out-of-sync (drift) even when both sides matched.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U4 (R2, R3, R5).
 */

class SyncVerificationTest extends Oa4mpTestCase {

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function client() {
    return array(
      'oa4mp_identifier' => 'https://example.org/oidc/client/abc',
      'name' => 'test client',
      'home_url' => 'https://example.org/',
      'proxy_limited' => false,
      'public_client' => false,
      // The server representation carries a comment starting with the plugin
      // signature; the comparator validates that prefix.
      'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': https://example.org/',
    );
  }

  private function syncData() {
    return array(
      'Oa4mpClientCoOidcClient' => $this->client(),
      'Oa4mpClientRefreshToken' => array('token_lifetime' => 3600),
      'Oa4mpClientCoCallback' => array(),
      'Oa4mpClientCoScope' => array(),
      'Oa4mpClientClaim' => array(),
    );
  }

  /**
   * A client whose plugin and server representations are identical must report
   * in-sync (no false drift). Guards against the comparator-drift bug where
   * key-name mismatches produced spurious out-of-sync results.
   */
  public function testIdenticalClientDataReportsInSync() {
    $data = $this->syncData();

    $this->assertTrue($this->server()->isClientDataSynchronized($data, $data),
      'identical plugin and server data must report in-sync');
  }

  /**
   * A genuine difference (public_client flag) must report out-of-sync.
   */
  public function testDifferingPublicClientReportsOutOfSync() {
    $cur = $this->syncData();
    $server = $this->syncData();
    $server['Oa4mpClientCoOidcClient']['public_client'] = true;

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $server),
      'a differing public_client flag must report out-of-sync');
  }
}
