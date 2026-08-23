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

class ClaimsControllerHarnessTest extends Oa4mpClaimsControllerTestCase {

  /**
   * Set by the first test that drives an action and read by the next one.
   * Static because the runner keeps one instance per file but setUp() resets
   * the per-test state; a driven action that exit()ed would take the whole
   * process with it and the reader below would never run at all.
   */
  private static $droveAnAction = false;

  /**
   * The seeded client graph, its teardown, and the harness/count/source
   * helpers come from Oa4mpClaimsControllerTestCase (Test/lib), which
   * Test/Case/Controller/ClaimsWritePathTest.php shares. Only the fixture tag
   * differs.
   */
  protected function fixtureTagPrefix() {
    return 'oa4mpclaimsctl';
  }

  /**
   * The load-bearing scenario: a real claims action runs inside the runner.
   *
   * delete()'s server-error branch is the first target -- one server call, one
   * flash, no local write -- so a failure here is about drivability and
   * nothing else. The branch redirects to the claims index rather than falling
   * off the end of the action, because delete() has no view to fall through
   * to; what this test asserts is that the harness recorded that redirect and
   * handed control back, instead of the process exiting inside the action.
   */
  public function testHarnessDrivesAClaimsActionWithoutExiting() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 0;

    $redirect = $harness->harnessInvoke('delete', array($this->claimId));

    self::$droveAnAction = true;

    $this->assertTrue($harness->harnessStopped,
      'the harness recorded the redirect and returned control to the test');
    $this->assertEqual('index', $redirect['action'],
      'the error branch redirected to the claims index');
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
    // 2 -- plugin and server out of sync. Flash, no delete, and a redirect
    // away from this client's claims: delete() has no view to fall through to,
    // and the claims index re-verifies on every request, so an out-of-sync
    // client sent there would bounce straight back out.
    $outOfSync = $this->harness();
    $outOfSync->harnessServer->editClientReturn = 2;
    $outOfSync->harnessInvoke('delete', array($this->claimId));

    $this->assertEqual(1, $outOfSync->harnessRedirectCount, 'the out-of-sync branch redirects');
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
   * The other verdict the GET tail can get back: re-verification finds the
   * plugin and the OA4MP server out of sync.
   *
   * The tail then flashes and redirects out of this controller entirely -- to
   * the OIDC client list, which is the nearest page that can still show this
   * client. It cannot be the claims index: that action re-verifies on every
   * request and would bounce the user straight back here. The plugin and the
   * controller are named on the target on purpose; without them Router::url
   * resolves it relative to this request and lands on this controller's index
   * with no clientid, an action that cannot run.
   *
   * The fake defaults to a synchronized verdict, so nothing else in the suite
   * takes this branch.
   */
  public function testGetTailRedirectsOutWhenReVerificationFindsDrift() {
    $harness = $this->harness();
    $harness->harnessServer->verifySynchronized = false;

    $redirect = $harness->harnessInvoke('add');

    $this->assertTrue($harness->harnessStopped, 'the tail stopped at the redirect');
    $this->assertEqual(1, $harness->harnessRedirectCount, 'redirect() was called once');
    $this->assertEqual(array('oa4mpVerifyClient'), $harness->harnessServer->callNames(),
      'the redirect follows the one verification call the tail makes');
    $this->assertEqual('oa4mp_client', $redirect['plugin'],
      'the target names this plugin, so it does not resolve relative to the'
      . ' current request');
    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'],
      'the target is the OIDC client list, not the claims index this action'
      . ' would bounce back out of');
    $this->assertEqual('index', $redirect['action'], 'the client list index');
    $this->assertEqual($this->coId, $redirect['co'], 'for the current CO');
    $this->assertContains(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'),
      $harness->Flash->last(), 'the out-of-sync error was flashed');
    $this->assertFalse(array_key_exists('vv_identifier_types', $harness->viewVars),
      'the redirect terminated the tail: the four type lookups the add form'
      . ' needs never ran');
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
    // Scoped to the factory body. The class this seam hands back is chosen
    // here and nowhere else, so this is the region where a configuration read
    // would matter; scanning the whole controller instead makes an unrelated
    // action that legitimately reads configuration red for a reason that has
    // nothing to do with the substitution point.
    $this->assertFalse(strpos($body, 'Configure::read') !== false,
      'the factory reads no configuration, so no deployment setting can select'
      . ' the class it constructs');
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
