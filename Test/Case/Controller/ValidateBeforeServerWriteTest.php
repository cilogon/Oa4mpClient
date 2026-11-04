<?php
/**
 * Regression coverage for the pages that sent a change to the OA4MP server
 * before checking it against the Registry's validation rules.
 *
 * Each of these actions used to call the server first and save second. When
 * the save then failed validation, its result was never read: the server held
 * a value the plugin never stored, the page reported success, and the
 * synchronization guard blocked every later edit of the client. A hand-built
 * request could do the same with values the form never offers.
 *
 * For each page the tests pin three things:
 *  - input the model rejects never reaches the server, and the form comes
 *    back with what was typed;
 *  - valid input reaches the server exactly as checked and is saved;
 *  - a save that fails after the server accepted the change is reported as
 *    an error, never as success, and nothing is sent to undo it.
 *
 * Driven through Test/lib/Oa4mpWritePathHarnesses.php with a fake server.
 */

App::uses('ConnectionManager', 'Model');

class ValidateBeforeServerWriteTest extends Oa4mpTestCase {

  private $fx = null;
  private $coId = null;
  private $clientId = null;
  private $groupId = null;
  private $refreshTokenId = null;
  private $accessTokenId = null;
  private $accessControlId = null;

  const STORED_LIFETIME = 86400;

  public function setUp() {
    $this->fx = null;
    $this->coId = $this->clientId = $this->groupId = null;
    $this->refreshTokenId = $this->accessTokenId = $this->accessControlId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpvbsw');

    $this->coId = $this->fx->co($tag);
    $adminId = $this->fx->adminClient($this->coId, $tag);
    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));
    $this->clientId = $this->fx->oidcClient($adminId, 'vbsw-' . $tag);

    // Seeded before the access control row so cleanup, which runs newest
    // first, removes a row pointing at the group before the group itself.
    $this->groupId = $this->fx->group($this->coId, 'editors ' . $tag);

    $this->refreshTokenId = $this->fx->insert('cm_oa4mp_client_refresh_tokens', array(
      'client_id' => $this->clientId,
      'token_lifetime' => self::STORED_LIFETIME
    ));

    $this->accessTokenId = $this->fx->insert('cm_oa4mp_client_access_tokens', array(
      'client_id' => $this->clientId,
      'is_jwt' => false
    ));

    $this->accessControlId = $this->fx->insert('cm_oa4mp_client_access_controls', array(
      'client_id' => $this->clientId,
      'co_group_id' => null
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if ($this->clientId !== null) {
      $purge['cm_oa4mp_client_refresh_tokens'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_access_tokens'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_access_controls'] = 'client_id = ' . (int)$this->clientId;
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  // ==========================================================================
  // Refresh tokens manage()
  // ==========================================================================

  /**
   * A lifetime over the one-year maximum is rejected before the server is
   * touched, and the form comes back with it and its error.
   */
  public function testRefreshTokenLifetimeOverTheMaximumNeverReachesTheServer() {
    $harness = Oa4mpRefreshTokensControllerHarness::build($this->clientId, $this->coId,
      $this->refreshTokenPost('99999999'));
    // Extras that differ from the stored ones make the GET tail write the
    // client row, which must not cost the rejected post its errors.
    $harness->harnessServer->verifyExtra = '{"harness":"extra"}';

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a lifetime the plugin rejects');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual(self::STORED_LIFETIME, $this->storedLifetime(), 'the stored lifetime is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('99999999', $harness->request->data['Oa4mpClientRefreshToken']['token_lifetime'],
      'the form re-renders with the rejected lifetime, not the stored one');
    $this->assertEqual($this->clientId, (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the manage view reads');
    $this->assertNotEmpty($harness->Oa4mpClientRefreshToken->validationErrors['token_lifetime'] ?? null,
      'the lifetime error is there for the form to show');
  }

  /** A lifetime that is not a whole number is rejected the same way. */
  public function testRefreshTokenNonIntegerLifetimeNeverReachesTheServer() {
    $harness = Oa4mpRefreshTokensControllerHarness::build($this->clientId, $this->coId,
      $this->refreshTokenPost('abc'));

    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a lifetime the plugin rejects');
    $this->assertEqual(self::STORED_LIFETIME, $this->storedLifetime(), 'the stored lifetime is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('abc', $harness->request->data['Oa4mpClientRefreshToken']['token_lifetime'],
      'the form re-renders with the rejected lifetime');
  }

  /** A valid lifetime is sent, saved, and reported as before. */
  public function testRefreshTokenValidLifetimeIsSentAndSaved() {
    $harness = Oa4mpRefreshTokensControllerHarness::build($this->clientId, $this->coId,
      $this->refreshTokenPost('3600'));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual('3600', $sent['Oa4mpClientRefreshToken']['token_lifetime'] ?? null,
      'the OA4MP server was sent the posted lifetime');
    $this->assertEqual(3600, $this->storedLifetime(), 'the plugin stored the same lifetime');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_refresh_token.token.edit.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the action redirected to the manage view');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the same client');
  }

  /**
   * The server accepted the lifetime and the save then failed: that is
   * reported as an error, and nothing is sent to the server to undo it.
   */
  public function testRefreshTokenFailedSaveAfterTheServerAcceptedIsReported() {
    $harness = Oa4mpRefreshTokensControllerHarness::build($this->clientId, $this->coId,
      $this->refreshTokenPost('3600'));
    $harness->harnessFailWrites('save');

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_refresh_token.er.edit.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the action redirected where success does, so the next load shows the drift');
    $this->assertEqual(self::STORED_LIFETIME, $this->storedLifetime(), 'premise: nothing was saved');
  }

  /**
   * A rejected post on a client that is out of sync still leaves the page
   * the way it always has, without writing to the server.
   */
  public function testRejectedPostOnAnOutOfSyncClientStillRedirects() {
    $harness = Oa4mpRefreshTokensControllerHarness::build($this->clientId, $this->coId,
      $this->refreshTokenPost('99999999'));
    $harness->harnessServer->verifySynchronized = false;

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited');
    $this->assertTrue(in_array('oa4mpVerifyClient', $harness->harnessServer->callNames(), true),
      'the synchronization check still ran');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the out-of-sync client redirects as before');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_oidc_client.er.bad_client'), 'error'),
      'the out-of-sync client is reported');
    $this->assertEqual(self::STORED_LIFETIME, $this->storedLifetime(), 'the stored lifetime is unchanged');
  }

  // ==========================================================================
  // Access tokens manage()
  // ==========================================================================

  /** A hand-built JWT flag that is not a boolean never reaches the server. */
  public function testAccessTokenNonBooleanJwtFlagNeverReachesTheServer() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->clientId, $this->coId,
      $this->accessTokenPost('maybe'));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a flag the plugin rejects');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertFalse($this->storedIsJwt(), 'the stored flag is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('maybe', $harness->request->data['Oa4mpClientAccessToken']['is_jwt'],
      'the form re-renders with the rejected input');
    $this->assertEqual($this->clientId, (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the manage view reads');
  }

  /** Turning JWT on is sent, saved, and reported as before. */
  public function testAccessTokenValidToggleIsSentAndSaved() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->clientId, $this->coId,
      $this->accessTokenPost('1'));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual('1', $sent['Oa4mpClientAccessToken']['is_jwt'] ?? null,
      'the OA4MP server was sent the posted flag');
    $this->assertTrue($this->storedIsJwt(), 'the plugin stored the same flag');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_access_token.token.edit.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the action redirected to the manage view');
  }

  public function testAccessTokenFailedSaveAfterTheServerAcceptedIsReported() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->clientId, $this->coId,
      $this->accessTokenPost('1'));
    $harness->harnessFailWrites('save');

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_access_token.er.edit.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the action redirected where success does, so the next load shows the drift');
  }

  // ==========================================================================
  // Access controls manage()
  // ==========================================================================

  /** A hand-built group id that is not a number never reaches the server. */
  public function testAccessControlNonNumericGroupNeverReachesTheServer() {
    $harness = Oa4mpAccessControlsControllerHarness::build($this->clientId, $this->coId,
      $this->accessControlPost('editors'));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a group the plugin rejects');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertNull($this->storedEditorGroup(), 'the stored group is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('editors', $harness->request->data['Oa4mpClientAccessControl']['co_group_id'],
      'the form re-renders with the rejected input');
    $this->assertEqual($this->clientId, (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the manage view reads');
  }

  /** Choosing an editor group is sent, saved, and reported as before. */
  public function testAccessControlValidGroupIsSentAndSaved() {
    $harness = Oa4mpAccessControlsControllerHarness::build($this->clientId, $this->coId,
      $this->accessControlPost((string)$this->groupId));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual((string)$this->groupId, $sent['Oa4mpClientAccessControl']['co_group_id'] ?? null,
      'the OA4MP server was sent the posted group');
    $this->assertEqual($this->groupId, $this->storedEditorGroup(), 'the plugin stored the same group');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_access_control.manage.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the action redirected to the manage view');
  }

  /** Clearing the editor group is allowed, and is sent and saved. */
  public function testAccessControlBlankGroupIsSentAndSaved() {
    $this->fx->query('UPDATE cm_oa4mp_client_access_controls SET co_group_id = ' . (int)$this->groupId
      . ' WHERE id = ' . (int)$this->accessControlId);

    $harness = Oa4mpAccessControlsControllerHarness::build($this->clientId, $this->coId,
      $this->accessControlPost(''));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual('', $sent['Oa4mpClientAccessControl']['co_group_id'] ?? null,
      'the OA4MP server was sent the cleared group');
    $this->assertNull($this->storedEditorGroup(), 'the plugin cleared the stored group');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_access_control.manage.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the action redirected to the manage view');
  }

  public function testAccessControlFailedSaveAfterTheServerAcceptedIsReported() {
    $harness = Oa4mpAccessControlsControllerHarness::build($this->clientId, $this->coId,
      $this->accessControlPost((string)$this->groupId));
    $harness->harnessFailWrites('save');

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_access_control.er.edit.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the action redirected where success does, so the next load shows the drift');
  }

  // ==========================================================================
  // Helpers
  // ==========================================================================

  private function refreshTokenPost($lifetime) {
    return array('Oa4mpClientRefreshToken' => array(
      'id' => $this->refreshTokenId,
      'client_id' => $this->clientId,
      'token_lifetime' => $lifetime
    ));
  }

  private function accessTokenPost($isJwt) {
    return array('Oa4mpClientAccessToken' => array(
      'id' => $this->accessTokenId,
      'client_id' => $this->clientId,
      'is_jwt' => $isJwt
    ));
  }

  private function accessControlPost($groupId) {
    return array('Oa4mpClientAccessControl' => array(
      'id' => $this->accessControlId,
      'client_id' => $this->clientId,
      'co_group_id' => $groupId
    ));
  }

  /** How many times the fake server was asked to edit the client. */
  private function editCalls($harness) {
    return count(array_keys($harness->harnessServer->callNames(), 'oa4mpEditClient', true));
  }

  private function storedLifetime() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $value = $this->fx->scalar('SELECT token_lifetime FROM cm_oa4mp_client_refresh_tokens WHERE id = '
      . (int)$this->refreshTokenId);
    return ($value === null) ? null : (int)$value;
  }

  private function storedIsJwt() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->count('cm_oa4mp_client_access_tokens', 'id = ' . (int)$this->accessTokenId
      . ' AND is_jwt = true') === 1;
  }

  private function storedEditorGroup() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $value = $this->fx->scalar('SELECT co_group_id FROM cm_oa4mp_client_access_controls WHERE id = '
      . (int)$this->accessControlId);
    return ($value === null) ? null : (int)$value;
  }
}
