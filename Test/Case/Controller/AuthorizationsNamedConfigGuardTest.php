<?php
/**
 * The Authorization tab is read-only while a Named configuration is applied.
 *
 * manage() handles the page and the save in one action, so only the POST is
 * refused -- redirected back to the same action, whose GET renders the saved
 * settings disabled under a notice. Driven through
 * Oa4mpAuthorizationsControllerHarness, whose fake server records every
 * server call. The disabled form itself cannot be rendered by the thin
 * runner, so it is checked in source, as AuthorizationNoRecordFieldTest does.
 *
 * The client graph is the claims controller fixture's, which already seeds a
 * Named-configuration client and an ordinary one; this file adds a saved
 * authorization row to the Named-configuration client.
 *
 * See docs/plans/2026-10-14-1946-feat-named-config-read-only-tabs-plan.md
 * (R1-R3, AE2, U4).
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

  private function namedConfigError() {
    return _txt('pl.oa4mp_client_authorization.er.named_config', array(self::NAMED_CONFIG_NAME));
  }

  private function template() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientAuthorizations' . DS . 'manage.ctp';
    $this->assertTrue(is_readable($path), "the Authorization template exists at $path");
    return file_get_contents($path);
  }

  /** A changed setting is refused before anything is sent or saved. */
  public function testNamedConfigClientAuthorizationSaveIsRefused() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->namedConfigClientId, $this->coId,
      $this->changedSettings($this->namedConfigClientId, $this->authorizationId));

    $redirect = $harness->harnessInvoke('manage', 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertEqual('manage', $redirect['action'] ?? null,
      'the guard redirected back to the Authorization page');
    $this->assertEqual(1, $this->countRows($this->fx, 'cm_oa4mp_client_authorizations',
      'id = ' . (int)$this->authorizationId . ' AND require_active = true'
      . ' AND no_record_redirect_url IS NULL'),
      'the saved settings are unchanged');
    $this->assertTrue($this->flashed($harness, $this->namedConfigError()),
      'the Named-configuration error was flashed');
    $this->assertFalse($this->flashed($harness, _txt('pl.oa4mp_client_authorization.edit.flash.success')),
      'no success was reported');
  }

  /**
   * Covers AE2, tab half. The page still renders, with the saved Require
   * Active Status and the configuration's name for the notice.
   */
  public function testNamedConfigClientAuthorizationPageRenders() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('manage', 'GET');

    $this->assertNull($redirect, 'the Authorization page rendered');
    $this->assertEqual(self::NAMED_CONFIG_NAME, $harness->viewVars['vv_named_config'] ?? null,
      'the view receives the Named configuration\'s name');
    $this->assertTrue($harness->viewVars['vv_require_active'] ?? null,
      'the saved Require Active Status is still shown');
  }

  /**
   * The control: the same save for a client without a Named configuration
   * reaches the server, so it is the guard that refuses the case above.
   */
  public function testClientWithoutNamedConfigAuthorizationSaveReachesTheServer() {
    $harness = Oa4mpAuthorizationsControllerHarness::build($this->clientId, $this->coId,
      $this->changedSettings($this->clientId));

    $harness->harnessInvoke('manage', 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'an ordinary client\'s save was sent to the server');
    $this->assertFalse($this->flashed($harness, $this->namedConfigError()),
      'no Named-configuration error for an ordinary client');
  }

  /**
   * Every form control is disabled by the read-only flag, the submit block is
   * left out, and the script that hides and clears the redirect URL fields
   * does not run, so a disabled form shows every saved value.
   */
  public function testEveryAuthorizationInputIsTiedToTheReadOnlyFlag() {
    $source = $this->template();

    preg_match_all('/\$this->Form->(input|select)\(.*$/m', $source, $matches);
    $controls = $matches[0];

    $this->assertTrue(count($controls) >= self::FORM_INPUT_FLOOR,
      'found ' . count($controls) . ' form controls, expected at least ' . self::FORM_INPUT_FLOOR);

    foreach ($controls as $line) {
      $this->assertContains('$readOnly', $line,
        "this control is not disabled while a Named configuration is applied: $line");
    }

    $this->assertContains("\$readOnly = \$is_named_config ? array('disabled' => true) : array();", $source,
      'the read-only options disable the control');
    $this->assertTrue((bool)preg_match('/<\?php if\(!\$is_named_config\): \?>\s*<li class="fields-submit">/', $source),
      'the submit block is left out while a Named configuration is applied');
    $this->assertTrue((bool)preg_match('/<\?php else: \?>\s*<script type="text\/javascript">\s*function js_local_onload\(\)/', $source),
      'the hide-and-clear script runs only when no Named configuration is applied');
  }
}
