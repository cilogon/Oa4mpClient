<?php
/**
 * Regression coverage for cilogon/Oa4mpClient#48: whitespace around a URL
 * drove the plugin and the OA4MP server out of sync.
 *
 * The callbacks add/edit actions and the authorization manage action sent the
 * posted URL to the OA4MP server first and validated it second. The plugin's
 * validators reject a URL with a leading or trailing space, so the local save
 * then failed -- and its result was never read, so the action reported
 * success. The server now held a callback (or redirect URL) the plugin did
 * not, and the synchronization guard blocked every later edit of the client.
 *
 * The tests here pin both halves of the fix:
 *  - a padded URL is trimmed, and the server and the database receive the
 *    same trimmed value;
 *  - a URL that is invalid for any other reason never reaches the server.
 *
 * Driven through Test/lib/Oa4mpWritePathHarnesses.php with a fake server.
 */

App::uses('ConnectionManager', 'Model');

class UrlWhitespaceWritePathTest extends Oa4mpTestCase {

  private $fx = null;
  private $coId = null;
  private $clientId = null;
  private $callbackId = null;
  private $authzId = null;

  const EXISTING = 'https://example.org/existing';

  public function setUp() {
    $this->fx = null;
    $this->coId = $this->clientId = $this->callbackId = $this->authzId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpurlws');

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
    $this->clientId = $this->fx->oidcClient($adminId, 'urlws-' . $tag);

    $this->callbackId = $this->fx->insert('cm_oa4mp_client_co_callbacks', array(
      'client_id' => $this->clientId,
      'url' => self::EXISTING
    ));

    $this->authzId = $this->fx->insert('cm_oa4mp_client_authorizations', array(
      'client_id' => $this->clientId,
      'require_active' => true
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if ($this->clientId !== null) {
      $purge['cm_oa4mp_client_co_callbacks'] = 'client_id = ' . (int)$this->clientId;
      $purge['cm_oa4mp_client_authorizations'] = 'client_id = ' . (int)$this->clientId;
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  // ==========================================================================
  // Model: the trim itself
  // ==========================================================================

  /** A padded callback validates, and validation leaves the trimmed value behind. */
  public function testCallbackModelTrimsTheUrlBeforeValidating() {
    $model = ClassRegistry::init('Oa4mpClient.Oa4mpClientCoCallback');
    $model->create(false);
    $model->set(array('Oa4mpClientCoCallback' => array(
      'client_id' => $this->clientId,
      'url' => " \thttps://example.org/cb \n"
    )));

    $this->assertTrue($model->validates(), 'a callback URL with surrounding whitespace validates');
    $this->assertEqual('https://example.org/cb', $model->data['Oa4mpClientCoCallback']['url'],
      'the validated callback URL is the trimmed one');
  }

  /** The three redirect URLs are trimmed; an empty one stays empty. */
  public function testAuthorizationModelTrimsTheRedirectUrlsBeforeValidating() {
    $model = ClassRegistry::init('Oa4mpClient.Oa4mpClientAuthorization');
    $model->create(false);
    $model->set(array('Oa4mpClientAuthorization' => array(
      'client_id' => $this->clientId,
      'authz_group_redirect_url' => ' https://example.org/denied',
      'require_active_redirect_url' => "https://example.org/inactive \n",
      'no_record_redirect_url' => '   '
    )));

    $this->assertTrue($model->validates(), 'padded redirect URLs validate');
    $authz = $model->data['Oa4mpClientAuthorization'];
    $this->assertEqual('https://example.org/denied', $authz['authz_group_redirect_url'], 'leading space trimmed');
    $this->assertEqual('https://example.org/inactive', $authz['require_active_redirect_url'], 'trailing space trimmed');
    $this->assertEqual('', $authz['no_record_redirect_url'], 'an all-blank URL becomes empty, which is allowed');
  }

  // ==========================================================================
  // Callbacks controller
  // ==========================================================================

  /** The regression: the server and the database get the same trimmed callback. */
  public function testCallbackAddSendsAndSavesTheTrimmedUrl() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->clientId, $this->coId, array(
      'Oa4mpClientCoCallback' => array('client_id' => $this->clientId, 'url' => ' https://example.org/new ')
    ));

    $redirect = $harness->harnessInvoke('add', array(), 'POST');

    $sent = $this->callbackUrls($harness->harnessServer->lastEditData());
    $this->assertEqual(array(self::EXISTING, 'https://example.org/new'), $sent,
      'the OA4MP server was sent the trimmed callback URL');
    $this->assertEqual(array(self::EXISTING, 'https://example.org/new'), $this->storedCallbacks(),
      'the plugin stored the same trimmed callback URL the server was sent');
    $this->assertEqual('index', $redirect['action'], 'the action redirected to the callbacks index');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.callback.add.flash.success'), 'success'),
      'success was reported');
  }

  /** An invalid callback is rejected before the server is touched. */
  public function testCallbackAddInvalidUrlNeverReachesTheServer() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->clientId, $this->coId, array(
      'Oa4mpClientCoCallback' => array('client_id' => $this->clientId, 'url' => 'ftp://example.org/cb')
    ));

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a callback the plugin rejects');
    $this->assertEqual(array(self::EXISTING), $this->storedCallbacks(), 'no callback was stored');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertTrue($harness->harnessFlashed(_txt('er.fields'), 'error'), 'the field error was reported');
  }

  /** Editing a callback trims it on both sides too. */
  public function testCallbackEditSendsAndSavesTheTrimmedUrl() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->clientId, $this->coId, array(
      'Oa4mpClientCoCallback' => array(
        'id' => $this->callbackId,
        'client_id' => $this->clientId,
        'url' => "https://example.org/edited\n"
      )
    ));

    $harness->harnessInvoke('edit', array($this->callbackId), 'POST');

    $this->assertEqual(array('https://example.org/edited'),
      $this->callbackUrls($harness->harnessServer->lastEditData()),
      'the OA4MP server was sent the trimmed callback URL');
    $this->assertEqual(array('https://example.org/edited'), $this->storedCallbacks(),
      'the plugin stored the same trimmed callback URL');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.callback.edit.flash.success'), 'success'),
      'success was reported');
  }

  /** An invalid edit never reaches the server, and the form keeps what was typed. */
  public function testCallbackEditInvalidUrlNeverReachesTheServerAndKeepsTheInput() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->clientId, $this->coId, array(
      'Oa4mpClientCoCallback' => array(
        'id' => $this->callbackId,
        'client_id' => $this->clientId,
        'url' => 'https://example.org/*'
      )
    ));

    $harness->harnessInvoke('edit', array($this->callbackId), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a callback the plugin rejects');
    $this->assertEqual(array(self::EXISTING), $this->storedCallbacks(), 'the stored callback is unchanged');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('https://example.org/*', $harness->request->data['Oa4mpClientCoCallback']['url'],
      'the edit form re-renders with the rejected input, not the stored value');
  }

  // ==========================================================================
  // Authorization controller
  // ==========================================================================

  /** The regression: the server and the database get the same trimmed redirect URL. */
  public function testAuthorizationManageSendsAndSavesTheTrimmedUrls() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->clientId, $this->coId,
      $this->authzPost(' https://example.org/denied '));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual('https://example.org/denied', $sent['Oa4mpClientAuthorization']['authz_group_redirect_url'],
      'the OA4MP server was sent the trimmed redirect URL');
    $this->assertEqual('https://example.org/denied', $this->storedAuthzRedirect(),
      'the plugin stored the same trimmed redirect URL');
    $this->assertEqual('manage', $redirect['action'], 'the action redirected to the manage view');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_authorization.edit.flash.success'), 'success'),
      'success was reported');
  }

  /** An invalid redirect URL is rejected before the server is touched. */
  public function testAuthorizationManageInvalidUrlNeverReachesTheServer() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->clientId, $this->coId,
      $this->authzPost('not a url'));

    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'the OA4MP server was not edited with a redirect URL the plugin rejects');
    $this->assertNull($this->storedAuthzRedirect(), 'nothing was stored');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
    $this->assertEqual('not a url', $harness->request->data['Oa4mpClientAuthorization']['authz_group_redirect_url'],
      'the form re-renders with the rejected input');
    $this->assertEqual($this->clientId, $harness->request->data['Oa4mpClientCoOidcClient']['id'],
      'the form still has the client the manage view reads');
  }

  /**
   * A rejected post re-renders Require Active Status as posted, not as
   * stored, so the redirect URL field the box shows or hides stays visible
   * with the input being corrected.
   */
  public function testAuthorizationManageRejectedPostKeepsThePostedRequireActive() {
    $this->fx->query('UPDATE cm_oa4mp_client_authorizations SET require_active = false WHERE id = '
      . (int)$this->authzId);

    $post = $this->authzPost('');
    $post['Oa4mpClientAuthorization']['require_active'] = '1';
    $post['Oa4mpClientAuthorization']['require_active_redirect_url'] = 'not a url';
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->clientId, $this->coId, $post);

    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'premise: the post was rejected before the server was called');
    $this->assertTrue($harness->viewVars['vv_require_active'],
      'Require Active Status is shown ticked as posted, although the stored value is off');
  }

  // ==========================================================================
  // OIDC clients controller: home_url
  // ==========================================================================
  //
  // Nothing in the suite drives the OIDC clients controller's actions (see
  // CreateTimeCfgTest::testAddSendsAndSavesTheBuilderOutput), so these two
  // lock the order in its source instead: the home URL is trimmed before the
  // code that builds what the server is sent.

  public function testOidcClientAddTrimsTheHomeUrlBeforeBuildingTheServerData() {
    $add = $this->oidcActionSource('function add()', 'function calculateImpliedCoId(');

    $trim = strpos($add, "\$data['Oa4mpClientCoOidcClient']['home_url'] = trim(");
    $build = strpos($add, '$oa4mpServer->newClientData($adminClient, $data)');

    $this->assertTrue($trim !== false, 'add() trims the posted home URL');
    $this->assertTrue($build !== false && $trim < $build,
      'the trim comes before the create data is built and marshalled');
  }

  public function testOidcClientEditTrimsTheHomeUrlBeforeCopyingItForTheServer() {
    $edit = $this->oidcActionSource('function edit($id)', 'function index()');

    $trim = strpos($edit, "\$this->request->data['Oa4mpClientCoOidcClient']['home_url'] = trim(");
    $copy = strpos($edit, "\$newClient['Oa4mpClientCoOidcClient']['home_url'] = \$this->request->data");

    $this->assertTrue($trim !== false, 'edit() trims the posted home URL');
    $this->assertTrue($copy !== false && $trim < $copy,
      'the trim comes before the home URL is copied into what the server is sent');
  }

  // ==========================================================================
  // Helpers
  // ==========================================================================

  /** The source of one OIDC clients controller action, between two markers. */
  private function oidcActionSource($startMarker, $endMarker) {
    $path = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientCoOidcClientsController.php';
    $this->assertTrue(is_readable($path), "the OIDC clients controller exists at $path");

    $source = file_get_contents($path);
    $start = strpos($source, $startMarker);
    $end = ($start === false) ? false : strpos($source, $endMarker, $start);
    $this->assertTrue($start !== false && $end !== false,
      "the action between '$startMarker' and '$endMarker' is found");

    return substr($source, $start, $end - $start);
  }

  private function authzPost($groupRedirectUrl) {
    return array('Oa4mpClientAuthorization' => array(
      'id' => $this->authzId,
      'client_id' => $this->clientId,
      'authz_co_group_id' => '',
      'require_active' => '1',
      'authz_group_redirect_url' => $groupRedirectUrl,
      'require_active_redirect_url' => '',
      'no_record_redirect_url' => ''
    ));
  }

  /** Sorted callback URLs in a client array sent to the server. */
  private function callbackUrls($data) {
    $urls = array();
    foreach (($data['Oa4mpClientCoCallback'] ?? array()) as $cb) {
      $urls[] = $cb['url'];
    }
    sort($urls);
    return $urls;
  }

  /** Sorted callback URLs stored for the seeded client. */
  private function storedCallbacks() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $rows = $this->fx->query('SELECT url FROM cm_oa4mp_client_co_callbacks WHERE client_id = '
      . (int)$this->clientId);
    $urls = array();
    foreach ($rows as $row) {
      $urls[] = $row[0]['url'];
    }
    sort($urls);
    return $urls;
  }

  private function storedAuthzRedirect() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->scalar('SELECT authz_group_redirect_url FROM cm_oa4mp_client_authorizations WHERE id = '
      . (int)$this->authzId);
  }
}
