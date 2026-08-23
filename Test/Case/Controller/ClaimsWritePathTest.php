<?php
/**
 * Regression coverage for the claims controller write path.
 *
 * Every claims action (add, edit, delete) calls the OA4MP server first and
 * writes locally second. Before U9 the local write's result was assigned to
 * $ret and never read, so an accepted server edit followed by a failed local
 * write reported "Claim Added" / "Claim Updated" / "Claim Deleted" while the
 * plugin and the server had just been driven out of agreement -- and the
 * synchronization guard then blocked every later edit of that client.
 *
 * These tests drive each action through the U8 harness
 * (Test/lib/Oa4mpClaimsControllerHarness.php) with a fake OA4MP server, and
 * assert, for every branch, both what was written locally and what the user
 * was told. Two rules the local write is forced to fail by:
 *
 *  - saveAssociated (add, edit): a blank claim_name. The model validates it
 *    notBlank with allowEmpty false, and saveAssociated is atomic, so it
 *    returns a plain boolean false and rolls back.
 *  - delete: an id that does not exist. Model::delete() returns false from its
 *    exists() check before touching the database.
 *
 * A bogus foreign key is deliberately not used: Postgres raises a
 * PDOException that propagates as a test error rather than the false return
 * the controller used to discard.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U9 and
 * docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md
 * (prevention rule 1: the critical persistence pair, here the server edit and
 * the local write, must both be checked at the write site).
 */

App::uses('ConnectionManager', 'Model');

class ClaimsWritePathTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;
  private $clientId;
  private $publicClientId;
  private $claimId;
  private $constraintId;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpwritepath');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag);

    // admin() reads DefaultDynamoConfig, so seed one and exercise the real read.
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

    $this->clientId = $this->fx->oidcClient($this->adminId, 'writepath-' . $tag);
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

    $this->constraintId = $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
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

  // ==========================================================================
  // add()
  // ==========================================================================

  /**
   * The OA4MP server reports the plugin and its own representation out of
   * sync (verdict 2). Nothing is written locally and the user is told so.
   */
  public function testAddOutOfSyncVerdictLeavesNoClaimAndReportsTheError() {
    $harness = $this->harness($this->validAddData());
    $harness->harnessServer->editClientReturn = 2;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->claimCount(), 'no claim was added');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_co_oidc_client.er.bad_client')),
      'the out-of-sync error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /**
   * The OA4MP server rejects the edit (verdict 0). Nothing is written locally
   * and the user is told so.
   */
  public function testAddServerErrorVerdictLeavesNoClaimAndReportsTheError() {
    $harness = $this->harness($this->validAddData());
    $harness->harnessServer->editClientReturn = 0;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->claimCount(), 'no claim was added');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_co_admin_client.er.edit_error')),
      'the server error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** Server accepts, local save succeeds: the claim lands and success is reported. */
  public function testAddSuccessSavesTheClaimAndReportsSuccess() {
    $harness = $this->harness($this->validAddData());
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(2, $this->claimCount(), 'the new claim was saved');
    $this->assertEqual(1, $this->claimNameCount('writepath_added'), 'the posted claim is the saved one');
    $this->assertEqual('index', $redirect['action'], 'the action redirected to the claims index');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.add.flash.success')),
      'success was reported');
  }

  /**
   * The regression. The server accepted the new claim and the local save then
   * failed, so the plugin and the server now disagree. Before U9 this branch
   * flashed "Claim Added" and redirected as if nothing had gone wrong.
   */
  public function testAddLocalSaveFailureReportsTheDriftAndTheRepair() {
    $harness = $this->harness($this->invalidAddData());
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->claimCount(), 'the local save wrote nothing');
    $this->assertFalse($this->flashedAnySuccess($harness),
      'the action did not report success for a save that failed');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.er.add.save')),
      'the failure, the resulting drift and the repair were reported');
    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the server was edited and the action stopped there. It must not fall'
      . ' through to the GET logic: that tail re-verifies, finds the drift this'
      . ' failure just created, and flashes the generic out-of-sync message'
      . ' under the same error key, overwriting the specific one above');
  }

  // ==========================================================================
  // edit()
  // ==========================================================================

  /** Verdict 2: the claim is left as it was and the out-of-sync error is reported. */
  public function testEditOutOfSyncVerdictLeavesTheClaimUnchangedAndReportsTheError() {
    $harness = $this->harness($this->validEditData());
    $harness->harnessServer->editClientReturn = 2;

    $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEqual(1, $this->claimNameCount('eppn'), 'the claim is unchanged');
    $this->assertEqual(0, $this->claimNameCount('eppn_edited'), 'the edit was not written');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_co_oidc_client.er.bad_client')),
      'the out-of-sync error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** Verdict 0: the claim is left as it was and the server error is reported. */
  public function testEditServerErrorVerdictLeavesTheClaimUnchangedAndReportsTheError() {
    $harness = $this->harness($this->validEditData());
    $harness->harnessServer->editClientReturn = 0;

    $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEqual(1, $this->claimNameCount('eppn'), 'the claim is unchanged');
    $this->assertEqual(0, $this->claimNameCount('eppn_edited'), 'the edit was not written');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_co_admin_client.er.edit_error')),
      'the server error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** Server accepts, local save succeeds: the edit lands and success is reported. */
  public function testEditSuccessSavesTheClaimAndReportsSuccess() {
    $harness = $this->harness($this->validEditData());
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEqual(1, $this->claimCount(), 'the edit updated in place rather than inserting');
    $this->assertEqual(1, $this->claimNameCount('eppn_edited'), 'the edit was written');
    $this->assertEqual('index', $redirect['action'], 'the action redirected to the claims index');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.edit.flash.success')),
      'success was reported');
  }

  /**
   * The regression. The server accepted the edited claim and the local save
   * then failed. Before U9 this branch flashed "Claim Updated".
   */
  public function testEditLocalSaveFailureReportsTheDriftAndTheRepair() {
    $harness = $this->harness($this->invalidEditData());
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEqual(1, $this->claimNameCount('eppn'), 'the local save wrote nothing');
    $this->assertFalse($this->flashedAnySuccess($harness),
      'the action did not report success for a save that failed');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.er.edit.save')),
      'the failure, the resulting drift and the repair were reported');
    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the server was edited and the action stopped there. It must not fall'
      . ' through to the GET logic: that tail re-verifies, finds the drift this'
      . ' failure just created, and flashes the generic out-of-sync message'
      . ' under the same error key, overwriting the specific one above');
  }

  // ==========================================================================
  // delete()
  // ==========================================================================

  /** Verdict 2: the claim stays and the out-of-sync error is reported. */
  public function testDeleteOutOfSyncVerdictLeavesTheClaimAndReportsTheError() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 2;

    $harness->harnessInvoke('delete', array($this->claimId));

    $this->assertEqual(1, $this->claimCount(), 'the claim was not deleted');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_co_oidc_client.er.bad_client')),
      'the out-of-sync error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** Verdict 0: the claim stays and the server error is reported. */
  public function testDeleteServerErrorVerdictLeavesTheClaimAndReportsTheError() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 0;

    $harness->harnessInvoke('delete', array($this->claimId));

    $this->assertEqual(1, $this->claimCount(), 'the claim was not deleted');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_co_admin_client.er.edit_error')),
      'the server error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** Server accepts, local delete succeeds: the claim goes and success is reported. */
  public function testDeleteSuccessRemovesTheClaimAndReportsSuccess() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('delete', array($this->claimId));

    $this->assertEqual(0, $this->claimCount(), 'the claim was deleted');
    $this->assertEqual('index', $redirect['action'], 'the action redirected to the claims index');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.delete.flash.success')),
      'success was reported');
  }

  /**
   * The regression. The server dropped the claim and the local delete then
   * failed (Model::delete() returns false for an id that does not exist).
   * Before U9 this branch flashed "Claim Deleted".
   */
  public function testDeleteLocalDeleteFailureReportsTheDriftAndTheRepair() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('delete', array($this->unusedClaimId()));

    $this->assertEqual(1, $this->claimCount(), 'the local delete removed nothing');
    $this->assertFalse($this->flashedAnySuccess($harness),
      'the action did not report success for a delete that failed');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.er.delete.remove')),
      'the failure, the resulting drift and the repair were reported');
    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the server was edited exactly once');
  }

  /**
   * delete()'s failure branch must redirect, not fall through the way its
   * sibling error branches do. There is no delete view for this controller --
   * not in the plugin and not in Registry core -- so a fall-through would
   * raise a missing-view error in place of the message above.
   */
  public function testDeleteLocalDeleteFailureRedirectsInsteadOfSeekingAView() {
    foreach ($this->deleteViewCandidates() as $candidate) {
      $this->assertFalse(file_exists($candidate),
        "there is no delete view at $candidate, so falling through would seek a view that does not exist");
    }

    // The sibling actions do have views, which is why the fall-through shape
    // is available to them and not to delete().
    $pluginViews = App::pluginPath('Oa4mpClient') . 'View' . DS . 'Oa4mpClientClaims' . DS;
    $this->assertTrue(file_exists($pluginViews . 'add.ctp'), 'add() has a view to fall through to');
    $this->assertTrue(file_exists($pluginViews . 'edit.ctp'), 'edit() has a view to fall through to');

    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('delete', array($this->unusedClaimId()));

    $this->assertTrue($harness->harnessStopped, 'the action stopped at a redirect');
    $this->assertEqual(1, $harness->harnessRedirectCount, 'redirect() was called exactly once');
    $this->assertEqual('index', $redirect['action'], 'the target is the claims index');
    $this->assertEqual('oa4mp_client_claims', $redirect['controller'], 'in the claims controller');
    $this->assertEqual($this->clientId, $redirect['clientid'], 'for the same client');
    $this->assertFalse($harness->autoRender, 'no view is rendered for a redirected action');
  }

  // ==========================================================================
  // Guards and cross-branch invariants
  // ==========================================================================

  /** A public client never reaches the OA4MP server through add(). */
  public function testPublicClientIsBlockedFromAddBeforeAnyServerCall() {
    $harness = Oa4mpClaimsControllerHarness::build($this->publicClientId, $this->coId,
      $this->validAddData());
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the action before any server call');
    $this->assertEqual('index', $redirect['action'], 'the guard redirected to the claims index');
    $this->assertEqual(1, $this->claimCount(), 'nothing was written');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.er.public_client')),
      'the public-client error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** A public client never reaches the OA4MP server through edit(). */
  public function testPublicClientIsBlockedFromEditBeforeAnyServerCall() {
    $harness = Oa4mpClaimsControllerHarness::build($this->publicClientId, $this->coId,
      $this->validEditData());
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the action before any server call');
    $this->assertEqual('index', $redirect['action'], 'the guard redirected to the claims index');
    $this->assertEqual(1, $this->claimNameCount('eppn'), 'the claim is unchanged');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.er.public_client')),
      'the public-client error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /**
   * Every failure branch of every action, in one place: none of them may
   * report success. This is the invariant the discarded write result broke.
   */
  public function testNoFailureBranchSetsTheSuccessFlash() {
    $branches = array();

    foreach (array(0, 2) as $verdict) {
      $add = $this->harness($this->validAddData());
      $add->harnessServer->editClientReturn = $verdict;
      $add->harnessInvoke('add', array(), 'POST');
      $branches["add server verdict $verdict"] = $add;

      $edit = $this->harness($this->validEditData());
      $edit->harnessServer->editClientReturn = $verdict;
      $edit->harnessInvoke('edit', array($this->claimId), 'POST');
      $branches["edit server verdict $verdict"] = $edit;

      $delete = $this->harness();
      $delete->harnessServer->editClientReturn = $verdict;
      $delete->harnessInvoke('delete', array($this->claimId));
      $branches["delete server verdict $verdict"] = $delete;
    }

    $addFail = $this->harness($this->invalidAddData());
    $addFail->harnessServer->editClientReturn = 1;
    $addFail->harnessInvoke('add', array(), 'POST');
    $branches['add local save failure'] = $addFail;

    $editFail = $this->harness($this->invalidEditData());
    $editFail->harnessServer->editClientReturn = 1;
    $editFail->harnessInvoke('edit', array($this->claimId), 'POST');
    $branches['edit local save failure'] = $editFail;

    $deleteFail = $this->harness();
    $deleteFail->harnessServer->editClientReturn = 1;
    $deleteFail->harnessInvoke('delete', array($this->unusedClaimId()));
    $branches['delete local delete failure'] = $deleteFail;

    $publicAdd = Oa4mpClaimsControllerHarness::build($this->publicClientId, $this->coId,
      $this->validAddData());
    $publicAdd->harnessInvoke('add', array(), 'POST');
    $branches['add public client'] = $publicAdd;

    foreach ($branches as $name => $harness) {
      $this->assertFalse($this->flashedAnySuccess($harness),
        "the $name branch reported success");
      $this->assertNotEmpty($harness->Flash->messages, "the $name branch said nothing at all");
    }

    // Nine failure branches and the claim is exactly as it was seeded.
    $this->assertEqual(1, $this->claimCount(), 'no failure branch wrote anything');
    $this->assertEqual(1, $this->claimNameCount('eppn'), 'the seeded claim is untouched');
  }

  /**
   * Each new failure message is a lang key that resolves, and each says what
   * broke, that the two representations now disagree, and what to do about
   * it. A missing key surfaces as the key itself, so resolution is asserted
   * rather than assumed.
   *
   * The plugin's lang file is loaded by AppController::beforeFilter() in
   * production, which the console runner never reaches, so the texts are
   * bootstrapped and the globals restored around the check.
   */
  public function testFailureStringsResolveThroughLang() {
    $keys = array(
      'pl.oa4mp_client_claim.er.add.save',
      'pl.oa4mp_client_claim.er.edit.save',
      'pl.oa4mp_client_claim.er.delete.remove'
    );

    $source = $this->controllerSource();

    foreach ($keys as $key) {
      $text = $this->pluginText($key);

      $this->assertFalse($text === $key, "the lang file defines $key");
      $this->assertNotEmpty($text, "$key resolves to a non-empty string");
      $this->assertContains('out of sync', $text, "$key names the resulting drift");
      $this->assertContains('OA4MP server', $text, "$key names both sides of the drift");
      $this->assertContains('Registry', $text, "$key names both sides of the drift");

      $this->assertEqual(1, substr_count($source, "_txt('" . $key . "')"),
        "the controller flashes $key through _txt() exactly once");
      $this->assertEqual(0, substr_count($source, '$this->Flash->set("'),
        'no flash message is a double-quoted literal in the controller');
    }

    // Each repair sentence names the action that undoes the half that landed.
    $this->assertContains('remove the claim from the OA4MP server',
      $this->pluginText('pl.oa4mp_client_claim.er.add.save'), 'the add repair is named');
    $this->assertContains('restore the claim on the OA4MP server',
      $this->pluginText('pl.oa4mp_client_claim.er.edit.save'), 'the edit repair is named');
    $this->assertContains('Delete the claim again',
      $this->pluginText('pl.oa4mp_client_claim.er.delete.remove'), 'the delete repair is named');
  }

  /**
   * Teardown removes every claim row this file's fixtures and driven actions
   * created. Checked inside one database session with a throwaway fixture
   * set, because Test/run.sh tears the volume down on exit and so cannot show
   * this across two invocations.
   */
  public function testTeardownLeavesNoClaimRowsBehind() {
    $scratch = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpwritepathteardown');

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
    $constraintId = $scratch->insert('cm_oa4mp_client_claim_constraints', array(
      'claim_id' => $claimId,
      'constraint_field' => 'type',
      'constraint_value' => 'scratch'
    ));

    $this->assertEqual(1, $this->countRows($scratch, 'cm_oa4mp_client_claims',
      'id = ' . (int)$claimId), 'the scratch claim was seeded');

    $scratch->cleanup(array(
      'cm_oa4mp_client_claim_constraints' => 'claim_id = ' . (int)$claimId
    ));

    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_claim_constraints',
      'id = ' . (int)$constraintId), 'the constraint is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_claims',
      'id = ' . (int)$claimId), 'the claim is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_co_oidc_clients',
      'id = ' . (int)$clientId), 'the OIDC client is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_oa4mp_client_co_admin_clients',
      'id = ' . (int)$adminId), 'the admin client is gone');
    $this->assertEqual(0, $this->countRows($scratch, 'cm_cos', 'id = ' . (int)$coId),
      'the CO is gone');
  }

  // ==========================================================================
  // Helpers (must not begin with "test": the runner would call them)
  // ==========================================================================

  /** A harness pointed at the ordinary (non-public) seeded client. */
  private function harness($data = array()) {
    return Oa4mpClaimsControllerHarness::build($this->clientId, $this->coId, $data);
  }

  /** A well-formed add() body. */
  private function validAddData() {
    return array(
      'Oa4mpClientClaim' => array(
        'client_id' => $this->clientId,
        'claim_name' => 'writepath_added',
        'source_model' => 'Identifier',
        'source_model_claim_value_field' => 'identifier',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string'
      ),
      'Oa4mpClientClaimConstraint' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'oidcsub')
      )
    );
  }

  /**
   * An add() body the model refuses.
   *
   * claim_name is blank rather than absent. The model declares
   * 'required' => 'true' -- the string, not the boolean -- and Cake compares
   * that with ===, so an absent key is not caught at all. A present but blank
   * value trips notBlank with allowEmpty false, on create and on update
   * alike.
   */
  private function invalidAddData() {
    $data = $this->validAddData();
    $data['Oa4mpClientClaim']['claim_name'] = '';
    return $data;
  }

  /** A well-formed edit() body for the seeded claim. */
  private function validEditData() {
    return array(
      'Oa4mpClientClaim' => array(
        'id' => $this->claimId,
        'client_id' => $this->clientId,
        'claim_name' => 'eppn_edited',
        'source_model' => 'Identifier',
        'source_model_claim_value_field' => 'identifier',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string'
      ),
      'Oa4mpClientClaimConstraint' => array(
        array(
          'id' => $this->constraintId,
          'claim_id' => $this->claimId,
          'constraint_field' => 'type',
          'constraint_value' => 'eppn'
        )
      )
    );
  }

  /** An edit() body the model refuses; see invalidAddData(). */
  private function invalidEditData() {
    $data = $this->validEditData();
    $data['Oa4mpClientClaim']['claim_name'] = '';
    return $data;
  }

  /** Claims currently attached to the seeded client. */
  private function claimCount() {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->clientId);
  }

  /** Claims on the seeded client with the given claim_name. */
  private function claimNameCount($claimName) {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->clientId . " AND claim_name = '" . $claimName . "'");
  }

  /**
   * Count rows, having first dropped CakePHP's in-memory query cache.
   *
   * DboSource::fetchAll() caches every SELECT by its exact SQL text and
   * nothing invalidates that cache on a write, so asking the same count
   * question twice around a write returns the first answer both times.
   */
  private function countRows($fx, $table, $where) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $fx->count($table, $where);
  }

  /** A claim id that is certain not to exist, so Model::delete() returns false. */
  private function unusedClaimId() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return (int)$this->fx->scalar('SELECT COALESCE(MAX(id), 0) AS m FROM cm_oa4mp_client_claims') + 1000;
  }

  /** Whether the harness recorded a flash with exactly this message. */
  private function flashed($harness, $message) {
    foreach ($harness->Flash->messages as $flash) {
      if ((string)$flash['message'] === (string)$message) {
        return true;
      }
    }
    return false;
  }

  /** Whether the harness recorded any of the three claim success messages. */
  private function flashedAnySuccess($harness) {
    $successes = array(
      _txt('pl.oa4mp_client_claim.add.flash.success'),
      _txt('pl.oa4mp_client_claim.edit.flash.success'),
      _txt('pl.oa4mp_client_claim.delete.flash.success')
    );

    foreach ($harness->Flash->messages as $flash) {
      if (in_array((string)$flash['message'], $successes, true)) {
        return true;
      }
      if (isset($flash['options']['key']) && $flash['options']['key'] === 'success') {
        return true;
      }
    }
    return false;
  }

  /**
   * Resolve a plugin lang key with the plugin texts loaded, then put the
   * global text tables back the way they were so no later test sees a
   * different _txt().
   */
  private function pluginText($key) {
    $texts = $GLOBALS['cm_texts'];
    $orig = isset($GLOBALS['cm_texts_orig']) ? $GLOBALS['cm_texts_orig'] : null;

    _bootstrap_plugin_txt();
    $text = _txt($key);

    $GLOBALS['cm_texts'] = $texts;
    if ($orig !== null) {
      $GLOBALS['cm_texts_orig'] = $orig;
    }

    return $text;
  }

  /** Every path a rendered delete view could be found on. */
  private function deleteViewCandidates() {
    $candidates = array(
      App::pluginPath('Oa4mpClient') . 'View' . DS . 'Oa4mpClientClaims' . DS . 'delete.ctp'
    );

    foreach (App::path('View') as $viewPath) {
      $candidates[] = $viewPath . 'Oa4mpClientClaims' . DS . 'delete.ctp';
    }

    return $candidates;
  }

  /** The claims controller's source, for the lang-key locks above. */
  private function controllerSource() {
    $path = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientClaimsController.php';
    $this->assertTrue(is_readable($path), "the claims controller exists at $path");
    return file_get_contents($path);
  }
}
