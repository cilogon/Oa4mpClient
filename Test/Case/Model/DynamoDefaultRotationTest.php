<?php
/**
 * Regression tests for the stale per-client DynamoDB configuration snapshot.
 *
 * Oa4mpClientCoOidcClientsController::add() copies the admin client's
 * DefaultDynamoConfig into a per-client Oa4mpClientDynamoConfig row when the
 * client is created, and Oa4mpClientOa4mpServer::resolveDynamoConfig() prefers
 * that row over the default. Nothing else ever wrote it, so a credential
 * rotated on the admin client never reached an existing client's cfg: saving
 * the client re-sent the credentials captured at creation, and because the
 * OA4MP server carried those same stale values the synchronization check saw
 * nothing wrong and the save reported success.
 *
 * Oa4mpClientDynamoConfig::refreshedFromAdminDefault() is the correction, and
 * these tests are its lock. The two cases it must decline -- a client with no
 * per-client row, and an admin client with no default -- are locked alongside,
 * because either one silently changes which values reach the cfg.
 *
 * Every credential-shaped value here is a synthetic placeholder declared in
 * Test/Case/Model/ClaimCfgFixtureHygieneTest.php.
 */

class DynamoDefaultRotationTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;

  /** The admin client with no DefaultDynamoConfig at all. */
  private $bareAdminId;

  /** The client carrying the snapshot taken before the rotation. */
  private $clientId;

  /** The same admin client's other client, which has no per-client row. */
  private $fallbackClientId;

  /** The client under $bareAdminId, which does have a per-client row. */
  private $bareAdminClientId;

  private $perClientConfigId;

  // The credentials as they stood when the client was created.
  const SNAPSHOT_ACCESS_KEY_ID = 'AKIAEXAMPLE';
  const SNAPSHOT_SECRET_ACCESS_KEY = 'not-a-real-secret';

  // The credentials after the operator rotated them on the admin client.
  const ROTATED_ACCESS_KEY_ID = 'AKIAEXAMPLEROTATED';
  const ROTATED_SECRET_ACCESS_KEY = 'not-a-real-rotated-secret';

  // Pinned so the emitted cfg does not depend on
  // COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT or on the hard-coded fallback.
  const QDL_CLAIM_SOURCE = 'COmanageRegistry/test/dynamodb_claims.qdl';

  public function setUp() {
    // The runner reuses one instance across methods, so a setUp that fails
    // partway must not leave the previous method's ids visible to tearDown.
    $this->fx = null;
    $this->coId = null;
    $this->adminId = null;
    $this->bareAdminId = null;
    $this->clientId = null;
    $this->fallbackClientId = null;
    $this->bareAdminClientId = null;
    $this->perClientConfigId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mprotation');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag, array(
      'qdl_claim_source' => self::QDL_CLAIM_SOURCE
    ));
    $this->bareAdminId = $this->fx->adminClient($this->coId, 'bare ' . $tag, array(
      'qdl_claim_source' => self::QDL_CLAIM_SOURCE
    ));

    // The admin client default as it stands AFTER the rotation. admin_id set
    // and client_id null is what makes it the DefaultDynamoConfig.
    $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('admin_id' => $this->adminId, 'client_id' => null)
      + $this->config(self::ROTATED_ACCESS_KEY_ID, self::ROTATED_SECRET_ACCESS_KEY));

    // The client under test, carrying the snapshot add() took BEFORE it.
    $this->clientId = $this->fx->oidcClient($this->adminId, 'rotation ' . $tag);
    $this->perClientConfigId = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('admin_id' => null, 'client_id' => $this->clientId)
      + $this->config(self::SNAPSHOT_ACCESS_KEY_ID, self::SNAPSHOT_SECRET_ACCESS_KEY));

    $this->fx->insert('cm_oa4mp_client_claims',
      array('client_id' => $this->clientId) + $this->claimColumns());

    // A client of the same admin with no per-client row of its own.
    $this->fallbackClientId = $this->fx->oidcClient($this->adminId, 'fallback ' . $tag);

    // A client whose admin has no default to copy from.
    $this->bareAdminClientId = $this->fx->oidcClient($this->bareAdminId, 'bare ' . $tag);
    $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('admin_id' => null, 'client_id' => $this->bareAdminClientId)
      + $this->config(self::SNAPSHOT_ACCESS_KEY_ID, self::SNAPSHOT_SECRET_ACCESS_KEY));
  }

  public function tearDown() {
    if($this->fx === null) {
      return;
    }
    $this->fx->cleanup($this->purgeClauses());
    $this->fx = null;
  }

  /**
   * Configuration rows are purged by clause rather than by tracked id because
   * the save path under test writes a row the fixture helper never saw.
   */
  private function purgeClauses() {
    return array(
      'cm_oa4mp_client_dynamo_configs' => $this->configPurgeWhere()
    );
  }

  private function configPurgeWhere() {
    return 'admin_id IN (' . (int)$this->adminId . ', ' . (int)$this->bareAdminId . ')'
      . ' OR client_id IN (' . (int)$this->clientId
      . ', ' . (int)$this->fallbackClientId
      . ', ' . (int)$this->bareAdminClientId . ')';
  }

  /**
   * Everything but the credentials is identical between the default and the
   * per-client row, so an assertion on the credentials cannot pass or fail for
   * some other reason.
   */
  private function config($keyId, $secret) {
    return array(
      'aws_region' => 'us-east-2',
      'aws_access_key_id' => $keyId,
      'aws_secret_access_key' => $secret,
      'table_name' => 'registry',
      'partition_key' => 'sub',
      'partition_key_template' => '${sub}',
      'partition_key_claim_name' => 'sub'
    );
  }

  private function claimColumns() {
    return array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';'
    );
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function configModel() {
    return $this->model('Oa4mpClient.Oa4mpClientDynamoConfig');
  }

  /**
   * The canonical database read. Call it once per test method -- CakePHP 2's
   * DboSource caches every SELECT by its literal SQL text (Test/README.md).
   */
  private function currentClient($id) {
    return $this->model('Oa4mpClient.Oa4mpClientCoOidcClient')->current($id);
  }

  /** The dynamo_module_config block of a marshalled cfg. */
  private function emittedModuleConfig($curData) {
    $content = $this->server()->oa4mpMarshallContent(
      array('Oa4mpClientCoAdminClient' => $curData['Oa4mpClientCoAdminClient']), $curData);

    return $content['cfg']['tokens']['identity']['qdl']['args']['dynamo_module_config'];
  }

  /**
   * The bug itself, as it reaches the wire. edit() marshalls the client data
   * with the refreshed row in place of the stored one, so the rotated
   * credentials are what OA4MP is sent.
   */
  public function testRefreshedClientDataMarshallsTheRotatedCredentials() {
    $curData = $this->currentClient($this->clientId);

    $curData['Oa4mpClientDynamoConfig'] =
      $this->configModel()->refreshedFromAdminDefault($curData);

    $module = $this->emittedModuleConfig($curData);

    $this->assertEqual(self::ROTATED_ACCESS_KEY_ID, $module['access_key_id'],
      'the rotated admin default access key id reaches the cfg');
    $this->assertEqual(self::ROTATED_SECRET_ACCESS_KEY, $module['secret_access_key'],
      'the rotated admin default secret access key reaches the cfg');
  }

  /**
   * The control the test above needs. Without the refresh the stored snapshot
   * is what is marshalled -- so the assertion above is reading the refresh and
   * not some path that would have emitted the rotated values regardless.
   */
  public function testUnrefreshedClientDataStillMarshallsTheSnapshot() {
    $curData = $this->currentClient($this->clientId);

    $module = $this->emittedModuleConfig($curData);

    $this->assertEqual(self::SNAPSHOT_ACCESS_KEY_ID, $module['access_key_id'],
      'the stored per-client row is the snapshot, and it wins over the default '
      . 'until it is refreshed');
  }

  /**
   * The refreshed row keeps its own identity, so persisting it updates the
   * client's configuration in place. A row that carried the default's id would
   * overwrite the admin client default instead, and a row with no id at all
   * would insert a second configuration for the client -- the duplicate-insert
   * shape of
   * docs/solutions/logic-errors/oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md.
   */
  public function testRefreshedRowSavesOverTheClientsOwnRow() {
    $curData = $this->currentClient($this->clientId);

    $refreshed = $this->configModel()->refreshedFromAdminDefault($curData);

    $this->assertEqual($this->perClientConfigId, $refreshed['id'],
      'the refreshed row keeps the per-client row id');
    $this->assertEqual($this->clientId, $refreshed['client_id'],
      'the refreshed row is still the client\'s own');
    $this->assertTrue(empty($refreshed['admin_id']),
      'the refreshed row does not become a second admin default');

    $model = $this->configModel();
    $model->clear();
    $this->assertTrue((bool)$model->save($refreshed),
      'the refreshed row saves');

    $this->assertEqual(1,
      $this->fx->count('cm_oa4mp_client_dynamo_configs',
        'client_id = ' . (int)$this->clientId),
      'the client still has exactly one configuration row');
    $this->assertEqual(self::ROTATED_ACCESS_KEY_ID,
      $this->fx->scalar('SELECT aws_access_key_id FROM cm_oa4mp_client_dynamo_configs'
        . ' WHERE id = ' . (int)$this->perClientConfigId),
      'the stored row now holds the rotated credential');
    $this->assertEqual(1,
      $this->fx->count('cm_oa4mp_client_dynamo_configs',
        'admin_id = ' . (int)$this->adminId),
      'the admin client default was not duplicated or overwritten in place');
  }

  /**
   * A client with no per-client row already resolves to the admin default on
   * every marshall. Creating a row for it would reintroduce the snapshot this
   * whole correction exists to remove, so the refresh declines.
   *
   * The read matters here: with no row, Containable returns the hasOne
   * association as an array of null-valued fields rather than an empty array
   * (docs/solutions/logic-errors/oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md),
   * so the decision cannot rest on a bare !empty().
   */
  public function testClientWithNoPerClientRowIsNotGivenOne() {
    $curData = $this->currentClient($this->fallbackClientId);

    $this->assertNotEmpty($curData['Oa4mpClientDynamoConfig'],
      'the phantom association is not empty, which is why the refresh cannot '
      . 'test it with a bare !empty()');

    $this->assertNull($this->configModel()->refreshedFromAdminDefault($curData),
      'a client with no per-client configuration row has nothing to refresh');

    $this->assertEqual(0,
      $this->fx->count('cm_oa4mp_client_dynamo_configs',
        'client_id = ' . (int)$this->fallbackClientId),
      'and no row was created for it');
  }

  /**
   * An admin client with no default configuration has nothing to copy. Copying
   * the phantom's nulls over a populated per-client row would empty the cfg
   * the client is already being sent.
   */
  public function testAdminClientWithNoDefaultDoesNotEmptyThePerClientRow() {
    $curData = $this->currentClient($this->bareAdminClientId);

    $this->assertNull(
      $curData['Oa4mpClientCoAdminClient']['DefaultDynamoConfig']['aws_region'],
      'the admin client really has no default configuration');

    $this->assertNull($this->configModel()->refreshedFromAdminDefault($curData),
      'there is nothing to refresh from');

    $this->assertEqual(self::SNAPSHOT_ACCESS_KEY_ID,
      $this->fx->scalar('SELECT aws_access_key_id FROM cm_oa4mp_client_dynamo_configs'
        . ' WHERE client_id = ' . (int)$this->bareAdminClientId),
      'the per-client row is left exactly as it was');
  }

  /**
   * A client whose row already holds the default's values has nothing to bring
   * forward. Saving it anyway would rewrite the same row on every edit of
   * every client, and every such write is a chance to disturb a row that was
   * already correct.
   */
  public function testRowAlreadyMatchingTheDefaultIsNotRewritten() {
    $model = $this->configModel();
    $model->clear();
    $this->assertTrue((bool)$model->save(
      $model->refreshedFromAdminDefault($this->currentClient($this->clientId))),
      'the client is brought up to date first');

    // A second read, because the save above invalidated the first one.
    $curData = $this->model('Oa4mpClient.Oa4mpClientCoOidcClient')->find('first', array(
      'conditions' => array('Oa4mpClientCoOidcClient.id' => $this->clientId),
      'contain' => array('Oa4mpClientDynamoConfig', 'Oa4mpClientCoAdminClient' => 'DefaultDynamoConfig')
    ));

    $this->assertEqual(self::ROTATED_ACCESS_KEY_ID,
      $curData['Oa4mpClientDynamoConfig']['aws_access_key_id'],
      'the row now matches the admin default');

    $this->assertNull($this->configModel()->refreshedFromAdminDefault($curData),
      'a row that already matches the default is not refreshed again');
  }

  /**
   * The wiring lock. Nothing in this suite can drive the OIDC clients
   * controller -- the only controller harness is the claims one
   * (Test/lib/Oa4mpClaimsControllerHarness.php) -- so the tests above prove
   * the refresh works while saying nothing about edit() calling it. This scan
   * covers that gap as far as a scan can: that edit() computes the refresh,
   * puts it into the client data that is marshalled, sends it, and only then
   * persists it.
   *
   * All three positions are load-bearing. Persisting before the send would
   * leave the plugin's copy ahead of the server's on a failed call, and every
   * later edit would report the client out of sync. And a refresh that is
   * computed but never assigned into $newClient would leave both orderings
   * true while the stale snapshot went back on the wire -- the original bug,
   * with the scan still green.
   */
  public function testEditWiresTheRefreshBeforeSendingAndPersistsAfter() {
    $path = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientCoOidcClientsController.php';
    $this->assertTrue(is_readable($path), "the OIDC clients controller exists at $path");

    $source = file_get_contents($path);

    $refresh = strpos($source, 'refreshedFromAdminDefault($client)');
    $this->assertTrue($refresh !== false,
      'edit() refreshes the per-client configuration from the admin default');

    $assign = strpos($source,
      '$newClient[\'Oa4mpClientDynamoConfig\'] = $refreshedDynamoConfig;');
    $this->assertTrue($assign !== false,
      'edit() puts the refreshed configuration into the client data it marshalls');
    $this->assertTrue($refresh < $assign,
      'the refresh is computed before it is assigned');

    $send = strpos($source, '$oa4mpServer->oa4mpEditClient(');
    $this->assertTrue($send !== false, 'edit() still calls the server');
    $this->assertTrue($assign < $send,
      'the refreshed configuration is in the client data before it is marshalled '
      . 'and sent, or the stale snapshot goes back on the wire');

    $persist = strpos($source, 'Oa4mpClientDynamoConfig->save($refreshedDynamoConfig)');
    $this->assertTrue($persist !== false,
      'edit() persists the refreshed configuration');
    $this->assertTrue($send < $persist,
      'the refreshed configuration is persisted only after the server accepted '
      . 'the edit, never before');

    $clientSave = strpos($source, '$this->Oa4mpClientCoOidcClient->saveAssociated($this->request->data)');
    $this->assertTrue($clientSave !== false, 'edit() still saves the client');
    $this->assertTrue($persist < $clientSave,
      'the refreshed configuration is persisted before the client save, which '
      . 'can fail on its own validation without saying anything about what the '
      . 'server now holds');
  }

  /**
   * Teardown completeness: the purge clause must cover every configuration row
   * attributable to this fixture, including the one the save path writes.
   */
  public function testTeardownPurgesEveryConfigRowTheFixtureCreates() {
    $attributable = 'admin_id IN (' . (int)$this->adminId . ', ' . (int)$this->bareAdminId . ')'
      . ' OR client_id IN (SELECT id FROM cm_oa4mp_client_co_oidc_clients'
      . ' WHERE admin_id IN (' . (int)$this->adminId . ', ' . (int)$this->bareAdminId . '))';

    $leftBehind = $this->fx->count('cm_oa4mp_client_dynamo_configs',
      '(' . $attributable . ') AND NOT (' . $this->configPurgeWhere() . ')');
    $this->assertEqual(0, $leftBehind,
      'the tearDown purge clause covers every configuration row this fixture owns');
  }
}
