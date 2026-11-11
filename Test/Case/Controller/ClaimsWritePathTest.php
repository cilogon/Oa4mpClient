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
 *  - delete: the harness swaps in a claim model whose delete() returns false
 *    (harnessFailWrites('delete')). An id that does not exist used to serve,
 *    since Model::delete() returns false from its exists() check, but delete()
 *    now refuses an id that is not one of the client's claims before the
 *    server call (cilogon/Oa4mpClient#56), so it no longer reaches that branch.
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

class ClaimsWritePathTest extends Oa4mpClaimsControllerTestCase {

  /**
   * The seeded client graph, its teardown, and the harness/count/source
   * helpers come from Oa4mpClaimsControllerTestCase (Test/lib), which
   * Test/Case/Controller/ClaimsControllerHarnessTest.php shares. Only the
   * fixture tag differs.
   */
  protected function fixtureTagPrefix() {
    return 'oa4mpwritepath';
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
   * failed (the harness makes Model::delete() return false). Before U9 this
   * branch flashed "Claim Deleted".
   */
  public function testDeleteLocalDeleteFailureReportsTheDriftAndTheRepair() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 1;
    $harness->harnessFailWrites('delete');

    $harness->harnessInvoke('delete', array($this->claimId));

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
    $harness->harnessFailWrites('delete');

    $redirect = $harness->harnessInvoke('delete', array($this->claimId));

    $this->assertTrue($harness->harnessStopped, 'the action stopped at a redirect');
    $this->assertEqual(1, $harness->harnessRedirectCount, 'redirect() was called exactly once');
    $this->assertEqual('index', $redirect['action'], 'the target is the claims index');
    $this->assertEqual('oa4mp_client_claims', $redirect['controller'], 'in the claims controller');
    $this->assertEqual($this->clientId, $redirect['clientid'], 'for the same client');
    $this->assertFalse($harness->autoRender, 'no view is rendered for a redirected action');
  }

  // ==========================================================================
  // Claims of another client (cilogon/Oa4mpClient#56)
  //
  // Authorization covers the client in the URL. Each action must then act on
  // that client's own claims only, whatever claim id the route or the posted
  // hidden fields name. The Named-configuration client's stored claim is the
  // other client here: it is the case the issue names, a client whose stored
  // claims are not sent, which a cross-client write would leave out of sync.
  // ==========================================================================

  /**
   * Deleting another client's claim through this client's URL is refused
   * before anything is sent. Before the fix the action sent this client's
   * claims unchanged and then deleted the other client's claim.
   */
  public function testDeleteOfAnotherClientsClaimIsRefusedBeforeAnyServerCall() {
    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('delete', array($this->namedConfigClaimId));

    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before any server call');
    $this->assertEqual(1, $this->namedConfigClaimCount(), 'the other client\'s claim was not deleted');
    $this->assertEqual(1, $this->claimCount(), 'this client\'s claim was not deleted');
    $this->assertTrue($this->flashed($harness, $this->notClientClaimError()),
      'the not-this-client\'s-claim error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the action redirected to the claims index');
    $this->assertEqual('oa4mp_client_claims', $redirect['controller'] ?? null, 'in the claims controller');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the client in the URL');
    $this->assertNotEmpty($harness->harnessLogged, 'the refused id was logged');
  }

  /**
   * Opening the edit form for another client's claim through this client's
   * URL is refused before the synchronization check reaches the server.
   */
  public function testEditFormForAnotherClientsClaimIsRefusedBeforeAnyServerCall() {
    $harness = $this->harness();

    $redirect = $harness->harnessInvoke('edit', array($this->namedConfigClaimId), 'GET');

    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before any server call');
    $this->assertTrue($this->flashed($harness, $this->notClientClaimError()),
      'the not-this-client\'s-claim error was flashed');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the action redirected to the claims index');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the client in the URL');
  }

  /**
   * Posting an edit of another client's claim through this client's URL is
   * refused before anything is sent or saved. Before the fix the post was
   * sent as an unchanged client and then saved over the other client's claim,
   * moving it onto this client.
   */
  public function testEditPostOfAnotherClientsClaimIsRefusedBeforeAnyServerCall() {
    $data = $this->validEditData();
    $data['Oa4mpClientClaim']['id'] = $this->namedConfigClaimId;
    $data['Oa4mpClientClaimConstraint'] = array();

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('edit', array($this->namedConfigClaimId), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before any server call');
    $this->assertTrue($this->namedConfigClaimIsUnchanged(), 'the other client\'s claim is unchanged');
    $this->assertEqual(1, $this->claimNameCount('eppn'), 'this client\'s claim is unchanged');
    $this->assertEqual(0, $this->claimNameCount('eppn_edited'), 'the edit was not written');
    $this->assertTrue($this->flashed($harness, $this->notClientClaimError()),
      'the not-this-client\'s-claim error was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the action redirected to the claims index');
  }

  /**
   * An add whose hidden id names another client's claim adds a new claim to
   * this client. Before the fix the save updated the named claim in place.
   */
  public function testAddWithAForgedClaimIdAddsANewClaimForTheUrlClientOnly() {
    $data = $this->validAddData();
    $data['Oa4mpClientClaim']['id'] = $this->namedConfigClaimId;

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(2, $this->claimCount(), 'a new claim was added to this client');
    $this->assertEqual(1, $this->claimNameCount('writepath_added'), 'the new claim is the posted one');
    $this->assertTrue($this->namedConfigClaimIsUnchanged(), 'the other client\'s claim is unchanged');
    $this->assertEqual(1, $this->namedConfigClaimCount(), 'the other client still has one claim');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.add.flash.success')),
      'success was reported');

    $sent = $this->sentNewClaim($harness);
    $this->assertFalse(array_key_exists('id', $sent), 'the claim sent to the server carries no posted id');
    $this->assertEqual($this->clientId, $sent['client_id'] ?? null,
      'the claim sent to the server is this client\'s');
    $this->assertNotEmpty($harness->harnessLogged, 'the forged id was logged');
  }

  /**
   * An add whose hidden client_id names another client adds the claim to the
   * client in the URL. Before the fix it was saved onto the named client.
   */
  public function testAddWithAForgedClientIdAddsTheClaimToTheUrlClient() {
    $data = $this->validAddData();
    $data['Oa4mpClientClaim']['client_id'] = $this->namedConfigClientId;

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->claimNameCount('writepath_added'), 'the claim was added to this client');
    $this->assertEqual(1, $this->namedConfigClaimCount(), 'nothing was added to the other client');
    $this->assertTrue($this->namedConfigClaimIsUnchanged(), 'the other client\'s claim is unchanged');

    $sent = $this->sentNewClaim($harness);
    $this->assertEqual($this->clientId, $sent['client_id'] ?? null,
      'the claim sent to the server is this client\'s');
    $this->assertNotEmpty($harness->harnessLogged, 'the forged client id was logged');
  }

  /**
   * An add whose hidden constraint id names an existing constraint creates a
   * new constraint. Before the fix the save moved the named constraint, here
   * this client's own seeded one, onto the new claim.
   */
  public function testAddWithAForgedConstraintIdLeavesThatConstraintAlone() {
    $data = $this->validAddData();
    $data['Oa4mpClientClaimConstraint'][0]['id'] = $this->constraintId;

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->claimNameCount('writepath_added'), 'the claim was added');
    $this->assertTrue($this->constraintIs($this->constraintId, $this->claimId, 'eppn'),
      'the named constraint still belongs to its own claim, unchanged');

    // The posted constraint itself is kept, as a new row on the new claim and
    // in what the server was sent; only its forged id is dropped.
    $this->assertEqual(1, $this->countRows($this->fx, 'cm_oa4mp_client_claim_constraints',
      "constraint_value = 'oidcsub' AND claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id = "
      . (int)$this->clientId . " AND claim_name = 'writepath_added')"),
      'the new claim was saved with the posted constraint');

    $sentConstraint = null;
    foreach ($this->sentClaims($harness) as $claim) {
      if (($claim['claim_name'] ?? null) === 'writepath_added') {
        $sentConstraint = $claim['Oa4mpClientClaimConstraint'][0]['constraint_value'] ?? null;
      }
    }
    $this->assertEqual('oidcsub', $sentConstraint, 'the server was sent the posted constraint');
  }

  /**
   * An edit whose hidden constraint id names another claim's constraint
   * leaves that constraint alone, while a constraint id of the edited claim
   * itself still updates that constraint in place.
   */
  public function testEditWithAForgedConstraintIdLeavesThatConstraintUnchanged() {
    $otherConstraintId = $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
      'claim_id' => $this->namedConfigClaimId,
      'constraint_field' => 'type',
      'constraint_value' => 'other'
    ));

    $data = $this->validEditData();
    $data['Oa4mpClientClaimConstraint'][0]['constraint_value'] = 'eppn_new';
    $data['Oa4mpClientClaimConstraint'][1] = array(
      'id' => $otherConstraintId,
      'claim_id' => $this->namedConfigClaimId,
      'constraint_field' => 'type',
      'constraint_value' => 'forged'
    );

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEqual(1, $this->claimNameCount('eppn_edited'), 'the edit was written');
    $this->assertTrue($this->constraintIs($otherConstraintId, $this->namedConfigClaimId, 'other'),
      'the other claim\'s constraint is unchanged');
    $this->assertTrue($this->constraintIs($this->constraintId, $this->claimId, 'eppn_new'),
      'the edited claim\'s own constraint was updated in place');
    $this->assertEqual(2, $this->countRows($this->fx, 'cm_oa4mp_client_claim_constraints',
      'claim_id = ' . (int)$this->claimId), 'the forged constraint was added as a new one');

    $sent = $this->sentClaim($harness, $this->claimId);
    $sentIds = array();
    foreach ($sent['Oa4mpClientClaimConstraint'] ?? array() as $c) {
      $sentIds[] = (string)($c['id'] ?? '');
    }
    $this->assertFalse(in_array((string)$otherConstraintId, $sentIds, true),
      'the constraint sent to the server carries no forged id');
    $this->assertNotEmpty($harness->harnessLogged, 'the forged constraint id was logged');
  }

  /**
   * An edit whose hidden claim id and client id name another client's claim,
   * posted to this client's own claim, edits this client's claim only.
   */
  public function testEditWithForgedClaimAndClientIdsEditsTheRouteClaimOnly() {
    $data = $this->validEditData();
    $data['Oa4mpClientClaim']['id'] = $this->namedConfigClaimId;
    $data['Oa4mpClientClaim']['client_id'] = $this->namedConfigClientId;
    $data['Oa4mpClientClaimConstraint'] = array();

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEqual(1, $this->claimNameCount('eppn_edited'), 'the route\'s claim was edited');
    $this->assertEqual(1, $this->claimCount(), 'in place, on this client');
    $this->assertTrue($this->namedConfigClaimIsUnchanged(), 'the other client\'s claim is unchanged');
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
   * A client that uses a Named configuration never reaches the OA4MP server
   * through add(), nothing is written, and it is sent to its Named
   * Configuration page: the Claims page it used to be sent back to is
   * refused too, so that would loop (cilogon/Oa4mpClient#58).
   */
  public function testNamedConfigClientIsRefusedFromAddBeforeAnyServerCall() {
    $data = $this->validAddData();
    $data['Oa4mpClientClaim']['client_id'] = $this->namedConfigClientId;

    $harness = Oa4mpClaimsControllerHarness::build($this->namedConfigClientId, $this->coId, $data);

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the action before any server call');
    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the guard redirected to the client\'s Named Configuration page');
    $this->assertEqual(0, $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->namedConfigClientId . " AND claim_name = 'writepath_added'"),
      'the posted claim was not written');
    $this->assertTrue($this->flashed($harness, $this->namedConfigRefusal()),
      'the refusal was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** The same refusal on edit(): the posted change is not written. */
  public function testNamedConfigClientIsRefusedFromEditBeforeAnyServerCall() {
    $data = $this->validEditData();
    $data['Oa4mpClientClaim']['id'] = $this->namedConfigClaimId;
    $data['Oa4mpClientClaim']['client_id'] = $this->namedConfigClientId;

    $harness = Oa4mpClaimsControllerHarness::build($this->namedConfigClientId, $this->coId, $data);

    $redirect = $harness->harnessInvoke('edit', array($this->namedConfigClaimId), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the action before any server call');
    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the guard redirected to the client\'s Named Configuration page');
    $this->assertEqual(0, $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      "claim_name = 'eppn_edited' AND client_id = " . (int)$this->namedConfigClientId),
      'the posted change was not written');
    $this->assertTrue($this->flashed($harness, $this->namedConfigRefusal()),
      'the refusal was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** The same refusal on delete(): nothing is sent and no success reported. */
  public function testNamedConfigClientIsRefusedFromDeleteBeforeAnyServerCall() {
    $harness = Oa4mpClaimsControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('delete', array($this->namedConfigClaimId), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the action before any server call');
    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the guard redirected to the client\'s Named Configuration page');
    $this->assertTrue($this->flashed($harness, $this->namedConfigRefusal()),
      'the refusal was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
  }

  /** Opening the add form by direct URL is refused as well. */
  public function testNamedConfigClientAddFormIsRefused() {
    $harness = Oa4mpClaimsControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('add', array(), 'GET');

    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the add form redirected to the client\'s Named Configuration page');
    $this->assertEmpty($harness->harnessServer->calls, 'nothing was sent');
    $this->assertTrue($this->flashed($harness, $this->namedConfigRefusal()),
      'the refusal was flashed');
  }

  /** Opening the edit form by direct URL is refused as well. */
  public function testNamedConfigClientEditFormIsRefused() {
    $harness = Oa4mpClaimsControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('edit', array($this->namedConfigClaimId), 'GET');

    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the edit form redirected to the client\'s Named Configuration page');
    $this->assertEmpty($harness->harnessServer->calls, 'nothing was sent');
    $this->assertTrue($this->flashed($harness, $this->namedConfigRefusal()),
      'the refusal was flashed');
  }

  /**
   * The control: the same add for a client without a Named configuration
   * reaches the server, so it is the guard that refuses the cases above.
   */
  public function testClientWithoutNamedConfigAddReachesTheServer() {
    $harness = $this->harness($this->validAddData());
    $harness->harnessServer->editClientReturn = 1;

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'an ordinary client\'s add was sent to the server');
    $this->assertFalse($this->flashed($harness, $this->namedConfigRefusal()),
      'no refusal for an ordinary client');
  }

  /**
   * The index is refused for a Named-configuration client before the
   * synchronization check, and still renders for an ordinary client.
   */
  public function testIndexIsRefusedForANamedConfigClient() {
    $harness = Oa4mpClaimsControllerHarness::build($this->namedConfigClientId, $this->coId);
    $redirect = $harness->harnessInvoke('index', array(), 'GET');

    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the index redirected to the client\'s Named Configuration page');
    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before the synchronization check');
    $this->assertTrue($this->flashed($harness, $this->namedConfigRefusal()),
      'the refusal was flashed');

    $ordinary = $this->harness();
    $ordinary->harnessInvoke('index', array(), 'GET');

    $this->assertNull($ordinary->harnessRedirect, 'the index renders for an ordinary client');
  }

  /**
   * The claims index offers write controls to every client but a public one:
   * the Add, Edit and Delete controls are left out only for a public client.
   * A Named-configuration client never reaches the index, so it no longer
   * reads the Named configuration. The thin runner cannot render the index,
   * so it is checked in source. The index builds exactly these four links,
   * so a new one fails here until it is checked too.
   */
  public function testIndexWriteControlsAreTiedToThePublicClientFlag() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientClaims' . DS . 'index.ctp';
    $this->assertTrue(is_readable($path), "the claims index exists at $path");
    $source = file_get_contents($path);

    $this->assertFalse(strpos($source, 'named_config') !== false,
      'the index no longer reads the Named configuration');

    $this->assertTrue((bool)preg_match(
      '/if\(\$permissions\[\'add\'\] && !\$is_public_client\) \{\s*'
      . '\$params\[\'topLinks\'\]\[\] = \$this->Html->link\(/', $source),
      'the Add link is left out for a public client');
    $this->assertTrue((bool)preg_match(
      '/if\(!\$is_public_client && \$permissions\[\'edit\'\]\) \{\s*'
      . 'print \$this->Html->link\(/', $source),
      'the Edit button is left out for a public client');
    $this->assertTrue((bool)preg_match(
      '/if\(!\$is_public_client && \$permissions\[\'delete\'\]\) \{\s*'
      . 'print \'<button type="button" class="deletebutton"/', $source),
      'the Delete button is left out for a public client');

    $this->assertEqual(4, preg_match_all('/->Html->(link|url)\(/', $source),
      'the index builds the Add, claim name, Edit and Delete links only');
    // One control of each write kind, so a second one placed outside the
    // guards above fails here.
    foreach (array('addbutton', 'editbutton', 'deletebutton') as $class) {
      $this->assertEqual(1, substr_count($source, $class),
        "the index has exactly one $class");
    }
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
    $deleteFail->harnessFailWrites('delete');
    $deleteFail->harnessInvoke('delete', array($this->claimId));
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

      // Scoped to this branch's own flash statement rather than scanned over
      // the whole controller. What the check is after is that these three
      // messages reach the user through the lang file: a double-quoted literal
      // elsewhere in the file is a different concern, and scanning for one
      // makes an unrelated edit anywhere in the controller red for no reason
      // this test can explain.
      $statement = '$this->Flash->set(_txt(\'' . $key . '\'), array(\'key\' => \'error\'));';
      $this->assertEqual(1, substr_count($source, $statement),
        "the $key branch flashes the key through _txt() under the error key,"
        . " as a single-quoted literal: $statement");
    }

    // Each message names the repair on the OA4MP server -- the side that
    // still holds the half that landed -- and says that retrying the action
    // in Registry is not the way back. It cannot be: the drift these messages
    // report is exactly what the synchronization guard blocks, so every
    // further change to this client, the retry included, is refused until the
    // two sides agree again. The delete message used to end "Delete the claim
    // again to bring them back into agreement", which was advice that could
    // not work.
    $add = $this->pluginText('pl.oa4mp_client_claim.er.add.save');
    $this->assertContains('including adding the claim again', $add,
      'the add message names the blocked retry rather than offering it');
    $this->assertContains('remove the claim from the OA4MP server', $add,
      'the add repair is named, and it is the server-side one');

    $edit = $this->pluginText('pl.oa4mp_client_claim.er.edit.save');
    $this->assertContains('including editing the claim again', $edit,
      'the edit message names the blocked retry rather than offering it');
    $this->assertContains('restore the claim on the OA4MP server', $edit,
      'the edit repair is named, and it is the server-side one');

    $delete = $this->pluginText('pl.oa4mp_client_claim.er.delete.remove');
    $this->assertContains('including deleting the claim again', $delete,
      'the delete message names the blocked retry rather than offering it');
    $this->assertContains('restore the claim on the OA4MP server', $delete,
      'the delete repair is named, and it is the server-side one: Registry'
      . ' still holds the claim, so the values to restore it with are there');
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
  // The reserved copy of the DynamoDB key claim (cilogon/Oa4mpClient#67)
  //
  // When a client configures a claim named like its DynamoDB key claim, the
  // DynamoDB claims script keeps the key claim's original value in a claim
  // named cilogon_<key claim> and finds the user by that copy. The Claims tab
  // refuses a claim with that name before anything is sent, so a configured
  // claim can never be mistaken for the copy. The key claim is the
  // partition_key_claim_name of the configuration that applies to the
  // client: its own row, or else the admin client's default (sub here).
  // ==========================================================================

  /** Adding a claim named cilogon_sub is refused before anything is sent. */
  public function testAddOfTheReservedNameIsRefusedBeforeAnyServerCall() {
    $harness = $this->harness($this->addDataNamed('cilogon_sub'));
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before any server call');
    $this->assertEqual(1, $this->claimCount(), 'no claim was added');
    $this->assertEqual(0, $this->claimNameCount('cilogon_sub'), 'the reserved name was not saved');
    $this->assertTrue($this->flashed($harness, $this->reservedNameRefusal('cilogon_sub')),
      'the reserved-name error, naming the claim, was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the action redirected to the claims index');
    $this->assertEqual('oa4mp_client_claims', $redirect['controller'] ?? null, 'in the claims controller');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the client in the URL');
  }

  /** Renaming a stored claim to cilogon_sub is refused before anything is sent. */
  public function testEditRenamingToTheReservedNameIsRefusedBeforeAnyServerCall() {
    $data = $this->validEditData();
    $data['Oa4mpClientClaim']['claim_name'] = 'cilogon_sub';

    $harness = $this->harness($data);
    $harness->harnessServer->editClientReturn = 1;

    $redirect = $harness->harnessInvoke('edit', array($this->claimId), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before any server call');
    $this->assertEqual(1, $this->claimNameCount('eppn'), 'the stored claim is unchanged');
    $this->assertEqual(0, $this->claimNameCount('cilogon_sub'), 'the reserved name was not saved');
    $this->assertTrue($this->flashed($harness, $this->reservedNameRefusal('cilogon_sub')),
      'the reserved-name error, naming the claim, was flashed');
    $this->assertFalse($this->flashedAnySuccess($harness), 'no success was reported');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the action redirected to the claims index');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the client in the URL');
  }

  /**
   * A client with its own DynamoDB configuration, keyed on uid, reserves
   * cilogon_uid and not the default's cilogon_sub. The two configurations
   * differ in every value, so this shows which one was read.
   */
  public function testPerClientDynamoConfigKeyClaimDecidesTheReservedName() {
    $this->seedPerClientDynamoConfig();

    $refused = $this->harness($this->addDataNamed('cilogon_uid'));
    $refused->harnessServer->editClientReturn = 1;
    $refused->harnessInvoke('add', array(), 'POST');

    $this->assertEmpty($refused->harnessServer->calls,
      'cilogon_uid was refused before any server call');
    $this->assertEqual(0, $this->claimNameCount('cilogon_uid'), 'cilogon_uid was not saved');
    $this->assertTrue($this->flashed($refused, $this->reservedNameRefusal('cilogon_uid')),
      'the reserved-name error names cilogon_uid');

    $accepted = $this->harness($this->addDataNamed('cilogon_sub'));
    $accepted->harnessServer->editClientReturn = 1;
    $accepted->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(array('oa4mpEditClient'), $accepted->harnessServer->callNames(),
      'cilogon_sub was sent to the server, since the default\'s key claim does not apply');
    $this->assertEqual('cilogon_sub', $this->sentNewClaim($accepted)['claim_name'] ?? null,
      'the claim sent is the posted one');
    $this->assertEqual(1, $this->claimNameCount('cilogon_sub'), 'cilogon_sub was saved');
  }

  /**
   * A per-client row whose every column is null is not a configuration, so
   * the admin client's default applies and cilogon_sub is refused.
   */
  public function testAllNullPerClientRowLeavesTheDefaultKeyClaimInForce() {
    $this->seedPerClientDynamoConfig(array());

    $harness = $this->harness($this->addDataNamed('cilogon_sub'));
    $harness->harnessServer->editClientReturn = 1;
    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before any server call');
    $this->assertEqual(0, $this->claimNameCount('cilogon_sub'), 'the reserved name was not saved');
    $this->assertTrue($this->flashed($harness, $this->reservedNameRefusal('cilogon_sub')),
      'the reserved-name error names the default\'s copy claim');
  }

  /** With no DynamoDB configuration at all, no name is reserved. */
  public function testNoDynamoConfigReservesNoName() {
    $this->removeDefaultDynamoConfig();

    $harness = $this->harness($this->addDataNamed('cilogon_sub'));
    $harness->harnessServer->editClientReturn = 1;
    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the claim was sent to the server as before');
    $this->assertEqual(1, $this->claimNameCount('cilogon_sub'), 'the claim was saved');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.add.flash.success')),
      'success was reported');
  }

  /**
   * The comparison is exact and case-sensitive, as the claims script's is:
   * names that only resemble the reserved one are accepted.
   */
  public function testNearMissesOfTheReservedNameAreAccepted() {
    foreach (array('CILOGON_SUB', 'cilogon_sub2') as $name) {
      $harness = $this->harness($this->addDataNamed($name));
      $harness->harnessServer->editClientReturn = 1;
      $harness->harnessInvoke('add', array(), 'POST');

      $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
        "$name was sent to the server");
      $this->assertEqual(1, $this->claimNameCount($name), "$name was saved");
      $this->assertFalse($this->flashed($harness, $this->reservedNameRefusal('cilogon_sub')),
        "$name was not refused as reserved");
    }
  }

  /**
   * A claim named like the key claim itself is the collision the claims
   * script handles, so it is accepted and sent as before.
   */
  public function testAClaimNamedLikeTheKeyClaimIsAccepted() {
    $harness = $this->harness($this->addDataNamed('sub'));
    $harness->harnessServer->editClientReturn = 1;
    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the sub claim was sent to the server');
    $this->assertEqual(1, $this->claimNameCount('sub'), 'the sub claim was saved');
  }

  /**
   * A claim already stored with the reserved name can still be deleted, so
   * an admin can remove one saved before the name was reserved.
   */
  public function testDeleteOfAStoredReservedNameClaimIsAllowed() {
    $reservedId = $this->fx->insert('cm_oa4mp_client_claims', array(
      'client_id' => $this->clientId,
      'claim_name' => 'cilogon_sub',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string'
    ));

    $harness = $this->harness();
    $harness->harnessServer->editClientReturn = 1;
    $harness->harnessInvoke('delete', array($reservedId));

    $this->assertEqual(array('oa4mpEditClient'), $harness->harnessServer->callNames(),
      'the delete was sent to the server');
    $this->assertEqual(0, $this->claimNameCount('cilogon_sub'), 'the claim was deleted');
    $this->assertTrue($this->flashed($harness, _txt('pl.oa4mp_client_claim.delete.flash.success')),
      'success was reported');
  }

  /**
   * The predicate behind the refusal: cilogon_ plus the key claim of the
   * configuration that applies, or null when none applies or it names no
   * key claim.
   */
  public function testReservedClaimNamePredicate() {
    $server = ClassRegistry::init('Oa4mpClient.Oa4mpClientOa4mpServer');

    $default = array('aws_region' => 'us-east-1', 'partition_key_claim_name' => 'sub');
    $perClient = array('aws_region' => 'us-west-2', 'partition_key_claim_name' => 'uid');
    $phantom = array('aws_region' => null, 'partition_key_claim_name' => null);

    $this->assertEqual('cilogon_uid', $server->reservedClaimName(array(
      'Oa4mpClientDynamoConfig' => $perClient,
      'Oa4mpClientCoAdminClient' => array('DefaultDynamoConfig' => $default)
    )), 'a per-client configuration decides the name');

    $this->assertEqual('cilogon_sub', $server->reservedClaimName(array(
      'Oa4mpClientDynamoConfig' => $phantom,
      'Oa4mpClientCoAdminClient' => array('DefaultDynamoConfig' => $default)
    )), 'without a per-client configuration the default decides the name');

    $this->assertNull($server->reservedClaimName(array(
      'Oa4mpClientDynamoConfig' => $phantom,
      'Oa4mpClientCoAdminClient' => array('DefaultDynamoConfig' => $phantom)
    )), 'no configuration reserves no name');

    $this->assertNull($server->reservedClaimName(array(
      'Oa4mpClientCoAdminClient' => array()
    )), 'a client read without either configuration reserves no name');

    $this->assertNull($server->reservedClaimName(array(
      'Oa4mpClientDynamoConfig' => array('aws_region' => 'us-west-2', 'partition_key_claim_name' => ''),
      'Oa4mpClientCoAdminClient' => array('DefaultDynamoConfig' => $default)
    )), 'a configuration that names no key claim reserves no name');
  }

  // ==========================================================================
  // Helpers (must not begin with "test": the runner would call them)
  // ==========================================================================

  /** A well-formed add() body for a claim with the given name. */
  private function addDataNamed($claimName) {
    $data = $this->validAddData();
    $data['Oa4mpClientClaim']['claim_name'] = $claimName;
    return $data;
  }

  /** The error flashed when a claim is given the reserved name $name. */
  private function reservedNameRefusal($name) {
    return _txt('pl.oa4mp_client_claim.er.reserved_name', array($name));
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

  /** Claims on the seeded client with the given claim_name. */
  private function claimNameCount($claimName) {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->clientId . " AND claim_name = '" . $claimName . "'");
  }

  /** Claims stored on the Named-configuration client. */
  private function namedConfigClaimCount() {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->namedConfigClientId);
  }

  /** The error flashed for a claim id that is not one of the URL client's claims. */
  private function notClientClaimError() {
    return _txt('pl.oa4mp_client_claim.er.not_client_claim');
  }

  /** Whether the Named-configuration client's seeded claim is still as seeded. */
  private function namedConfigClaimIsUnchanged() {
    return 1 === (int)$this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'id = ' . (int)$this->namedConfigClaimId
      . ' AND client_id = ' . (int)$this->namedConfigClientId
      . " AND claim_name = 'eppn'");
  }

  /** Whether a constraint belongs to the given claim with the given value. */
  private function constraintIs($constraintId, $claimId, $value) {
    return 1 === (int)$this->countRows($this->fx, 'cm_oa4mp_client_claim_constraints',
      'id = ' . (int)$constraintId . ' AND claim_id = ' . (int)$claimId
      . " AND constraint_value = '" . $value . "'");
  }

  /** The claim list the first oa4mpEditClient() call sent. */
  private function sentClaims($harness) {
    foreach ($harness->harnessServer->calls as $call) {
      if ($call['method'] === 'oa4mpEditClient') {
        return array_values($call['data']['Oa4mpClientClaim'] ?? array());
      }
    }
    $this->assertTrue(false, 'the action sent an edit to the server');
    return array();
  }

  /** The claim add() appended to the list it sent. */
  private function sentNewClaim($harness) {
    $claims = $this->sentClaims($harness);
    return empty($claims) ? array() : $claims[count($claims) - 1];
  }

  /** The sent claim with the given id. */
  private function sentClaim($harness, $claimId) {
    foreach ($this->sentClaims($harness) as $claim) {
      if ((string)($claim['id'] ?? '') === (string)$claimId) {
        return $claim;
      }
    }
    $this->assertTrue(false, "the claim $claimId was sent to the server");
    return array();
  }

  /** The refusal the guard flashes, naming the fixture's configuration. */
  private function namedConfigRefusal() {
    return _txt('pl.oa4mp_client_co_named_config.er.tab_replaced', array(self::NAMED_CONFIG_NAME));
  }

  /** Whether $redirect is the Named-configuration client's Named Configuration page. */
  private function isNamedConfigPage($redirect) {
    return ($redirect['controller'] ?? null) === 'oa4mp_client_co_named_configs'
      && ($redirect['action'] ?? null) === 'manage'
      && (int)($redirect['clientid'] ?? 0) === (int)$this->namedConfigClientId;
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
}
