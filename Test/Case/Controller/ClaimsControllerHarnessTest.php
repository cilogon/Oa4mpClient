<?php
/**
 * Self-test for the claims-controller harness (Test/lib/Oa4mpClaimsControllerHarness.php).
 *
 * No test in this suite had ever instantiated a controller. This file proves
 * that a claims action can now be driven and asserted from the hermetic tier
 * before U9's regression tests rely on it, and locks the two properties that
 * make it work: the production factory really is the single construction point
 * for the OA4MP server object, and the harness' redirect() really terminates
 * the action instead of letting it fall through.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U8.
 */

App::uses('Oa4mpClientClaimsController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');
App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('ConnectionManager', 'Model');

class ClaimsControllerHarnessTest extends Oa4mpTestCase {

  /**
   * Set by the first test that drives an action and read by the next one.
   * Static because the runner keeps one instance per file but setUp() resets
   * the per-test state; a driven action that exit()ed would take the whole
   * process with it and the reader below would never run at all.
   */
  private static $droveAnAction = false;

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;
  private $clientId;
  private $publicClientId;
  private $claimId;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpclaimsctl');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag);

    // The admin client's default configuration. admin() contains
    // DefaultDynamoConfig, so seed one to exercise the real read.
    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $this->adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));

    $this->clientId = $this->fx->oidcClient($this->adminId, 'claims-' . $tag);
    $this->publicClientId = $this->fx->oidcClient($this->adminId, 'public-' . $tag,
      array('public_client' => true));

    $this->claimId = $this->fx->insert('cm_oa4mp_client_claims', array(
      'client_id' => $this->clientId,
      'claim_name' => 'eppn',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string'
    ));

    $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
      'claim_id' => $this->claimId,
      'constraint_field' => 'type',
      'constraint_value' => 'eppn'
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    // The driven actions insert and delete claims of their own, so purge by
    // client rather than by the ids this file happens to know. Constraints
    // first: they carry the foreign key into claims.
    $purge = array();
    if ($this->clientId !== null) {
      $clients = (int)$this->clientId . ', ' . (int)$this->publicClientId;
      $purge['cm_oa4mp_client_claim_constraints'] =
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id IN (' . $clients . '))';
      $purge['cm_oa4mp_client_claims'] = 'client_id IN (' . $clients . ')';
      $purge['cm_oa4mp_client_dynamo_configs'] = 'admin_id = ' . (int)$this->adminId;
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  /** A harness pointed at the ordinary (non-public) seeded client. */
  private function harness($data = array()) {
    return Oa4mpClaimsControllerHarness::build($this->clientId, $this->coId, $data);
  }

  private function claimCount() {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->clientId);
  }

  /**
   * Count rows, having first dropped CakePHP's in-memory query cache.
   *
   * DboSource::fetchAll() caches every SELECT by its exact SQL text and
   * nothing invalidates that cache on a write, so asking the same count
   * question twice around a delete returns the first answer both times.
   */
  private function countRows($fx, $table, $where) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $fx->count($table, $where);
  }

  /** The controller's source, for the static locks below. */
  private function controllerSource() {
    $path = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientClaimsController.php';
    $this->assertTrue(is_readable($path), "the claims controller exists at $path");
    return file_get_contents($path);
  }

  /**
   * The load-bearing scenario: a real claims action runs inside the runner.
   *
   * delete()'s error branch is the easiest first target -- it flashes and
   * falls off the end of the action without redirecting -- so a failure here
   * is about drivability and nothing else.
   */
  public function testHarnessDrivesAClaimsActionWithoutExiting() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 0;

    $redirect = $harness->harnessInvoke('delete', array($this->claimId));

    self::$droveAnAction = true;

    $this->assertNull($redirect, 'the error branch does not redirect');
    $this->assertFalse($harness->harnessStopped, 'the action ran to its end');
    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the action called the OA4MP server exactly once');
    $this->assertEqual(1, $this->claimCount(),
      'the error branch leaves the claim in place');
  }

  /**
   * The runner survived the action above. If redirect() or the action had
   * reached exit(), the process would have ended mid-run and this method
   * would never have been reached.
   */
  public function testFollowingTestInTheSameFileStillRuns() {
    $this->assertTrue(self::$droveAnAction,
      'the preceding test drove an action and the runner is still alive');
  }

  /**
   * redirect() records its target and stops the action.
   *
   * _blockIfPublicClient() is the guard that depends on that: it flashes and
   * redirects, and if redirect() merely returned, control would fall through
   * into the POST branch and call the OA4MP server anyway. An empty call log
   * on the fake is what proves the action really stopped.
   */
  public function testRedirectRecordsItsTargetInsteadOfExiting() {
    $harness = Oa4mpClaimsControllerHarness::build($this->publicClientId, $this->coId);

    $redirect = $harness->harnessInvoke('delete', array($this->claimId));

    $this->assertTrue($harness->harnessStopped, 'the action stopped at the redirect');
    $this->assertEqual(1, $harness->harnessRedirectCount, 'redirect() was called once');
    $this->assertEqual('index', $redirect['action'], 'the target is the claims index');
    $this->assertEqual('oa4mp_client_claims', $redirect['controller'], 'in the claims controller');
    $this->assertEqual($this->publicClientId, $redirect['clientid'], 'for the same client');
    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the action before it reached the OA4MP server');
    $this->assertContains('public', strtolower($harness->Flash->last()),
      'the public-client error was flashed');
  }

  /**
   * The fake really is the object the action talks to: three different
   * verdicts from the same call site select three different branches.
   */
  public function testFakeServerVerdictSelectsTheBranchTaken() {
    // 2 -- plugin and server out of sync. Flash, no delete, no redirect.
    $outOfSync = $this->harness();
    $outOfSync->harnessServer->editClientReturn = 2;
    $outOfSync->harnessInvoke('delete', array($this->claimId));

    $this->assertEqual(0, $outOfSync->harnessRedirectCount, 'the out-of-sync branch does not redirect');
    $this->assertEqual(1, $this->claimCount(), 'the out-of-sync branch deletes nothing');
    $outOfSyncFlash = $outOfSync->Flash->last();

    // 0 -- save error. A different flash from the same call site.
    $error = $this->harness();
    $error->harnessServer->editClientReturn = 0;
    $error->harnessInvoke('delete', array($this->claimId));
    $this->assertFalse($outOfSyncFlash === $error->Flash->last(),
      'verdict 2 and verdict 0 flash different messages');

    // 1 -- success. The claim is deleted and the action redirects.
    $ok = $this->harness();
    $ok->harnessServer->editClientReturn = 1;
    $redirect = $ok->harnessInvoke('delete', array($this->claimId));

    $this->assertEqual(1, $ok->harnessRedirectCount, 'the success branch redirects');
    $this->assertEqual('index', $redirect['action'], 'to the claims index');
    $this->assertEqual(0, $this->claimCount(), 'the success branch deleted the claim');
  }

  /**
   * A success-path redirect terminates the action before the GET tail.
   *
   * add()'s success branch saves and redirects. Were redirect() to return,
   * the action would run on into the GET tail: a second verification call, a
   * saveField write and four database-backed type lookups. Exactly one
   * recorded server call is the assertion that catches that.
   */
  public function testSuccessRedirectTerminatesBeforeTheGetTail() {
    $harness = $this->harness(array(
      'Oa4mpClientClaim' => array(
        'client_id' => $this->clientId,
        'claim_name' => 'harness_added',
        'source_model' => 'Identifier',
        'source_model_claim_value_field' => 'identifier',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string'
      ),
      'Oa4mpClientClaimConstraint' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'oidcsub')
      )
    ));
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertTrue($harness->harnessStopped, 'the action stopped at the redirect');
    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the GET tail did not run, so the server was verified zero more times');
    $this->assertEqual('index', $redirect['action'], 'the redirect targets the claims index');
    $this->assertEqual(2, $this->claimCount(), 'the new claim was saved');
    $this->assertEqual(1, $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      "client_id = " . (int)$this->clientId . " AND claim_name = 'harness_added'"),
      'the saved claim is the posted one');
  }

  /**
   * A whole action driven to completion with no redirect at all: index()
   * with a synchronized verdict sets the view variables and returns.
   */
  public function testIndexRunsToCompletionAndSetsViewVariables() {
    $harness = $this->harness();
    $harness->harnessServer->verifySynchronized = true;

    $redirect = $harness->harnessInvoke('index');

    $this->assertNull($redirect, 'a synchronized client does not redirect');
    $this->assertEqual(array('oa4mpVerifyClient'), $harness->harnessServer->callNames(),
      'index() verifies once');
    $this->assertEqual($this->clientId, $harness->viewVars['vv_client_id'],
      'the client id reached the view');
    $this->assertNotEmpty($harness->viewVars['title_for_layout'],
      'the page title was set');
    $this->assertEqual($this->clientId,
      (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the loaded client was handed to the view');
  }

  /**
   * The GET tail add() falls into after a failed POST is reachable too: a
   * second verification call, the oa4mp_server_extra reconciliation and the
   * four database-backed type lookups all run under the harness. U9 needs
   * this path, so its reachability is asserted here rather than discovered
   * later.
   */
  public function testGetTailIsReachableWithTheFakeServer() {
    $harness = $this->harness();
    $harness->harnessServer->verifySynchronized = true;
    $harness->harnessServer->verifyExtra = null;

    $redirect = $harness->harnessInvoke('add');

    $this->assertNull($redirect, 'a synchronized client does not redirect out of the tail');
    $this->assertEqual(array('oa4mpVerifyClient'), $harness->harnessServer->callNames(),
      'the GET path verifies once');
    $this->assertNull($harness->request->data, 'stale request data was cleared');
    $this->assertTrue(array_key_exists('vv_identifier_types', $harness->viewVars),
      'the identifier types lookup ran');
    $this->assertTrue(array_key_exists('vv_email_types', $harness->viewVars),
      'the email types lookup ran');
    $this->assertTrue(array_key_exists('vv_name_types', $harness->viewVars),
      'the name types lookup ran');
    $this->assertNotEmpty($harness->viewVars['vv_ssh_key_types'],
      'the SSH key type enum resolved');
  }

  /**
   * The production default is unchanged: the factory hands the real actions a
   * real Oa4mpClientOa4mpServer. Without this, the seam could quietly ship a
   * test double to production.
   */
  public function testProductionFactoryReturnsARealServerObject() {
    $controller = new Oa4mpClientClaimsController(new CakeRequest('/', false), new CakeResponse());

    $factory = new ReflectionMethod('Oa4mpClientClaimsController', '_oa4mpServer');
    $this->assertTrue($factory->isProtected(), 'the factory is protected, not public');
    $factory->setAccessible(true);

    $server = $factory->invoke($controller);
    $this->assertTrue($server instanceof Oa4mpClientOa4mpServer,
      'the production factory returns a real Oa4mpClientOa4mpServer');
  }

  /**
   * The substitution point is a PHP subclass override and nothing else. If
   * the factory ever consulted Configure or the request for a class name, a
   * deployment or a crafted request could swap the OA4MP server object at
   * runtime.
   */
  public function testSubstitutionPointIsNotReachableFromConfigurationOrRequestData() {
    $source = $this->controllerSource();

    $body = $this->factoryBody($source);
    $this->assertNotEmpty($body, 'the _oa4mpServer factory is present');
    $this->assertEqual('return new Oa4mpClientOa4mpServer();', trim($body),
      'the factory body is a bare construction of the real class');

    $this->assertEqual(1, substr_count($source, 'new Oa4mpClientOa4mpServer()'),
      'the factory is the only place the server object is constructed');
    $this->assertFalse(strpos($source, 'Configure::read') !== false,
      'the controller reads no configuration at all, so none can select the class');
    $this->assertFalse(strpos($source, 'new $') !== false,
      'no variable class name is instantiated anywhere in the controller');
  }

  /**
   * Test/run.sh must require the runner's all-passed sentinel in the output.
   *
   * Without it a test that calls exit(0) mid-run ends the suite early with a
   * success status and the gate reports green. The runner prints
   * ALL_TESTS_PASSED only after every discovered test has run and passed, so
   * requiring that string is the mechanical backstop.
   */
  public function testRunShRequiresTheAllTestsPassedSentinel() {
    $path = App::pluginPath('Oa4mpClient') . 'Test' . DS . 'run.sh';
    $this->assertTrue(is_readable($path), "the runner script exists at $path");

    $script = file_get_contents($path);
    $this->assertContains('ALL_TESTS_PASSED', $script,
      'run.sh checks for the all-passed sentinel');
    $this->assertContains('grep -q', $script,
      'run.sh greps the captured suite output for the sentinel');
    $this->assertContains('exit 1', $script,
      'a missing sentinel fails the run');

    // The sentinel is only worth checking for if the runner still prints it.
    $shell = App::pluginPath('Oa4mpClient') . 'Console' . DS . 'Command' . DS
      . 'Oa4mpTestShell.php';
    $this->assertContains("ALL_TESTS_PASSED", file_get_contents($shell),
      'the runner still emits the sentinel run.sh looks for');
  }

  /**
   * Fixture teardown removes every row it seeded. Checked here inside one
   * database session with a throwaway fixture set, because Test/run.sh tears
   * the volume down on exit and so cannot show this across two invocations.
   */
  public function testFixtureTeardownLeavesNoRowsBehind() {
    $scratch = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpteardown');

    $coId = $scratch->co($tag);
    $adminId = $scratch->adminClient($coId, $tag);
    $clientId = $scratch->oidcClient($adminId, 'scratch-' . $tag);
    $claimId = $scratch->insert('cm_oa4mp_client_claims', array(
      'client_id' => $clientId,
      'claim_name' => 'scratch',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_json_format' => 'string'
    ));

    $this->assertEqual(1, $this->countRows($scratch, 'cm_oa4mp_client_claims',
      'id = ' . (int)$claimId), 'the scratch claim was seeded');

    $scratch->cleanup();

    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_claims',
      'id = ' . (int)$claimId), 'the claim is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_co_oidc_clients',
      'id = ' . (int)$clientId), 'the OIDC client is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_co_admin_clients',
      'id = ' . (int)$adminId), 'the admin client is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_cos', 'id = ' . (int)$coId),
      'the CO is gone');
  }

  /**
   * Return the body of the _oa4mpServer() factory, or '' if it is gone.
   */
  private function factoryBody($source) {
    $start = strpos($source, 'function _oa4mpServer()');
    if ($start === false) {
      return '';
    }

    $open = strpos($source, '{', $start);
    $close = strpos($source, '}', $open);
    if ($open === false || $close === false) {
      return '';
    }

    return substr($source, $open + 1, $close - $open - 1);
  }
}
