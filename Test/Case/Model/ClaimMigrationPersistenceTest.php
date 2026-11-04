<?php
/**
 * Database-backed regression tests for the search-attribute -> claim migration
 * in Model/Oa4mpClientCoSearchAttribute.php::toClaim(). Unlike the pure-function
 * cases in ClaimMigrationTest, these seed real Registry and plugin rows and
 * assert the resulting database state. Covers documented bugs:
 *
 *  - oa4mp-claim-migration-three-latent-bugs-2026-05-18 Bug 1 (#3a): the claim
 *    row and its Oa4mpClientCoSearchAttribute.claim_id back-pointer must land
 *    atomically, so a later DynamoConfig save failure cannot leave an orphan.
 *  - oa4mp-claim-migration-three-latent-bugs-2026-05-18 Bug 2 (#3b): a non-empty
 *    CoLdapProvisionerAttribute list with no matching entry must produce no
 *    claim, rather than leaking the last iterated attribute's type into a
 *    'type' constraint.
 *  - oa4mp-legacy-orphan-claim-recovery-2026-05-19 (#6): re-running migration
 *    for a search attribute whose claim was orphaned rewires the back-pointer
 *    to the existing matching claim instead of accumulating a duplicate row.
 *  - cilogon/Oa4mpClient#40: an isMemberOf search attribute must migrate to a
 *    claim serialized as a JSON array, which is what the LDAP claim source
 *    sent for a multi-valued attribute, not to a comma-delimited string. An
 *    isMemberOf claim row in the delimited-string form toClaim() wrote before
 *    that change is upgraded, not duplicated, when an orphan of it is
 *    rewired, and repairPreJsonArrayIsMemberOfClaims() upgrades one still
 *    linked from the client's search attribute; rows changed by hand are left
 *    alone either way.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U5 (R2, R3, R5).
 */

App::uses('ConnectionManager', 'Model');

class ClaimMigrationPersistenceTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;
  private $clientId;
  private $ldapId;
  private $serverUrl;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mptest');
    $this->serverUrl = 'ldaps://ldap.' . $tag . '.example.org';

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag);
    $this->clientId = $this->fx->oidcClient($this->adminId, 'client ' . $tag);

    $this->ldapId = $this->fx->insert('cm_oa4mp_client_co_ldap_configs', array(
      'client_id' => $this->clientId,
      'admin_id' => $this->adminId,
      'enabled' => true,
      'authorization_type' => 'simple',
      'serverurl' => $this->serverUrl,
      'binddn' => 'cn=test,dc=example,dc=org',
      'password' => 'not-a-real-password',
      'basedn' => 'dc=example,dc=org',
      'search_name' => 'uid'
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }
    // Rows the code under test creates are not tracked by the helper, so purge
    // them by client before the tracked rows come out in reverse order.
    $clientId = (int)$this->clientId;
    $this->fx->cleanup(array(
      'cm_oa4mp_client_claim_constraints' =>
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id = ' . $clientId . ')',
      'cm_oa4mp_client_claims' => 'client_id = ' . $clientId,
      'cm_oa4mp_client_dynamo_configs' => 'client_id = ' . $clientId
        . ' OR admin_id = ' . (int)$this->adminId
    ));
    $this->fx = null;
  }

  private function searchAttrModel() {
    return $this->model('Oa4mpClient.Oa4mpClientCoSearchAttribute');
  }

  /** Seed a search attribute row and return the array shape toClaim() expects. */
  private function seedSearchAttribute($name, $returnName, $returnAsList = false) {
    $id = $this->fx->insert('cm_oa4mp_client_co_search_attributes', array(
      'ldap_id' => $this->ldapId,
      'name' => $name,
      'return_as_list' => $returnAsList,
      'return_name' => $returnName,
      'claim_id' => null
    ));

    return array(
      'id' => $id,
      'ldap_id' => $this->ldapId,
      'name' => $name,
      'return_as_list' => $returnAsList,
      'return_name' => $returnName,
      'claim_id' => null
    );
  }

  /** The LDAP config array shape toClaim() expects. */
  private function ldapConfig() {
    return array('id' => $this->ldapId, 'serverurl' => $this->serverUrl);
  }

  /** A DynamoConfig that passes Oa4mpClientDynamoConfig validation. */
  private function validDynamoConfig() {
    return array(
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-key',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    );
  }

  /**
   * Seed the LdapProvisioner rows toClaim() consults for the search attributes
   * whose constraint value comes from the provisioner config.
   *
   * @param array $attributes list of array('attribute' => ..., 'type' => ...)
   */
  private function seedLdapProvisioner($attributes) {
    $targetId = $this->fx->insert('cm_co_provisioning_targets', array(
      'co_id' => $this->coId,
      'description' => 'hermetic test LdapProvisioner',
      'plugin' => 'LdapProvisioner',
      'status' => 'A',
      'ordr' => 1
    ));

    $ldapTargetId = $this->fx->insert('cm_co_ldap_provisioner_targets', array(
      'co_provisioning_target_id' => $targetId,
      'serverurl' => $this->serverUrl,
      'binddn' => 'cn=test,dc=example,dc=org',
      'password' => 'not-a-real-password',
      'basedn' => 'dc=example,dc=org',
      'dn_attribute_name' => 'uid',
      'dn_identifier_type' => 'uid',
      'attr_opts' => false
    ));

    foreach ($attributes as $i => $attr) {
      $this->fx->insert('cm_co_ldap_provisioner_attributes', array(
        'co_ldap_provisioner_target_id' => $ldapTargetId,
        'attribute' => $attr['attribute'],
        'objectclass' => 'objectclass' . $i,
        'type' => $attr['type'],
        'export' => true,
        'use_org_value' => false
      ));
    }
  }

  /** A second configuration, differing where an assertion can see it. */
  private function rotatedDynamoConfig() {
    return array('table_name' => 'oa4mp-rotated-table') + $this->validDynamoConfig();
  }

  private function dynamoConfigCount() {
    return $this->fx->count('cm_oa4mp_client_dynamo_configs',
      'client_id = ' . (int)$this->clientId);
  }

  private function claimCount() {
    return $this->fx->count('cm_oa4mp_client_claims', 'client_id = ' . (int)$this->clientId);
  }

  private function backPointer($searchAttributeId) {
    return $this->fx->scalar('SELECT claim_id FROM cm_oa4mp_client_co_search_attributes'
      . ' WHERE id = ' . (int)$searchAttributeId);
  }

  private function claimId() {
    return $this->fx->scalar('SELECT id FROM cm_oa4mp_client_claims'
      . ' WHERE client_id = ' . (int)$this->clientId . ' ORDER BY id LIMIT 1');
  }

  /** The serialization columns of the client's one migrated claim row. */
  private function claimSerialization() {
    return array(
      $this->fx->scalar('SELECT claim_multiple_value_serialization FROM cm_oa4mp_client_claims'
        . ' WHERE client_id = ' . (int)$this->clientId),
      $this->fx->scalar('SELECT claim_value_string_serialization_delimiter FROM cm_oa4mp_client_claims'
        . ' WHERE client_id = ' . (int)$this->clientId)
    );
  }

  /**
   * Issue #40. A list-valued isMemberOf must migrate to a JSON array claim
   * with no delimiter. The LDAP claim source returned the groups as a JSON
   * array, and toClaim() hard-coded a comma-delimited string, so converting
   * the client to DynamoDB claims changed what the relying party received.
   */
  public function testListIsMemberOfMigratesAsJsonArray() {
    $attr = $this->seedSearchAttribute('isMemberOf', 'is_member_of', true);

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(), 'isMemberOf migrates to one claim');
    list($serialization, $delimiter) = $this->claimSerialization();
    $this->assertEqual('json_array', $serialization,
      'a list-valued isMemberOf must migrate as a JSON array, got '
      . var_export($serialization, true));
    $this->assertNull($delimiter,
      'a JSON array claim carries no string delimiter, got ' . var_export($delimiter, true));
  }

  /**
   * Issue #40, the non-list case. The LDAP claim source returns a
   * multi-valued attribute as a JSON array whether or not the list flag is
   * set (the flag only wraps a single value), so return_as_list must not
   * change the migrated serialization.
   */
  public function testNonListIsMemberOfMigratesAsJsonArray() {
    $attr = $this->seedSearchAttribute('isMemberOf', 'is_member_of', false);

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(), 'isMemberOf migrates to one claim');
    list($serialization, $delimiter) = $this->claimSerialization();
    $this->assertEqual('json_array', $serialization,
      'a non-list isMemberOf must also migrate as a JSON array, got '
      . var_export($serialization, true));
    $this->assertNull($delimiter,
      'a JSON array claim carries no string delimiter, got ' . var_export($delimiter, true));
  }

  /**
   * Happy path: a supported search attribute migrates to exactly one claim row
   * with its constraints, and the search attribute's back-pointer is set.
   */
  public function testMigrationCreatesOneClaimWithBackPointer() {
    $attr = $this->seedSearchAttribute('gecos', 'gecos');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(), 'migration must create exactly one claim');

    $claimId = (int)$this->claimId();
    $this->assertEqual($claimId, (int)$this->backPointer($attr['id']),
      'the search attribute must point at the claim it migrated to');

    $constraints = $this->fx->count('cm_oa4mp_client_claim_constraints',
      'claim_id = ' . $claimId);
    $this->assertEqual(2, $constraints, 'gecos maps to a type and a primary constraint');
  }

  /**
   * Bug #3a (non-atomic save). The DynamoConfig save runs after the claim and
   * its back-pointer, so a validation failure there must still leave the search
   * attribute correctly marked as migrated. Pre-fix the DynamoConfig save ran
   * between saveAssociated() and saveField() and its failure returned early,
   * committing a claim row whose back-pointer was never set -- an orphan that
   * every later migration run duplicated.
   */
  public function testDynamoConfigFailureLeavesNoOrphanClaim() {
    $attr = $this->seedSearchAttribute('gecos', 'gecos');

    // table_name is notBlank + allowEmpty false, so this save fails validation.
    $badDynamoConfig = $this->validDynamoConfig();
    $badDynamoConfig['table_name'] = '';

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $badDynamoConfig, $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(), 'the claim row is still written');

    $claimId = (int)$this->claimId();
    $this->assertEqual($claimId, (int)$this->backPointer($attr['id']),
      'the back-pointer must be set even though the DynamoConfig save failed');

    $this->assertEqual(0, (int)$this->fx->scalar(
      'SELECT COUNT(*) FROM cm_oa4mp_client_dynamo_configs WHERE client_id = '
      . (int)$this->clientId), 'the failing DynamoConfig save persists nothing');
  }

  /**
   * The per-client DynamoDB configuration a migration writes must be the
   * client's one row, updated in place. toClaim() saved it with the id unset,
   * so CakePHP inserted a new row on every call -- one per search attribute on
   * the same client.
   */
  public function testMigratingTwoSearchAttributesWritesOneDynamoConfig() {
    $first = $this->seedSearchAttribute('gecos', 'gecos');
    $second = $this->seedSearchAttribute('eduPersonOrcid', 'orcid');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $first);
    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $second);

    $this->assertEqual(2, $this->claimCount(),
      'both search attributes migrated, so the count below is about the '
      . 'configuration rows and not about a migration that did not run');
    $this->assertEqual(1, $this->dynamoConfigCount(),
      'the client has one configuration row, not one per migrated search '
      . 'attribute');
  }

  /**
   * The same rule where the client already has a row, which is every client
   * created through Oa4mpClientCoOidcClientsController::add(): it copies the
   * admin client default into a per-client row at creation. A migration must
   * update that row, never add a second one beside it.
   */
  public function testMigrationUpdatesAnExistingDynamoConfigInPlace() {
    $existingId = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('client_id' => $this->clientId, 'admin_id' => null)
      + $this->validDynamoConfig());

    $attr = $this->seedSearchAttribute('gecos', 'gecos');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->rotatedDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->dynamoConfigCount(),
      'the existing configuration row is updated, not duplicated');
    $this->assertEqual($existingId, (int)$this->fx->scalar(
      'SELECT id FROM cm_oa4mp_client_dynamo_configs WHERE client_id = '
      . (int)$this->clientId), 'and it is still the same row');
    $this->assertEqual('oa4mp-rotated-table', $this->fx->scalar(
      'SELECT table_name FROM cm_oa4mp_client_dynamo_configs WHERE client_id = '
      . (int)$this->clientId),
      'carrying the values the migration was given');
  }

  /**
   * A client carrying duplicate rows from before the fix: the row the migration
   * updates and the row a later read returns must be the same one.
   *
   * The write side orders lowest id first, but that only means anything if the
   * read side agrees -- a hasOne with no order returns an unspecified row, so
   * the migration could update one duplicate while the marshaller and the
   * synchronization check kept reading another, and the migration would look
   * like it had succeeded while nothing observable changed.
   */
  public function testLegacyDuplicatesAreWrittenAndReadOnTheSameRow() {
    $lowest = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('client_id' => $this->clientId, 'admin_id' => null)
      + $this->validDynamoConfig());
    $higher = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('client_id' => $this->clientId, 'admin_id' => null)
      + $this->validDynamoConfig());

    $this->assertTrue($lowest < $higher, 'the fixture seeded them in id order');

    $attr = $this->seedSearchAttribute('gecos', 'gecos');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->rotatedDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual('oa4mp-rotated-table', $this->fx->scalar(
      'SELECT table_name FROM cm_oa4mp_client_dynamo_configs WHERE id = ' . (int)$lowest),
      'the migration wrote the lowest-id row');

    $curData = $this->model('Oa4mpClient.Oa4mpClientCoOidcClient')->current($this->clientId);

    $this->assertEqual($lowest, (int)$curData['Oa4mpClientDynamoConfig']['id'],
      'and a read returns that same row, not the other duplicate');
    $this->assertEqual('oa4mp-rotated-table',
      $curData['Oa4mpClientDynamoConfig']['table_name'],
      'so what was written is what is read back');
  }

  /**
   * The admin client's own DefaultDynamoConfig is what the controller hands
   * toClaim(), and it arrives carrying its own id. That id must never reach the
   * save: it would update the admin default row instead of the client's, and
   * every client of that admin client would then be reconfigured by one
   * client's migration.
   *
   * This is a guard, not a demonstration of the duplicate-insert bug: it held
   * before the fix too, because dropping the incoming id was already what the
   * pre-fix code did. It is here because the fix works by putting an id back
   * into the save, and the wrong id is the failure that change invites.
   */
  public function testMigrationDoesNotWriteThroughToTheAdminDefault() {
    $defaultId = $this->fx->insert('cm_oa4mp_client_dynamo_configs',
      array('admin_id' => $this->adminId, 'client_id' => null)
      + $this->validDynamoConfig());

    $attr = $this->seedSearchAttribute('gecos', 'gecos');

    // The shape the controller passes: the admin default row, id and all.
    $adminDefault = array('id' => $defaultId, 'admin_id' => $this->adminId,
      'client_id' => null) + $this->rotatedDynamoConfig();

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $adminDefault, $this->ldapConfig(), $attr);

    $this->assertEqual('oa4mp-test-table', $this->fx->scalar(
      'SELECT table_name FROM cm_oa4mp_client_dynamo_configs WHERE id = '
      . (int)$defaultId),
      'the admin client default is left exactly as it was');
    $this->assertEqual(1, $this->dynamoConfigCount(),
      'and the client got its own single row');
  }

  /**
   * Bug #6 (legacy orphan-claim recovery). A client carrying an orphan claim
   * from the pre-fix era must be rewired to that claim on the next migration
   * run, not given a second copy -- duplicates drive the "Number of claims is
   * out of sync" comparator error.
   */
  public function testOrphanClaimIsRewiredInsteadOfDuplicated() {
    $attr = $this->seedSearchAttribute('gecos', 'gecos');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);
    $originalClaimId = (int)$this->claimId();

    // Recreate the pre-fix orphan: the claim row is committed but the search
    // attribute's back-pointer was never written.
    $this->fx->query('UPDATE cm_oa4mp_client_co_search_attributes SET claim_id = NULL'
      . ' WHERE id = ' . (int)$attr['id']);

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(),
      're-running migration over an orphan must not create a duplicate claim');
    $this->assertEqual($originalClaimId, (int)$this->backPointer($attr['id']),
      'the search attribute must be rewired to the existing orphan claim');
  }

  /**
   * Migrate an isMemberOf search attribute, then put its claim row back in
   * the form toClaim() wrote before cilogon/Oa4mpClient#40: delimited_string
   * with $delimiter. Returns the search attribute.
   */
  private function migratePreJsonArrayIsMemberOf($delimiter = ',') {
    $attr = $this->seedSearchAttribute('isMemberOf', 'is_member_of', true);

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->fx->query("UPDATE cm_oa4mp_client_claims SET claim_multiple_value_serialization = 'delimited_string',"
      . ' claim_value_string_serialization_delimiter = '
      . ConnectionManager::getDataSource('default')->value($delimiter)
      . ' WHERE client_id = ' . (int)$this->clientId);

    return $attr;
  }

  /** Clear a search attribute's back-pointer, leaving its claim orphaned. */
  private function orphanClaimOf($attr) {
    $this->fx->query('UPDATE cm_oa4mp_client_co_search_attributes SET claim_id = NULL'
      . ' WHERE id = ' . (int)$attr['id']);
  }

  /** The serialization columns of one claim row. */
  private function serializationOf($claimId) {
    return array(
      $this->fx->scalar('SELECT claim_multiple_value_serialization FROM cm_oa4mp_client_claims'
        . ' WHERE id = ' . (int)$claimId),
      $this->fx->scalar('SELECT claim_value_string_serialization_delimiter FROM cm_oa4mp_client_claims'
        . ' WHERE id = ' . (int)$claimId)
    );
  }

  /**
   * Issue #40 meets bug #6. An isMemberOf orphan left from before the JSON
   * array change carries delimited_string ',' and so no longer matches the
   * claim toClaim() now builds. It must still be rewired, not duplicated --
   * two isMemberOf claims against the legacy cfg's one is "Number of claims
   * is out of sync" -- and upgraded to json_array on the way.
   */
  public function testPreJsonArrayIsMemberOfOrphanIsRewiredAndUpgraded() {
    $attr = $this->migratePreJsonArrayIsMemberOf();
    $originalClaimId = (int)$this->claimId();
    $this->orphanClaimOf($attr);

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(),
      'an old-format isMemberOf orphan must be reused, not joined by a second isMemberOf claim');
    $this->assertEqual($originalClaimId, (int)$this->backPointer($attr['id']),
      'the search attribute must be rewired to the old-format orphan');
    list($serialization, $delimiter) = $this->serializationOf($originalClaimId);
    $this->assertEqual('json_array', $serialization,
      'the rewired orphan must be upgraded to a JSON array, got ' . var_export($serialization, true));
    $this->assertNull($delimiter,
      'the upgraded orphan carries no delimiter, got ' . var_export($delimiter, true));
  }

  /**
   * The control: only the exact old conversion output is upgraded. An
   * isMemberOf orphan whose delimiter was changed by hand is not what
   * toClaim() ever wrote, so matching stays strict and it is not rewired.
   */
  public function testHandChangedIsMemberOfOrphanIsNotRewired() {
    $attr = $this->migratePreJsonArrayIsMemberOf(';');
    $originalClaimId = (int)$this->claimId();
    $this->orphanClaimOf($attr);

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(2, $this->claimCount(),
      'a hand-changed orphan is not a match, so a new claim is created beside it');
    $this->assertTrue($originalClaimId !== (int)$this->backPointer($attr['id']),
      'the search attribute must not be rewired to the hand-changed orphan');
    $this->assertEqual(array('delimited_string', ';'), $this->serializationOf($originalClaimId),
      'the hand-changed orphan is left as it was');
  }

  /**
   * Issue #40, a client converted before the fix and not yet saved: its
   * isMemberOf row is exactly the old conversion output and still linked
   * from the client's search attribute, so the repair upgrades it.
   */
  public function testRepairUpgradesPreJsonArrayIsMemberOfClaim() {
    $this->migratePreJsonArrayIsMemberOf();
    $claimId = (int)$this->claimId();

    $repaired = $this->searchAttrModel()->repairPreJsonArrayIsMemberOfClaims($this->clientId);

    $this->assertEqual(array($claimId), $repaired, 'the repair reports the claim it upgraded');
    list($serialization, $delimiter) = $this->serializationOf($claimId);
    $this->assertEqual('json_array', $serialization,
      'the old-format isMemberOf row must be upgraded to a JSON array, got '
      . var_export($serialization, true));
    $this->assertNull($delimiter,
      'the upgraded row carries no delimiter, got ' . var_export($delimiter, true));
  }

  /**
   * A row that differs from the old default was changed by hand on the
   * Claims tab, and the repair must not undo that.
   */
  public function testRepairLeavesHandChangedIsMemberOfClaimAlone() {
    $this->migratePreJsonArrayIsMemberOf(';');
    $claimId = (int)$this->claimId();

    $repaired = $this->searchAttrModel()->repairPreJsonArrayIsMemberOfClaims($this->clientId);

    $this->assertEqual(array(), $repaired, 'nothing was repaired');
    $this->assertEqual(array('delimited_string', ';'), $this->serializationOf($claimId),
      'a hand-changed isMemberOf row is left as it was');
  }

  /**
   * The repair touches only the plugin's own converted row: one no search
   * attribute of this client points at is left alone, however it looks.
   */
  public function testRepairLeavesUnlinkedIsMemberOfClaimAlone() {
    $attr = $this->migratePreJsonArrayIsMemberOf();
    $claimId = (int)$this->claimId();
    $this->orphanClaimOf($attr);

    $repaired = $this->searchAttrModel()->repairPreJsonArrayIsMemberOfClaims($this->clientId);

    $this->assertEqual(array(), $repaired, 'nothing was repaired');
    $this->assertEqual(array('delimited_string', ','), $this->serializationOf($claimId),
      'a row no search attribute of the client points at is left as it was');
  }

  /**
   * Bug #3b (foreach loop-variable leak). When the provisioner target has
   * attributes but none matching the search attribute being migrated, no claim
   * may be produced. Pre-fix the loop variable held the last iterated
   * attribute, so empty() never fired and that attribute's type was written
   * into the claim's 'type' constraint.
   */
  public function testNoMatchingProvisionerAttributeProducesNoClaim() {
    $this->seedLdapProvisioner(array(
      array('attribute' => 'mail', 'type' => 'delivery'),
      array('attribute' => 'employeeNumber', 'type' => 'network')
    ));

    // uid takes the useLdapProvisionerConfig branch, and no seeded provisioner
    // attribute is named uid.
    $attr = $this->seedSearchAttribute('uid', 'uid');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(0, $this->claimCount(),
      'a non-matching provisioner attribute list must produce no claim');
    $this->assertNull($this->backPointer($attr['id']),
      'the search attribute must remain unmigrated');
  }

  /**
   * The matching case of the same lookup: a provisioner attribute named for the
   * search attribute supplies the claim's type constraint value.
   */
  public function testMatchingProvisionerAttributeSuppliesConstraintValue() {
    $this->seedLdapProvisioner(array(
      array('attribute' => 'mail', 'type' => 'delivery'),
      array('attribute' => 'uid', 'type' => 'eppn')
    ));

    $attr = $this->seedSearchAttribute('uid', 'uid');

    $this->searchAttrModel()->toClaim($this->clientId, $this->coId,
      $this->validDynamoConfig(), $this->ldapConfig(), $attr);

    $this->assertEqual(1, $this->claimCount(), 'a matching attribute migrates');

    $value = $this->fx->scalar('SELECT constraint_value FROM cm_oa4mp_client_claim_constraints'
      . ' WHERE claim_id = ' . (int)$this->claimId()
      . " AND constraint_field = 'type'");
    $this->assertEqual('eppn', $value,
      "the matching provisioner attribute's type is used, not another entry's");
  }
}
