<?php
/**
 * Shared scaffolding for the test cases that drive the claims controller.
 *
 * Test/Case/Controller/ClaimsControllerHarnessTest.php and
 * Test/Case/Controller/ClaimsWritePathTest.php both need the same client
 * graph -- a CO, an admin client with a default Dynamo configuration, an
 * ordinary OIDC client and a public one, a claim and its constraint -- and
 * both purge it the same way. That scaffolding was duplicated in the two
 * files, and one of the copied helpers carried a correctness fact easy to
 * lose in a copy: every count must flush CakePHP's in-memory query cache
 * first, or a read after a write returns the pre-write answer.
 *
 * The two files differ only in the fixture tag their rows are named with, so
 * that is the one thing a subclass supplies.
 *
 * Loaded by Console/Command/Oa4mpTestShell.php along with every other Test/lib
 * file, before any test case is required, so this file must have no side
 * effects at load time. It is abstract: the runner instantiates only the class
 * named after each file under Test/Case, so nothing here is discovered as a
 * test case of its own.
 */

App::uses('ConnectionManager', 'Model');

abstract class Oa4mpClaimsControllerTestCase extends Oa4mpTestCase {

  /** @var Oa4mpFixtures The seeded rows, or null once tearDown has purged them. */
  protected $fx = null;

  protected $coId = null;
  protected $adminId = null;
  protected $clientId = null;
  protected $publicClientId = null;
  protected $claimId = null;
  protected $constraintId = null;

  /**
   * The fixture tag prefix this test file names its rows with. One per file,
   * so a leaked row is traceable to the file that seeded it.
   *
   * @return string
   */
  abstract protected function fixtureTagPrefix();

  /**
   * Seed the client graph the claims actions are driven against.
   *
   * The runner reuses one instance for every method in a file, so every id is
   * cleared before it is re-seeded: a setUp() that failed part way through
   * must not leave the next test reading the previous test's client.
   */
  public function setUp() {
    $this->fx = null;
    $this->coId = null;
    $this->adminId = null;
    $this->clientId = null;
    $this->publicClientId = null;
    $this->claimId = null;
    $this->constraintId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag($this->fixtureTagPrefix());

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag);

    // admin() reads DefaultDynamoConfig, so seed one and exercise the real read.
    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $this->adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));

    $this->clientId = $this->fx->oidcClient($this->adminId, 'claims-' . $tag);
    $this->publicClientId = $this->fx->oidcClient($this->adminId, 'public-' . $tag,
      array('public_client' => true));

    $this->claimId = $this->fx->insert('cm_oa4mp_client_claims', array(
      'client_id' => $this->clientId,
      'claim_name' => 'eppn',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string'
    ));

    $this->constraintId = $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
      'claim_id' => $this->claimId,
      'constraint_field' => 'type',
      'constraint_value' => 'eppn'
    ));
  }

  /**
   * Purge everything the fixtures and the driven actions created.
   *
   * Survives a setUp() that got part way: with no fixture set there is
   * nothing to purge, and the client ids are only used once they exist.
   */
  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    // The driven actions insert and delete claims of their own, so purge by
    // client rather than by the ids this file happens to know. Constraints
    // first: they carry the foreign key into claims.
    $purge = array();
    if ($this->clientId !== null) {
      $clients = (int)$this->clientId . ', ' . (int)$this->publicClientId;
      $purge['cm_oa4mp_client_claim_constraints'] =
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id IN (' . $clients . '))';
      $purge['cm_oa4mp_client_claims'] = 'client_id IN (' . $clients . ')';
      $purge['cm_oa4mp_client_dynamo_configs'] = 'admin_id = ' . (int)$this->adminId;
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  /** A harness pointed at the ordinary (non-public) seeded client. */
  protected function harness($data = array()) {
    return Oa4mpClaimsControllerHarness::build($this->clientId, $this->coId, $data);
  }

  /** Claims currently attached to the seeded client. */
  protected function claimCount() {
    return $this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$this->clientId);
  }

  /**
   * Count rows, having first dropped CakePHP's in-memory query cache.
   *
   * DboSource::fetchAll() caches every SELECT by its exact SQL text and
   * nothing invalidates that cache on a write, so asking the same count
   * question twice around a write returns the first answer both times.
   */
  protected function countRows($fx, $table, $where) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $fx->count($table, $where);
  }

  /** The claims controller's source, for the source-text locks. */
  protected function controllerSource() {
    $path = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientClaimsController.php';
    $this->assertTrue(is_readable($path), "the claims controller exists at $path");

    return file_get_contents($path);
  }
}
