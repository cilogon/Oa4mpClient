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
  private $adminId = null;
  private $clientId = null;
  private $groupId = null;
  private $refreshTokenId = null;
  private $accessTokenId = null;
  private $accessControlId = null;
  private $oidcClientName = null;
  private $contactEmailId = null;
  private $clientDynamoConfigId = null;
  private $addedClientName = null;
  private $postedAdminTag = null;

  const STORED_LIFETIME = 86400;
  const STORED_CONTACT = 'owner@example.org';
  const ADMIN_ACCESS_KEY = 'AKIAEXAMPLE';
  const STALE_ACCESS_KEY = 'AKIASTALEKEY';

  public function setUp() {
    $this->fx = null;
    $this->coId = $this->adminId = $this->clientId = $this->groupId = null;
    $this->refreshTokenId = $this->accessTokenId = $this->accessControlId = null;
    $this->oidcClientName = $this->contactEmailId = $this->clientDynamoConfigId = null;
    $this->addedClientName = $this->postedAdminTag = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpvbsw');

    $this->coId = $this->fx->co($tag);
    $adminId = $this->fx->adminClient($this->coId, $tag);
    $this->adminId = $adminId;
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
    $this->oidcClientName = 'vbsw-' . $tag;
    $this->addedClientName = 'vbsw-added-' . $tag;
    $this->clientId = $this->fx->oidcClient($adminId, $this->oidcClientName);

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

    // A client the add page created, and the rows saved with it, are removed
    // before the admin client they point at.
    if ($this->addedClientName !== null) {
      $added = 'SELECT id FROM cm_oa4mp_client_co_oidc_clients WHERE name = '
        . ConnectionManager::getDataSource('default')->value($this->addedClientName);
      foreach (array('cm_oa4mp_client_co_scopes', 'cm_oa4mp_client_co_email_addresses',
                     'cm_oa4mp_client_dynamo_configs', 'cm_oa4mp_client_authorizations') as $table) {
        $this->fx->query('DELETE FROM ' . $table . ' WHERE client_id IN (' . $added . ')');
      }
      $this->fx->query('DELETE FROM cm_oa4mp_client_co_oidc_clients WHERE id IN (' . $added . ')');
    }

    $purge = array();
    if ($this->clientId !== null) {
      $purge['cm_oa4mp_client_refresh_tokens'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_access_tokens'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_access_controls'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_co_scopes'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_co_callbacks'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_co_email_addresses'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_dynamo_configs'] = 'client_id = ' . (int)$this->clientId;

      // A named configuration a test seeded is removed before the client, so
      // the client must stop pointing at it first.
      $this->fx->query('UPDATE cm_oa4mp_client_co_oidc_clients SET named_config_id = NULL WHERE id = '
        . (int)$this->clientId);
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
  // Scopes edit_scopes()
  // ==========================================================================

  /**
   * A hand-built scope value outside the allowed list never reaches the
   * server, and the form comes back with it and an error on that row.
   */
  public function testScopeValueOutsideTheAllowedListNeverReachesTheServer() {
    $stored = $this->seedScopes();

    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->scopesPost($stored, array(0 => 'openid', 1 => 'profile', 2 => 'bogus')));

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a scope the plugin rejects');
    $this->assertTrue(in_array('oa4mpVerifyClient', $harness->harnessServer->callNames(), true),
      'the synchronization check still ran');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual(array('openid', 'profile'), $this->storedScopes(), 'the stored scopes are unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('bogus', $harness->request->data['Oa4mpClientCoScope'][2]['scope'] ?? null,
      'the form re-renders with the rejected scope');
    $this->assertEqual($this->clientId, (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the scopes view reads');
    $this->assertNotEmpty($harness->Oa4mpClientCoScope->validationErrors[2]['scope'] ?? null,
      'the error is on the rejected row, where the form shows it');
    $this->assertTrue(empty($harness->Oa4mpClientCoScope->validationErrors[0])
      && empty($harness->Oa4mpClientCoScope->validationErrors[1]),
      'the valid rows carry no error');
  }

  /**
   * A row carrying a stored scope's id with a different, allowed value is
   * rejected: the server would be sent the new value, and the save would
   * skip the row because it already has an id.
   */
  public function testScopeRowWhoseIdDoesNotMatchItsStoredScopeIsRejected() {
    $stored = $this->seedScopes();

    $data = $this->scopesPost($stored, array(0 => 'openid', 1 => 'profile'));
    $data['Oa4mpClientCoScope'][1]['scope'] = 'email';

    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId, $data);

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a scope the plugin would not store');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual(array('openid', 'profile'), $this->storedScopes(), 'the stored scopes are unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertNotEmpty($harness->Oa4mpClientCoScope->validationErrors[1]['scope'] ?? null,
      'the error is on the mismatched row');
  }

  /** A row carrying an id that is not one of this client's scopes is rejected. */
  public function testScopeRowWithAnUnknownIdIsRejected() {
    $stored = $this->seedScopes();

    $data = $this->scopesPost($stored, array(0 => 'openid', 1 => 'profile', 2 => 'email'));
    $data['Oa4mpClientCoScope'][2]['id'] = (string)(max($stored) + 100000);

    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId, $data);

    $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited');
    $this->assertEqual(array('openid', 'profile'), $this->storedScopes(), 'the stored scopes are unchanged');
    $this->assertNotEmpty($harness->Oa4mpClientCoScope->validationErrors[2]['scope'] ?? null,
      'the error is on the row with the unknown id');
  }

  /** Adding a scope is sent, saved, and reported as before. */
  public function testScopeAddedIsSentAndSaved() {
    $stored = $this->seedScopes();

    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->scopesPost($stored, array(0 => 'openid', 1 => 'profile', 2 => 'email')));

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertEqual(array('email', 'openid', 'profile'), $this->sentScopes($harness),
      'the OA4MP server was sent all three scopes');
    $this->assertEqual(array('email', 'openid', 'profile'), $this->storedScopes(),
      'the plugin stored the new scope alongside the existing ones');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_scope.scope.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('oa4mp_client_co_callbacks', $redirect['controller'] ?? null,
      'a client with no callbacks is sent on to add one, as before');
    $this->assertEqual('add', $redirect['action'] ?? null, 'to the callback add view');
  }

  /** Removing a scope is sent, and its row deleted, as before. */
  public function testScopeRemovedIsSentAndDeleted() {
    $stored = $this->seedScopes();
    $this->seedCallback();

    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->scopesPost($stored, array(0 => 'openid')));

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertEqual(array('openid'), $this->sentScopes($harness), 'the OA4MP server was sent openid only');
    $this->assertEqual(array('openid'), $this->storedScopes(), 'the profile row was deleted');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_scope.scope.flash.success'), 'success'),
      'success was reported');
    $this->assertNull($redirect, 'a client with callbacks stays on the scopes page, as before');
    $this->assertEqual(array('openid'), $this->scopeValues($harness->request->data['Oa4mpClientCoScope'] ?? array()),
      'the page shows the scopes read back after the save');
  }

  /**
   * The server accepted the change and saving a new row then failed: that is
   * reported as an error, the remaining rows are still processed, and nothing
   * is sent to the server to undo it.
   */
  public function testScopeFailedSaveAfterTheServerAcceptedIsReported() {
    $stored = $this->seedScopes();

    // Add email and remove profile, so the delete still has to run after the
    // failed save.
    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->scopesPost($stored, array(0 => 'openid', 2 => 'email')));
    $harness->harnessFailWrites('save');

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_scope.er.edit.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual(array('openid'), $this->storedScopes(),
      'the deselected row was still deleted, and the failed row was not stored');
    $this->assertEqual('oa4mp_client_co_callbacks', $redirect['controller'] ?? null,
      'the action redirected where success does');
  }

  /** A delete that fails after the server accepted is reported the same way. */
  public function testScopeFailedDeleteAfterTheServerAcceptedIsReported() {
    $stored = $this->seedScopes();
    $this->seedCallback();

    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->scopesPost($stored, array(0 => 'openid', 2 => 'email')));
    $harness->harnessFailWrites('delete');

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_scope.er.edit.save'), 'error'),
      'the failed delete was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual(array('email', 'openid', 'profile'), $this->storedScopes(),
      'the new row was still saved, and the row that failed to delete remains');
    $this->assertNull($redirect, 'a client with callbacks stays on the scopes page, as on success');
  }

  // ==========================================================================
  // Named config manage()
  // ==========================================================================

  /**
   * A named configuration that belongs to another admin client is not one
   * this client can use. It never reaches the server, and the form comes back
   * with the selection and its error.
   */
  public function testNamedConfigOfAnotherAdminClientNeverReachesTheServer() {
    $own = $this->seedNamedConfig($this->adminId, 'own');
    $this->setStoredNamedConfig($own);

    $tag = Oa4mpFixtures::tag('oa4mpvbsw-other');
    $otherAdminId = $this->fx->adminClient($this->coId, $tag);
    $foreign = $this->seedNamedConfig($otherAdminId, 'foreign');

    $harness = Oa4mpNamedConfigsControllerHarness::build($this->clientId, $this->coId,
      $this->namedConfigPost((string)$foreign));
    // Extras that differ from the stored ones make the GET tail write the
    // client row, which must not cost the rejected post its error.
    $harness->harnessServer->verifyExtra = '{"harness":"extra"}';

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a configuration this client cannot use');
    $this->assertTrue(in_array('oa4mpVerifyClient', $harness->harnessServer->callNames(), true),
      'the synchronization check still ran');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual($own, $this->storedNamedConfig(), 'the stored named configuration is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual((string)$foreign,
      $harness->request->data['Oa4mpClientCoNamedConfig']['selected_config_id'] ?? null,
      'the form re-renders with the rejected selection');
    $this->assertEqual($this->clientId, (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the manage view reads');
    $this->assertNotEmpty($harness->Oa4mpClientCoNamedConfig->validationErrors['selected_config_id'] ?? null,
      'the selection error is there for the form to show');
  }

  /** A named configuration id that does not exist is rejected the same way. */
  public function testNamedConfigThatDoesNotExistNeverReachesTheServer() {
    $own = $this->seedNamedConfig($this->adminId, 'own');

    $harness = Oa4mpNamedConfigsControllerHarness::build($this->clientId, $this->coId,
      $this->namedConfigPost((string)($own + 100000)));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a configuration that does not exist');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertNull($this->storedNamedConfig(), 'the client still has no named configuration');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertNotEmpty($harness->Oa4mpClientCoNamedConfig->validationErrors['selected_config_id'] ?? null,
      'the selection error is there for the form to show');
  }

  /** One of the admin client's named configurations is sent, saved, and reported as before. */
  public function testNamedConfigOfTheAdminClientIsSentAndSaved() {
    $own = $this->seedNamedConfig($this->adminId, 'own');

    $harness = Oa4mpNamedConfigsControllerHarness::build($this->clientId, $this->coId,
      $this->namedConfigPost((string)$own));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual((string)$own, $sent['Oa4mpClientCoOidcClient']['named_config_id'] ?? null,
      'the OA4MP server was sent the selected configuration');
    $this->assertEqual($own, $this->storedNamedConfig(), 'the plugin stored the same configuration');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the action redirected to the manage view');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the same client');
  }

  /** Choosing no named configuration is sent and saved as none. */
  public function testNamedConfigNoneIsSentAndSaved() {
    $own = $this->seedNamedConfig($this->adminId, 'own');
    $this->setStoredNamedConfig($own);

    $harness = Oa4mpNamedConfigsControllerHarness::build($this->clientId, $this->coId,
      $this->namedConfigPost('none'));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertTrue($sent !== null, 'the OA4MP server was edited');
    $this->assertTrue(array_key_exists('named_config_id', $sent['Oa4mpClientCoOidcClient'] ?? array())
      && $sent['Oa4mpClientCoOidcClient']['named_config_id'] === null,
      'the OA4MP server was sent no named configuration');
    $this->assertNull($this->storedNamedConfig(), 'the plugin cleared the stored configuration');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'), 'success'),
      'success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the action redirected to the manage view');
  }

  /**
   * The server accepted the selection and the save then failed: that is
   * reported as an error, the page redirects where success does instead of
   * stopping without the variables its view needs, and nothing is sent to
   * the server to undo it.
   */
  public function testNamedConfigFailedSaveAfterTheServerAcceptedIsReported() {
    $own = $this->seedNamedConfig($this->adminId, 'own');

    $harness = Oa4mpNamedConfigsControllerHarness::build($this->clientId, $this->coId,
      $this->namedConfigPost((string)$own));
    $harness->harnessFailWrites('saveField',
      array('Oa4mpClientCoNamedConfig', 'Oa4mpClientCoAdminClient', 'Oa4mpClientCoOidcClient'));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.edit.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the action redirected where success does, so the next load shows the drift');
    $this->assertEqual($this->clientId, $redirect['clientid'] ?? null, 'for the same client');
    $this->assertNull($this->storedNamedConfig(), 'premise: nothing was saved');
  }

  // ==========================================================================
  // OIDC client edit()
  // ==========================================================================

  /**
   * Clearing the name is rejected before the server is touched: the
   * refreshed DynamoDB configuration is not persisted either, and the form
   * comes back with the blank name and its error.
   */
  public function testOidcClientEditBlankNameNeverReachesTheServer() {
    $this->seedOidcClientEditRows();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientEditPost(array('name' => '')));

    $redirect = $harness->harnessInvoke('edit', array($this->clientId), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a name the plugin rejects');
    $this->assertTrue(in_array('oa4mpVerifyClient', $harness->harnessServer->callNames(), true),
      'the synchronization check still ran');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual($this->oidcClientName, $this->storedClientName(), 'the stored name is unchanged');
    $this->assertEqual(self::STALE_ACCESS_KEY, $this->storedClientAccessKey(),
      'the refreshed DynamoDB configuration was not persisted');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('', $harness->request->data['Oa4mpClientCoOidcClient']['name'],
      'the form re-renders with the blank name, not the stored one');
    $this->assertEqual($this->clientId, (int)$harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the edit view reads');
    $this->assertNotEmpty($harness->Oa4mpClientCoOidcClient->validationErrors['name'] ?? null,
      'the name error is there for the form to show');
  }

  /**
   * A home URL the model's url rule refuses is rejected the same way. That
   * rule does not require a scheme, so example.org/app is accepted, and is
   * stored alike by the server and the plugin.
   */
  public function testOidcClientEditInvalidHomeUrlNeverReachesTheServer() {
    $this->seedOidcClientEditRows();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientEditPost(array('home_url' => 'not a url')));

    $redirect = $harness->harnessInvoke('edit', array($this->clientId), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a home URL the plugin rejects');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual('https://example.org/', $this->storedClientHomeUrl(), 'the stored home URL is unchanged');
    $this->assertEqual(self::STALE_ACCESS_KEY, $this->storedClientAccessKey(),
      'the refreshed DynamoDB configuration was not persisted');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('not a url', $harness->request->data['Oa4mpClientCoOidcClient']['home_url'],
      'the form re-renders with the rejected home URL');
    $this->assertNotEmpty($harness->Oa4mpClientCoOidcClient->validationErrors['home_url'] ?? null,
      'the home URL error is there for the form to show');
  }

  /**
   * An invalid contact email is rejected before the server is touched. The
   * error is left where the form looks for it: on the email model under the
   * row index, and under the email model's name in the client's errors.
   */
  public function testOidcClientEditInvalidContactEmailNeverReachesTheServer() {
    $this->seedOidcClientEditRows();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientEditPost(array(), 'not-an-email'));

    $redirect = $harness->harnessInvoke('edit', array($this->clientId), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a contact email the plugin rejects');
    $this->assertNull($redirect, 'the form is shown again rather than redirecting');
    $this->assertEqual(self::STORED_CONTACT, $this->storedContactEmail(), 'the stored contact email is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('not-an-email', $harness->request->data['Oa4mpClientCoEmailAddress'][0]['mail'] ?? null,
      'the form re-renders with the rejected contact email');
    $this->assertNotEmpty(
      $harness->Oa4mpClientCoOidcClient->Oa4mpClientCoEmailAddress->validationErrors[0]['mail'] ?? null,
      'the email model holds the error under the row index the form input names');
    $this->assertNotEmpty(
      $harness->Oa4mpClientCoOidcClient->validationErrors['Oa4mpClientCoEmailAddress'][0]['mail'] ?? null,
      'the client errors hold the same error under the email model');
  }

  /**
   * Server extras that differ from the stored ones make the edit page write
   * the client row before it renders. That write clears the model's errors,
   * which a rejected post must not lose.
   */
  public function testOidcClientEditRejectedPostKeepsItsErrorsWhenTheExtrasAreRewritten() {
    $this->seedOidcClientEditRows();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientEditPost(array('name' => ''), 'not-an-email'));
    $harness->harnessServer->verifyExtra = '{"harness":"extra"}';

    $harness->harnessInvoke('edit', array($this->clientId), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited');
    $this->assertEqual('{"harness":"extra"}', $this->storedClientExtra(),
      'premise: the edit page wrote the new extras to the client row');
    $this->assertNotEmpty($harness->Oa4mpClientCoOidcClient->validationErrors['name'] ?? null,
      'the name error survived the write');
    $this->assertNotEmpty(
      $harness->Oa4mpClientCoOidcClient->validationErrors['Oa4mpClientCoEmailAddress'][0]['mail'] ?? null,
      'the contact email error survived the write');
    $this->assertEqual('', $harness->request->data['Oa4mpClientCoOidcClient']['name'],
      'the form re-renders with the rejected name');
  }

  /** A valid name is sent, saved, and reported as before. */
  public function testOidcClientEditValidNameIsSentAndSaved() {
    $this->seedOidcClientEditRows();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientEditPost(array('name' => 'renamed client')));

    $redirect = $harness->harnessInvoke('edit', array($this->clientId), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual('renamed client', $sent['Oa4mpClientCoOidcClient']['name'] ?? null,
      'the OA4MP server was sent the posted name');
    $this->assertEqual(self::ADMIN_ACCESS_KEY, $sent['Oa4mpClientDynamoConfig']['aws_access_key_id'] ?? null,
      'the OA4MP server was sent the refreshed DynamoDB configuration');
    $this->assertEqual('renamed client', $this->storedClientName(), 'the plugin stored the same name');
    $this->assertEqual(self::ADMIN_ACCESS_KEY, $this->storedClientAccessKey(),
      'the refreshed DynamoDB configuration was persisted');
    $this->assertTrue($harness->harnessFlashed(_txt('rs.updated', array('renamed client')), 'success'),
      'success was reported');
    $this->assertNull($redirect, 'the edit page is shown again, as before');
  }

  /**
   * The server accepted the edit and the client save then failed: that is
   * reported as a failed save rather than as a field error, the refreshed
   * DynamoDB configuration the server now holds is still persisted, and
   * nothing is sent to the server to undo it.
   */
  public function testOidcClientEditFailedSaveAfterTheServerAcceptedIsReported() {
    $this->seedOidcClientEditRows();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientEditPost(array('name' => 'renamed client')));
    $harness->harnessFailWrites('saveAssociated');

    $redirect = $harness->harnessInvoke('edit', array($this->clientId), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited exactly once, with no undo');
    $this->assertEqual(self::ADMIN_ACCESS_KEY, $this->storedClientAccessKey(),
      'the refreshed DynamoDB configuration the server accepted was persisted');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_oidc_client.er.edit.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashed(_txt('er.fields'), 'error'),
      'the input passed the check, so no field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertNull($redirect, 'the edit page is shown again, as on success');
    $this->assertEqual($this->oidcClientName, $this->storedClientName(), 'premise: nothing was saved');
  }

  // ==========================================================================
  // OIDC client add()
  // ==========================================================================

  /**
   * A blank name is rejected before the server is asked to create the
   * client. The add form comes back with what was typed, and with the form
   * setup for the admin client that was posted, not the first one.
   */
  public function testOidcClientAddBlankNameNeverReachesTheServer() {
    $this->seedAdminsGroup();
    $postedAdminId = $this->seedAddAdminClient('admin2@example.org');
    $namedConfigId = $this->seedNamedConfig($postedAdminId, 'second admin named configuration');
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($postedAdminId, array('name' => '', 'home_url' => 'https://example.org/typed')));

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(0, $this->newClientCalls($harness),
      'the OA4MP server was not asked to create a client the plugin rejects');
    $this->assertNull($redirect, 'the add form is shown again rather than redirecting');
    $this->assertEqual(array(), $harness->harnessRendered, 'the action\'s own add view renders, not the secret page');
    $this->assertEqual(0, $this->addedClientCount(), 'no Registry save was attempted');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertNotEmpty($harness->Oa4mpClientCoOidcClient->validationErrors['name'] ?? null,
      'the name error is there for the form to show');
    $this->assertEqual('', $harness->request->data['Oa4mpClientCoOidcClient']['name'],
      'the form re-renders with the blank name');
    $this->assertEqual('https://example.org/typed', $harness->request->data['Oa4mpClientCoOidcClient']['home_url'],
      'the form re-renders with the entered home URL');
    $this->assertEqual($postedAdminId, (int)($harness->viewVars['vv_admin_id'] ?? 0),
      'the form is set up for the posted admin client');
    $this->assertEqual('https://' . $this->postedAdminTag . '.example.org', $harness->viewVars['vv_admin_issuer'] ?? null,
      'with that admin client\'s issuer');
    $this->assertEqual('admin2@example.org', $harness->viewVars['vv_default_contact_email'] ?? null,
      'and its contact email as the default');
    $this->assertEqual(array($namedConfigId), $this->rowIds($harness->request->data['Oa4mpClientCoNamedConfig'] ?? array()),
      'and its named configurations as the choices');
    $this->assertEqual(2, count($harness->viewVars['adminClients'] ?? array()),
      'the admin client choices are still there');
  }

  /**
   * A home URL the model's url rule refuses is rejected the same way. That
   * rule does not require a scheme, so this needs a value that is no URL.
   */
  public function testOidcClientAddInvalidHomeUrlNeverReachesTheServer() {
    $this->seedAdminsGroup();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($this->adminId, array('home_url' => 'not a url')));

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(0, $this->newClientCalls($harness),
      'the OA4MP server was not asked to create a client with a home URL the plugin rejects');
    $this->assertNull($redirect, 'the add form is shown again rather than redirecting');
    $this->assertEqual(0, $this->addedClientCount(), 'no Registry save was attempted');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertEqual('not a url', $harness->request->data['Oa4mpClientCoOidcClient']['home_url'],
      'the form re-renders with the rejected home URL');
    $this->assertEqual($this->addedClientName, $harness->request->data['Oa4mpClientCoOidcClient']['name'],
      'and with the entered name');
    $this->assertNotEmpty($harness->Oa4mpClientCoOidcClient->validationErrors['home_url'] ?? null,
      'the home URL error is there for the form to show');
    $this->assertEqual($this->adminId, (int)($harness->viewVars['vv_admin_id'] ?? 0),
      'the form is set up for the posted admin client');
  }

  /**
   * An invalid contact email is rejected before the server is asked to
   * create the client, with the error on the email model under the row index
   * the form input names.
   */
  public function testOidcClientAddInvalidContactEmailNeverReachesTheServer() {
    $this->seedAdminsGroup();
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($this->adminId, array(), 'not-an-email'));

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(0, $this->newClientCalls($harness),
      'the OA4MP server was not asked to create a client with a contact email the plugin rejects');
    $this->assertEqual(0, $this->addedClientCount(), 'no Registry save was attempted');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertEqual('not-an-email', $harness->request->data['Oa4mpClientCoEmailAddress'][0]['mail'] ?? null,
      'the form re-renders with the rejected contact email');
    $this->assertNotEmpty(
      $harness->Oa4mpClientCoOidcClient->Oa4mpClientCoEmailAddress->validationErrors[0]['mail'] ?? null,
      'the email model holds the error under the row index the form input names');
  }

  /**
   * The new client's DynamoDB configuration is copied from the admin
   * client's default. A default missing a required credential is rejected
   * before the server is asked to create the client.
   */
  public function testOidcClientAddAdminDefaultMissingACredentialNeverReachesTheServer() {
    $this->seedAdminsGroup();
    $postedAdminId = $this->seedAddAdminClient('admin2@example.org', '');
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($postedAdminId));

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(0, $this->newClientCalls($harness),
      'the OA4MP server was not asked to create a client the plugin cannot save');
    $this->assertNull($redirect, 'the add form is shown again rather than redirecting');
    $this->assertEqual(0, $this->addedClientCount(), 'no Registry save was attempted');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /**
   * A valid confidential client is created once, saved with the returned
   * client ID and Require Active Status, and the secret page is shown.
   */
  public function testOidcClientAddValidConfidentialClientIsCreatedAndSaved() {
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($this->adminId));

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->newClientCalls($harness), 'the OA4MP server was asked to create the client once');
    $sent = $harness->harnessServer->lastNewClientData();
    $this->assertEqual($this->addedClientName, $sent['Oa4mpClientCoOidcClient']['name'] ?? null,
      'the OA4MP server was sent the posted name');
    $this->assertTrue(!empty($sent['Oa4mpClientAuthorization']['require_active']),
      'the OA4MP server was sent Require Active Status');
    $this->assertFalse(isset($sent['Oa4mpClientCoOidcClient']['proxy_limited']),
      'the check ran on a copy, so the create request is built as before');
    $this->assertNull($redirect, 'the action did not redirect');
    $this->assertEqual(array('secret'), $harness->harnessRendered, 'the secret page is shown');
    $this->assertFalse($harness->harnessFlashed(_txt('er.fields'), 'error'), 'no field error was reported');

    $stored = $this->addedClient();
    $this->assertEqual('harness-client-id', $stored['oa4mp_identifier'] ?? null,
      'the plugin stored the client ID the server returned');
    $this->assertEqual($stored['id'] ?? null, (int)($harness->viewVars['vv_id'] ?? 0),
      'the secret page links to the stored client');
    $this->assertEqual(1, $this->fx->count('cm_oa4mp_client_authorizations',
      'client_id = ' . (int)$stored['id'] . ' AND require_active = true'),
      'the stored client requires Active Status');
    $this->assertEqual(self::ADMIN_ACCESS_KEY, $this->fx->scalar('SELECT aws_access_key_id FROM cm_oa4mp_client_dynamo_configs'
      . ' WHERE client_id = ' . (int)$stored['id']),
      'the stored client has the admin client\'s default DynamoDB configuration');
  }

  /** A valid public client is created and saved with no authorization row. */
  public function testOidcClientAddValidPublicClientIsSavedWithoutAnAuthorization() {
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($this->adminId, array('public_client' => '1')));

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->newClientCalls($harness), 'the OA4MP server was asked to create the client once');
    $sent = $harness->harnessServer->lastNewClientData();
    $this->assertFalse(isset($sent['Oa4mpClientAuthorization']), 'no authorization was sent for a public client');
    $this->assertEqual(array('secret'), $harness->harnessRendered, 'the secret page is shown');

    $stored = $this->addedClient();
    $this->assertEqual('harness-client-id', $stored['oa4mp_identifier'] ?? null, 'the client was stored');
    $this->assertEqual(0, $this->fx->count('cm_oa4mp_client_authorizations', 'client_id = ' . (int)$stored['id']),
      'a public client is stored with no authorization row');
  }

  /** A posted public_client of 0 creates a confidential client. */
  public function testOidcClientAddPublicClientZeroIsConfidential() {
    $this->assertAddedConfidential($this->oidcClientAddPost($this->adminId, array('public_client' => '0')));
  }

  /** A post with no public_client at all creates a confidential client. */
  public function testOidcClientAddAbsentPublicClientIsConfidential() {
    $post = $this->oidcClientAddPost($this->adminId);
    unset($post['Oa4mpClientCoOidcClient']['public_client']);

    $this->assertAddedConfidential($post);
  }

  /**
   * The server created the client and the Registry save then failed: that
   * is reported, the new client ID (never the secret) is logged so the
   * server-only client can be found, and the page leaves for the client
   * index rather than showing the secret or the add form again.
   */
  public function testOidcClientAddFailedSaveAfterTheServerCreatedIsReported() {
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId,
      $this->oidcClientAddPost($this->adminId));
    $harness->harnessFailWrites('saveAssociated');

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->newClientCalls($harness), 'the server was asked to create the client exactly once');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_oidc_client.er.add.save'), 'error'),
      'the failed save was reported');
    $this->assertFalse($harness->harnessFlashed(_txt('er.fields'), 'error'),
      'the input passed the check, so no field error was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');

    $logged = implode("\n", $harness->harnessLogged);
    $this->assertContains('harness-client-id', $logged, 'the new client ID was logged');
    $this->assertFalse(strpos($logged, 'not-a-real-secret') !== false, 'the client secret was not logged');

    $this->assertEqual(array(
      'plugin' => 'oa4mp_client',
      'controller' => 'oa4mp_client_co_oidc_clients',
      'action' => 'index',
      'co' => $this->coId
    ), $redirect, 'the action redirected to the CO\'s OIDC client index');
    $this->assertFalse(in_array('secret', $harness->harnessRendered, true), 'the secret page was not shown');
    $this->assertEqual(0, $this->addedClientCount(), 'premise: nothing was saved');
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

  /** Store openid and profile for the client; returns their ids by scope. */
  private function seedScopes() {
    $ids = array();
    foreach (array('openid', 'profile') as $scope) {
      $ids[$scope] = $this->fx->insert('cm_oa4mp_client_co_scopes', array(
        'client_id' => $this->clientId,
        'scope' => $scope
      ));
    }
    return $ids;
  }

  private function seedCallback() {
    $this->fx->insert('cm_oa4mp_client_co_callbacks', array(
      'client_id' => $this->clientId,
      'url' => 'https://example.org/callback'
    ));
  }

  /**
   * The scopes form as posted: one row per checkbox, in the order the view
   * renders them, '0' for an unchecked box, and the stored row's id on the
   * rows the view gives one.
   *
   * @param array $stored  Stored row ids by scope, from seedScopes()
   * @param array $checked Checked scope values by row index
   */
  private function scopesPost($stored, $checked) {
    $rows = array();
    foreach (array('openid', 'profile', 'email', 'org.cilogon.userinfo') as $i => $scope) {
      $rows[$i] = array('scope' => $checked[$i] ?? '0');
      if (isset($stored[$scope])) {
        $rows[$i]['id'] = (string)$stored[$scope];
      }
    }
    return array('Oa4mpClientCoScope' => $rows);
  }

  /** The scope values of a set of rows, sorted. */
  private function scopeValues($rows) {
    $values = array();
    foreach ($rows as $row) {
      $values[] = $row['scope'];
    }
    sort($values);
    return $values;
  }

  /** The scopes the most recent server edit was sent, sorted. */
  private function sentScopes($harness) {
    $sent = $harness->harnessServer->lastEditData();
    return $this->scopeValues($sent['Oa4mpClientCoScope'] ?? array());
  }

  /** The client's stored scopes, sorted. */
  private function storedScopes() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $rows = $this->fx->query('SELECT scope FROM cm_oa4mp_client_co_scopes WHERE client_id = '
      . (int)$this->clientId . ' ORDER BY scope');
    $values = array();
    foreach ((array)$rows as $row) {
      $values[] = reset($row)['scope'];
    }
    return $values;
  }

  /** How many times the fake server was asked to edit the client. */
  private function editCalls($harness) {
    return count(array_keys($harness->harnessServer->callNames(), 'oa4mpEditClient', true));
  }

  /** The named config form as posted: 'none' or a named configuration id. */
  private function namedConfigPost($selection) {
    return array('Oa4mpClientCoNamedConfig' => array(
      'selected_config_id' => $selection
    ));
  }

  /** Store a named configuration for $adminId and return its id. */
  private function seedNamedConfig($adminId, $name) {
    return $this->fx->insert('cm_oa4mp_client_co_named_configs', array(
      'admin_id' => $adminId,
      'config_name' => $name,
      'description' => 'hermetic test named configuration',
      'config' => '{}'
    ));
  }

  private function setStoredNamedConfig($namedConfigId) {
    $this->fx->query('UPDATE cm_oa4mp_client_co_oidc_clients SET named_config_id = ' . (int)$namedConfigId
      . ' WHERE id = ' . (int)$this->clientId);
  }

  private function storedNamedConfig() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $value = $this->fx->scalar('SELECT named_config_id FROM cm_oa4mp_client_co_oidc_clients WHERE id = '
      . (int)$this->clientId);
    return ($value === null) ? null : (int)$value;
  }

  /**
   * Give the client the rows its edit page saves through: a contact email,
   * and a per-client DynamoDB configuration whose access key is behind the
   * admin client's default, so an accepted edit refreshes it.
   */
  private function seedOidcClientEditRows() {
    $this->contactEmailId = $this->fx->insert('cm_oa4mp_client_co_email_addresses', array(
      'client_id' => $this->clientId,
      'mail' => self::STORED_CONTACT
    ));

    $this->clientDynamoConfigId = $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => null,
      'client_id' => $this->clientId,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => self::STALE_ACCESS_KEY,
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));
  }

  /**
   * The OIDC client edit form as posted, with $client overriding the stored
   * client fields and $mail the contact email.
   */
  private function oidcClientEditPost($client = array(), $mail = self::STORED_CONTACT) {
    return array(
      'Oa4mpClientCoOidcClient' => $client + array(
        'id' => (string)$this->clientId,
        'admin_id' => (string)$this->adminId,
        'oa4mp_identifier' => 'https://example.org/oidc/client/' . $this->oidcClientName,
        'name' => $this->oidcClientName,
        'home_url' => 'https://example.org/',
        'public_client' => '0'
      ),
      'Oa4mpClientCoEmailAddress' => array(
        0 => array(
          'id' => (string)$this->contactEmailId,
          'client_id' => (string)$this->clientId,
          'mail' => $mail
        )
      )
    );
  }

  /**
   * The OIDC client add form as posted for $adminId, with $client overriding
   * the client fields and $mail the contact email.
   */
  private function oidcClientAddPost($adminId, $client = array(), $mail = 'contact@example.org') {
    return array(
      'Oa4mpClientCoOidcClient' => $client + array(
        'admin_id' => (string)$adminId,
        'name' => $this->addedClientName,
        'home_url' => 'https://example.org/app',
        'public_client' => '0'
      ),
      'Oa4mpClientCoEmailAddress' => array(
        0 => array('mail' => $mail)
      )
    );
  }

  /**
   * Seed the CO administrators group the add form setup looks up. It has no
   * members, so the default contact email falls back to the admin client's.
   */
  private function seedAdminsGroup() {
    $this->fx->group($this->coId, 'admins ' . $this->addedClientName, array('group_type' => 'A'));
  }

  /**
   * Seed a second admin client in the CO, with a contact email and a default
   * DynamoDB configuration whose access key is $accessKey. Returns its id.
   */
  private function seedAddAdminClient($mail, $accessKey = self::ADMIN_ACCESS_KEY) {
    $this->postedAdminTag = Oa4mpFixtures::tag('oa4mpvbsw2');
    $adminId = $this->fx->adminClient($this->coId, $this->postedAdminTag);

    $this->fx->insert('cm_oa4mp_client_co_email_addresses', array(
      'admin_id' => $adminId,
      'client_id' => null,
      'mail' => $mail
    ));

    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => $accessKey,
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));

    return $adminId;
  }

  /** Post $post to add() and check a confidential client was created and stored. */
  private function assertAddedConfidential($post) {
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId, $post);

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(1, $this->newClientCalls($harness), 'the OA4MP server was asked to create the client once');
    $sent = $harness->harnessServer->lastNewClientData();
    $this->assertTrue(!empty($sent['Oa4mpClientAuthorization']['require_active']),
      'the OA4MP server was sent Require Active Status');

    $stored = $this->addedClient();
    $this->assertEqual('harness-client-id', $stored['oa4mp_identifier'] ?? null, 'the client was stored');
    $this->assertEqual(1, $this->fx->count('cm_oa4mp_client_authorizations',
      'client_id = ' . (int)($stored['id'] ?? 0) . ' AND require_active = true'),
      'the stored client is confidential and requires Active Status');
  }

  /** How many times the fake server was asked to create a client. */
  private function newClientCalls($harness) {
    return count(array_keys($harness->harnessServer->callNames(), 'oa4mpNewClient', true));
  }

  /** How many clients the add page stored. */
  private function addedClientCount() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->count('cm_oa4mp_client_co_oidc_clients',
      'name = ' . ConnectionManager::getDataSource('default')->value($this->addedClientName));
  }

  /** The id and oa4mp_identifier of the client the add page stored, or array(). */
  private function addedClient() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $rows = $this->fx->query('SELECT id, oa4mp_identifier FROM cm_oa4mp_client_co_oidc_clients WHERE name = '
      . ConnectionManager::getDataSource('default')->value($this->addedClientName));
    if (empty($rows)) {
      return array();
    }
    $row = reset($rows[0]);
    return array('id' => (int)$row['id'], 'oa4mp_identifier' => $row['oa4mp_identifier']);
  }

  /** The ids of a set of rows. */
  private function rowIds($rows) {
    $ids = array();
    foreach ($rows as $row) {
      $ids[] = (int)$row['id'];
    }
    return $ids;
  }

  private function storedClientName() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->scalar('SELECT name FROM cm_oa4mp_client_co_oidc_clients WHERE id = '
      . (int)$this->clientId);
  }

  private function storedClientHomeUrl() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->scalar('SELECT home_url FROM cm_oa4mp_client_co_oidc_clients WHERE id = '
      . (int)$this->clientId);
  }

  private function storedClientExtra() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->scalar('SELECT oa4mp_server_extra FROM cm_oa4mp_client_co_oidc_clients WHERE id = '
      . (int)$this->clientId);
  }

  private function storedContactEmail() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->scalar('SELECT mail FROM cm_oa4mp_client_co_email_addresses WHERE id = '
      . (int)$this->contactEmailId);
  }

  private function storedClientAccessKey() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->scalar('SELECT aws_access_key_id FROM cm_oa4mp_client_dynamo_configs WHERE id = '
      . (int)$this->clientDynamoConfigId);
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
