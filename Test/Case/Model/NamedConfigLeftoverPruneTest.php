<?php
/**
 * The two shared helpers behind replacing a client's own configuration with a
 * Named configuration (cilogon/Oa4mpClient#58):
 *
 *  - Oa4mpClientOa4mpServer::newClientDefaults(), the one place that says
 *    what a new client starts with: the openid scope, Require Active Status
 *    for a confidential client, and a copy of the admin client's default
 *    DynamoDB configuration. Client creation uses it, and removing a Named
 *    configuration will reset a client to it, so the two cannot drift apart.
 *
 *  - Oa4mpClientCoOidcClient::pruneNamedConfigLeftovers(), which deletes the
 *    scope, claim, authorization, LDAP and DynamoDB rows a confidential
 *    client with a Named configuration still holds. None of them is sent to
 *    the OA4MP server while the Named configuration is applied, so the
 *    delete is Registry-only. The access token row is still sent for such
 *    clients, so it is kept.
 *
 * Database-backed: the rows are seeded directly and the prune is run through
 * the real model.
 */

App::uses('ConnectionManager', 'Model');

class NamedConfigLeftoverPruneTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;
  private $namedConfigId;

  /** @var array Client ids this test seeded, for the purge in tearDown. */
  private $clientIds = array();

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mptest');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag);
    $this->namedConfigId = $this->fx->insert('cm_oa4mp_client_co_named_configs', array(
      'admin_id' => $this->adminId,
      'config_name' => 'named configuration ' . $tag,
      'description' => 'hermetic test named configuration',
      'config' => '{}'
    ));
  }

  public function tearDown() {
    Oa4mpHarnessSwaps::restore();

    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if (!empty($this->clientIds)) {
      $in = implode(', ', array_map('intval', $this->clientIds));
      $purge['cm_oa4mp_client_co_search_attributes'] =
        'ldap_id IN (SELECT id FROM cm_oa4mp_client_co_ldap_configs WHERE client_id IN (' . $in . '))';
      $purge['cm_oa4mp_client_claim_constraints'] =
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id IN (' . $in . '))';
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
    $this->clientIds = array();
  }

  // ==========================================================================
  // The leftover prune
  // ==========================================================================

  /**
   * Every replaced row goes, claim constraints and search attributes with
   * their parents, and both of the client's DynamoDB rows. The access token
   * row stays because it is still sent, and the callback and refresh token
   * rows stay because a Named configuration does not replace them.
   */
  public function testPruneDeletesEveryLeftoverExceptTheAccessToken() {
    $clientId = $this->seedClient(array('named_config_id' => $this->namedConfigId));
    $this->seedLeftovers($clientId);

    $this->assertTrue($this->clientModel()->pruneNamedConfigLeftovers($clientId),
      'the prune reports that every delete succeeded');

    $this->assertEqual(array(
      'scopes' => 0,
      'claims' => 0,
      'constraints' => 0,
      'authorizations' => 0,
      'ldap' => 0,
      'search attributes' => 0,
      'dynamo' => 0,
      'access tokens' => 1,
      'callbacks' => 1,
      'refresh tokens' => 1
    ), $this->rowCounts($clientId), 'only the rows a Named configuration does not replace remain');
  }

  /** A client with no Named configuration keeps everything. */
  public function testPruneLeavesAClientWithNoNamedConfigAlone() {
    $clientId = $this->seedClient();
    $this->seedLeftovers($clientId);
    $before = $this->rowCounts($clientId);

    $this->assertTrue($this->clientModel()->pruneNamedConfigLeftovers($clientId),
      'nothing to prune is not a failure');

    $this->assertEqual($before, $this->rowCounts($clientId), 'nothing was deleted');
  }

  /**
   * A public client with a Named configuration is cleared by the maintainer
   * outside the plugin, so the prune leaves it alone.
   */
  public function testPruneLeavesAPublicClientAlone() {
    $clientId = $this->seedClient(array(
      'named_config_id' => $this->namedConfigId,
      'public_client' => true
    ));
    $this->seedScope($clientId, 'openid');
    $this->seedScope($clientId, 'profile');

    $this->assertTrue($this->clientModel()->pruneNamedConfigLeftovers($clientId),
      'nothing to prune is not a failure');

    $counts = $this->rowCounts($clientId);
    $this->assertEqual(2, $counts['scopes'], 'the public client keeps its scopes');
  }

  /**
   * A failed claim delete is reported. The LDAP configuration is deleted
   * before the claims, so the edit page cannot turn its search attributes
   * back into claims after a half-finished prune, and the rows after the
   * claims are still processed.
   */
  public function testFailedClaimDeleteIsReportedAfterTheLdapRowsAreGone() {
    $clientId = $this->seedClient(array('named_config_id' => $this->namedConfigId));
    $this->seedLeftovers($clientId);
    $model = $this->clientModel();
    $this->failDeletes($model, 'Oa4mpClientClaim');

    $this->assertFalse($model->pruneNamedConfigLeftovers($clientId),
      'the prune reports the failed delete');

    $counts = $this->rowCounts($clientId);
    $this->assertEqual(0, $counts['ldap'], 'the LDAP configuration is already gone');
    $this->assertEqual(0, $counts['search attributes'], 'and its search attributes with it');
    $this->assertEqual(2, $counts['claims'], 'premise: the claims could not be deleted');
    $this->assertEqual(0, $counts['scopes'], 'the scopes were still deleted');
    $this->assertEqual(0, $counts['authorizations'], 'the authorization was still deleted');
    $this->assertEqual(0, $counts['dynamo'], 'the DynamoDB rows were still deleted');
  }

  /** Only the client named is pruned; another client's rows are untouched. */
  public function testPruneLeavesAnotherClientsRowsAlone() {
    $clientId = $this->seedClient(array('named_config_id' => $this->namedConfigId));
    $otherId = $this->seedClient(array('named_config_id' => $this->namedConfigId));
    $this->seedLeftovers($clientId);
    $this->seedLeftovers($otherId);
    $before = $this->rowCounts($otherId);

    $this->assertTrue($this->clientModel()->pruneNamedConfigLeftovers($clientId),
      'the prune of the first client succeeded');

    $this->assertEqual(0, $this->rowCounts($clientId)['claims'], 'premise: the first client was pruned');
    $this->assertEqual($before, $this->rowCounts($otherId), 'the other client kept every row');
  }

  /** The Named configuration's own scopes share the scopes table and are kept. */
  public function testPruneKeepsTheNamedConfigsOwnScopes() {
    $clientId = $this->seedClient(array('named_config_id' => $this->namedConfigId));
    $this->seedScope($clientId, 'openid');
    $this->fx->insert('cm_oa4mp_client_co_scopes', array(
      'named_config_id' => $this->namedConfigId,
      'scope' => 'openid'
    ));

    $this->assertTrue($this->clientModel()->pruneNamedConfigLeftovers($clientId), 'the prune succeeded');

    $this->assertEqual(0, $this->rowCounts($clientId)['scopes'], 'the client\'s own scope was deleted');
    $this->assertEqual(1, $this->count('cm_oa4mp_client_co_scopes',
      'named_config_id = ' . (int)$this->namedConfigId), 'the Named configuration\'s scope was kept');
  }

  // ==========================================================================
  // New-client defaults
  // ==========================================================================

  /**
   * A confidential client starts with the openid scope, Require Active
   * Status, and the admin client's DynamoDB default without that row's id
   * and admin_id, so saving it creates the client's own copy.
   */
  public function testNewClientDefaultsForAConfidentialClient() {
    $defaults = $this->server()->newClientDefaults($this->adminDynamoDefault(), false);

    $this->assertEqual(array(array('scope' => 'openid')), $defaults['Oa4mpClientCoScope'] ?? null,
      'the openid scope only');
    $this->assertEqual(array('require_active' => true), $defaults['Oa4mpClientAuthorization'] ?? null,
      'Require Active Status on');
    $this->assertEqual(array(
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'table_name' => 'default-table'
    ), $defaults['Oa4mpClientDynamoConfig'] ?? null, 'a copy of the admin client default, minus id and admin_id');
  }

  /** A public client cannot carry a cfg, so it gets no authorization row. */
  public function testNewClientDefaultsForAPublicClientHaveNoAuthorization() {
    $defaults = $this->server()->newClientDefaults($this->adminDynamoDefault(), true);

    $this->assertFalse(array_key_exists('Oa4mpClientAuthorization', $defaults), 'no authorization row');
    $this->assertEqual(array(array('scope' => 'openid')), $defaults['Oa4mpClientCoScope'] ?? null,
      'the openid scope only');
    $this->assertEqual('default-table', $defaults['Oa4mpClientDynamoConfig']['table_name'] ?? null,
      'the admin client default DynamoDB configuration');
  }

  /**
   * newClientData() takes the defaults from the helper: the create request
   * and the saved client carry the openid scope even when the posted data
   * has none, which is how add() now gets it.
   */
  public function testNewClientDataTakesTheOpenidScopeFromTheDefaults() {
    $admin = array(
      'Oa4mpClientCoAdminClient' => array('id' => $this->adminId),
      'DefaultDynamoConfig' => $this->adminDynamoDefault()
    );
    $posted = array(
      'Oa4mpClientCoOidcClient' => array(
        'admin_id' => (string)$this->adminId,
        'name' => 'defaults client',
        'home_url' => 'https://example.org/',
        'public_client' => '0'
      )
    );

    list($saveData, $marshallData) = $this->server()->newClientData($admin, $posted);

    $this->assertEqual(array(array('scope' => 'openid')), $saveData['Oa4mpClientCoScope'] ?? null,
      'the saved client has the openid scope');
    $this->assertEqual(array(array('scope' => 'openid')), $marshallData['Oa4mpClientCoScope'] ?? null,
      'the create request has the openid scope');
    $this->assertEqual(array('require_active' => true), $saveData['Oa4mpClientAuthorization'] ?? null,
      'and Require Active Status');
  }

  // ==========================================================================
  // Helpers
  // ==========================================================================

  private function server() {
    return ClassRegistry::init('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function clientModel() {
    return ClassRegistry::init('Oa4mpClient.Oa4mpClientCoOidcClient');
  }

  /** An admin client default DynamoDB row as find() returns it. */
  private function adminDynamoDefault() {
    return array(
      'id' => 41,
      'client_id' => null,
      'admin_id' => 7,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'table_name' => 'default-table'
    );
  }

  private function seedClient($overrides = array()) {
    $id = $this->fx->oidcClient($this->adminId, 'client ' . uniqid(), $overrides);
    $this->clientIds[] = $id;
    return $id;
  }

  private function seedScope($clientId, $scope) {
    return $this->fx->insert('cm_oa4mp_client_co_scopes', array(
      'client_id' => $clientId,
      'scope' => $scope
    ));
  }

  private function seedClaim($clientId, $name) {
    $claimId = $this->fx->insert('cm_oa4mp_client_claims', array(
      'client_id' => $clientId,
      'claim_name' => $name,
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_json_format' => 'string'
    ));
    $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
      'claim_id' => $claimId,
      'constraint_field' => 'type',
      'constraint_value' => 'eppn'
    ));
    return $claimId;
  }

  private function seedDynamo($clientId, $table) {
    return $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'client_id' => $clientId,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-key',
      'table_name' => $table,
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));
  }

  /**
   * Seed every row kind: two claims with a constraint each, two scopes, an
   * authorization, an LDAP configuration with two search attributes, two
   * DynamoDB rows (as some older clients hold), an access token, a callback
   * and a refresh token.
   */
  private function seedLeftovers($clientId) {
    $this->seedScope($clientId, 'openid');
    $this->seedScope($clientId, 'profile');
    $this->seedClaim($clientId, 'eppn');
    $this->seedClaim($clientId, 'email');

    $this->fx->insert('cm_oa4mp_client_authorizations', array(
      'client_id' => $clientId,
      'require_active' => true
    ));

    $ldapId = $this->fx->insert('cm_oa4mp_client_co_ldap_configs', array(
      'client_id' => $clientId,
      'enabled' => true,
      'authorization_type' => 'simple',
      'serverurl' => 'ldaps://ldap.example.org',
      'binddn' => 'cn=test,dc=example,dc=org',
      'password' => 'not-a-real-password',
      'basedn' => 'dc=example,dc=org',
      'search_name' => 'uid'
    ));
    foreach (array('mail', 'uid') as $name) {
      $this->fx->insert('cm_oa4mp_client_co_search_attributes', array(
        'ldap_id' => $ldapId,
        'name' => $name,
        'return_as_list' => false,
        'return_name' => $name,
        'claim_id' => null
      ));
    }

    $this->seedDynamo($clientId, 'first-table');
    $this->seedDynamo($clientId, 'second-table');

    $this->fx->insert('cm_oa4mp_client_access_tokens', array(
      'client_id' => $clientId,
      'is_jwt' => true
    ));
    $this->fx->insert('cm_oa4mp_client_co_callbacks', array(
      'client_id' => $clientId,
      'url' => 'https://example.org/cb'
    ));
    $this->fx->insert('cm_oa4mp_client_refresh_tokens', array(
      'client_id' => $clientId,
      'token_lifetime' => 3600
    ));
  }

  private function count($table, $where) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return $this->fx->count($table, $where);
  }

  /** How many rows of each kind the client holds. */
  private function rowCounts($clientId) {
    $c = 'client_id = ' . (int)$clientId;

    return array(
      'scopes' => $this->count('cm_oa4mp_client_co_scopes', $c),
      'claims' => $this->count('cm_oa4mp_client_claims', $c),
      'constraints' => $this->count('cm_oa4mp_client_claim_constraints',
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE ' . $c . ')'),
      'authorizations' => $this->count('cm_oa4mp_client_authorizations', $c),
      'ldap' => $this->count('cm_oa4mp_client_co_ldap_configs', $c),
      'search attributes' => $this->count('cm_oa4mp_client_co_search_attributes',
        'ldap_id IN (SELECT id FROM cm_oa4mp_client_co_ldap_configs WHERE ' . $c . ')'),
      'dynamo' => $this->count('cm_oa4mp_client_dynamo_configs', $c),
      'access tokens' => $this->count('cm_oa4mp_client_access_tokens', $c),
      'callbacks' => $this->count('cm_oa4mp_client_co_callbacks', $c),
      'refresh tokens' => $this->count('cm_oa4mp_client_refresh_tokens', $c)
    );
  }

  /**
   * Replace one association of $model with its failing subclass from
   * Test/lib/Oa4mpWritePathHarnesses.php, whose delete() returns false, the
   * way the controller harnesses' harnessFailWrites() does. tearDown puts the
   * real model back.
   */
  private function failDeletes($model, $association) {
    $real = $model->{$association};
    $class = 'Oa4mpHarnessFailing' . get_class($real);

    $failing = new $class(array(
      'alias' => $real->alias,
      'plugin' => $real->plugin,
      'table' => $real->useTable,
      'ds' => $real->useDbConfig
    ));

    if (ClassRegistry::getObject($real->alias) === $failing) {
      ClassRegistry::removeObject($real->alias);
      ClassRegistry::addObject($real->alias, $real);
    }

    $failing->harnessFailOn = 'delete';
    $model->{$association} = $failing;
    Oa4mpHarnessSwaps::$swaps[] = array($model, $association, $real);
  }
}
