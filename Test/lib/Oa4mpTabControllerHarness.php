<?php
/**
 * Test-only harnesses for driving the Scopes and Authorization tab
 * controllers from the hermetic tier.
 *
 * They follow Oa4mpClaimsControllerHarness: the controller is constructed
 * directly, only what the action touches is hand-assigned (a request, the CO
 * context, the controller's model, a flash recorder), _oa4mpServer() returns
 * the recording fake, and redirect() records its target and throws so the
 * action stops where production would. The shared plumbing lives in the trait
 * below; each class names its controller, route and model.
 *
 * Loaded by Console/Command/Oa4mpTestShell.php after
 * Oa4mpClaimsControllerHarness.php (the glob is alphabetical), whose
 * Oa4mpHarnessOa4mpServer, Oa4mpHarnessFlash and Oa4mpHarnessRedirect it
 * reuses. No side effects at load time.
 *
 * See docs/plans/2026-10-14-1946-feat-named-config-read-only-tabs-plan.md
 * (KTD5).
 */

App::uses('Oa4mpClientCoScopesController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientAuthorizationsController', 'Oa4mpClient.Controller');
App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('ConnectionManager', 'Model');

trait Oa4mpTabControllerHarnessTrait {

  /** @var Oa4mpHarnessFlash The flash recorder. */
  public $Flash = null;

  /** @var Oa4mpHarnessOa4mpServer The fake _oa4mpServer() hands the action. */
  public $harnessServer = null;

  /** @var mixed The url of the last recorded redirect, or null if none. */
  public $harnessRedirect = null;

  /** @var array Every message log() was handed, in order. */
  public $harnessLogged = array();

  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->harnessLogged[] = (string)$msg;

    return true;
  }

  /**
   * Build a harness wired to one OIDC client.
   *
   * @param  integer $clientId Oa4mpClientCoOidcClient id the named parameter carries
   * @param  integer $coId     CO id for the hand-assigned CO context
   * @param  array   $data     Posted request data
   * @return static
   */
  public static function build($clientId, $coId, $data = array()) {
    // Every SELECT is cached by its SQL text and no write invalidates it, so
    // start each harness from the real current state of the database.
    ConnectionManager::getDataSource('default')->flushQueryCache();

    $harness = new static(new CakeRequest('/', false), new CakeResponse());

    $harness->harnessServer = new Oa4mpHarnessOa4mpServer();
    $harness->Flash = new Oa4mpHarnessFlash();

    $harness->request->params['plugin'] = 'oa4mp_client';
    $harness->request->params['controller'] = static::$harnessRoute;
    $harness->request->params['named'] = array('clientid' => $clientId);
    $harness->request->data = $data;

    $harness->cur_co = array('Co' => array('id' => $coId));

    // constructClasses() is deliberately not called; see
    // Oa4mpClaimsControllerHarness::build().
    $harness->loadModel(static::$harnessModel);

    return $harness;
  }

  /**
   * Drive one action with the given HTTP method.
   *
   * @param  string $action Action name
   * @param  string $method HTTP method the request should report
   * @return mixed  The recorded redirect target, or null if the action returned
   */
  public function harnessInvoke($action, $method = 'GET') {
    $prior = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = $method;

    $this->action = $action;
    $this->request->params['action'] = $action;
    $this->harnessRedirect = null;

    try {
      $this->$action();
    } catch (Oa4mpHarnessRedirect $e) {
      // The action stopped at its redirect, as it would in production.
    } finally {
      if ($prior === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $prior;
      }
    }

    return $this->harnessRedirect;
  }

  protected function _oa4mpServer() {
    return $this->harnessServer;
  }

  public function redirect($url, $status = null, $exit = true) {
    $this->autoRender = false;
    $this->harnessRedirect = $url;

    throw new Oa4mpHarnessRedirect($url);
  }
}

class Oa4mpScopesControllerHarness extends Oa4mpClientCoScopesController {
  use Oa4mpTabControllerHarnessTrait;

  protected static $harnessRoute = 'oa4mp_client_co_scopes';
  protected static $harnessModel = 'Oa4mpClient.Oa4mpClientCoScope';
}

class Oa4mpAuthorizationsControllerHarness extends Oa4mpClientAuthorizationsController {
  use Oa4mpTabControllerHarnessTrait;

  protected static $harnessRoute = 'oa4mp_client_authorizations';
  protected static $harnessModel = 'Oa4mpClient.Oa4mpClientAuthorization';
}
