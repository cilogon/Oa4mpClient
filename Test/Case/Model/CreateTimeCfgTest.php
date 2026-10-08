<?php
/**
 * Tests for the data a new OIDC client is created with
 * (Oa4mpClientOa4mpServer::newClientData()) and the cfg that data sends to the
 * OA4MP server.
 *
 * A confidential client is created requiring Active Status, so its create
 * request is the first one ever to carry a cfg. That cfg has to be the one a
 * later edit of the same client sends: every edit marshals from
 * Oa4mpClientCoOidcClient::current(), which carries the admin client, its
 * DefaultDynamoConfig and the claims list. A create cfg built from the posted
 * form data alone carried null DynamoDB settings (every token request fails
 * when the QDL opens DynamoDB) and loaded a QDL script the admin client does
 * not configure. The sync check cannot catch either: it skips the DynamoDB
 * comparison when the server copy is empty and never compares qdl.load. So the
 * values are asserted directly, and the create cfg is compared to the edit cfg.
 *
 * See docs/plans/2026-09-28-1104-feat-require-active-status-default-plan.md
 * U2 (R1, R2, R3, R6, R8, AE1, AE6, KTD1, KTD3, KTD4, KTD5).
 */

/**
 * Reads its cfg contract from $contractPath, so a test can make building the
 * cfg fail. Test/Case/Model/ContractRedactionTest.php overrides the same
 * method the same way.
 */
class Oa4mpCreateTimeContractProbe extends Oa4mpClientOa4mpServer {

  /** @var string Where this instance reads its contract from. */
  public $contractPath = '';

  protected function cfgContractPath() {
    return $this->contractPath;
  }
}

class CreateTimeCfgTest extends Oa4mpTestCase {

  const QDL_CLAIM_SOURCE = 'COmanageRegistry/test/create_time_claims.qdl';

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;

  /** @var array Ids of OIDC clients created by the code under test. */
  private $clientIds = array();

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpcreatecfg');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag, array(
      'qdl_claim_source' => self::QDL_CLAIM_SOURCE
    ));

    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $this->adminId,
      'client_id' => null,
      'aws_region' => 'us-east-2',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-key',
      'table_name' => 'create-time-table',
      'partition_key' => 'sub',
      'partition_key_template' => '${sub}',
      'partition_key_claim_name' => 'sub'
    ));

    $this->clientIds = array();
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if (!empty($this->clientIds)) {
      $in = implode(', ', array_map('intval', $this->clientIds));
      $purge['cm_oa4mp_client_authorizations'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_dynamo_configs'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_co_scopes'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_co_email_addresses'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_co_oidc_clients'] = 'id IN (' . $in . ')';
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function oidcClientModel() {
    return $this->model('Oa4mpClient.Oa4mpClientCoOidcClient');
  }

  /** The admin client as the Add action reads it. */
  private function adminClient() {
    $args = array();
    $args['conditions']['Oa4mpClientCoAdminClient.id'] = $this->adminId;
    $args['contain'] = array();
    $args['contain'][] = 'Oa4mpClientCoEmailAddress';
    $args['contain']['Oa4mpClientCoNamedConfig'] = 'Oa4mpClientCoScope';
    $args['contain'][] = 'DefaultLdapConfig';
    $args['contain'][] = 'DefaultDynamoConfig';

    return $this->oidcClientModel()->Oa4mpClientCoAdminClient->find('first', $args);
  }

  /**
   * The Add form's POST as the Add action holds it just before the OA4MP
   * call: the form fields plus the openid scope the action forces.
   * $publicClient is the posted string, '0' or '1'.
   */
  private function posted($publicClient) {
    return array(
      'Oa4mpClientCoOidcClient' => array(
        'admin_id' => (string)$this->adminId,
        'name' => 'create time client ' . uniqid(),
        'home_url' => 'https://example.org/',
        'public_client' => $publicClient
      ),
      'Oa4mpClientCoEmailAddress' => array(
        array('mail' => 'admin@example.org')
      ),
      'Oa4mpClientCoScope' => array(
        array('scope' => 'openid')
      )
    );
  }

  /** Marshall the create request body for posted Add form data. */
  private function createContent($publicClient) {
    $admin = $this->adminClient();
    list($saveData, $marshallData) = $this->server()->newClientData($admin, $this->posted($publicClient));

    return array($admin, $saveData, $marshallData,
                 $this->server()->oa4mpMarshallContent($admin, $marshallData));
  }

  /**
   * Save a new client the way the Add action does after the OA4MP server
   * returned its identifier, and return its id.
   */
  private function saveNewClient($saveData) {
    $saveData['Oa4mpClientCoOidcClient']['oa4mp_identifier'] =
      'https://example.org/oidc/client/' . uniqid();
    $saveData['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

    $model = $this->oidcClientModel();
    $model->clear();
    $ret = $model->saveAssociated($saveData, array('deep' => true));

    $this->assertTrue((bool)$ret, 'the new client saves; validation errors: '
      . var_export($model->validationErrors, true));

    $id = (int)$model->id;
    $this->clientIds[] = $id;
    return $id;
  }

  private function qdlArgs($content) {
    return $content['cfg']['tokens']['identity']['qdl']['args'] ?? array();
  }

  /**
   * Covers AE1: a confidential client (posted '0') is saved and created
   * requiring Active Status, with no redirect URL.
   */
  public function testConfidentialClientIsCreatedRequiringActiveStatus() {
    list($admin, $saveData, $marshallData, $content) = $this->createContent('0');

    $this->assertTrue(!empty($saveData['Oa4mpClientAuthorization']['require_active']),
      'the save data carries require_active on');
    $this->assertFalse(isset($saveData['Oa4mpClientAuthorization']['require_active_redirect_url']),
      'a new client has no Active Status redirect URL');

    $this->assertTrue(isset($content['cfg']), 'a confidential create request carries a cfg');
    $args = $this->qdlArgs($content);
    $this->assertTrue(!empty($args['require_active_status']),
      'the cfg sends require_active_status');
    $this->assertFalse(isset($args['require_active_redirect_url']),
      'the cfg sends no Active Status redirect URL');
  }

  /**
   * Covers AE6: a public client (posted '1') gets no authorization row and
   * its create request carries no cfg.
   */
  public function testPublicClientIsCreatedWithoutAuthorization() {
    list($admin, $saveData, $marshallData, $content) = $this->createContent('1');

    $this->assertFalse(isset($saveData['Oa4mpClientAuthorization']),
      'a public client is saved with no authorization row');
    $this->assertFalse(isset($content['cfg']),
      'a public client create request carries no cfg');
    $this->assertEqual('none', $content['token_endpoint_auth_method'],
      'the public client is still created as public');
  }

  /**
   * The create cfg carries the admin client's DynamoDB default and QDL script,
   * not nulls or a fallback script. Asserted directly because the sync check
   * skips the DynamoDB comparison when the server copy is empty.
   */
  public function testCreateCfgCarriesAdminDynamoDefaultAndQdlScript() {
    list($admin, $saveData, $marshallData, $content) = $this->createContent('0');

    $qdl = $content['cfg']['tokens']['identity']['qdl'];
    $this->assertEqual(self::QDL_CLAIM_SOURCE, $qdl['load'],
      'the cfg loads the admin client\'s QDL script');

    $module = $qdl['args']['dynamo_module_config'];
    $this->assertEqual('us-east-2', $module['region'], 'the DynamoDB region is the admin default');
    $this->assertEqual('AKIAEXAMPLE', $module['access_key_id'], 'the access key is the admin default');
    $this->assertEqual('create-time-table', $module['table_name'], 'the table is the admin default');
    $this->assertEqual('sub', $module['partition_key'], 'the partition key is the admin default');
    $this->assertEqual('${sub}', $qdl['args']['partition_key_template'],
      'the partition key template is the admin default');
    $this->assertEqual(array(), $qdl['args']['claim_mappings'],
      'a new client has no claim mappings');
  }

  /** Marshalling the create data raises no PHP warnings. */
  public function testCreateCfgMarshallsWithoutWarnings() {
    $admin = $this->adminClient();
    list($saveData, $marshallData) = $this->server()->newClientData($admin, $this->posted('0'));

    $warnings = array();
    set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$warnings) {
      $warnings[] = "$errstr in $errfile line $errline";
      return true;
    }, E_ALL);

    try {
      $this->server()->oa4mpMarshallContent($admin, $marshallData);
    } catch (Exception $e) {
      restore_error_handler();
      throw $e;
    }
    restore_error_handler();

    $this->assertEqual(array(), $warnings, 'marshalling the create data raises no warnings');
  }

  /**
   * The cfg sent on create equals the cfg a later edit of the same client
   * sends, marshalled from current() after the client is saved.
   */
  public function testCreateCfgEqualsTheCfgALaterEditSends() {
    list($admin, $saveData, $marshallData, $content) = $this->createContent('0');
    $clientId = $this->saveNewClient($saveData);

    $current = $this->oidcClientModel()->current($clientId);
    $editContent = $this->server()->oa4mpMarshallContent($admin, $current);

    $this->assertEqual(json_encode($editContent['cfg']), json_encode($content['cfg']),
      'the create cfg and the edit cfg are identical');
  }

  /**
   * Covers AE1: the client the server holds after create, read back through
   * the real unmarshaller, is in sync with the saved client -- and stays in
   * sync once Require Active Status is turned off and sent again.
   */
  public function testCreatedClientReadsBackInSync() {
    list($admin, $saveData, $marshallData, $content) = $this->createContent('0');
    $clientId = $this->saveNewClient($saveData);
    $server = $this->server();

    $current = $this->oidcClientModel()->current($clientId);
    $this->assertTrue($server->isClientDataSynchronized($current,
                        $this->readBack($current, $content)),
      'the new client reads back in sync');

    // Turn Require Active Status off, as the Authorization tab would.
    $this->fx->query('UPDATE cm_oa4mp_client_authorizations SET require_active = false'
      . ' WHERE client_id = ' . (int)$clientId);
    $current = $this->oidcClientModel()->current($clientId);
    $offContent = $server->oa4mpMarshallContent($admin, $current);

    $this->assertFalse(!empty($this->qdlArgs($offContent)['require_active_status']),
      'with Require Active Status off, the cfg does not send it');
    $this->assertTrue($server->isClientDataSynchronized($current,
                        $this->readBack($current, $offContent)),
      'the client reads back in sync after Require Active Status is turned off');
  }

  /**
   * The server's view of $content, as oa4mpVerifyClient() hands it to the
   * unmarshaller: the request body plus the identifier and scope the server
   * reports.
   */
  private function readBack($current, $content) {
    $object = json_decode(json_encode($content), true);
    $object['client_id'] = $current['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $object['scope'] = 'openid';

    return $this->server()->oa4mpUnMarshallContent($object,
      array('Oa4mpClientCoAdminClient' => array('co_id' => $this->coId)));
  }

  /**
   * An edit of a client that has no authorization row -- read by Containable
   * as an all-null array -- still sends no require_active_status.
   */
  public function testPhantomAuthorizationRowSendsNoRequireActiveStatus() {
    $clientId = $this->fx->oidcClient($this->adminId, 'phantom-authz-' . uniqid());
    $this->clientIds[] = $clientId;

    $current = $this->oidcClientModel()->current($clientId);
    $this->assertTrue(array_key_exists('require_active', $current['Oa4mpClientAuthorization']),
      'premise: the missing row is read as an all-null array');

    $content = $this->server()->oa4mpMarshallContent($this->adminClient(), $current);
    $this->assertFalse(isset($this->qdlArgs($content)['require_active_status']),
      'no require_active_status is sent for a client with no authorization row');
  }

  /**
   * Covers AE4: when the cfg cannot be built, oa4mpNewClient() throws while
   * building the request body, before it sends anything. The Add action's
   * catch relies on this to show the create error with nothing created.
   */
  public function testUnbuildableCfgStopsTheCreateBeforeItIsSent() {
    $admin = $this->adminClient();
    list($saveData, $marshallData) = $this->server()->newClientData($admin, $this->posted('0'));

    $probe = new Oa4mpCreateTimeContractProbe();
    $probe->contractPath = sys_get_temp_dir() . DS . 'oa4mp_contract_absent_'
                         . getmypid() . '.json';
    $this->assertFalse(is_file($probe->contractPath),
      'premise: the contract path names no file');

    $thrown = null;
    try {
      $probe->oa4mpNewClient($admin, $marshallData);
    } catch (RuntimeException $e) {
      $thrown = $e;
    }

    $this->assertTrue($thrown !== null,
      'an unbuildable cfg throws from oa4mpNewClient()');
    $this->assertContains('cfg capability contract cannot be read', $thrown->getMessage(),
      'the failure is the cfg contract, raised while building the body');
  }

  /**
   * The Add action sends the builder's marshalling copy inside the catch that
   * turns a failure into the create error, and saves the builder's save data.
   * Sending or saving the raw posted data instead would create the client
   * with no cfg or save it with no authorization row, and nothing else in the
   * suite drives add().
   */
  public function testAddSendsAndSavesTheBuilderOutput() {
    $path = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientCoOidcClientsController.php';
    $this->assertTrue(is_readable($path), "the OIDC clients controller exists at $path");

    $source = file_get_contents($path);
    $start = strpos($source, 'function add()');
    $end = strpos($source, 'function calculateImpliedCoId(');
    $this->assertTrue($start !== false && $end !== false && $start < $end,
      'add() is found in the controller');
    $add = substr($source, $start, $end - $start);

    $build = strpos($add, 'list($saveData, $marshallData) = $oa4mpServer->newClientData($adminClient, $data);');
    $try = strpos($add, 'try {');
    $send = strpos($add, '$oa4mpServer->oa4mpNewClient($adminClient, $marshallData)');
    $catch = strpos($add, 'catch(Exception $e)');
    $failed = strpos($add, "_txt('pl.oa4mp_client_co_admin_client.er.create_error')");
    $save = strpos($add, '->saveAssociated($saveData, $args)');

    $this->assertTrue($build !== false, 'add() builds the create data with newClientData()');
    $this->assertTrue($send !== false, 'add() sends the marshalling copy, not the posted data');
    $this->assertTrue($try !== false && $catch !== false && $try < $send && $send < $catch,
      'the send is inside the try whose catch handles a failed create');
    $this->assertTrue($failed !== false && $catch < $failed,
      'a caught failure reaches the create error flash');
    $this->assertTrue($save !== false && $failed < $save,
      'add() saves the save data, and only after a successful create');
    $this->assertTrue($build < $send, 'the data is built before it is sent');
  }
}
