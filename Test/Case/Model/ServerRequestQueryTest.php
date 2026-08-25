<?php
/**
 * Which query each kind of OA4MP server request carries (U1, R1, R2, R8).
 *
 * The client-read asks the server for its newest client representation by
 * carrying api_version=latest. Without it the server answers with an older
 * representation that omits rt_grace_period and several sibling settings
 * entirely, so those settings never reach the extras blob and are silently
 * dropped from the body of the next update. Adding the parameter is what makes
 * them exist to be preserved.
 *
 * Only the read carries it. The update, delete, and create requests must be
 * byte-for-byte what they were, and that is not a detail -- whether the update
 * also needs to declare the version could not be established from this
 * repository, and the plan records it as assumption A1 with a live check
 * gating the deploy. These tests are the evidence the parameter did NOT leak
 * into the other three.
 *
 * Everything is driven through oa4mpBuildRequest() rather than through
 * oa4mpVerifyClient(), oa4mpEditClient(), oa4mpDeleteClient(), or
 * oa4mpNewClient(), because each of those constructs an HttpSocket and reaches
 * a real server. That seam is the point, and it is deliberately the whole
 * request rather than just the query: a query builder alone proves what the
 * builder returns, not that any call site assigns it, so an unwired-but-correct
 * builder would pass. Asserting the wiring with a source scan instead is the
 * false-coverage shape recorded in
 * docs/solutions/test-failures/oa4mp-green-run-does-not-prove-a-test-can-fail.md.
 *
 * See docs/plans/2026-08-25-0459-feat-oa4mp-client-read-api-version-latest-plan.md U1.
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');

/** The model with the two request-building seams reachable by name. */
class Oa4mpRequestBuildingProbe extends Oa4mpClientOa4mpServer {

  public function queryFor($kind, $clientIdentifier = null) {
    return $this->oa4mpRequestQuery($kind, $clientIdentifier);
  }

  public function requestFor($kind, $adminClient, $clientIdentifier = null) {
    return $this->oa4mpBuildRequest($kind, $adminClient, $clientIdentifier);
  }
}

/**
 * Raised by the probe below instead of returning a request, to stop each
 * sender before it reaches the network.
 */
class Oa4mpRequestKindObserved extends Exception {

  /** @var string The kind the sender asked for. */
  public $kind = null;

  public function __construct($kind) {
    $this->kind = $kind;
    parent::__construct("request kind observed: " . $kind);
  }
}

/**
 * Observes which kind each sender asks for.
 *
 * The whole change reduces to four string literals, one per sender. A test
 * that only exercises the builder cannot see them: point oa4mpVerifyClient at
 * 'update', or back at oa4mpInitializeRequest, and every builder assertion in
 * this file still passes. Overriding the seam and raising from it stops each
 * sender at the moment it names its kind -- before HttpSocket::request() -- so
 * the literal is observable without a server.
 */
class Oa4mpRequestKindProbe extends Oa4mpClientOa4mpServer {

  protected function oa4mpBuildRequest($kind, $adminClient, $clientIdentifier = null) {
    throw new Oa4mpRequestKindObserved($kind);
  }
}

/**
 * The same probe for the update path only.
 *
 * oa4mpEditClient() verifies before it builds, and the real verification
 * reaches a server, so it has to be answered for the update path to get as far
 * as naming its kind. That stub lives here rather than on the probe above
 * because stubbing oa4mpVerifyClient there would make the read test observe
 * the stub instead of the sender it is meant to be watching -- which is exactly
 * what it did on the first attempt.
 */
class Oa4mpUpdateKindProbe extends Oa4mpRequestKindProbe {

  public function oa4mpVerifyClient($adminClient, $curClient, $returnExtras = false) {
    if($returnExtras) {
      return array('synchronized' => true, 'oa4mp_server_extra' => null,
                   'error' => false);
    }

    return true;
  }
}

class ServerRequestQueryTest extends Oa4mpTestCase {

  /** Nothing holds instance state; each test builds what it needs. */
  public function setUp() {
  }

  private function probe() {
    return new Oa4mpRequestBuildingProbe();
  }

  private function adminClient() {
    return array(
      'Oa4mpClientCoAdminClient' => array(
        'serverurl' => 'https://oa4mp.example.org/oauth2/register',
        'admin_identifier' => 'cilogon:/client_id/admin',
        'secret' => 'not-a-real-secret',
        'co_id' => 1
      )
    );
  }

  private function clientIdentifier() {
    return 'cilogon:/client_id/abc123';
  }

  // ------------------------------------------------------------------
  // The query each request kind carries.

  public function testReadQueryCarriesTheNewestRepresentation() {
    $query = $this->probe()->queryFor('read', $this->clientIdentifier());

    $this->assertEqual('latest', $query['api_version'],
      'The client-read must ask for the newest representation, or rt_grace_period'
      . ' is never reported and cannot be preserved');
    $this->assertEqual($this->clientIdentifier(), $query['client_id'],
      'The client-read must still identify the client');
  }

  public function testUpdateQueryDoesNotCarryTheVersion() {
    $query = $this->probe()->queryFor('update', $this->clientIdentifier());

    $this->assertEqual(array('client_id' => $this->clientIdentifier()), $query,
      'The update query is unchanged by this work. Whether the server needs the'
      . ' version declared here is assumption A1, settled by a live check, not here');
  }

  public function testDeleteQueryDoesNotCarryTheVersion() {
    $query = $this->probe()->queryFor('delete', $this->clientIdentifier());

    $this->assertEqual(array('client_id' => $this->clientIdentifier()), $query,
      'The delete query is unchanged by this work');
  }

  public function testCreateCarriesNoQueryAtAll() {
    $this->assertEqual(array(), $this->probe()->queryFor('create'),
      'The create request carries no query');
  }

  public function testAnUnknownRequestKindRaises() {
    try {
      $this->probe()->queryFor('sideways', $this->clientIdentifier());
      $this->fail('An unrecognised request kind must raise rather than silently'
                  . ' producing a query with no version and no identifier');
    }
    catch(InvalidArgumentException $e) {
      $this->assertContains('sideways', $e->getMessage(),
        'The message must name the kind that was not recognised');
    }
  }

  // ------------------------------------------------------------------
  // That each call site's request actually carries the built query.
  //
  // These are the assertions a query-only seam could not make. Unwire any one
  // call site in oa4mpBuildRequest() and only that kind's test below fails.

  public function testReadRequestIsAGetCarryingTheVersion() {
    $request = $this->probe()->requestFor('read', $this->adminClient(),
                                          $this->clientIdentifier());

    $this->assertEqual('GET', $request['method']);
    $this->assertEqual('latest', $request['uri']['query']['api_version'],
      'The built read request must carry the version, not merely be able to');
    $this->assertEqual($this->clientIdentifier(), $request['uri']['query']['client_id']);
  }

  public function testUpdateRequestIsAPutWithNoVersion() {
    $request = $this->probe()->requestFor('update', $this->adminClient(),
                                          $this->clientIdentifier());

    $this->assertEqual('PUT', $request['method']);
    $this->assertFalse(array_key_exists('api_version', $request['uri']['query']),
      'The version must not leak into the update request');
    $this->assertEqual($this->clientIdentifier(), $request['uri']['query']['client_id']);
  }

  public function testDeleteRequestIsADeleteWithNoVersion() {
    $request = $this->probe()->requestFor('delete', $this->adminClient(),
                                          $this->clientIdentifier());

    $this->assertEqual('DELETE', $request['method']);
    $this->assertFalse(array_key_exists('api_version', $request['uri']['query']),
      'The version must not leak into the delete request');
    $this->assertEqual($this->clientIdentifier(), $request['uri']['query']['client_id']);
  }

  public function testCreateRequestIsAPostWithNoQueryKey() {
    $request = $this->probe()->requestFor('create', $this->adminClient());

    $this->assertEqual('POST', $request['method']);
    $this->assertFalse(array_key_exists('query', $request['uri']),
      'The create request must carry no query key at all. An empty query array'
      . ' would leave HttpSocket to decide how to render it, which is a change'
      . ' to a request this work must not touch');
  }

  // ------------------------------------------------------------------
  // Which kind each sender actually asks for.
  //
  // The builder assertions above prove what each kind produces. They cannot
  // prove that oa4mpVerifyClient asks for 'read' -- swap it to 'update' and
  // every test above still passes, while the plugin silently stops asking the
  // server for its newest representation and the grace period goes back to
  // being dropped. These four are the only tests that see that.

  private function kindAskedFor($invoke, $probe = null) {
    if($probe === null) {
      $probe = new Oa4mpRequestKindProbe();
    }

    try {
      $invoke($probe);
    }
    catch(Oa4mpRequestKindObserved $observed) {
      return $observed->kind;
    }

    $this->fail('the sender did not build a request through the seam at all,'
                . ' so the request it sends is unobserved');

    return null;
  }

  public function testTheClientReadAsksForTheReadKind() {
    $admin = $this->adminClient();
    $client = array('Oa4mpClientCoOidcClient' =>
                    array('oa4mp_identifier' => $this->clientIdentifier()));

    $this->assertEqual('read', $this->kindAskedFor(
      function($probe) use ($admin, $client) {
        $probe->oa4mpVerifyClient($admin, $client, true);
      }),
      'the client-read must ask for the read kind, which is the only kind that'
      . ' carries the version. Any other kind silently reverts this change');
  }

  public function testTheClientUpdateAsksForTheUpdateKind() {
    $admin = $this->adminClient();
    $client = array('Oa4mpClientCoOidcClient' =>
                    array('oa4mp_identifier' => $this->clientIdentifier()));

    $this->assertEqual('update', $this->kindAskedFor(
      function($probe) use ($admin, $client) {
        $probe->oa4mpEditClient($admin, $client, $client);
      }, new Oa4mpUpdateKindProbe()),
      'the client-update must ask for the update kind');
  }

  public function testTheClientDeleteAsksForTheDeleteKind() {
    $admin = $this->adminClient();
    $client = array('Oa4mpClientCoOidcClient' =>
                    array('oa4mp_identifier' => $this->clientIdentifier()));

    $this->assertEqual('delete', $this->kindAskedFor(
      function($probe) use ($admin, $client) {
        $probe->oa4mpDeleteClient($admin, $client);
      }),
      'the client-delete must ask for the delete kind');
  }

  public function testTheClientCreateAsksForTheCreateKind() {
    $admin = $this->adminClient();

    $this->assertEqual('create', $this->kindAskedFor(
      function($probe) use ($admin) {
        $probe->oa4mpNewClient($admin, array());
      }),
      'the client-create must ask for the create kind, which carries no query');
  }

  public function testTheUnknownKindGuardOnTheRequestBuilderAlsoRaises() {
    try {
      $this->probe()->requestFor('sideways', $this->adminClient(),
                                 $this->clientIdentifier());
      $this->fail('the request builder must raise on an unrecognised kind too,'
                  . ' not only the query builder');
    }
    catch(InvalidArgumentException $e) {
      $this->assertContains('sideways', $e->getMessage(),
        'the message must name the kind that was not recognised');
    }
  }

  public function testEveryRequestKindStillCarriesAuthorization() {
    foreach(array('read', 'update', 'delete', 'create') as $kind) {
      $identifier = $kind === 'create' ? null : $this->clientIdentifier();
      $request = $this->probe()->requestFor($kind, $this->adminClient(), $identifier);

      $this->assertNotEmpty($request['header']['Authorization'],
        "The $kind request must still carry the admin client's bearer token");
      $this->assertEqual('oa4mp.example.org', $request['uri']['host'],
        "The $kind request must still target the admin client's server");
    }
  }
}
