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
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U5 (R2, R3, R5).
 */

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
    $tag = 'oa4mptest-' . getmypid() . '-' . substr(uniqid(), -6);
    $this->serverUrl = 'ldaps://ldap.' . $tag . '.example.org';

    $this->coId = $this->fx->insert('cm_cos', array(
      'name' => 'CO ' . $tag,
      'description' => 'hermetic test CO',
      'status' => 'A'
    ));

    $this->adminId = $this->fx->insert('cm_oa4mp_client_co_admin_clients', array(
      'co_id' => $this->coId,
      'serverurl' => 'https://oa4mp.' . $tag . '.example.org/oauth2',
      'name' => 'admin ' . $tag,
      'issuer' => 'https://' . $tag . '.example.org',
      'admin_identifier' => 'admin:' . $tag,
      'secret' => 'not-a-real-secret'
    ));

    $this->clientId = $this->fx->insert('cm_oa4mp_client_co_oidc_clients', array(
      'admin_id' => $this->adminId,
      'oa4mp_identifier' => 'https://example.org/oidc/client/' . $tag,
      'name' => 'client ' . $tag,
      'home_url' => 'https://' . $tag . '.example.org/',
      'proxy_limited' => false,
      'public_client' => false
    ));

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
    ));
    $this->fx = null;
  }

  private function searchAttrModel() {
    return $this->model('Oa4mpClient.Oa4mpClientCoSearchAttribute');
  }

  /** Seed a search attribute row and return the array shape toClaim() expects. */
  private function seedSearchAttribute($name, $returnName) {
    $id = $this->fx->insert('cm_oa4mp_client_co_search_attributes', array(
      'ldap_id' => $this->ldapId,
      'name' => $name,
      'return_as_list' => false,
      'return_name' => $returnName,
      'claim_id' => null
    ));

    return array(
      'id' => $id,
      'ldap_id' => $this->ldapId,
      'name' => $name,
      'return_as_list' => false,
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
