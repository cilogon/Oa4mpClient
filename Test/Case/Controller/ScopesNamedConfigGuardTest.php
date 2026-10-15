<?php
/**
 * The Scopes tab is read-only while a Named configuration is applied.
 *
 * edit_scopes() handles the page and the save in one action, so only the
 * POST is refused -- redirected back to the same action, whose GET renders
 * the stored scopes disabled under a notice. Driven through
 * Oa4mpScopesControllerHarness, whose fake server records every server call,
 * so "nothing was sent" is an empty call list rather than a source scan.
 *
 * The client graph is the claims controller fixture's, which already seeds a
 * Named-configuration client and an ordinary one; this file adds a scope row
 * to each.
 *
 * See docs/plans/2026-10-14-1946-feat-named-config-read-only-tabs-plan.md
 * (R1-R3, U3).
 */

class ScopesNamedConfigGuardTest extends Oa4mpClaimsControllerTestCase {

  protected function fixtureTagPrefix() {
    return 'scopes-named';
  }

  public function setUp() {
    parent::setUp();

    foreach (array($this->clientId, $this->namedConfigClientId) as $clientId) {
      $this->fx->insert('cm_oa4mp_client_co_scopes', array(
        'client_id' => $clientId,
        'scope' => Oa4mpClientScopeEnum::OpenId
      ));
    }
  }

  public function tearDown() {
    if ($this->fx !== null) {
      // The ordinary client's save writes scope rows of its own.
      $this->fx->cleanup(array('cm_oa4mp_client_co_scopes' =>
        'client_id IN (' . (int)$this->clientId . ', ' . (int)$this->namedConfigClientId . ')'));
    }

    parent::tearDown();
  }

  /** A posted scope set that adds profile and email to openid. */
  private function changedScopes() {
    return array('Oa4mpClientCoScope' => array(
      0 => array('scope' => Oa4mpClientScopeEnum::OpenId),
      1 => array('scope' => Oa4mpClientScopeEnum::Profile),
      2 => array('scope' => Oa4mpClientScopeEnum::Email),
      3 => array('scope' => 0),
    ));
  }

  private function scopeCount($clientId) {
    return $this->countRows($this->fx, 'cm_oa4mp_client_co_scopes',
      'client_id = ' . (int)$clientId);
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
    return _txt('pl.oa4mp_client_co_scope.er.named_config', array(self::NAMED_CONFIG_NAME));
  }

  /** A changed scope set is refused before anything is sent or saved. */
  public function testNamedConfigClientScopeSaveIsRefused() {
    $harness = Oa4mpScopesControllerHarness::build($this->namedConfigClientId, $this->coId,
      $this->changedScopes());

    $redirect = $harness->harnessInvoke('edit_scopes', 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertEqual('edit_scopes', $redirect['action'] ?? null,
      'the guard redirected back to the scopes page');
    $this->assertEqual(1, $this->scopeCount($this->namedConfigClientId),
      'the stored scopes are unchanged');
    $this->assertTrue($this->flashed($harness, $this->namedConfigError()),
      'the Named-configuration error was flashed');
    $this->assertFalse($this->flashed($harness, _txt('pl.oa4mp_client_co_scope.scope.flash.success')),
      'no success was reported');
  }

  /**
   * The page itself still renders, so the guard does not block the read-only
   * view, and it hands the view the configuration's name.
   */
  public function testNamedConfigClientScopesPageRenders() {
    $harness = Oa4mpScopesControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('edit_scopes', 'GET');

    $this->assertNull($redirect, 'the scopes page rendered');
    $this->assertEqual(self::NAMED_CONFIG_NAME, $harness->viewVars['vv_named_config'] ?? null,
      'the view receives the Named configuration\'s name');
    $this->assertFalse(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'rendering the page sends nothing');
  }

  /**
   * The control: the same save for a client without a Named configuration
   * reaches the server, so it is the guard that refuses the case above.
   */
  public function testClientWithoutNamedConfigScopeSaveReachesTheServer() {
    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->changedScopes());

    $harness->harnessInvoke('edit_scopes', 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'an ordinary client\'s scope save was sent to the server');
    $this->assertFalse($this->flashed($harness, $this->namedConfigError()),
      'no Named-configuration error for an ordinary client');
  }
}
