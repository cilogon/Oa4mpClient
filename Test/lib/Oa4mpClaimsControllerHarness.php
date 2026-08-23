<?php
/**
 * Test-only harness for driving Oa4mpClientClaimsController from the hermetic
 * tier.
 *
 * No test in this suite instantiates a controller through Cake's dispatcher:
 * that would need a Session, an Auth component, a Security component and a
 * rendered view. This harness instead constructs the controller directly,
 * hand-assigns only what the claims actions actually touch (a request, the CO
 * context, the claim model, a flash recorder) and substitutes a fake OA4MP
 * server for the real one, so an action can be driven and asserted without a
 * network call and without constructClasses().
 *
 * Two overrides make that possible:
 *
 *  - _oa4mpServer() returns the fake instead of a real Oa4mpClientOa4mpServer.
 *    That factory is the only production change; see the controller.
 *  - redirect() records its target and THROWS. It must not merely return:
 *    every caller in the controller assumes redirect() terminates the action.
 *    _blockIfPublicClient() would otherwise fall through into the POST branch
 *    and call the server anyway, and a success-path redirect would fall
 *    through into the whole GET tail (a second verification call, a saveField
 *    write and four database-backed type lookups).
 *
 * Loaded by Console/Command/Oa4mpTestShell.php along with every other
 * Test/lib file, in both the hermetic and the live run, so this file must have
 * no side effects at load time.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U8.
 */

App::uses('Oa4mpClientClaimsController', 'Oa4mpClient.Controller');
App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('ConnectionManager', 'Model');

/**
 * Thrown by the harness' redirect() so the driven action stops exactly where
 * the production _stop()/exit() would have stopped it.
 */
class Oa4mpHarnessRedirect extends Exception {

  /** @var mixed The url argument redirect() was called with. */
  public $url;

  public function __construct($url) {
    $this->url = $url;
    parent::__construct('harness redirect');
  }
}

/**
 * Stand-in for the Flash component. Records instead of writing to a session.
 */
class Oa4mpHarnessFlash {

  /** @var array List of array('message' => ..., 'options' => ...). */
  public $messages = array();

  public function set($message, $options = array()) {
    $this->messages[] = array('message' => $message, 'options' => $options);
  }

  /** The most recent flash message, or '' if none was set. */
  public function last() {
    if (empty($this->messages)) {
      return '';
    }
    $last = $this->messages[count($this->messages) - 1];
    return (string)$last['message'];
  }
}

/**
 * Stand-in for Oa4mpClientOa4mpServer. Returns whatever verdict the test
 * configures and records every call, so a test can prove which server object
 * the action actually used and which branch that verdict selected.
 *
 * Deliberately does NOT extend Oa4mpClientOa4mpServer: inheriting would drag
 * in the real model's constructor and leave un-overridden HTTP methods
 * reachable. The controller only ever calls the two methods below.
 */
class Oa4mpHarnessOa4mpServer {

  /** @var integer Verdict oa4mpEditClient() returns: 0 error, 2 out of sync. */
  public $editClientReturn = 1;

  /** @var boolean Verdict oa4mpVerifyClient() reports. */
  public $verifySynchronized = true;

  /** @var mixed oa4mp_server_extra oa4mpVerifyClient() reports with extras. */
  public $verifyExtra = null;

  /** @var array One entry per call, in order. */
  public $calls = array();

  public function oa4mpEditClient($adminClient, $curData, $data) {
    $this->calls[] = array(
      'method' => 'oa4mpEditClient',
      'curData' => $curData,
      'data' => $data
    );

    return $this->editClientReturn;
  }

  public function oa4mpVerifyClient($adminClient, $curClient, $returnExtras = false) {
    $this->calls[] = array(
      'method' => 'oa4mpVerifyClient',
      'returnExtras' => $returnExtras
    );

    if ($returnExtras) {
      return array(
        'synchronized' => $this->verifySynchronized,
        'oa4mp_server_extra' => $this->verifyExtra
      );
    }

    return $this->verifySynchronized;
  }

  /** The ordered list of method names this fake was called with. */
  public function callNames() {
    $names = array();
    foreach ($this->calls as $call) {
      $names[] = $call['method'];
    }
    return $names;
  }
}

class Oa4mpClaimsControllerHarness extends Oa4mpClientClaimsController {

  // Declared so assigning the flash recorder does not create a dynamic
  // property (deprecated in PHP 8.2, and this suite runs on PHP 8.4). The
  // real Flash is a component the component collection would create; the
  // harness never runs constructClasses(), so it supplies its own.
  public $Flash = null;

  /** @var Oa4mpHarnessOa4mpServer The fake _oa4mpServer() hands the action. */
  public $harnessServer = null;

  /** @var mixed The url of the last recorded redirect, or null if none. */
  public $harnessRedirect = null;

  /** @var integer How many times redirect() was called. */
  public $harnessRedirectCount = 0;

  /** @var boolean Whether the last driven action ended in a redirect. */
  public $harnessStopped = false;

  /**
   * Build a harness wired to one OIDC client.
   *
   * $data is the posted body. The claim-constraint key is always present:
   * add() and edit() dereference $this->request->data['Oa4mpClientClaimConstraint']
   * unconditionally and raise a TypeError in array_filter() without it.
   *
   * @param  integer $clientId Oa4mpClientCoOidcClient id the named parameter carries
   * @param  integer $coId     CO id for the hand-assigned CO context
   * @param  array   $data     Posted request data, merged over the constraint key
   * @return Oa4mpClaimsControllerHarness
   */
  public static function build($clientId, $coId, $data = array()) {
    // DboSource::fetchAll() caches every SELECT by its exact SQL text and
    // nothing invalidates that cache on a write, so a second harness built in
    // the same test would otherwise be handed the client graph the first one
    // saw. Start every harness from the real current state of the database.
    ConnectionManager::getDataSource('default')->flushQueryCache();

    $harness = new Oa4mpClaimsControllerHarness(new CakeRequest('/', false), new CakeResponse());

    $harness->harnessServer = new Oa4mpHarnessOa4mpServer();
    $harness->Flash = new Oa4mpHarnessFlash();

    $harness->request->params['plugin'] = 'oa4mp_client';
    $harness->request->params['controller'] = 'oa4mp_client_claims';
    $harness->request->params['named'] = array('clientid' => $clientId);
    $harness->request->data = $data + array('Oa4mpClientClaimConstraint' => array());

    $harness->cur_co = array('Co' => array('id' => $coId));

    // constructClasses() is deliberately not called: it runs the component
    // collection init and would instantiate Auth, Security, Session,
    // RequestHandler, Role, Paginator and the plugin's authz component. The
    // claims actions need exactly one model, so load exactly that.
    $harness->loadModel('Oa4mpClient.Oa4mpClientClaim');

    return $harness;
  }

  /**
   * Drive one action.
   *
   * $method is the HTTP verb the request should report; CakeRequest resolves
   * its post/put detectors through env(), so it is set on $_SERVER for the
   * duration of the call and restored afterwards.
   *
   * @param  string $action Action name, e.g. 'add', 'edit', 'delete', 'index'
   * @param  array  $args   Positional arguments, e.g. array($claimId)
   * @param  string $method HTTP method the request should report
   * @return mixed  The recorded redirect target, or null if the action returned
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

  /**
   * Return the fake instead of a real Oa4mpClientOa4mpServer. This is the
   * whole point of the production factory.
   */
  protected function _oa4mpServer() {
    return $this->harnessServer;
  }

  /**
   * Record the target and stop the action.
   *
   * Signature matches Controller::redirect() and COmanage AppController's
   * override of it, both public. Returning here instead of throwing would let
   * the action run on past a guard that assumes it terminated.
   */
  public function redirect($url, $status = null, $exit = true) {
    $this->autoRender = false;
    $this->harnessRedirect = $url;
    $this->harnessRedirectCount++;

    throw new Oa4mpHarnessRedirect($url);
  }
}
