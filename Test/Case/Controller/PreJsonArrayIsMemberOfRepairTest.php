<?php
/**
 * Regression coverage for cilogon/Oa4mpClient#40 on the OIDC client edit
 * page: a client converted to DynamoDB claims before isMemberOf became a JSON
 * array, and not saved since, holds an isMemberOf claim row serialized as a
 * comma-delimited string while the OA4MP server still holds the legacy LDAP
 * cfg, which sent a JSON array. Its next save would send the delimited string.
 *
 * Opening the edit page repairs that row, but only while the server copy is a
 * legacy cfg. Once the client has been saved under DynamoDB the server holds
 * the delimited string too, so changing the row there would be real drift;
 * the operator changes it on the Claims tab instead.
 *
 * Driven through Test/lib/Oa4mpWritePathHarnesses.php with a fake server
 * whose oa4mpVerifyClient() reports which kind of cfg it compared against.
 */

App::uses('ConnectionManager', 'Model');

class PreJsonArrayIsMemberOfRepairTest extends Oa4mpTestCase {

  private $fx = null;
  private $coId = null;
  private $clientId = null;
  private $claimId = null;

  public function setUp() {
    $this->fx = null;
    $this->coId = $this->clientId = $this->claimId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpimrp');

    $this->coId = $this->fx->co($tag);
    $adminId = $this->fx->adminClient($this->coId, $tag);
    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => 'oa4mp-test-table',
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));
    $this->clientId = $this->fx->oidcClient($adminId, 'imrp-' . $tag);

    $ldapId = $this->fx->insert('cm_oa4mp_client_co_ldap_configs', array(
      'client_id' => $this->clientId,
      'admin_id' => $adminId,
      'enabled' => true,
      'authorization_type' => 'simple',
      'serverurl' => 'ldaps://ldap.' . $tag . '.example.org',
      'binddn' => 'cn=test,dc=example,dc=org',
      'password' => 'not-a-real-password',
      'basedn' => 'dc=example,dc=org',
      'search_name' => 'uid'
    ));

    // The isMemberOf claim exactly as toClaim() converted it before #40,
    // already linked from its search attribute, so the edit page's conversion
    // block skips it.
    $this->claimId = $this->fx->insert('cm_oa4mp_client_claims', array(
      'client_id' => $this->clientId,
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ','
    ));
    $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
      'claim_id' => $this->claimId,
      'constraint_field' => 'owner',
      'constraint_value' => 'false'
    ));
    $this->fx->insert('cm_oa4mp_client_co_search_attributes', array(
      'ldap_id' => $ldapId,
      'name' => 'isMemberOf',
      'return_as_list' => true,
      'return_name' => 'is_member_of',
      'claim_id' => $this->claimId
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    $this->fx->cleanup(array(
      'cm_oa4mp_client_dynamo_configs' => 'client_id = ' . (int)$this->clientId
    ));
    $this->fx = null;
  }

  /** The serialization columns of the seeded claim row. */
  private function storedSerialization() {
    ConnectionManager::getDataSource('default')->flushQueryCache();

    return array(
      $this->fx->scalar('SELECT claim_multiple_value_serialization FROM cm_oa4mp_client_claims'
        . ' WHERE id = ' . (int)$this->claimId),
      $this->fx->scalar('SELECT claim_value_string_serialization_delimiter FROM cm_oa4mp_client_claims'
        . ' WHERE id = ' . (int)$this->claimId)
    );
  }

  /** Open the edit page with the fake server reporting the given cfg kind. */
  private function openEditPage($legacyCfg) {
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId);
    $harness->harnessServer->verifyLegacyCfg = $legacyCfg;

    $redirect = $harness->harnessInvoke('edit', array($this->clientId), 'GET');

    $this->assertNull($redirect, 'premise: the edit page renders, the client is in sync');
    $this->assertEqual(array('oa4mpVerifyClient'), $harness->harnessServer->callNames(),
      'premise: the page only read the server, it never wrote to it');

    return $harness;
  }

  /**
   * The server still holds the legacy cfg: the old-format row is upgraded to
   * a JSON array, and the form the page renders carries the repaired row.
   */
  public function testEditPageRepairsPreJsonArrayIsMemberOfAgainstLegacyCfg() {
    $harness = $this->openEditPage(true);

    $this->assertEqual(array('json_array', null), $this->storedSerialization(),
      'the old-format isMemberOf row must be upgraded while the server holds a legacy cfg');

    $rendered = null;
    foreach ($harness->request->data['Oa4mpClientClaim'] ?? array() as $claim) {
      if ((int)$claim['id'] === (int)$this->claimId) {
        $rendered = $claim['claim_multiple_value_serialization'];
      }
    }
    $this->assertEqual('json_array', $rendered,
      'the page re-reads the client, so it renders the repaired row');
  }

  /**
   * The control: the server holds a QDLv3 cfg, so it sends the delimited
   * string too. Changing the row would be drift, so it is left alone.
   */
  public function testEditPageLeavesPreJsonArrayIsMemberOfAgainstQdlv3Cfg() {
    $this->openEditPage(false);

    $this->assertEqual(array('delimited_string', ','), $this->storedSerialization(),
      'the isMemberOf row must not change while the server holds a QDLv3 cfg');
  }
}
