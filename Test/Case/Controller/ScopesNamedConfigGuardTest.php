<?php
/**
 * The Scopes page is refused while a Named configuration is applied
 * (cilogon/Oa4mpClient#58).
 *
 * The Named configuration supplies the client's scopes, so the client's own
 * are deleted and the Scopes tab is hidden. Opening the page directly or
 * posting to it is refused before anything is sent, on GET and POST alike,
 * and the refusal sends a confidential client to its Named Configuration
 * page and a public client to its edit page: redirecting back to the Scopes
 * page, as the read-only design of cilogon/Oa4mpClient#39 did, would loop
 * now that the page itself is refused.
 *
 * Driven through Oa4mpScopesControllerHarness, whose fake server records
 * every server call, so "nothing was sent" is an empty call list rather than
 * a source scan.
 *
 * The client graph is the claims controller fixture's, which already seeds a
 * Named-configuration client, an ordinary one and a public one; this file
 * adds a scope row to each.
 */

class ScopesNamedConfigGuardTest extends Oa4mpClaimsControllerTestCase {

  /**
   * The scopes the form lets a user choose: profile, email,
   * org.cilogon.userinfo and getcert. openid is always on and cannot be
   * cleared, so it is not one of them. A new scope checkbox that is not tied
   * to the public-client flag must fail the source check below, so the check
   * also asserts it found at least these.
   */
  const CHOOSABLE_SCOPE_FLOOR = 4;

  protected function fixtureTagPrefix() {
    return 'scopes-named';
  }

  public function setUp() {
    parent::setUp();

    foreach (array($this->clientId, $this->namedConfigClientId, $this->publicClientId) as $clientId) {
      $this->fx->insert('cm_oa4mp_client_co_scopes', array(
        'client_id' => $clientId,
        'scope' => Oa4mpClientScopeEnum::OpenId
      ));
    }
  }

  public function tearDown() {
    if ($this->fx !== null) {
      // One test gives the public client the Named configuration, which is
      // seeded after it and so purged before it.
      $this->fx->query('UPDATE cm_oa4mp_client_co_oidc_clients SET named_config_id = NULL'
        . ' WHERE id = ' . (int)$this->publicClientId);

      // The ordinary client's save writes scope rows of its own.
      $this->fx->cleanup(array('cm_oa4mp_client_co_scopes' =>
        'client_id IN (' . (int)$this->clientId . ', ' . (int)$this->namedConfigClientId
        . ', ' . (int)$this->publicClientId . ')'));
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

  private function refusal() {
    return _txt('pl.oa4mp_client_co_named_config.er.tab_replaced', array(self::NAMED_CONFIG_NAME));
  }

  /** Whether $redirect is the Named Configuration page of the client. */
  private function isNamedConfigPage($redirect, $clientId) {
    return ($redirect['controller'] ?? null) === 'oa4mp_client_co_named_configs'
      && ($redirect['action'] ?? null) === 'manage'
      && (int)($redirect['clientid'] ?? 0) === (int)$clientId;
  }

  /**
   * A changed scope set is refused before anything is sent, and the client
   * is sent to its Named Configuration page. The prune deletes the leftover
   * openid row on the way in, and the posted scopes are not saved.
   */
  public function testNamedConfigClientScopeSaveIsRefused() {
    $harness = Oa4mpScopesControllerHarness::build($this->namedConfigClientId, $this->coId,
      $this->changedScopes());

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertEmpty($harness->harnessServer->calls,
      'the guard stopped the save before any server call');
    $this->assertTrue($this->isNamedConfigPage($redirect, $this->namedConfigClientId),
      'the guard redirected to the client\'s Named Configuration page');
    $this->assertEqual(0, $this->scopeCount($this->namedConfigClientId),
      'the posted scopes were not saved');
    $this->assertTrue($this->flashed($harness, $this->refusal()),
      'the refusal was flashed');
    $this->assertFalse($this->flashed($harness, _txt('pl.oa4mp_client_co_scope.scope.flash.success')),
      'no success was reported');
  }

  /** Opening the page is refused the same way, with nothing sent. */
  public function testNamedConfigClientScopesPageIsRefused() {
    $harness = Oa4mpScopesControllerHarness::build($this->namedConfigClientId, $this->coId);

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'GET');

    $this->assertTrue($this->isNamedConfigPage($redirect, $this->namedConfigClientId),
      'the page redirected to the client\'s Named Configuration page');
    $this->assertEmpty($harness->harnessServer->calls,
      'the refusal came before the synchronization check');
    $this->assertTrue($this->flashed($harness, $this->refusal()),
      'the refusal was flashed');
  }

  /**
   * A public client cannot open the Named Configuration page, so one that
   * still carries a Named configuration is sent to its edit page instead.
   * Its rows are left for the maintainer to clear by hand.
   */
  public function testPublicClientWithNamedConfigIsSentToItsEditPage() {
    $this->fx->query('UPDATE cm_oa4mp_client_co_oidc_clients SET named_config_id = '
      . (int)$this->namedConfigId . ' WHERE id = ' . (int)$this->publicClientId);

    $harness = Oa4mpScopesControllerHarness::build($this->publicClientId, $this->coId);

    $redirect = $harness->harnessInvoke('edit_scopes', array(), 'GET');

    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'] ?? null,
      'the page redirected to the OIDC client pages');
    $this->assertEqual('edit', $redirect['action'] ?? null, 'to the edit page');
    $this->assertEqual($this->publicClientId, (int)($redirect[0] ?? 0), 'of the same client');
    $this->assertEmpty($harness->harnessServer->calls, 'nothing was sent');
    $this->assertEqual(1, $this->scopeCount($this->publicClientId),
      'the public client\'s own scope row was not pruned');
  }

  /**
   * The control: the same save for a client without a Named configuration
   * reaches the server, so it is the guard that refuses the case above.
   */
  public function testClientWithoutNamedConfigScopeSaveReachesTheServer() {
    $harness = Oa4mpScopesControllerHarness::build($this->clientId, $this->coId,
      $this->changedScopes());

    $harness->harnessInvoke('edit_scopes', array(), 'POST');

    $this->assertTrue(in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true),
      'an ordinary client\'s scope save was sent to the server');
    $this->assertFalse($this->flashed($harness, $this->refusal()),
      'no refusal for an ordinary client');
  }

  /**
   * The Scopes form is read-only for a public client only: every scope
   * checkbox but openid is disabled by the public-client flag, and the save
   * button is left out for it. A Named-configuration client never reaches
   * the form, so the form no longer reads the Named configuration. The thin
   * runner cannot render the form, so it is checked in source.
   */
  public function testEveryScopeCheckboxIsTiedToThePublicClientFlag() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientCoScopes' . DS . 'fields.inc';
    $this->assertTrue(is_readable($path), "the Scopes form exists at $path");
    $source = file_get_contents($path);

    $this->assertFalse(strpos($source, 'named_config') !== false,
      'the form no longer reads the Named configuration');

    preg_match_all("/\\\$this->Form->input\\(['\"]Oa4mpClientCoScope\\.(\\d+)\\.scope['\"]/", $source,
      $matches, PREG_OFFSET_CAPTURE);

    // Every scope field the form names is one of the checkboxes checked
    // below, so one built another way cannot escape the check.
    $this->assertEqual(count($matches[0]),
      preg_match_all('/Oa4mpClientCoScope\.\d+\.scope/', $source),
      'every scope field in the form is a Form->input checkbox');

    $choosable = 0;
    $previousEnd = 0;
    foreach ($matches[0] as $n => $match) {
      $index = (int)$matches[1][$n][0];
      // The code that builds this checkbox's options, since the previous one.
      $segment = substr($source, $previousEnd, $match[1] - $previousEnd);
      $previousEnd = $match[1] + strlen($match[0]);

      if ($index === 0) {
        continue;
      }

      $choosable++;
      $this->assertTrue((bool)preg_match(
        '/if\(\$is_public_client \|\| !\$e\) \{\s*\$args\[\'disabled\'\] = true;/',
        $segment),
        "scope checkbox $index is not disabled for a public client");
    }

    $this->assertTrue($choosable >= self::CHOOSABLE_SCOPE_FLOOR,
      "found $choosable choosable scope checkboxes, expected at least " . self::CHOOSABLE_SCOPE_FLOOR);

    $this->assertEqual(1, substr_count($source, '$this->Form->submit('),
      'the form has one save button');
    $this->assertTrue((bool)preg_match(
      '/<\?php if\(!\$is_public_client\): \?>\s*<li class="fields-submit">/',
      $source),
      'the save button is left out for a public client');
  }
}
