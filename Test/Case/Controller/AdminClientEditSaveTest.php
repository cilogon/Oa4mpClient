<?php
/**
 * Regression tests for the admin-client duplicate-insert bug
 * (docs/solutions/logic-errors/oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md, #1).
 *
 * Editing an admin client saves through CakePHP's associated save. For a hasOne
 * child that save UPDATEs in place only when the submitted child data carries
 * its primary key, and a CakePHP form only submits fields it renders. The edit
 * form rendered the visible DefaultDynamoConfig inputs but not its hidden id,
 * so every save inserted another fully-populated dynamo-config row. The sibling
 * DefaultLdapConfig, whose hidden id *was* rendered, updated correctly -- the
 * behavioral contrast that pinned the mechanism.
 *
 * Both halves are locked: that the save really does duplicate without the id
 * (exercised against the real database), and that the edit form still renders
 * the hidden id that keeps it from happening.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U6 (R3, R5).
 */

class AdminClientEditSaveTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;
  private $dynamoConfigId;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = 'oa4mpadmin-' . getmypid() . '-' . substr(uniqid(), -6);

    $this->coId = $this->fx->insert('cm_cos', array(
      'name' => 'CO ' . $tag,
      'description' => 'hermetic admin-client test CO',
      'status' => 'A'
    ));

    $this->adminId = $this->fx->insert('cm_oa4mp_client_co_admin_clients', array(
      'co_id' => $this->coId,
      'serverurl' => 'https://oa4mp.example.org/oauth2',
      'name' => 'admin ' . $tag,
      'issuer' => 'https://example.org',
      'admin_identifier' => 'admin:' . $tag,
      'secret' => 'not-a-real-secret',
      'qdl_claim_source' => 'source.qdl',
      'qdl_claim_process' => 'process.qdl'
    ));

    $this->dynamoConfigId = $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $this->adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-key',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }
    $this->fx->cleanup(array(
      'cm_oa4mp_client_dynamo_configs' => 'admin_id = ' . (int)$this->adminId
    ));
    $this->fx = null;
  }

  private function adminClientModel() {
    return $this->model('Oa4mpClient.Oa4mpClientCoAdminClient');
  }

  private function dynamoConfigCount() {
    return $this->fx->count('cm_oa4mp_client_dynamo_configs',
      'admin_id = ' . (int)$this->adminId);
  }

  /**
   * The POST body the edit form produces. $withId mirrors whether the hidden
   * DefaultDynamoConfig.id field is rendered.
   */
  private function editPost($withId, $tableName) {
    $dynamo = array(
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-key',
      'table_name' => $tableName,
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    );

    if ($withId) {
      $dynamo['id'] = $this->dynamoConfigId;
    }

    return array(
      'Oa4mpClientCoAdminClient' => array(
        'id' => $this->adminId,
        'co_id' => $this->coId,
        'serverurl' => 'https://oa4mp.example.org/oauth2',
        'name' => 'admin renamed',
        'issuer' => 'https://example.org',
        'admin_identifier' => 'admin:hermetic',
        'secret' => 'not-a-real-secret',
        'qdl_claim_source' => 'source.qdl',
        'qdl_claim_process' => 'process.qdl'
      ),
      'DefaultDynamoConfig' => $dynamo
    );
  }

  private function save($data) {
    $model = $this->adminClientModel();
    $model->clear();
    return $model->saveAssociated($data);
  }

  /**
   * With the hidden id in the POST -- what the form renders today -- repeated
   * saves update the existing dynamo config in place.
   */
  public function testSaveWithDynamoConfigIdUpdatesInPlace() {
    $this->assertTrue((bool)$this->save($this->editPost(true, 'first-save')),
      'the first save succeeds');
    $this->assertTrue((bool)$this->save($this->editPost(true, 'second-save')),
      'the second save succeeds');

    $this->assertEqual(1, $this->dynamoConfigCount(),
      'two saves with the id present leave exactly one dynamo config');
    $this->assertEqual('second-save', $this->fx->scalar(
      'SELECT table_name FROM cm_oa4mp_client_dynamo_configs WHERE id = '
      . (int)$this->dynamoConfigId), 'the existing row was updated in place');
  }

  /**
   * Without the id -- the pre-fix form -- the same save inserts another row.
   * This is the mechanism the hidden field exists to prevent; it is
   * characterized here so the lock below has a demonstrated reason.
   */
  public function testSaveWithoutDynamoConfigIdInsertsDuplicate() {
    $this->assertTrue((bool)$this->save($this->editPost(false, 'no-id-save')),
      'the save succeeds');

    $this->assertEqual(2, $this->dynamoConfigCount(),
      'omitting the id inserts a second dynamo config instead of updating');
  }

  /**
   * The lock: the admin-client edit form must render the hidden
   * DefaultDynamoConfig.id inside the edit-mode guard, mirroring its
   * DefaultLdapConfig.id sibling.
   */
  public function testEditFormRendersHiddenDynamoConfigId() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientCoAdminClients' . DS . 'fields.inc';
    $this->assertTrue(is_readable($path), "the admin-client fields include exists at $path");

    $guard = $this->editGuardBlock(file_get_contents($path));
    $this->assertNotEmpty($guard, 'the edit-mode hidden-field guard is present');

    $this->assertContains("Form->hidden('DefaultLdapConfig.id'", $guard,
      'the LDAP config id is submitted');
    $this->assertContains("Form->hidden('DefaultDynamoConfig.id'", $guard,
      'the dynamo config id must be submitted or every edit inserts a duplicate');
  }

  /**
   * Return the body of the `if(isset($oa4mp_client_co_admin_clients) && $e)`
   * block that renders the hidden primary keys, or '' if it is gone.
   */
  private function editGuardBlock($source) {
    $start = strpos($source, 'if(isset($oa4mp_client_co_admin_clients) && $e)');
    if ($start === false) {
      return '';
    }

    $open = strpos($source, '{', $start);
    $close = strpos($source, '}', $open);
    if ($open === false || $close === false) {
      return '';
    }

    return substr($source, $open, $close - $open);
  }
}
