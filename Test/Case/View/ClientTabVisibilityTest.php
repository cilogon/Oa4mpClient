<?php
/**
 * Which tabs a client's tab bar shows (cilogon/Oa4mpClient#58).
 *
 * While a Named configuration is applied it takes the place of the client's
 * own scopes, claims, access token settings and authorization settings, so
 * the Scopes, Claims, Access Tokens and Authorization tabs are not shown.
 * A public client gets no cfg, so a Named configuration has nothing to
 * supply and the Named Configuration tab is not shown for it.
 *
 * The bar is View/Oa4mpClientCoOidcClients/tabs.inc (every other tabs.inc is
 * a symlink to it). It is drawn by about a dozen actions, some through
 * Registry core's Standard add and edit views, so it decides from two view
 * variables one model helper computes, and an unset variable shows the tab:
 * an action that forgets the helper draws today's full bar rather than a
 * broken one. A source check holds every action that draws the bar to the
 * helper.
 *
 * The bar itself is rendered here through a bare View; the action side is
 * driven through the callbacks controller harness.
 */

App::uses('View', 'View');
App::uses('CakeRequest', 'Network');

class ClientTabVisibilityTest extends Oa4mpClaimsControllerTestCase {

  const NAMED_CONFIG_VAR = 'vv_tab_named_config_applied';
  const PUBLIC_CLIENT_VAR = 'vv_tab_public_client';

  /**
   * Every action that draws the tab bar, by controller. Each one must set
   * the two variables from the client it loaded.
   */
  private $drawingActions = array(
    'Oa4mpClientCoOidcClientsController' => array('edit'),
    'Oa4mpClientCoScopesController' => array('edit_scopes'),
    'Oa4mpClientClaimsController' => array('add', 'edit', 'index'),
    'Oa4mpClientCoCallbacksController' => array('add', 'edit', 'index'),
    'Oa4mpClientRefreshTokensController' => array('manage'),
    'Oa4mpClientAccessTokensController' => array('manage'),
    'Oa4mpClientAuthorizationsController' => array('manage'),
    'Oa4mpClientAccessControlsController' => array('manage'),
    'Oa4mpClientCoNamedConfigsController' => array('manage'),
  );

  protected function fixtureTagPrefix() {
    return 'tabvis';
  }

  /** The label every tab is printed with. */
  private function labels() {
    return array(
      'properties' => _txt('me.properties'),
      'scopes' => _txt('pl.oa4mp_client_co_scope.scope.tab.name'),
      'callbacks' => _txt('pl.oa4mp_client_co_oidc_client.callbacks.tab.name'),
      'claims' => _txt('pl.oa4mp_client_co_oidc_client.claims.tab.name'),
      'refresh_tokens' => _txt('pl.oa4mp_client_refresh_token.tab.name'),
      'access_tokens' => _txt('pl.oa4mp_client_access_token.tab.name'),
      'authorization' => _txt('pl.oa4mp_client_authorization.tab.name'),
      'access_control' => _txt('pl.oa4mp_client_access_control.tab.name'),
      'named_config' => _txt('pl.oa4mp_client_co_named_config.tab.name'),
    );
  }

  /**
   * Render tabs.inc the way a view includes it, with $vars extracted into
   * its scope, as drawn from $controller's index page.
   */
  private function renderTabs($vars, $controller = 'oa4mp_client_co_callbacks') {
    $view = new View(null);
    $view->request = new CakeRequest('/', false);
    $view->request->params = array(
      'plugin' => 'oa4mp_client',
      'controller' => $controller,
      'action' => 'index',
      'named' => array()
    );
    $view->action = 'index';
    $view->loadHelper('Html');

    $path = App::pluginPath('Oa4mpClient') . 'View' . DS . 'Oa4mpClientCoOidcClients' . DS . 'tabs.inc';
    $this->assertTrue(is_readable($path), "the tab bar exists at $path");

    $include = function ($__path, $__vars) {
      extract($__vars);
      ob_start();
      include $__path;
      return ob_get_clean();
    };

    return Closure::bind($include, $view, 'View')($path, $vars + array('vv_client_id' => 42));
  }

  /** The tabs, by key, whose label appears in $html as a tab. */
  private function shownTabs($html) {
    $shown = array();
    foreach ($this->labels() as $key => $label) {
      if (strpos($html, '>' . $label . '<') !== false) {
        $shown[] = $key;
      }
    }
    return $shown;
  }

  private function allTabs() {
    return array_keys($this->labels());
  }

  /**
   * The source of one method in $source, from its signature to its closing
   * brace, or null when the method is not found.
   */
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

  /**
   * A client with a Named configuration applied: the four replaced tabs are
   * gone, and the edit page, Callbacks, Refresh Tokens, Access Control and
   * Named Configuration remain.
   */
  public function testNamedConfigClientBarHidesTheFourReplacedTabs() {
    $shown = $this->shownTabs($this->renderTabs(array(
      self::NAMED_CONFIG_VAR => true,
      self::PUBLIC_CLIENT_VAR => false
    )));

    $this->assertEqual(
      array('properties', 'callbacks', 'refresh_tokens', 'access_control', 'named_config'),
      $shown, 'the bar shows only the tabs a Named configuration does not replace');
  }

  /** A public client's bar leaves out only the Named Configuration tab. */
  public function testPublicClientBarHidesNamedConfigurationTab() {
    $shown = $this->shownTabs($this->renderTabs(array(
      self::NAMED_CONFIG_VAR => false,
      self::PUBLIC_CLIENT_VAR => true
    )));

    $this->assertEqual(array_values(array_diff($this->allTabs(), array('named_config'))),
      $shown, 'every tab but Named Configuration is shown');
  }

  /** An ordinary confidential client's bar shows every tab. */
  public function testConfidentialClientBarShowsEveryTab() {
    $shown = $this->shownTabs($this->renderTabs(array(
      self::NAMED_CONFIG_VAR => false,
      self::PUBLIC_CLIENT_VAR => false
    )));

    $this->assertEqual($this->allTabs(), $shown, 'every tab is shown');
  }

  /**
   * Neither variable set -- an action that never called the helper -- draws
   * the full bar, as before the tabs could be hidden.
   */
  public function testUnsetVariablesShowEveryTab() {
    $shown = $this->shownTabs($this->renderTabs(array()));

    $this->assertEqual($this->allTabs(), $shown, 'an unset variable shows the tab');
  }

  /**
   * The bar tests each variable only through empty(), so an unset variable
   * reads as "show" without a notice, whichever tab is the current one.
   */
  public function testTabBarTestsTheVariablesOnlyThroughEmpty() {
    $source = file_get_contents(App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientCoOidcClients' . DS . 'tabs.inc');

    foreach (array(self::NAMED_CONFIG_VAR, self::PUBLIC_CLIENT_VAR) as $var) {
      $all = substr_count($source, '$' . $var);
      $guarded = substr_count($source, 'empty($' . $var . ')');

      $this->assertTrue($all > 0, "premise: tabs.inc reads \$$var");
      $this->assertEqual($all, $guarded, "every read of \$$var in tabs.inc is inside empty()");
    }
  }

  /**
   * Every action that draws the tab bar sets the two variables through the
   * helper, and every plugin view directory that carries the bar belongs to
   * a controller listed here.
   */
  public function testEveryActionThatDrawsTheBarCallsTheHelper() {
    $base = App::pluginPath('Oa4mpClient');

    foreach ($this->drawingActions as $controller => $actions) {
      $path = $base . 'Controller' . DS . $controller . '.php';
      $this->assertTrue(is_readable($path), "the controller exists at $path");
      $source = file_get_contents($path);

      foreach ($actions as $action) {
        $body = $this->methodBody($source, $action);
        $this->assertNotEmpty($body, "premise: $controller has a $action() action");
        $this->assertTrue(
          (bool)preg_match('/\$this->set\(\$oa4mpServer->clientTabViewVars\(\$client\)\);/', (string)$body),
          "$controller::$action() sets the tab variables from the client it loaded");
      }
    }

    foreach (glob($base . 'View' . DS . '*' . DS . 'tabs.inc') as $tabs) {
      $dir = basename(dirname($tabs));
      $this->assertTrue(isset($this->drawingActions[$dir . 'Controller']),
        "the $dir views draw the tab bar, so its controller is held to the helper");
    }
  }

  /**
   * The helper answers from the loaded client: the Callbacks page of a
   * client with a Named configuration is told it is applied, and the same
   * page of an ordinary client is told it is not.
   */
  public function testCallbacksIndexSetsTheNamedConfigVariable() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->namedConfigClientId, $this->coId);
    $redirect = $harness->harnessInvoke('index', array(), 'GET');

    $this->assertNull($redirect, 'the Callbacks page rendered for the Named-configuration client');
    $this->assertTrue(($harness->viewVars[self::NAMED_CONFIG_VAR] ?? null) === true,
      'the Named configuration is reported applied');
    $this->assertTrue(($harness->viewVars[self::PUBLIC_CLIENT_VAR] ?? null) === false,
      'the client is reported confidential');

    $harness = Oa4mpCallbacksControllerHarness::build($this->clientId, $this->coId);
    $redirect = $harness->harnessInvoke('index', array(), 'GET');

    $this->assertNull($redirect, 'the Callbacks page rendered for the ordinary client');
    $this->assertTrue(($harness->viewVars[self::NAMED_CONFIG_VAR] ?? null) === false,
      'no Named configuration is reported applied');
  }

  /** The Callbacks page of a public client is told the client is public. */
  public function testCallbacksIndexSetsThePublicClientVariable() {
    $harness = Oa4mpCallbacksControllerHarness::build($this->publicClientId, $this->coId);
    $redirect = $harness->harnessInvoke('index', array(), 'GET');

    $this->assertNull($redirect, 'the Callbacks page rendered for the public client');
    $this->assertTrue(($harness->viewVars[self::PUBLIC_CLIENT_VAR] ?? null) === true,
      'the client is reported public');
  }
}
