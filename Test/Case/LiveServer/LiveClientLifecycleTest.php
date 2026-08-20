<?php
/**
 * Live-server tier: exercises a real admin client on dev.cilogon.org.
 *
 * This tier never gates a pull request. It runs on a schedule or on demand from
 * .github/workflows/live-server-tests.yml, on main only, where the dedicated
 * test admin client's credential is available. The hermetic runner skips this
 * directory; run it explicitly with Test/run-live.sh.
 *
 * What it adds over the hermetic tier: the hermetic tests assert the marshalled
 * cfg the plugin *sends*, because the stub encodes an assumption about how the
 * server replies. Only here does a real OA4MP server decide whether it accepts
 * that cfg -- notably that it rejects a custom cfg on a public client, the
 * behaviour behind the public-client bug.
 *
 * Every client this tier creates carries the OA4MP_LIVE_CLIENT_PREFIX name
 * prefix and is deleted in tearDown, including when a test fails.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U9 (R9-R13).
 */

class LiveClientLifecycleTest extends Oa4mpTestCase {

  /** Name prefix every client created here carries, for identification. */
  const CLIENT_PREFIX = 'oa4mp-live-test-';

  /** @var array oa4mp_identifier values created by the current test. */
  private $created = array();

  private $adminClient;

  public function setUp() {
    $this->created = array();
    $this->adminClient = $this->adminClientFromEnvironment();
  }

  /**
   * Delete anything this test created, even if it failed part way through.
   *
   * A client that survives teardown is a real client left behind on the shared
   * dev.cilogon.org server, so cleanup is defensive: each identifier gets its
   * own attempt (one failure must not skip the rest), the list is cleared
   * unconditionally so a later test cannot retry the same identifiers, and
   * anything that could not be deleted is named in a loud failure. Silently
   * discarding the delete result would report a leaking test as passing --
   * oa4mpDeleteClient() returns false rather than throwing for any non-204
   * response, so the return value is the only signal there is.
   */
  public function tearDown() {
    if (empty($this->created)) {
      // Nothing was created, e.g. the environment fixture was never built.
      return;
    }

    $leaked = array();

    foreach ($this->created as $identifier) {
      try {
        if (!$this->deleteClient($identifier)) {
          $leaked[] = $identifier;
        }
      } catch (Exception $e) {
        $leaked[] = $identifier . ' (' . $e->getMessage() . ')';
      }
    }

    $this->created = array();

    if (!empty($leaked)) {
      $this->fail('the live tier failed to delete ' . count($leaked)
        . ' real ' . self::CLIENT_PREFIX . ' client(s) on the server; delete '
        . 'these oa4mp_identifier values by hand: ' . implode(', ', $leaked));
    }
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * Build the admin-client array the server model expects from the environment.
   * The credential is never committed: CI supplies it from the live-server
   * GitHub Environment, local runs from a gitignored Test/.env (see
   * Test/.env.example).
   */
  private function adminClientFromEnvironment() {
    $required = array(
      'OA4MP_LIVE_SERVER_URL',
      'OA4MP_LIVE_ADMIN_IDENTIFIER',
      'OA4MP_LIVE_ADMIN_SECRET',
      'OA4MP_LIVE_CO_ID'
    );

    $values = array();
    foreach ($required as $name) {
      $value = getenv($name);
      if ($value === false || $value === '') {
        $this->fail("$name is not set; the live-server tier needs a configured "
          . 'dev.cilogon.org test admin client (see Test/.env.example)');
      }
      $values[$name] = $value;
    }

    return array(
      'Oa4mpClientCoAdminClient' => array(
        'co_id' => (int)$values['OA4MP_LIVE_CO_ID'],
        'serverurl' => $values['OA4MP_LIVE_SERVER_URL'],
        'admin_identifier' => $values['OA4MP_LIVE_ADMIN_IDENTIFIER'],
        'secret' => $values['OA4MP_LIVE_ADMIN_SECRET']
      ),
      'Oa4mpClientCoNamedConfig' => array()
    );
  }

  /** A uniquely-namespaced client payload. */
  private function clientData($publicClient) {
    $name = self::CLIENT_PREFIX . ($publicClient ? 'public-' : 'confidential-')
      . getmypid() . '-' . substr(uniqid(), -8);

    return array(
      'Oa4mpClientCoOidcClient' => array(
        'name' => $name,
        'home_url' => 'https://example.org/' . $name,
        'proxy_limited' => false,
        'public_client' => $publicClient
      ),
      'Oa4mpClientCoCallback' => array(
        array('url' => 'https://example.org/' . $name . '/callback')
      ),
      'Oa4mpClientCoScope' => array(
        array('scope' => 'openid')
      ),
      'Oa4mpClientClaim' => array()
    );
  }

  /** Create a client on the server and remember it for cleanup. */
  private function createClient($data) {
    $result = $this->server()->oa4mpNewClient($this->adminClient, $data);

    // Record the identifier before asserting anything about the result: an
    // assertion throws, and a client the server did create but tearDown never
    // learned about is a real client stranded on dev.cilogon.org.
    if (is_array($result) && !empty($result['clientId'])) {
      $this->created[] = $result['clientId'];
    }

    $this->assertNotEmpty($result, 'the server returned no result for the create');
    $this->assertNotEmpty($result['clientId'], 'the server returned no client id');

    return $result;
  }

  private function deleteClient($identifier) {
    return $this->server()->oa4mpDeleteClient($this->adminClient, array(
      'Oa4mpClientCoOidcClient' => array('oa4mp_identifier' => $identifier)
    ));
  }

  /** The plugin-side representation of a client the server already holds. */
  private function currentData($data, $identifier) {
    $data['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $identifier;
    return $data;
  }

  /**
   * A confidential client round-trips: the server accepts it, reads back
   * in-sync with what the plugin believes it sent, and deletes cleanly.
   */
  public function testConfidentialClientCreateVerifyDelete() {
    $data = $this->clientData(false);
    $result = $this->createClient($data);

    $this->assertNotEmpty($result['secret'],
      'a confidential client is issued a secret');

    $current = $this->currentData($data, $result['clientId']);
    $this->assertTrue($this->server()->oa4mpVerifyClient($this->adminClient, $current),
      'the freshly created client must read back in sync');

    $this->assertTrue($this->deleteClient($result['clientId']),
      'the client must delete cleanly');
    $this->created = array();
  }

  /**
   * The server-acceptance half of the public-client cfg bug. The hermetic tier
   * can only assert that the plugin sends no cfg for a public client; here the
   * real server accepts the request, which is what the bug broke.
   */
  public function testPublicClientIsAcceptedWithoutCustomConfiguration() {
    $data = $this->clientData(true);
    $result = $this->createClient($data);

    // assertTrue(empty(...)) rather than assertEmpty(): assertEmpty var_exports
    // the value it received into the failure message, so if the server ever did
    // issue a secret here the real secret would be written to the CI log.
    $this->assertTrue(empty($result['secret']),
      'a public client is not issued a secret');

    $current = $this->currentData($data, $result['clientId']);
    $this->assertTrue($this->server()->oa4mpVerifyClient($this->adminClient, $current),
      'the public client must read back in sync');
  }

  /**
   * An edit is accepted and the client still reads back in sync afterwards.
   */
  public function testEditIsAcceptedAndStaysInSync() {
    $data = $this->clientData(false);
    $result = $this->createClient($data);

    $current = $this->currentData($data, $result['clientId']);

    $edited = $current;
    $edited['Oa4mpClientCoOidcClient']['home_url'] =
      $current['Oa4mpClientCoOidcClient']['home_url'] . '/edited';

    $this->assertEqual(1, $this->server()->oa4mpEditClient($this->adminClient, $current, $edited),
      'the edit must be accepted (2 means the server drifted from the plugin)');

    $this->assertTrue($this->server()->oa4mpVerifyClient($this->adminClient, $edited),
      'the edited client must read back in sync');
  }
}
