<?php
/**
 * What the OIDC client edit page does while a Named configuration is applied
 * (cilogon/Oa4mpClient#58).
 *
 * The Named configuration takes the place of the client's own claims and of
 * its LDAP and DynamoDB configurations. The edit page used to turn any
 * unconverted LDAP search attribute into a claim row whatever the client
 * used, recreating a claim the Named configuration had replaced. It now
 * skips that conversion for such a client, and, like every other client
 * page, deletes the never-sent rows an older client still holds as soon as
 * it loads the client.
 *
 * Driven through Oa4mpOidcClientsControllerHarness, whose fake server
 * records every server call and reports the client in sync. The client graph
 * is the claims controller fixture's; this file adds an LDAP configuration
 * with one unconverted search attribute to the client under test.
 */

class NamedConfigEditPageTest extends Oa4mpClaimsControllerTestCase {

  /** @var integer|null A public client with a Named configuration, seeded by the test that needs it. */
  protected $publicNamedConfigClientId = null;

  protected $accessTokenId = null;

  protected function fixtureTagPrefix() {
    return 'named-edit';
  }

  public function setUp() {
    $this->publicNamedConfigClientId = null;
    $this->accessTokenId = null;

    parent::setUp();
  }

  public function tearDown() {
    if ($this->fx !== null && $this->namedConfigClientId !== null) {
      // The driven actions write claims, DynamoDB rows and contact email rows
      // of their own, so purge them by client before the clients go.
      $ids = array($this->clientId, $this->publicClientId, $this->namedConfigClientId);
      if ($this->publicNamedConfigClientId !== null) {
        $ids[] = $this->publicNamedConfigClientId;
      }
      $clients = implode(', ', array_map('intval', $ids));

      $this->fx->cleanup(array(
        'cm_oa4mp_client_claim_constraints' =>
          'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id IN (' . $clients . '))',
        'cm_oa4mp_client_claims' => 'client_id IN (' . $clients . ')',
        'cm_oa4mp_client_dynamo_configs' => 'admin_id = ' . (int)$this->adminId
          . ' OR client_id IN (' . $clients . ')',
        'cm_oa4mp_client_co_email_addresses' => 'client_id IN (' . $clients . ')',
        'cm_oa4mp_client_access_tokens' => 'client_id IN (' . $clients . ')'
      ));
    }

    parent::tearDown();
  }

  /**
   * Give $clientId an LDAP configuration holding one eduPersonOrcid search
   * attribute not yet converted into a claim.
   *
   * @return array The LDAP configuration id and the search attribute id
   */
  private function seedUnconvertedSearchAttribute($clientId) {
    $ldapId = $this->fx->insert('cm_oa4mp_client_co_ldap_configs', array(
      'client_id' => $clientId,
      'admin_id' => $this->adminId,
      'enabled' => true,
      'authorization_type' => 'simple',
      'serverurl' => 'ldaps://ldap.example.org',
      'binddn' => 'cn=test,dc=example,dc=org',
      'password' => 'not-a-real-password',
      'basedn' => 'dc=example,dc=org',
      'search_name' => 'uid'
    ));

    $searchAttrId = $this->fx->insert('cm_oa4mp_client_co_search_attributes', array(
      'ldap_id' => $ldapId,
      'name' => 'eduPersonOrcid',
      'return_as_list' => false,
      'return_name' => 'orcid',
      'claim_id' => null
    ));

    return array($ldapId, $searchAttrId);
  }

  /** The number of orcid claims, the claim the seeded search attribute converts to, on $clientId. */
  private function orcidClaimCount($clientId) {
    return (int)$this->countRows($this->fx, 'cm_oa4mp_client_claims',
      'client_id = ' . (int)$clientId . " AND claim_name = 'orcid'");
  }

  /** Open the edit page of $clientId and check it rendered without writing to the server. */
  private function openEditPage($clientId) {
    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId);

    $redirect = $harness->harnessInvoke('edit', array($clientId), 'GET');

    $this->assertNull($redirect, 'premise: the edit page rendered');
    $this->assertEqual(array('oa4mpVerifyClient'), $harness->harnessServer->callNames(),
      'premise: the page only read the server, it never wrote to it');

    return $harness;
  }

  // ==========================================================================
  // LDAP search attribute conversion
  // ==========================================================================

  /**
   * Opening the edit page of a Named-configuration client that still holds
   * an unconverted search attribute creates no claim, and the LDAP
   * configuration the attribute belongs to is deleted with the client's
   * other never-sent leftovers.
   */
  public function testEditPageCreatesNoClaimForNamedConfigClientSearchAttribute() {
    list($ldapId) = $this->seedUnconvertedSearchAttribute($this->namedConfigClientId);

    $this->openEditPage($this->namedConfigClientId);

    $this->assertEqual(0, $this->orcidClaimCount($this->namedConfigClientId),
      'no claim was created from the search attribute');
    $this->assertEqual(0, (int)$this->countRows($this->fx, 'cm_oa4mp_client_co_ldap_configs',
      'id = ' . (int)$ldapId), 'the LDAP configuration was deleted');
  }

  /**
   * A public client with a Named configuration keeps its rows until the
   * maintainer clears it, so its LDAP configuration is still there when the
   * page opens. The conversion is skipped for it all the same: the page
   * itself, not the delete, keeps the claim from being created.
   */
  public function testEditPageSkipsConversionWhileNamedConfigApplied() {
    $this->publicNamedConfigClientId = $this->fx->oidcClient($this->adminId,
      'public-named-' . $this->namedConfigClientId,
      array('public_client' => true, 'named_config_id' => $this->namedConfigId));
    list($ldapId, $searchAttrId) = $this->seedUnconvertedSearchAttribute($this->publicNamedConfigClientId);

    $this->openEditPage($this->publicNamedConfigClientId);

    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_co_ldap_configs',
      'id = ' . (int)$ldapId), 'premise: the public client\'s LDAP configuration is kept');
    $this->assertEqual(0, $this->orcidClaimCount($this->publicNamedConfigClientId),
      'no claim was created from the search attribute');
    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_co_search_attributes',
      'id = ' . (int)$searchAttrId . ' AND claim_id IS NULL'),
      'the search attribute was left unconverted');
  }

  /**
   * The control: the same search attribute on a client without a Named
   * configuration is still converted, so it is the Named configuration that
   * stops the cases above.
   */
  public function testEditPageStillConvertsSearchAttributeWithoutNamedConfig() {
    list(, $searchAttrId) = $this->seedUnconvertedSearchAttribute($this->clientId);

    $this->openEditPage($this->clientId);

    $this->assertEqual(1, $this->orcidClaimCount($this->clientId), 'the search attribute became a claim');
    $this->assertEqual(0, (int)$this->countRows($this->fx, 'cm_oa4mp_client_co_search_attributes',
      'id = ' . (int)$searchAttrId . ' AND claim_id IS NULL'),
      'the search attribute points at its claim');
  }

  // ==========================================================================
  // Saving the edit form
  // ==========================================================================

  /**
   * A save of a Named-configuration client that carries a DynamoDB section
   * writes no DynamoDB row, while the name and home URL are still saved.
   * Only the fields the form edits are taken from a post, so this pins the
   * behavior the page already has.
   */
  public function testEditSaveForNamedConfigClientWritesNoDynamoConfig() {
    $dynamoWhere = 'client_id = ' . (int)$this->namedConfigClientId
      . ' OR (admin_id = ' . (int)$this->adminId . " AND table_name = 'oa4mp-test-table')";
    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_dynamo_configs', $dynamoWhere),
      'premise: the only DynamoDB row is the admin client default');

    $harness = Oa4mpOidcClientsControllerHarness::build(null, $this->coId, array(
      'Oa4mpClientCoOidcClient' => array(
        'id' => (string)$this->namedConfigClientId,
        'name' => 'renamed named client',
        'home_url' => 'https://example.org/renamed'
      ),
      'Oa4mpClientCoEmailAddress' => array(
        0 => array('mail' => 'contact@example.org')
      ),
      'Oa4mpClientDynamoConfig' => array(
        'client_id' => (string)$this->namedConfigClientId,
        'aws_region' => 'us-west-2',
        'aws_access_key_id' => 'AKIAEXAMPLEROTATED',
        'aws_secret_access_key' => 'not-a-real-rotated-secret',
        'table_name' => 'posted-table',
        'partition_key' => 'client_id',
        'partition_key_template' => '${client_id}',
        'partition_key_claim_name' => 'sub'
      )
    ));

    $redirect = $harness->harnessInvoke('edit', array($this->namedConfigClientId), 'POST');

    $this->assertNull($redirect, 'the edit page rendered after the save');
    $this->assertTrue($harness->harnessFlashed(_txt('rs.updated', array('renamed named client')), 'success'),
      'success was reported');
    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_dynamo_configs', $dynamoWhere),
      'no DynamoDB row was created for the client');
    $this->assertEqual(0, (int)$this->countRows($this->fx, 'cm_oa4mp_client_dynamo_configs',
      "table_name = 'posted-table'"), 'the posted DynamoDB section was written nowhere');
    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_co_oidc_clients',
      'id = ' . (int)$this->namedConfigClientId
      . " AND name = 'renamed named client' AND home_url = 'https://example.org/renamed'"),
      'the name and home URL were saved');
  }

  // ==========================================================================
  // Leftovers deleted on load
  // ==========================================================================

  /**
   * Opening the edit page of a Named-configuration client that still holds
   * a claim and an access token row deletes the claim, which was never sent,
   * and keeps the access token row, which still is. The form is built from
   * the client as it is after the delete.
   */
  public function testOpeningEditPageDeletesLeftoversAndKeepsTheAccessToken() {
    $this->accessTokenId = $this->fx->insert('cm_oa4mp_client_access_tokens', array(
      'client_id' => $this->namedConfigClientId,
      'is_jwt' => true
    ));
    $claimWhere = 'client_id = ' . (int)$this->namedConfigClientId;
    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_claims', $claimWhere),
      'premise: the client holds a leftover claim');

    $harness = $this->openEditPage($this->namedConfigClientId);

    $this->assertEqual(0, (int)$this->countRows($this->fx, 'cm_oa4mp_client_claims', $claimWhere),
      'the leftover claim was deleted');
    $this->assertEqual(1, (int)$this->countRows($this->fx, 'cm_oa4mp_client_access_tokens',
      'id = ' . (int)$this->accessTokenId . ' AND is_jwt = true'), 'the access token row was kept');
    $this->assertEmpty($harness->request->data['Oa4mpClientClaim'] ?? array(),
      'the page read the client again after the delete');
    $this->assertFalse($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.prune'), 'error'),
      'no cleanup error was reported');
  }
}
