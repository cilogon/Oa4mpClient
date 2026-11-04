<?php
/**
 * Test-only harnesses for driving the controllers that write to the OA4MP
 * server (callbacks, authorizations, refresh and access tokens, access
 * controls, scopes, named configurations and OIDC clients) from the hermetic
 * tier.
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
App::uses('Oa4mpClientRefreshTokensController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientAccessTokensController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientAccessControlsController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientCoScopesController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientCoNamedConfigsController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientCoOidcClientsController', 'Oa4mpClient.Controller');
App::uses('Oa4mpClientRefreshToken', 'Oa4mpClient.Model');
App::uses('Oa4mpClientAccessToken', 'Oa4mpClient.Model');
App::uses('Oa4mpClientAccessControl', 'Oa4mpClient.Model');
App::uses('Oa4mpClientCoScope', 'Oa4mpClient.Model');
App::uses('Oa4mpClientCoOidcClient', 'Oa4mpClient.Model');
App::uses('Oa4mpClientDynamoConfig', 'Oa4mpClient.Model');
App::uses('ClassRegistry', 'Utility');
App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('ConnectionManager', 'Model');

/**
 * The claims fake plus the extra methods the authorization manage() GET tail
 * and OIDC client add() call. The pure data helpers delegate to the real model
 * so what they feed is computed exactly as in production; only the create
 * request itself is faked.
 */
class Oa4mpWritePathOa4mpServer extends Oa4mpHarnessOa4mpServer {

  /**
   * @var array What oa4mpNewClient() returns. Set it to array() to make the
   *            create fail the way the real model reports a failed request.
   */
  public $newClientReturn = array('clientId' => 'harness-client-id', 'secret' => 'not-a-real-secret');

  public function requiresActiveStatus($data) {
    return ClassRegistry::init('Oa4mpClient.Oa4mpClientOa4mpServer')->requiresActiveStatus($data);
  }

  public function newClientData($adminClient, $data) {
    return ClassRegistry::init('Oa4mpClient.Oa4mpClientOa4mpServer')->newClientData($adminClient, $data);
  }

  public function oa4mpNewClient($adminClient, $data) {
    $this->calls[] = array(
      'method' => 'oa4mpNewClient',
      'data' => $data
    );

    return $this->newClientReturn;
  }

  /** The data argument of the most recent oa4mpNewClient() call, or null. */
  public function lastNewClientData() {
    foreach (array_reverse($this->calls) as $call) {
      if ($call['method'] === 'oa4mpNewClient') {
        return $call['data'];
      }
    }
    return null;
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
 * Stand-in for Registry's Role component that reports a CO administrator, so
 * the access controls page and the OIDC client add form setup take their
 * administrator branch and never read the session the harness does not have.
 */
class Oa4mpHarnessAdminRole {

  public function calculateCMRoles() {
    return array(
      'cmadmin' => true,
      'coadmin' => true,
      'coapprover' => false,
      'couadmin' => false,
      'couapprover' => false,
      'admincous' => null,
      'comember' => false,
      'admin' => false,
      'subadmin' => false,
      'user' => false,
      'apiuser' => false,
      'orgidentityid' => false,
      'copersonid' => false,
      'orgidentities' => null
    );
  }
}

/**
 * Makes one named write method of a real model return false without touching
 * the database, so a test can force "the server accepted the change, then the
 * Registry save failed". Every other method, the table, the associations and
 * the validation rules are the real model's.
 *
 * Only the named method fails. saveField() and saveAssociated() call save()
 * internally, so failing 'save' also fails those on the same model.
 */
trait Oa4mpHarnessFailingWrites {

  /** @var string|null The write method that returns false, or null for none. */
  public $harnessFailOn = null;

  public function save($data = null, $validate = true, $fieldList = array()) {
    if ($this->harnessFailOn === 'save') {
      return false;
    }
    return parent::save($data, $validate, $fieldList);
  }

  public function saveAssociated($data = null, $options = array()) {
    if ($this->harnessFailOn === 'saveAssociated') {
      return false;
    }
    return parent::saveAssociated($data, $options);
  }

  public function saveField($name, $value, $validate = false) {
    if ($this->harnessFailOn === 'saveField') {
      return false;
    }
    return parent::saveField($name, $value, $validate);
  }

  public function delete($id = null, $cascade = true) {
    if ($this->harnessFailOn === 'delete') {
      return false;
    }
    return parent::delete($id, $cascade);
  }
}

// One failing subclass per model a write path saves through after the server
// accepted the change. harnessFailWrites() finds them by this naming rule.
class Oa4mpHarnessFailingOa4mpClientRefreshToken extends Oa4mpClientRefreshToken {
  use Oa4mpHarnessFailingWrites;
}

class Oa4mpHarnessFailingOa4mpClientAccessToken extends Oa4mpClientAccessToken {
  use Oa4mpHarnessFailingWrites;
}

class Oa4mpHarnessFailingOa4mpClientAccessControl extends Oa4mpClientAccessControl {
  use Oa4mpHarnessFailingWrites;
}

class Oa4mpHarnessFailingOa4mpClientCoScope extends Oa4mpClientCoScope {
  use Oa4mpHarnessFailingWrites;
}

class Oa4mpHarnessFailingOa4mpClientCoOidcClient extends Oa4mpClientCoOidcClient {
  use Oa4mpHarnessFailingWrites;
}

class Oa4mpHarnessFailingOa4mpClientDynamoConfig extends Oa4mpClientDynamoConfig {
  use Oa4mpHarnessFailingWrites;
}

/**
 * Everything the harnesses share. See Oa4mpClaimsControllerHarness for
 * why redirect() throws and why constructClasses() is never called.
 */
trait Oa4mpWritePathHarnessTrait {

  public $Flash = null;

  /** @var Oa4mpWritePathOa4mpServer */
  public $harnessServer = null;

  public $harnessRedirect = null;

  public $harnessStopped = false;

  public $harnessLogged = array();

  /** @var Oa4mpHarnessAdminRole|null Set only by harnesses whose action reads roles. */
  public $Role = null;

  /** @var string|null Alias of the model wire() loaded. */
  public $harnessModel = null;

  /** @var array View names render() was called with, in order (null is the action's own). */
  public $harnessRendered = array();

  /**
   * Wire a harness instance to one OIDC client.
   *
   * @param  object  $harness    A freshly constructed harness
   * @param  string  $controller Controller name for the request params
   * @param  string  $model      The one model the actions use
   * @param  integer $clientId   Oa4mpClientCoOidcClient id, or null for an
   *                             action that takes no clientid named parameter
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
    $harness->request->params['named'] = ($clientId === null) ? array() : array('clientid' => $clientId);
    $harness->request->data = $data;

    $harness->cur_co = array('Co' => array('id' => $coId));

    $harness->loadModel($model);
    list(, $harness->harnessModel) = pluginSplit($model);

    return $harness;
  }

  /**
   * Replace a model with its failing subclass, whose $method write returns
   * false while everything else behaves as the real model.
   *
   * Call after build(). $path names the model by the property chain from the
   * controller, e.g. array('Oa4mpClientCoNamedConfig', 'Oa4mpClientCoAdminClient',
   * 'Oa4mpClientCoOidcClient'); null means the controller's own model. Only
   * that one reference is replaced: the same model reached another way, or
   * through ClassRegistry, is still the real one.
   *
   * @param  string     $method 'save', 'saveAssociated', 'saveField' or 'delete'
   * @param  array|null $path   Property chain from the controller to the model
   * @return Model      The installed replacement
   * @throws InvalidArgumentException For an unknown method or a model with no failing subclass
   */
  public function harnessFailWrites($method, $path = null) {
    if (!in_array($method, array('save', 'saveAssociated', 'saveField', 'delete'), true)) {
      throw new InvalidArgumentException("harnessFailWrites: unsupported write method '$method'");
    }

    $path = ($path === null) ? array($this->harnessModel) : array_values($path);
    $property = array_pop($path);

    $owner = $this;
    foreach ($path as $step) {
      $owner = $owner->{$step};
    }

    // Reading the property first makes Cake load a lazy association, so the
    // real instance is registered before the replacement is constructed.
    $real = $owner->{$property};
    $class = 'Oa4mpHarnessFailing' . get_class($real);
    if (!class_exists($class, false)) {
      throw new InvalidArgumentException("harnessFailWrites: no $class defined in " . basename(__FILE__));
    }

    $failing = new $class(array(
      'alias' => $real->alias,
      'plugin' => $real->plugin,
      'table' => $real->useTable,
      'ds' => $real->useDbConfig
    ));

    // The Model constructor registers itself when the alias is not yet
    // registered. Keep a failing model out of the registry so it cannot leak
    // into later tests.
    if (ClassRegistry::getObject($real->alias) === $failing) {
      ClassRegistry::removeObject($real->alias);
      ClassRegistry::addObject($real->alias, $real);
    }

    $failing->harnessFailOn = $method;
    $owner->{$property} = $failing;

    return $failing;
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

  /**
   * Record the view and render nothing: the thin runner cannot render a full
   * plugin view. Unlike redirect(), the action continues, as it would after a
   * real render().
   */
  public function render($view = null, $layout = null) {
    $this->autoRender = false;
    $this->harnessRendered[] = $view;

    return $this->response;
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

class Oa4mpRefreshTokensControllerHarness extends Oa4mpClientRefreshTokensController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    return self::wire(new Oa4mpRefreshTokensControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_refresh_tokens', 'Oa4mpClient.Oa4mpClientRefreshToken', $clientId, $coId, $data);
  }
}

class Oa4mpAccessTokensControllerHarness extends Oa4mpClientAccessTokensController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    return self::wire(new Oa4mpAccessTokensControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_access_tokens', 'Oa4mpClient.Oa4mpClientAccessToken', $clientId, $coId, $data);
  }
}

class Oa4mpAccessControlsControllerHarness extends Oa4mpClientAccessControlsController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    $harness = self::wire(new Oa4mpAccessControlsControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_access_controls', 'Oa4mpClient.Oa4mpClientAccessControl', $clientId, $coId, $data);
    $harness->Role = new Oa4mpHarnessAdminRole();

    return $harness;
  }
}

class Oa4mpScopesControllerHarness extends Oa4mpClientCoScopesController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    return self::wire(new Oa4mpScopesControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_co_scopes', 'Oa4mpClient.Oa4mpClientCoScope', $clientId, $coId, $data);
  }
}

class Oa4mpNamedConfigsControllerHarness extends Oa4mpClientCoNamedConfigsController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    return self::wire(new Oa4mpNamedConfigsControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_co_named_configs', 'Oa4mpClient.Oa4mpClientCoNamedConfig', $clientId, $coId, $data);
  }
}

/**
 * add() reads the CO from cur_co and edit() takes the client id as its
 * argument, so neither needs a clientid named parameter; pass null.
 */
class Oa4mpOidcClientsControllerHarness extends Oa4mpClientCoOidcClientsController {
  use Oa4mpWritePathHarnessTrait;

  public static function build($clientId, $coId, $data = array()) {
    $harness = self::wire(new Oa4mpOidcClientsControllerHarness(new CakeRequest('/', false), new CakeResponse()),
      'oa4mp_client_co_oidc_clients', 'Oa4mpClient.Oa4mpClientCoOidcClient', $clientId, $coId, $data);
    $harness->Role = new Oa4mpHarnessAdminRole();

    return $harness;
  }
}
