<?php
/**
 * Characterization of the named-configuration exemption in
 * isClientDataSynchronized() (Model/Oa4mpClientOa4mpServer.php).
 *
 * The comparator returns true for a client that uses a named configuration
 * well before it reaches the claim comparison:
 *
 *   // If this client uses a named configuration than return true here,
 *   // else continue with more detailed comparison.
 *   if($usesNamedConfig) {
 *     return true;
 *   }
 *
 * That early return has been in the file since it was created, and it is
 * matched on the outbound side by oa4mpMarshallCfgQdl(), which returns the
 * stored named configuration and never runs the claim loop.
 *
 * WHAT THIS TEST ASSERTS IS A KNOWN DEFECT, DELIBERATELY FROZEN.
 *
 * The claims tab is completely ungated for named-configuration clients, so a
 * user can create claims on one. Those claims are stored, are never sent to
 * the OA4MP server, and are never compared -- they are inert, and nothing in
 * the interface says so. Clearing the named configuration later activates
 * every previously inert claim at once, so a relying party begins receiving
 * identity attributes that nobody reviewed at the moment they go live.
 *
 * The remedy is a product decision, because it has to cover claims already
 * stored and not only future tab gating. It is recorded as open question Q1 in
 * docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md and the
 * defect itself is written up in
 * docs/solutions/logic-errors/oa4mp-named-config-claims-inert-2026-08-22.md.
 *
 * THE EXEMPTION IS LOCKED PROVISIONALLY.
 *
 * This file exists so that an incidental edit -- a refactor that moves the
 * early return, or a cleanup that deletes it as dead-looking code -- fails
 * here rather than in production, where it would silently begin releasing
 * inert claims. It is NOT a decision that the current behavior is correct. A
 * Q1 remedy that changes how the comparator treats named-configuration clients
 * is EXPECTED to change this test. If you are fixing Q1 and these methods go
 * red, that is the intended outcome: update them to describe the new behavior,
 * and update the learning document with it. Nothing here should be worked
 * around.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U7
 * (AE8, R18). Patterned on the comparator assertions in
 * Test/Case/Model/SyncVerificationTest.php.
 */

class NamedConfigClaimSyncTest extends Oa4mpTestCase {

  /**
   * The runner reuses one instance across every method in this file. Nothing
   * here holds instance state; setUp() says so explicitly.
   */
  public function setUp() {
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * The persisted (plugin) side of the comparison, carrying the given claim
   * rows and using no named configuration.
   *
   * @param array $claims The persisted Oa4mpClientClaim rows.
   * @return array
   */
  private function pluginSide($claims) {
    $data = Oa4mpClaimRows::pluginSide(Oa4mpClaimRows::claim());
    $data['Oa4mpClientClaim'] = $claims;

    return $data;
  }

  /**
   * The same plugin side, but for a client that uses a named configuration.
   *
   * Two things change together, because the comparator reads them together:
   * the client carries named_config_id, and the scopes it is compared on come
   * from the named configuration's own scope list rather than from the
   * client's. The scopes here are the ones the server representation below
   * reports, so the comparison reaches the early return on a client that is
   * genuinely in sync in every respect except its claims.
   *
   * @param array $claims The persisted Oa4mpClientClaim rows.
   * @return array
   */
  private function namedConfigPluginSide($claims) {
    $data = $this->pluginSide($claims);

    $data['Oa4mpClientCoOidcClient']['named_config_id'] = Oa4mpClaimRows::NAMED_CONFIG_ID;

    $data['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] = array(
      array(
        'id' => Oa4mpClaimRows::NAMED_CONFIG_ID,
        'config_name' => 'exemption characterization named configuration',
        'Oa4mpClientCoScope' => array(
          array('scope' => 'openid'),
          array('scope' => 'email'),
        ),
      ),
    );

    return $data;
  }

  /**
   * The OA4MP server's representation of the same client, already unmarshalled
   * into the plugin's shape, carrying the given claim rows.
   *
   * Hand-built rather than round-tripped through oa4mpUnMarshallContent(). A
   * named configuration's stored cfg is not a QDL claim cfg -- it carries its
   * own load path, execution phases and args -- so feeding it to the
   * unmarshaller would exercise the unmarshaller's handling of a foreign cfg
   * shape, which is a different question from the one this file asks.
   *
   * @param array $claims The claim rows the server side reports.
   * @return array
   */
  private function serverSide($claims) {
    $signature = _txt('pl.oa4mp_client_co_oidc_client.signature');

    return array(
      'Oa4mpClientCoOidcClient' => array(
        'oa4mp_identifier' => Oa4mpClaimRows::CLIENT_IDENTIFIER,
        'name' => Oa4mpClaimRows::CLIENT_NAME,
        'proxy_limited' => false,
        'public_client' => false,
        // The comparator requires the server's comment to carry the plugin
        // signature prefix before it reaches the named-configuration branch.
        'comment' => $signature . ': ' . Oa4mpClaimRows::CLIENT_HOME_URL,
      ),
      'Oa4mpClientRefreshToken' => array('token_lifetime' => 3600),
      'Oa4mpClientCoCallback' => array(array('url' => Oa4mpClaimRows::CALLBACK_URL)),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid'), array('scope' => 'email')),
      'Oa4mpClientAccessToken' => array(),
      'Oa4mpClientClaim' => $claims,
    );
  }

  /**
   * Covers AE8. A named-configuration client carrying claims reports in sync
   * against a server representation that carries none.
   *
   * This is the method that binds to the early return: it is the one that goes
   * red if the exemption is removed, because without it the comparator reaches
   * "Plugin has claims but OA4MP server does not" and returns false.
   *
   * The verdict asserted here is the inert-claims defect, not a correctness
   * claim. See the file docblock, Q1 in
   * docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md, and
   * docs/solutions/logic-errors/oa4mp-named-config-claims-inert-2026-08-22.md.
   */
  public function testNamedConfigClientWithClaimsReportsInSync() {
    $cur = $this->namedConfigPluginSide(array(Oa4mpClaimRows::claim()));
    $server = $this->serverSide(array());

    $this->assertNotEmpty($cur['Oa4mpClientClaim'],
      'the plugin side must actually carry a claim, or this asserts nothing');
    $this->assertEmpty($server['Oa4mpClientClaim'],
      'the server side must carry no claim, so the only thing that can produce'
      . ' an in-sync verdict is the named-configuration early return');

    $this->assertTrue($this->server()->isClientDataSynchronized($cur, $server),
      'a named-configuration client reports in sync even though it carries a'
      . ' claim the OA4MP server has never been sent -- the comparator returns'
      . ' before the claim comparison. This is the deferred inert-claims'
      . ' defect (Q1), locked provisionally; a Q1 remedy is expected to change'
      . ' this assertion, not to be blocked by it');
  }

  /**
   * Positive control. A named-configuration client with no claims also reports
   * in sync, which is what keeps the case above readable: the in-sync verdict
   * there is not an artifact of some unrelated mismatch in the fixture, since
   * the identical fixture without the claim reaches the same verdict.
   */
  public function testNamedConfigClientWithNoClaimsReportsInSync() {
    $cur = $this->namedConfigPluginSide(array());
    $server = $this->serverSide(array());

    $this->assertTrue($this->server()->isClientDataSynchronized($cur, $server),
      'a named-configuration client with no claims on either side must report'
      . ' in sync');
  }

  /**
   * The negative control. A client with NO named configuration, whose claims
   * genuinely differ from the server's, still reports out of sync -- so the
   * in-sync verdict above comes from the named-configuration exemption and not
   * from the comparator passing everything this fixture can throw at it.
   *
   * Two differences are checked, because they exit at different places:
   *
   *  - The one-variable control: exactly the data of the first case with the
   *    named configuration removed. Claims on the plugin side, none on the
   *    server's, which is the guard the exemption skips past.
   *  - A claim present on both sides but different, which reaches the
   *    normalized claim-by-claim comparison at the end of the function.
   *
   * This method CANNOT bind to the early return. Its client uses no named
   * configuration, so the exemption is never reached and deleting it changes
   * nothing here. The binding proof is
   * testNamedConfigClientWithClaimsReportsInSync().
   */
  public function testClientWithoutNamedConfigDifferingClaimsReportsOutOfSync() {
    $cur = $this->pluginSide(array(Oa4mpClaimRows::claim()));

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $this->serverSide(array())),
      'the same claim-carrying client without a named configuration must report'
      . ' out of sync against a server representation with no claims');

    $other = Oa4mpClaimRows::claim(array('claim_name' => 'eduperson_entitlement'));

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $this->serverSide(array($other))),
      'a claim present on both sides but carrying a different claim_name must'
      . ' report out of sync');
  }
}
