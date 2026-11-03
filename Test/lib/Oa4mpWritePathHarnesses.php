<?php
/**
 * Test-only harnesses for driving the callbacks and authorization controllers
 * from the hermetic tier.
 *
 * Same technique as Test/lib/Oa4mpClaimsControllerHarness.php, whose header
 * explains it: construct the controller directly, hand-assign only what the
 * actions touch, substitute a fake OA4MP server through the controller's
 * _oa4mpServer() factory, and make redirect() record its target and throw.
 * The fakes (Oa4mpHarnessOa4mpServer, Oa4mpHarnessFlash, Oa4mpHarnessRedirect)
 * are reused from that file, which is required explicitly below because the
 * runner loads Test/lib in glob order and this file sorts after it only by
 * accident of naming.
 *
 * Written for cilogon/Oa4mpClient#48: both controllers sent posted URLs to the
 * OA4MP server before the plugin validated them, then discarded the failed
 * local save and reported success, so a callback or redirect URL with a stray
 * space left the server holding a value the plugin never stored.
 *
 * Loaded by Console/Command/Oa4mpTestShell.php along with every other Test/lib
 * file, so this file must have no side effects at load time.
 */

require_once __DIR__ . DS . 'Oa4mpClaimsControllerHarness.php';

App::uses('Oa4mpClientCoCallbacksController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientAuthorizationsController', 'Oa4mpClient.Controller');
App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('ConnectionManager', 'Model');

/**
 * The claims fake plus the one extra method the authorization manage() GET
 * tail calls. It delegates to the real model so the view variable it feeds is
 * computed exactly as in production.
 */
class Oa4mpWritePathOa4mpServer extends Oa4mpHarnessOa4mpServer {

  public function requiresActiveStatus($data) {
    return ClassRegistry::init('Oa4mpClient.Oa4mpClientOa4mpServer')->requiresActiveStatus($data);
  }

  /** The data argument of the most recent oa4mpEditClient() call, or null. */
  public function lastEditData() {
    foreach (array_reverse($this->calls) as $call) {
      if ($call['method'] === 'oa4mpEditClient') {
        return $call['data'];
      }
    }
    return null;
  }
}

/**
 * Everything the two harnesses share. See Oa4mpClaimsControllerHarness for
 * why redirect() throws and why constructClasses() is never called.
 */
trait Oa4mpWritePathHarnessTrait {

  public $Flash = null;

  /** @var Oa4mpWritePathOa4mpServer */
  public $harnessServer = null;

  public $harnessRedirect = null;

  public $harnessStopped = false;

  public $harnessLogged = array();

  /**
   * Wire a harness instance to one OIDC client.
   *
   * @param  object  $harness    A freshly constructed harness
   * @param  string  $controller Controller name for the request params
   * @param  string  $model      The one model the actions use
   * @param  integer $clientId   Oa4mpClientCoOidcClient id
   * @param  integer $coId       CO id
   * @param  array   $data       Posted request data
   * @return object  The harness
   */
  protected static function wire($harness, $controller, $model, $clientId, $coId, $data) {
    // See Oa4mpClaimsControllerHarness::build(): the query cache must not
    // carry a previous harness' view of the client graph.
    ConnectionManager::getDataSource('default')->flushQueryCache();

    $harness->harnessServer = new Oa4mpWritePathOa4mpServer();
    $harness->Flash = new Oa4mpHarnessFlash();

    $harness->request->params['plugin'] = 'oa4mp_client';
    $harness->request->params['controller'] = $controller;
    $harness->request->params['named'] = array('clientid' => $clientId);
    $harness->request->data = $data;

    $harness->cur_co = array('Co' => array('id' => $coId));

    $harness->loadModel($model);

    return $harness;
  }

  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->harnessLogged[] = (string)$msg;

    return true;
  }

  /**
   * Drive one action with $method as the reported HTTP verb.
   *
   * @return mixed The recorded redirect target, or null if the action returned
   */
  public function harnessInvoke($action, $args = array(), $method = 'GET') {
    $prior = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = $method;

    $this->action = $action;
    $this->request->params['action'] = $action;
    $this->harnessRedirect = null;
    $this->harnessStopped = false;

    try {
      call_user_func_array(array($this, $action), $args);
    } catch (Oa4mpHarnessRedirect $e) {
      $this->harnessStopped = true;
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

  /** Whether $message was flashed under $key. */
  public function harnessFlashed($message, $key) {
    foreach ($this->Flash->messages as $flash) {
      if ((string)$flash['message'] === (string)$message
          && ($flash['options']['key'] ?? null) === $key) {
        return true;
      }
    }
    return false;
  }

  /** Whether any success flash was recorded. */
  public function harnessFlashedAnySuccess() {
    foreach ($this->Flash->messages as $flash) {
      if (($flash['options']['key'] ?? null) === 'success') {
        return true;
      }
    }
    return false;
  }
}

class Oa4mpCallbacksControllerHarness extends Oa4mpClientCoCallbacksController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    return self::wire(new Oa4mpCallbacksControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_co_callbacks', 'Oa4mpClient.Oa4mpClientCoCallback', $clientId, $coId, $data);
  }
}

class Oa4mpAuthorizationsControllerHarness extends Oa4mpClientAuthorizationsController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    return self::wire(new Oa4mpAuthorizationsControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_authorizations', 'Oa4mpClient.Oa4mpClientAuthorization', $clientId, $coId, $data);
  }
}
