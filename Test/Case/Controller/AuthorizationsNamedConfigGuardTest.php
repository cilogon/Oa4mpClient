<?php
/**
 * The Authorization page is refused while a Named configuration is applied
 * (cilogon/Oa4mpClient#58).
 *
 * A Named configuration points the client at its own QDL script, which
 * decides who may log in, so the client's own authorization settings are
 * deleted and the Authorization tab is hidden. Opening the page directly or
 * posting to it is refused before anything is sent, and the client is sent
 * to its Named Configuration page. Driven through
 * Oa4mpAuthorizationsControllerHarness, whose fake server records every
 * server call. The form itself cannot be rendered by the thin runner, so it
 * is checked in source, as AuthorizationNoRecordFieldTest does.
 *
 * The client graph is the claims controller fixture's, which already seeds a
 * Named-configuration client and an ordinary one; this file adds a saved
 * authorization row to the Named-configuration client.
 */

class AuthorizationsNamedConfigGuardTest extends Oa4mpClaimsControllerTestCase {

  /**
   * The inputs the Authorization form renders: the group select and four
   * inputs. A new input that is not tied to the read-only flag must fail the
   * source check below, so the check also asserts it found at least these.
   */
  const FORM_INPUT_FLOOR = 5;

  protected $authorizationId = null;

  protected function fixtureTagPrefix() {
    return 'authz-named';
  }

  public function setUp() {
    parent::setUp();

    $this->authorizationId = $this->fx->insert('cm_oa4mp_client_authorizations', array(
      'client_id' => $this->namedConfigClientId,
      'require_active' => true,
      'require_active_redirect_url' => 'https://example.org/inactive'
    ));
  }

  public function tearDown() {
    if ($this->fx !== null) {
      // The ordinary client's save writes an authorization row of its own.
      $this->fx->cleanup(array('cm_oa4mp_client_authorizations' =>
        'client_id IN (' . (int)$this->clientId . ', ' . (int)$this->namedConfigClientId . ')'));
    }

    parent::tearDown();
  }

  /** A posted change turning Require Active Status off. */
  private function changedSettings($clientId, $id = null) {
    $data = array('Oa4mpClientAuthorization' => array(
      'client_id' => $clientId,
      'require_active' => 0,
      'require_active_redirect_url' => '',
      'authz_co_group_id' => '',
      'authz_group_redirect_url' => '',
      'no_record_redirect_url' => 'https://example.org/enroll'
    ));

    if ($id !== null) {
      $data['Oa4mpClientAuthorization']['id'] = $id;
    }

    return $data;
  }

  private function flashed($harness, $message) {
    foreach ($harness->Flash->messages as $flash) {
      if ((string)$flash['message'] === (string)$message) {
        return true;
      }
    }
    return false;
  }

  private function refusal() {
    return _txt('pl.oa4mp_client_co_named_config.er.tab_replaced', array(self::NAMED_CONFIG_NAME));
  }

  /** Whether $redirect is the Named Configuration page of the client. */
  private function isNamedConfigPage($redirect, $clientId) {
    return ($redirect['controller'] ?? null) === 'oa4mp_client_co_named_configs'
      && ($redirect['action'] ?? null) === 'manage'
      && (int)($redirect['clientid'] ?? 0) === (int)$clientId;
  }

  private function template() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientAuthorizations' . DS . 'manage.ctp';
    $this->assertTrue(is_readable($path), "the Authorization template exists at $path");
    return file_get_contents($path);
  }

  /**
   * A changed setting is refused before anything is sent, and the client is
   * sent to its Named Configuration page. The prune deletes the leftover
   * authorization row on the way in, and the posted settings are not saved.
   */
  public function testNamedConfigClientAuthorizationSaveIsRefused() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->namedConfigClientId, $this->coId,
      $this->changedSettings($this->namedConfigClientId, $this->authorizationId));

    $redirect = $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertTrue($this->isNamedConfigPage($redirect, $this->namedConfigClientId),
      'the guard redirected to the client\'s Named Configuration page');
    $this->assertEqual(0, $this->countRows($this->fx, 'cm_oa4mp_client_authorizations',
      'client_id = ' . (int)$this->namedConfigClientId
      . " AND no_record_redirect_url = 'https://example.org/enroll'"),
      'the posted settings were not saved');
    $this->assertTrue($this->flashed($harness, $this->refusal()),
      'the refusal was flashed');
    $this->assertFalse($this->flashed($harness, _txt('pl.oa4mp_client_authorization.edit.flash.success')),
      'no success was reported');
  }

  /** Opening the page is refused the same way, with nothing sent. */
  public function testNamedConfigClientAuthorizationPageIsRefused() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertTrue($this->isNamedConfigPage($redirect, $this->namedConfigClientId),
      'the page redirected to the client\'s Named Configuration page');
    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before the synchronization check');
    $this->assertTrue($this->flashed($harness, $this->refusal()),
      'the refusal was flashed');
  }

  /**
   * The control: the same save for a client without a Named configuration
   * reaches the server, so it is the guard that refuses the case above.
   */
  public function testClientWithoutNamedConfigAuthorizationSaveReachesTheServer() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->clientId, $this->coId,
      $this->changedSettings($this->clientId));

    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'an ordinary client\'s save was sent to the server');
    $this->assertFalse($this->flashed($harness, $this->refusal()),
      'no refusal for an ordinary client');
  }

  /**
   * Every form control is disabled by the read-only flag, which only a
   * public client sets now that a Named-configuration client never reaches
   * the form; the submit block is left out while it is set, and the script
   * that hides and clears the redirect URL fields does not run, so a
   * disabled form shows every saved value.
   */
  public function testEveryAuthorizationInputIsTiedToTheReadOnlyFlag() {
    $source = $this->template();

    preg_match_all('/\$this->Form->(input|select)\(.*$/m', $source, $matches);
    $controls = $matches[0];

    $this->assertTrue(count($controls) >= self::FORM_INPUT_FLOOR,
      'found ' . count($controls) . ' form controls, expected at least ' . self::FORM_INPUT_FLOOR);

    foreach ($controls as $line) {
      $this->assertContains('$readOnly', $line,
        "this control is not disabled while the form is read-only: $line");
    }

    $this->assertFalse(strpos($source, 'named_config') !== false,
      'the form no longer reads the Named configuration');
    $this->assertContains('$is_read_only = $is_public_client;', $source,
      'only a public client makes the form read-only');
    $this->assertContains("\$readOnly = \$is_read_only ? array('disabled' => true) : array();", $source,
      'the read-only options disable the control');
    $this->assertTrue((bool)preg_match('/<\?php if\(!\$is_read_only\): \?>\s*<li class="fields-submit">/', $source),
      'the submit block is left out while read-only');
    $this->assertTrue((bool)preg_match('/<\?php else: \?>\s*<script type="text\/javascript">\s*function js_local_onload\(\)/', $source),
      'the hide-and-clear script runs only when the form is editable');
  }
}
