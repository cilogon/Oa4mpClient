<?php
/**
 * The Access Token and Authorization tabs are read-only for a public client
 * (cilogon/Oa4mpClient#47).
 *
 * OA4MP rejects a custom configuration (cfg) on a public client, so the
 * plugin never sends one, and JWT Format and every Authorization setting live
 * only in the cfg. Both tabs still saved them: nothing was enforced, and the
 * synchronization check read the saved JWT Format or Require Active Status as
 * drift, which blocked every later edit of the client.
 *
 * manage() handles the page and the save in one action, so only the POST is
 * refused -- redirected back to the same action, whose GET renders the saved
 * settings disabled under a notice. Driven through the write-path harnesses,
 * whose fake server records every server call. The disabled forms cannot be
 * rendered by the thin runner, so they are checked in source, as
 * AuthorizationsNamedConfigGuardTest does.
 *
 * That a confidential client's save on either tab still reaches the server is
 * covered by ValidateBeforeServerWriteTest::testAccessTokenValidToggleIsSentAndSaved
 * and AuthorizationsNamedConfigGuardTest::
 * testClientWithoutNamedConfigAuthorizationSaveReachesTheServer.
 *
 * The client graph is the claims controller fixture's, which already seeds a
 * public client and a confidential one; this file adds a saved access token
 * and authorization row to the public client, as a client stuck out of sync
 * by this defect has.
 */

class PublicClientTabGuardTest extends Oa4mpClaimsControllerTestCase {

  protected $accessTokenId = null;
  protected $authorizationId = null;

  protected function fixtureTagPrefix() {
    return 'public-tabs';
  }

  public function setUp() {
    $this->accessTokenId = null;
    $this->authorizationId = null;

    parent::setUp();

    $this->accessTokenId = $this->fx->insert('cm_oa4mp_client_access_tokens', array(
      'client_id' => $this->publicClientId,
      'is_jwt' => true
    ));

    $this->authorizationId = $this->fx->insert('cm_oa4mp_client_authorizations', array(
      'client_id' => $this->publicClientId,
      'require_active' => true,
      'require_active_redirect_url' => 'https://example.org/inactive'
    ));
  }

  public function tearDown() {
    if ($this->fx !== null && $this->publicClientId !== null) {
      // A save the guard failed to stop may have written rows of its own.
      $this->fx->cleanup(array(
        'cm_oa4mp_client_access_tokens' => 'client_id = ' . (int)$this->publicClientId,
        'cm_oa4mp_client_authorizations' => 'client_id = ' . (int)$this->publicClientId
      ));
    }

    parent::tearDown();
  }

  private function template($controller) {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS . $controller . DS . 'manage.ctp';
    $this->assertTrue(is_readable($path), "the template exists at $path");
    return file_get_contents($path);
  }

  // ==========================================================================
  // Access Token tab
  // ==========================================================================

  /** A posted JWT Format is refused before anything is sent or saved. */
  public function testPublicClientAccessTokenSaveIsRefused() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->publicClientId, $this->coId,
      array('Oa4mpClientAccessToken' => array(
        'id' => $this->accessTokenId,
        'client_id' => $this->publicClientId,
        'is_jwt' => '0'
      )));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the guard redirected back to the Access Token page');
    $this->assertEqual('oa4mp_client_access_tokens', $redirect['controller'] ?? null,
      'on the Access Token tab');
    $this->assertEqual($this->publicClientId, (int)($redirect['clientid'] ?? 0),
      'for the same client');
    $this->assertEqual(1, $this->countRows($this->fx, 'cm_oa4mp_client_access_tokens',
      'id = ' . (int)$this->accessTokenId . ' AND is_jwt = true'),
      'the saved setting is unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_access_token.er.public_client'), 'error'),
      'the public-client error was flashed');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /** The page still renders, and is told the client is public. */
  public function testPublicClientAccessTokenPageRendersReadOnly() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->publicClientId, $this->coId);

    $redirect = $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertNull($redirect, 'the Access Token page rendered');
    $this->assertTrue(($harness->viewVars['vv_public_client'] ?? null) === true,
      'the view is told the client is public');
  }

  /** The control: a confidential client's page is not read-only. */
  public function testConfidentialClientAccessTokenPageIsEditable() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->clientId, $this->coId);

    $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertTrue(($harness->viewVars['vv_public_client'] ?? null) === false,
      'the view is told the client is not public');
  }

  /** The JWT Format control is disabled and the submit left out while public. */
  public function testAccessTokenInputIsTiedToThePublicClientFlag() {
    $source = $this->template('Oa4mpClientAccessTokens');

    preg_match_all('/\$this->Form->(input|select)\(.*$/m', $source, $matches);
    $controls = $matches[0];

    $this->assertTrue(count($controls) >= 1,
      'found ' . count($controls) . ' form controls, expected at least 1');

    foreach ($controls as $line) {
      $this->assertContains('$readOnly', $line,
        "this control is not disabled for a public client: $line");
    }

    $this->assertContains("\$readOnly = \$is_public_client ? array('disabled' => true) : array();", $source,
      'the read-only options disable the control');
    $this->assertTrue((bool)preg_match('/<\?php if\(!\$is_public_client\): \?>\s*<li class="fields-submit">/', $source),
      'the submit block is left out for a public client');
    $this->assertContains("_txt('pl.oa4mp_client_access_token.public_client.description')", $source,
      'a public client is shown why');
  }

  // ==========================================================================
  // Authorization tab
  // ==========================================================================

  /** A posted change is refused before anything is sent or saved. */
  public function testPublicClientAuthorizationSaveIsRefused() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->publicClientId, $this->coId,
      array('Oa4mpClientAuthorization' => array(
        'id' => $this->authorizationId,
        'client_id' => $this->publicClientId,
        'require_active' => 0,
        'require_active_redirect_url' => '',
        'authz_co_group_id' => '',
        'authz_group_redirect_url' => '',
        'no_record_redirect_url' => 'https://example.org/enroll'
      )));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the guard redirected back to the Authorization page');
    $this->assertEqual('oa4mp_client_authorizations', $redirect['controller'] ?? null,
      'on the Authorization tab');
    $this->assertEqual($this->publicClientId, (int)($redirect['clientid'] ?? 0),
      'for the same client');
    $this->assertEqual(1, $this->countRows($this->fx, 'cm_oa4mp_client_authorizations',
      'id = ' . (int)$this->authorizationId . ' AND require_active = true'
      . ' AND no_record_redirect_url IS NULL'),
      'the saved settings are unchanged');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_authorization.er.public_client'), 'error'),
      'the public-client error was flashed');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /** The page still renders, with the saved settings and the public flag. */
  public function testPublicClientAuthorizationPageRendersReadOnly() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->publicClientId, $this->coId);

    $redirect = $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertNull($redirect, 'the Authorization page rendered');
    $this->assertTrue(($harness->viewVars['vv_public_client'] ?? null) === true,
      'the view is told the client is public');
    $this->assertTrue($harness->viewVars['vv_require_active'] ?? null,
      'the saved Require Active Status is still shown');
  }

  /** The control: a confidential client's page is not read-only. */
  public function testConfidentialClientAuthorizationPageIsEditable() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->clientId, $this->coId);

    $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertTrue(($harness->viewVars['vv_public_client'] ?? null) === false,
      'the view is told the client is not public');
  }

  /**
   * The Authorization form's read-only flag is the public-client flag: the
   * controls are disabled, the submit is left out, and the notice says why. AuthorizationsNamedConfigGuardTest checks that
   * every control is tied to the flag.
   */
  public function testAuthorizationFormIsReadOnlyForAPublicClient() {
    $source = $this->template('Oa4mpClientAuthorizations');

    $this->assertContains('$is_read_only = $is_public_client;', $source,
      'a public client makes the form read-only');
    $this->assertContains("\$readOnly = \$is_read_only ? array('disabled' => true) : array();", $source,
      'the read-only options disable the controls');
    $this->assertTrue((bool)preg_match('/<\?php if\(!\$is_read_only\): \?>\s*<li class="fields-submit">/', $source),
      'the submit block is left out while read-only');
    $this->assertContains("_txt('pl.oa4mp_client_authorization.public_client.description')", $source,
      'a public client is shown why');
  }
}
