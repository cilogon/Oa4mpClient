<?php
/**
 * Database-backed configuration-fallback row of the claim cfg contract matrix.
 *
 * Every other row in the matrix builds its client data in memory and hands it
 * straight to the marshaller. That bypasses the one thing this row exists for:
 * the Containable read of the per-client hasOne Oa4mpClientDynamoConfig. When
 * the client has no per-client row that association comes back as an array of
 * null-valued keys -- not as an empty array -- so a bare !empty() on it is
 * always truthy. See
 * docs/solutions/logic-errors/oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30.md.
 *
 * The production fix routed both the send path (oa4mpMarshallCfgQdl) and the
 * compare path (isClientDataSynchronized) through one resolver,
 * resolveDynamoConfig(). CfgMarshallingTest already locks that resolver against
 * hand-built arrays. What nothing tested -- the "Remaining gap" that learning
 * document names -- is that the two call sites AGREE: a regression reverting
 * isClientDataSynchronized() to reading $curData['Oa4mpClientDynamoConfig']
 * directly would leave every other test green. That is the pair this file
 * closes, and it can only be closed from a real database read, because only a
 * real read produces the phantom.
 *
 * Every credential-shaped value here is a synthetic placeholder, matching
 * Test/lib/Oa4mpClaimRows.php. The secret scan walks full history, so a
 * scanner-matching value committed once can never be cleared by editing the
 * working tree.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U3
 * (R16, R15, R13).
 */

class ClaimCfgFallbackTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;

  /** The OIDC client with NO per-client configuration row. */
  private $fallbackClientId;

  /** The OIDC client that does have one, the positive control. */
  private $perClientClientId;

  private $defaultConfigId;
  private $perClientConfigId;
  private $claimId;

  // Synthetic placeholders, the same short forms already used by
  // Test/lib/Oa4mpClaimRows.php and Test/Case/Controller/AdminClientEditSaveTest.php.
  const AWS_ACCESS_KEY_ID = 'AKIAEXAMPLE';
  const AWS_SECRET_ACCESS_KEY = 'not-a-real-secret';

  // Pinned so the emitted cfg does not depend on
  // COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT or on the hard-coded fallback.
  const QDL_CLAIM_SOURCE = 'COmanageRegistry/test/dynamodb_claims.qdl';

  public function setUp() {
    // Reset every field first: the runner reuses one instance across methods,
    // so a setUp that fails partway must not leave the previous method's ids
    // visible to tearDown.
    $this->fx = null;
    $this->coId = null;
    $this->adminId = null;
    $this->fallbackClientId = null;
    $this->perClientClientId = null;
    $this->defaultConfigId = null;
    $this->perClientConfigId = null;
    $this->claimId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpfallback');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag, array(
      'qdl_claim_source' => self::QDL_CLAIM_SOURCE
    ));

    // The admin client's DefaultDynamoConfig: admin_id set, client_id null.
    // Oa4mpClientCoAdminClient hasOne DefaultDynamoConfig keys on admin_id
    // alone, so a per-client row must leave admin_id null or it would be read
    // as the default too.
    $this->defaultConfigId = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('admin_id' => $this->adminId, 'client_id' => null)
      + $this->fallbackConfig());

    // The client under test: no per-client configuration row at all.
    $this->fallbackClientId = $this->fx->oidcClient($this->adminId, 'fallback ' . $tag);

    // The positive control: same admin client, but with its own row.
    $this->perClientClientId = $this->fx->oidcClient($this->adminId, 'perclient ' . $tag);
    $this->perClientConfigId = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('admin_id' => null, 'client_id' => $this->perClientClientId)
      + $this->perClientConfig());

    // One claim on the fallback client, so oa4mpMarshallContent() has a reason
    // to attach a cfg at all and the row exercises the real send path rather
    // than the QDL marshaller in isolation.
    $this->claimId = $this->fx->insert('cm_oa4mp_client_claims',
      array('client_id' => $this->fallbackClientId) + $this->claimColumns());
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }
    $this->fx->cleanup($this->purgeClauses());
    $this->fx = null;
  }

  /**
   * The tearDown purge, as data, so testTeardownPurgesEveryConfigRow can check
   * that it really covers every configuration row attributable to this fixture.
   *
   * Configuration rows are purged by clause rather than by tracked id because
   * the save paths under test create rows the fixture helper never saw.
   */
  private function purgeClauses() {
    return array(
      'cm_oa4mp_client_dynamo_configs' => $this->configPurgeWhere()
    );
  }

  private function configPurgeWhere() {
    return 'admin_id = ' . (int)$this->adminId
      . ' OR client_id IN (' . (int)$this->fallbackClientId
      . ', ' . (int)$this->perClientClientId . ')';
  }

  /** The admin client's default configuration -- what the fallback must emit. */
  private function fallbackConfig() {
    return array(
      'aws_region' => 'us-east-2',
      'aws_access_key_id' => self::AWS_ACCESS_KEY_ID,
      'aws_secret_access_key' => self::AWS_SECRET_ACCESS_KEY,
      'table_name' => 'fallback-registry',
      'partition_key' => 'sub',
      'partition_key_template' => '${sub}',
      'partition_key_claim_name' => 'sub'
    );
  }

  /**
   * The control client's own configuration. Every field differs from the
   * default above, so an assertion cannot pass by reading the wrong row.
   */
  private function perClientConfig() {
    return array(
      'aws_region' => 'eu-west-1',
      'aws_access_key_id' => self::AWS_ACCESS_KEY_ID,
      'aws_secret_access_key' => self::AWS_SECRET_ACCESS_KEY,
      'table_name' => 'per-client-registry',
      'partition_key' => 'uid',
      'partition_key_template' => '${uid}',
      'partition_key_claim_name' => 'uid'
    );
  }

  /** The seeded claim, minus client_id. */
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

  private function oidcClientModel() {
    return $this->model('Oa4mpClient.Oa4mpClientCoOidcClient');
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * The canonical database read: Oa4mpClientCoOidcClient::current() is the
   * entry point that resolves the client's configuration out of the database
   * rather than from a supplied array. Call it once per test method -- CakePHP
   * 2's DboSource caches every SELECT by its literal SQL text (Test/README.md).
   */
  private function currentClient($id) {
    return $this->oidcClientModel()->current($id);
  }

  /** The admin-client argument oa4mpMarshallContent() takes. */
  private function adminClientContext($curData) {
    return array('Oa4mpClientCoAdminClient' => $curData['Oa4mpClientCoAdminClient']);
  }

  /** The QDL args block of a marshalled cfg. */
  private function qdlArgs($cfg) {
    return $cfg['tokens']['identity']['qdl']['args'];
  }

  /**
   * Assert that the emitted cfg carries exactly $expected, the seven columns of
   * a configuration row that reach the wire. dynamo_module_config renames five
   * of them; the remaining two ride at the args level.
   */
  private function assertEmittedConfig($expected, $cfg, $label) {
    $args = $this->qdlArgs($cfg);
    $module = $args['dynamo_module_config'];

    $this->assertEqual($expected['aws_region'], $module['region'],
      "$label: region");
    $this->assertEqual($expected['aws_access_key_id'], $module['access_key_id'],
      "$label: access_key_id");
    $this->assertEqual($expected['aws_secret_access_key'], $module['secret_access_key'],
      "$label: secret_access_key");
    $this->assertEqual($expected['table_name'], $module['table_name'],
      "$label: table_name");
    $this->assertEqual($expected['partition_key'], $module['partition_key'],
      "$label: partition_key");
    $this->assertEqual($expected['partition_key_template'], $args['partition_key_template'],
      "$label: partition_key_template");
    $this->assertEqual($expected['partition_key_claim_name'], $args['partition_key_claim_name'],
      "$label: partition_key_claim_name");
  }

  /**
   * The server's representation of the configuration, built from what the
   * marshaller actually emitted. "The server carries the values we sent it" is
   * the premise of the whole sync check, so deriving this from the emitted cfg
   * rather than from the fixture is the point: if the send path and the compare
   * path resolve the configuration differently, this reports out of sync.
   *
   * sort_key and sort_key_template are absent rather than pinned to null.
   * cfg_contract.json declares neither name, so the marshaller writes neither
   * and the unmarshaller reads neither back -- there is no value here to
   * derive from the emitted cfg. Pinning them to null used to be what let this
   * pass: the comparator compared both, and the fixture happened to leave the
   * plugin side null too. A configuration with either column populated
   * reported out of sync forever; see
   * SyncVerificationTest::testPopulatedSortKeyReportsInSync.
   */
  private function serverConfigFromCfg($cfg) {
    $args = $this->qdlArgs($cfg);
    $module = $args['dynamo_module_config'];

    return array(
      'aws_region' => $module['region'],
      'aws_access_key_id' => $module['access_key_id'],
      'aws_secret_access_key' => $module['secret_access_key'],
      'table_name' => $module['table_name'],
      'partition_key' => $module['partition_key'],
      'partition_key_template' => $args['partition_key_template'],
      'partition_key_claim_name' => $args['partition_key_claim_name']
    );
  }

  /**
   * The OA4MP server representation of the fallback client, matching the
   * persisted rows on every axis the comparator checks except the
   * configuration, which the caller supplies.
   */
  private function serverData($curData, $serverConfig) {
    $client = $curData['Oa4mpClientCoOidcClient'];

    return array(
      'Oa4mpClientCoOidcClient' => array(
        'oa4mp_identifier' => $client['oa4mp_identifier'],
        'name' => $client['name'],
        'proxy_limited' => $client['proxy_limited'],
        'public_client' => $client['public_client'],
        'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature')
          . ': https://example.org/'
      ),
      'Oa4mpClientRefreshToken' => array('token_lifetime' => null),
      'Oa4mpClientCoEmailAddress' => array(),
      'Oa4mpClientCoCallback' => array(),
      'Oa4mpClientCoScope' => array(),
      'Oa4mpClientAccessToken' => array(),
      'Oa4mpClientAuthorization' => array(),
      'Oa4mpClientDynamoConfig' => $serverConfig,
      'Oa4mpClientClaim' => array($this->claimColumns())
    );
  }

  /**
   * The read this whole row rests on: with no per-client row, Containable does
   * not omit the association and does not return an empty array -- it returns
   * every column, null. Characterized here so the two tests below cannot pass
   * for the wrong reason (an absent key would make a bare !empty() guard
   * behave correctly by accident, and the fallback would look locked when it
   * is not).
   */
  public function testMissingPerClientConfigReadsAsPhantomAllNullArray() {
    $curData = $this->currentClient($this->fallbackClientId);

    $this->assertTrue(array_key_exists('Oa4mpClientDynamoConfig', $curData),
      'the hasOne association key is present even with no row');
    $this->assertNotEmpty($curData['Oa4mpClientDynamoConfig'],
      'the phantom association is NOT empty -- this is why a bare !empty() guard '
      . 'is always truthy and skips the fallback');
    $this->assertNull($curData['Oa4mpClientDynamoConfig']['aws_region'],
      'every column of the phantom association is null');
    $this->assertNull($curData['Oa4mpClientDynamoConfig']['table_name'],
      'every column of the phantom association is null');

    // And the admin default really is there to fall back to.
    $this->assertEqual('us-east-2',
      $curData['Oa4mpClientCoAdminClient']['DefaultDynamoConfig']['aws_region'],
      'the admin client default configuration was read alongside the client');
  }

  /**
   * Covers AE7. A client with no per-client configuration row emits the
   * admin client's default values, marshalled through the real send path.
   */
  public function testClientWithoutPerClientConfigEmitsFallbackValues() {
    $curData = $this->currentClient($this->fallbackClientId);

    $content = $this->server()->oa4mpMarshallContent(
      $this->adminClientContext($curData), $curData);

    $this->assertTrue(isset($content['cfg']),
      'a confidential client carrying a claim is sent a cfg');
    $this->assertEqual(self::QDL_CLAIM_SOURCE,
      $content['cfg']['tokens']['identity']['qdl']['load'],
      'the admin client QDL source is used, so the cfg is the QDL shape');

    $this->assertEmittedConfig($this->fallbackConfig(), $content['cfg'],
      'a client with no per-client configuration row emits the admin default');
  }

  /**
   * The learning document's named open test, second half: having sent the
   * fallback values, the compare path must agree that a server carrying those
   * same values is in sync.
   *
   * A regression that reverts isClientDataSynchronized() to reading
   * $curData['Oa4mpClientDynamoConfig'] directly compares the phantom nulls
   * against the real values that were sent, and this goes red -- which is
   * exactly the second bug (commit d354872) that no other test can see.
   */
  public function testFallbackClientReportsInSyncAgainstServerCarryingSentValues() {
    $curData = $this->currentClient($this->fallbackClientId);

    $cfg = $this->server()->oa4mpMarshallCfgQdl($curData);

    // Sanity: the values below are the fallback's, not the phantom's. Without
    // this the sync assertion would still pass if BOTH paths regressed to the
    // phantom, since they would then agree on null.
    $this->assertEmittedConfig($this->fallbackConfig(), $cfg,
      'the send path resolved the fallback');

    $serverData = $this->serverData($curData, $this->serverConfigFromCfg($cfg));

    $this->assertTrue(
      $this->server()->isClientDataSynchronized($curData, $serverData),
      'the compare path must resolve the configuration the same way the send '
      . 'path did: a client with no per-client row is in sync with a server '
      . 'carrying the admin default values it was sent');
  }

  /**
   * Positive control: a client that does have a per-client row emits that
   * row's values, not the admin default. Without this the fallback assertion
   * above would pass for the wrong reason if the resolver stopped reading the
   * per-client row at all.
   */
  public function testClientWithPerClientConfigEmitsItsOwnValues() {
    $curData = $this->currentClient($this->perClientClientId);

    $cfg = $this->server()->oa4mpMarshallCfgQdl($curData);

    $this->assertEmittedConfig($this->perClientConfig(), $cfg,
      'a real per-client configuration row wins over the admin default');
  }

  /**
   * Teardown completeness: the purge clause tearDown runs must cover every
   * configuration row attributable to this fixture, including any a save path
   * created that the fixture helper never tracked. The attributable set is
   * expressed independently of the purge clause, so a purge that forgets a
   * case leaves rows behind and this fails.
   *
   * The end-to-end proof is running the suite twice in a row; this makes the
   * first run fail loudly instead of quietly seeding the second.
   */
  public function testTeardownPurgesEveryConfigRowTheFixtureCreates() {
    $attributable = 'admin_id = ' . (int)$this->adminId
      . ' OR client_id IN (SELECT id FROM cm_oa4mp_client_co_oidc_clients'
      . ' WHERE admin_id = ' . (int)$this->adminId . ')';

    $total = $this->fx->count('cm_oa4mp_client_dynamo_configs', $attributable);
    $this->assertEqual(2, $total,
      'the fixture owns exactly the two configuration rows it seeded');

    $leftBehind = $this->fx->count('cm_oa4mp_client_dynamo_configs',
      '(' . $attributable . ') AND NOT (' . $this->configPurgeWhere() . ')');
    $this->assertEqual(0, $leftBehind,
      'the tearDown purge clause covers every configuration row this fixture owns');
  }
}
