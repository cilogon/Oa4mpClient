<?php
/**
 * Tests that a new OIDC client can be saved together with its
 * Oa4mpClientAuthorization hasOne row, the way the Add action saves a
 * confidential client that requires Active Status from creation.
 *
 * CakePHP's saveAssociated() validates hasOne children before the parent row
 * exists ('validate' => 'first') and injects client_id only when it saves the
 * child. A client_id rule with 'required' => true therefore failed every such
 * create, after the OA4MP server had already created the client. The rule is
 * required only on update.
 *
 * See docs/plans/2026-09-28-1104-feat-require-active-status-default-plan.md
 * U1 (R1, AE5, KTD2).
 */

class AuthorizationCreateSaveTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $adminId;

  /** @var array Ids of OIDC clients created by the code under test. */
  private $clientIds = array();

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpauthzsave');

    $coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($coId, $tag);
    $this->clientIds = array();
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if (!empty($this->clientIds)) {
      $in = implode(', ', array_map('intval', $this->clientIds));
      $purge['cm_oa4mp_client_authorizations'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_dynamo_configs'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_co_scopes'] = 'client_id IN (' . $in . ')';
      $purge['cm_oa4mp_client_co_oidc_clients'] = 'id IN (' . $in . ')';
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  private function oidcClientModel() {
    return $this->model('Oa4mpClient.Oa4mpClientCoOidcClient');
  }

  /**
   * The data the Add action hands to saveAssociated() for a new client: no
   * ids anywhere, the openid scope, the admin client's DynamoDB default, and
   * (for a confidential client) the authorization default.
   */
  private function createData($name, $withAuthorization) {
    $data = array(
      'Oa4mpClientCoOidcClient' => array(
        'admin_id' => $this->adminId,
        'oa4mp_identifier' => 'https://example.org/oidc/client/' . $name,
        'name' => $name,
        'home_url' => 'https://example.org/',
        'proxy_limited' => '0',
        'public_client' => '0'
      ),
      'Oa4mpClientCoScope' => array(
        array('scope' => 'openid')
      ),
      'Oa4mpClientDynamoConfig' => array(
        'aws_region' => 'us-east-1',
        'aws_access_key_id' => 'AKIAEXAMPLE',
        'aws_secret_access_key' => 'not-a-real-key',
        'table_name' => 'oa4mp-test-table',
        'partition_key' => 'client_id',
        'partition_key_template' => '${client_id}',
        'partition_key_claim_name' => 'sub'
      )
    );

    if ($withAuthorization) {
      $data['Oa4mpClientAuthorization'] = array('require_active' => true);
    }

    return $data;
  }

  private function saveNewClient($data) {
    $model = $this->oidcClientModel();
    $model->clear();

    $ret = $model->saveAssociated($data, array('deep' => true));

    if ($ret && !empty($model->id)) {
      $this->clientIds[] = (int)$model->id;
    }

    return array($ret, $model);
  }

  /**
   * Covers AE5: the create-shaped save with the authorization default
   * succeeds and stores exactly one authorization row for the new client.
   */
  public function testNewClientSavesWithAuthorizationRow() {
    list($ret, $model) = $this->saveNewClient(
      $this->createData('authz-create-' . uniqid(), true));

    $this->assertTrue((bool)$ret,
      'saving a new client with its authorization row must succeed; validation errors: '
      . var_export($model->validationErrors, true));

    $clientId = (int)$model->id;
    $this->assertEqual(1, $this->fx->count('cm_oa4mp_client_authorizations',
      'client_id = ' . $clientId),
      'exactly one authorization row is linked to the new client');
    $this->assertEqual(1, $this->fx->count('cm_oa4mp_client_authorizations',
      'client_id = ' . $clientId . ' AND require_active = true'),
      'the saved row requires Active Status');
  }

  /**
   * A new client saved without an authorization row (a public client) still
   * saves, and gets no authorization row.
   */
  public function testNewClientSavesWithoutAuthorizationRow() {
    list($ret, $model) = $this->saveNewClient(
      $this->createData('authz-none-' . uniqid(), false));

    $this->assertTrue((bool)$ret, 'saving a new client without an authorization row must succeed');
    $this->assertEqual(0, $this->fx->count('cm_oa4mp_client_authorizations',
      'client_id = ' . (int)$model->id),
      'no authorization row is created');
  }

  /**
   * Relaxing the rule to "required on update" must not let an update drop
   * client_id: an existing authorization row saved without client_id fails
   * validation.
   */
  public function testAuthorizationUpdateStillRequiresClientId() {
    $clientId = $this->fx->oidcClient($this->adminId, 'authz-update-' . uniqid());
    $authzId = $this->fx->insert('cm_oa4mp_client_authorizations', array(
      'client_id' => $clientId,
      'require_active' => true
    ));

    $authz = $this->model('Oa4mpClient.Oa4mpClientAuthorization');
    $authz->clear();
    $authz->set(array('Oa4mpClientAuthorization' => array(
      'id' => $authzId,
      'require_active' => false
    )));

    $this->assertFalse($authz->validates(),
      'an update without client_id must fail validation');
    $this->assertTrue(isset($authz->validationErrors['client_id']),
      'the failure is on client_id');
  }
}
