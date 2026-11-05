<?php
/**
 * What a client's own pages do while a Named configuration is applied
 * (cilogon/Oa4mpClient#58).
 *
 * The Named configuration takes the place of the client's scopes, claims,
 * authorization settings and access token settings. This file covers the
 * parts the Scopes, Claims and Authorization guard tests do not:
 *
 *  - the Access Tokens page is refused, on GET and POST, and the client is
 *    sent to its Named Configuration page;
 *  - Callbacks and Refresh Tokens stay available, and their saves still
 *    reach the OA4MP server;
 *  - every client action deletes the never-sent rows a client applied before
 *    such rows were deleted on apply still holds, keeping its access token
 *    row, which is still sent; and a failed delete is reported without
 *    blocking the page.
 *
 * Driven through the write-path harnesses, whose fake server records every
 * server call. The client graph is the claims controller fixture's: its
 * Named-configuration client already holds a leftover claim, and this file
 * adds an access token row to it.
 */

class NamedConfigClientActionsTest extends Oa4mpClaimsControllerTestCase {

  protected $accessTokenId = null;

  /**
   * Every client action, by controller, that must delete the leftovers right
   * after loading the client. The Named Configuration page and the OIDC
   * client edit page are not listed: what they do with a Named configuration
   * is their own.
   */
  private $pruningActions = array(
    'Oa4mpClientCoScopesController' => array('edit_scopes'),
    'Oa4mpClientClaimsController' => array('add', 'edit', 'delete', 'index'),
    'Oa4mpClientAccessTokensController' => array('manage'),
    'Oa4mpClientAuthorizationsController' => array('manage'),
    'Oa4mpClientCoCallbacksController' => array('add', 'edit', 'delete', 'index'),
    'Oa4mpClientRefreshTokensController' => array('manage'),
    'Oa4mpClientAccessControlsController' => array('manage'),
  );

  protected function fixtureTagPrefix() {
    return 'named-actions';
  }

  public function setUp() {
    $this->accessTokenId = null;

    parent::setUp();

    $this->accessTokenId = $this->fx->insert('cm_oa4mp_client_access_tokens', array(
      'client_id' => $this->namedConfigClientId,
      'is_jwt' => true
    ));
  }

  public function tearDown() {
    Oa4mpCallbacksControllerHarness::harnessRestoreWrites();

    if ($this->fx !== null && $this->namedConfigClientId !== null) {
      // The saves this file drives write rows of their own.
      $clients = (int)$this->clientId . ', ' . (int)$this->namedConfigClientId;
      $this->fx->cleanup(array(
        'cm_oa4mp_client_access_tokens' => 'client_id IN (' . $clients . ')',
        'cm_oa4mp_client_refresh_tokens' => 'client_id IN (' . $clients . ')',
        'cm_oa4mp_client_co_callbacks' => 'client_id IN (' . $clients . ')'
      ));
    }

    parent::tearDown();
  }

  private function refusal() {
    return _txt('pl.oa4mp_client_co_named_config.er.tab_replaced', array(self::NAMED_CONFIG_NAME));
  }

  /** Whether $redirect is the Named-configuration client's Named Configuration page. */
  private function isNamedConfigPage($redirect) {
    return ($redirect['controller'] ?? null) === 'oa4mp_client_co_named_configs'
      && ($redirect['action'] ?? null) === 'manage'
      && (int)($redirect['clientid'] ?? 0) === (int)$this->namedConfigClientId;
  }

  private function accessTokenPost($clientId, $id = null) {
    $data = array('Oa4mpClientAccessToken' => array(
      'client_id' => $clientId,
      'is_jwt' => '0'
    ));

    if ($id !== null) {
      $data['Oa4mpClientAccessToken']['id'] = $id;
    }

    return $data;
  }

  private function namedConfigClaimCount() {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->namedConfigClientId);
  }

  private function namedConfigAccessTokenKept() {
    return 1 === (int)$this->countRows($this->fx, 'cm_oa4mp_client_access_tokens',
      'id = ' . (int)$this->accessTokenId . ' AND is_jwt = true');
  }

  // ==========================================================================
  // Access Tokens
  // ==========================================================================

  /** A posted JWT setting is refused before anything is sent or saved. */
  public function testNamedConfigClientAccessTokenSaveIsRefused() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->namedConfigClientId, $this->coId,
      $this->accessTokenPost($this->namedConfigClientId, $this->accessTokenId));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the guard redirected to the client\'s Named Configuration page');
    $this->assertTrue($this->namedConfigAccessTokenKept(), 'the saved setting is unchanged');
    $this->assertTrue($harness->harnessFlashed($this->refusal(), 'error'), 'the refusal was flashed');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /** Opening the page is refused the same way, with nothing sent. */
  public function testNamedConfigClientAccessTokenPageIsRefused() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertTrue($this->isNamedConfigPage($redirect),
      'the page redirected to the client\'s Named Configuration page');
    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before the synchronization check');
    $this->assertTrue($harness->harnessFlashed($this->refusal(), 'error'), 'the refusal was flashed');
  }

  /**
   * The control: the same save for a client without a Named configuration
   * reaches the server, so it is the guard that refuses the case above.
   */
  public function testClientWithoutNamedConfigAccessTokenSaveReachesTheServer() {
    $harness = Oa4mpAccessTokensControllerHarness::build($this->clientId, $this->coId,
      $this->accessTokenPost($this->clientId));

    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'an ordinary client\'s save was sent to the server');
    $this->assertFalse($harness->harnessFlashed($this->refusal(), 'error'),
      'no refusal for an ordinary client');
  }

  // ==========================================================================
  // Callbacks and Refresh Tokens stay available
  // ==========================================================================

  /** A callback added to a Named-configuration client is sent to the server. */
  public function testNamedConfigClientCallbackSaveReachesTheServer() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->namedConfigClientId, $this->coId, array(
      'Oa4mpClientCoCallback' => array('client_id' => $this->namedConfigClientId,
                                       'url' => 'https://example.org/named-callback')
    ));

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the callback was sent to the server');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the save went on to the callbacks index');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.callback.add.flash.success'),
      'success'), 'success was reported');
    $this->assertFalse($harness->harnessFlashed($this->refusal(), 'error'), 'Callbacks is not refused');
  }

  /** A refresh token lifetime saved for a Named-configuration client is sent to the server. */
  public function testNamedConfigClientRefreshTokenSaveReachesTheServer() {
    $harness = Oa4mpRefreshTokensControllerHarness::build($this->namedConfigClientId, $this->coId,
      array('Oa4mpClientRefreshToken' => array(
        'client_id' => $this->namedConfigClientId,
        'token_lifetime' => '3600'
      )));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the refresh token lifetime was sent to the server');
    $this->assertEqual('manage', $redirect['action'] ?? null, 'the save went on to the manage page');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_refresh_token.token.edit.flash.success'),
      'success'), 'success was reported');
  }

  // ==========================================================================
  // Leftovers deleted on touch
  // ==========================================================================

  /**
   * Opening Callbacks for a Named-configuration client that still holds a
   * claim and an access token row deletes the claim, which was never sent,
   * and keeps the access token row, which still is. The page then works from
   * the client as it is after the delete.
   */
  public function testOpeningCallbacksDeletesLeftoversAndKeepsTheAccessToken() {
    $this->assertEqual(1, $this->namedConfigClaimCount(), 'premise: the client holds a leftover claim');

    $harness = Oa4mpCallbacksControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('index', array(), 'GET');

    $this->assertNull($redirect, 'the Callbacks page rendered');
    $this->assertEqual(0, $this->namedConfigClaimCount(), 'the leftover claim was deleted');
    $this->assertTrue($this->namedConfigAccessTokenKept(), 'the access token row was kept');
    $this->assertEmpty($harness->request->data['Oa4mpClientClaim'] ?? array(),
      'the page read the client again after the delete');
    $this->assertFalse($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.prune'), 'error'),
      'no cleanup error was reported');
  }

  /**
   * A delete that fails is logged and reported, and the page still opens:
   * the rows are not sent, so nothing is wrong on the server, and the next
   * visit tries again.
   */
  public function testFailedLeftoverDeleteIsReportedAndDoesNotBlockThePage() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->namedConfigClientId, $this->coId);
    $harness->harnessFailWrites('delete',
      array('Oa4mpClientCoCallback', 'Oa4mpClientCoOidcClient', 'Oa4mpClientClaim'));

    $redirect = $harness->harnessInvoke('index', array(), 'GET');

    $this->assertNull($redirect, 'the Callbacks page still rendered');
    $this->assertEqual(1, $this->namedConfigClaimCount(), 'premise: the claim delete failed');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.prune'), 'error'),
      'the cleanup error was reported');
    $this->assertNotEmpty($harness->harnessLogged, 'the failure was logged');
  }

  /**
   * Every client action deletes the leftovers right after loading the
   * client, through the one helper. The harness tests above drive
   * Callbacks; this holds the other actions to the same call.
   */
  public function testEveryClientActionDeletesLeftoversOnLoad() {
    $base = App::pluginPath('Oa4mpClient') . 'Controller' . DS;

    foreach ($this->pruningActions as $controller => $actions) {
      $source = file_get_contents($base . $controller . '.php');
      $this->assertNotEmpty($source, "premise: $controller exists");

      foreach ($actions as $action) {
        $body = $this->methodBody((string)$source, $action);
        $this->assertNotEmpty($body, "premise: $controller has a $action() action");
        $this->assertEqual(1, substr_count((string)$body, '$this->_currentClientPruned('),
          "$controller::$action() loads the client through the pruning helper");
        // A read after a save is fine; a load before the helper is not.
        $helperAt = strpos((string)$body, '$this->_currentClientPruned(');
        $loadAt = strpos((string)$body, '->current($clientId)');
        $this->assertTrue($loadAt === false || ($helperAt !== false && $loadAt > $helperAt),
          "$controller::$action() loads the client through the helper first");
      }
    }
  }

  /** The source of $method in $source, from its declaration to its closing brace. */
  private function methodBody($source, $method) {
    if (!preg_match('/\n\s*(?:public\s+|protected\s+)?function\s+' . preg_quote($method, '/') . '\s*\(/',
                    $source, $m, PREG_OFFSET_CAPTURE)) {
      return null;
    }

    $start = strpos($source, '{', $m[0][1]);
    $depth = 0;
    $len = strlen($source);
    for ($i = $start; $i < $len; $i++) {
      if ($source[$i] === '{') {
        $depth++;
      } elseif ($source[$i] === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($source, $m[0][1], $i - $m[0][1] + 1);
        }
      }
    }

    return null;
  }
}
