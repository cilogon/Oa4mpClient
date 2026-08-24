<?php
/**
 * Regression tests for sync verification (isClientDataSynchronized) in
 * Model/Oa4mpClientOa4mpServer.php. Covers:
 *  - oa4mp-unmarshall-claim-comparator-drift-2026-05-05 (#7): the comparator
 *    reported false out-of-sync (drift) even when both sides matched. The
 *    defect lived in the unmarshall layer, so the claim cases here build the
 *    server side by running a real cfg through oa4mpUnMarshallContent() rather
 *    than comparing a hand-built array to itself.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U4 (R2, R3, R5).
 */

class SyncVerificationTest extends Oa4mpTestCase {

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  private function client() {
    return array(
      'oa4mp_identifier' => 'https://example.org/oidc/client/abc',
      'name' => 'test client',
      'home_url' => 'https://example.org/',
      'proxy_limited' => false,
      'public_client' => false,
      // The server representation carries a comment starting with the plugin
      // signature; the comparator validates that prefix.
      'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': https://example.org/',
    );
  }

  private function syncData() {
    return array(
      'Oa4mpClientCoOidcClient' => $this->client(),
      'Oa4mpClientRefreshToken' => array('token_lifetime' => 3600),
      'Oa4mpClientCoCallback' => array(),
      'Oa4mpClientCoScope' => array(),
      'Oa4mpClientClaim' => array(),
    );
  }

  /**
   * A client whose plugin and server representations are identical must report
   * in-sync (no false drift). Guards against the comparator-drift bug where
   * key-name mismatches produced spurious out-of-sync results.
   */
  public function testIdenticalClientDataReportsInSync() {
    $data = $this->syncData();

    $this->assertTrue($this->server()->isClientDataSynchronized($data, $data),
      'identical plugin and server data must report in-sync');
  }

  /**
   * A genuine difference (public_client flag) must report out-of-sync.
   */
  public function testDifferingPublicClientReportsOutOfSync() {
    $cur = $this->syncData();
    $server = $this->syncData();
    $server['Oa4mpClientCoOidcClient']['public_client'] = true;

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $server),
      'a differing public_client flag must report out-of-sync');
  }

  /** Admin client context: buildClaimFromLdapMapping() needs the CO id. */
  private function adminClient() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  /**
   * The OA4MP server representation of the client above, as json_decode()'d by
   * oa4mpVerifyClient() before it calls oa4mpUnMarshallContent(). The cfg is
   * supplied by the caller so each test drives a specific cfg format.
   */
  private function serverObject($cfg) {
    return array(
      'client_id' => 'https://example.org/oidc/client/abc',
      'client_name' => 'test client',
      'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': https://example.org/',
      'rt_lifetime' => 3600,
      'redirect_uris' => array('https://example.org/callback'),
      'scope' => 'openid email',
      'cfg' => $cfg,
    );
  }

  /**
   * The plugin (persisted) side matching serverObject(), carrying the claim
   * rows as Containable reads them out of the database. Built independently of
   * the unmarshaller so the two sides only agree if the unmarshaller really
   * produces the persisted-side shape.
   */
  private function pluginSide($claims) {
    $data = $this->syncData();
    $data['Oa4mpClientCoCallback'] = array(array('url' => 'https://example.org/callback'));
    $data['Oa4mpClientCoScope'] = array(array('scope' => 'openid'), array('scope' => 'email'));
    $data['Oa4mpClientAccessToken'] = array();
    $data['Oa4mpClientClaim'] = $claims;

    return $data;
  }

  /** A QDLv3 cfg carrying the given claim_mappings block. */
  private function qdlv3Cfg($claimMappings) {
    return array(
      'tokens' => array(
        'identity' => array(
          'qdl' => array(
            'args' => array(
              'partition_key_template' => '${sub}',
              'partition_key_claim_name' => 'sub',
              'claim_mappings' => $claimMappings,
            ),
          ),
        ),
      ),
    );
  }

  /** The claim_mappings the marshaller writes into a QDLv3 cfg. */
  private function qdlv3ClaimMappings() {
    return array(
      array(
        'claim_name' => 'is_member_of',
        'source_model' => 'CoGroupMember',
        'source_model_claim_value_field' => 'member',
        'claim_value_selection' => 'all',
        'claim_value_json_format' => 'string',
        'claim_multiple_value_serialization' => 'delimited_string',
        'claim_value_string_serialization_delimiter' => ',',
        'claim_constraints' => array(
          array('constraint_field' => 'owner', 'constraint_value' => 'false'),
        ),
      ),
      array(
        'claim_name' => 'email',
        'source_model' => 'EmailAddress',
        'source_model_claim_value_field' => 'mail',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string',
        'claim_constraints' => array(
          array('constraint_field' => 'type', 'constraint_value' => 'official'),
        ),
      ),
    );
  }

  /**
   * The persisted Oa4mpClientClaim rows equivalent to qdlv3ClaimMappings(),
   * in database read shape (surrogate keys, nulls for unset columns) and in a
   * different order, so the comparator's own normalization is exercised.
   */
  private function persistedQdlv3Claims() {
    return array(
      array(
        'id' => 22,
        'client_id' => 7,
        'claim_name' => 'email',
        'source_model' => 'EmailAddress',
        'source_model_claim_value_field' => 'mail',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string',
        'claim_multiple_value_serialization' => null,
        'claim_value_string_serialization_delimiter' => null,
        'Oa4mpClientClaimConstraint' => array(
          array('id' => 91, 'claim_id' => 22, 'constraint_field' => 'type', 'constraint_value' => 'official'),
        ),
      ),
      array(
        'id' => 21,
        'client_id' => 7,
        'claim_name' => 'is_member_of',
        'source_model' => 'CoGroupMember',
        'source_model_claim_value_field' => 'member',
        'claim_value_selection' => 'all',
        'claim_value_json_format' => 'string',
        'claim_multiple_value_serialization' => 'delimited_string',
        'claim_value_string_serialization_delimiter' => ',',
        'Oa4mpClientClaimConstraint' => array(
          array('id' => 90, 'claim_id' => 21, 'constraint_field' => 'owner', 'constraint_value' => 'false'),
        ),
      ),
    );
  }

  /**
   * Bug #7, defect 2 (QDLv3 key names). The QDLv3 unmarshall path wrote its
   * output to $oa4mpClient['Oa4mpClaim'] and $claimMapping['ClaimConstraint'],
   * but isClientDataSynchronized() reads 'Oa4mpClientClaim' and
   * 'Oa4mpClientClaimConstraint'. The claims existed and were simply invisible
   * to the comparator, which then reported "OA4MP server has no claims" drift
   * on every verify pass. This drives the real path: cfg -> unmarshall ->
   * comparator, rather than comparing a hand-built array to itself.
   *
   * See docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md
   * (Step 2 / "Bug 2 (QDLv3 wrong keys)").
   */
  public function testUnmarshalledQdlv3ClaimsReportInSync() {
    $serverData = $this->server()->oa4mpUnMarshallContent(
      $this->serverObject($this->qdlv3Cfg($this->qdlv3ClaimMappings())),
      $this->adminClient());

    $this->assertNotEmpty($serverData['Oa4mpClientClaim'],
      'the QDLv3 unmarshaller must publish claims under the Oa4mpClientClaim key'
      . ' the comparator reads, not an abbreviated one');

    $cur = $this->pluginSide($this->persistedQdlv3Claims());

    $this->assertTrue($this->server()->isClientDataSynchronized($cur, $serverData),
      'unmarshalled QDLv3 claims must compare in-sync against the equivalent'
      . ' persisted claim rows');
  }

  /**
   * The other half of the same seam: constraints unmarshalled from QDLv3 must
   * actually participate in the comparison, so a real difference in one
   * constraint value reports out-of-sync. Without this, an unmarshaller that
   * dropped constraints entirely would still satisfy the in-sync case above.
   */
  public function testUnmarshalledQdlv3ConstraintDifferenceReportsOutOfSync() {
    $mappings = $this->qdlv3ClaimMappings();
    $mappings[1]['claim_constraints'][0]['constraint_value'] = 'delivery';

    $serverData = $this->server()->oa4mpUnMarshallContent(
      $this->serverObject($this->qdlv3Cfg($mappings)),
      $this->adminClient());

    $cur = $this->pluginSide($this->persistedQdlv3Claims());

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $serverData),
      'a differing claim constraint value must report out-of-sync');
  }

  /** A legacy QDLv2 cfg carrying the given ldap_to_claim_mappings block. */
  private function qdlv2Cfg($ldapToClaimMappings) {
    return array(
      'tokens' => array(
        'identity' => array(
          'qdl' => array(
            0 => array(
              'args' => array(
                'server_fqdn' => 'ldap.example.org',
                'server_port' => 636,
                'bind_dn' => 'cn=service,dc=example,dc=org',
                'bind_password' => 'not-a-real-password',
                'search_base' => 'ou=people,dc=example,dc=org',
                'search_attribute' => 'uid',
                'list_attributes' => array('isMemberOf'),
                'ldap_to_claim_mappings' => $ldapToClaimMappings,
              ),
            ),
          ),
        ),
      ),
    );
  }

  /**
   * The persisted claim rows toClaim() produces for the isMemberOf and gecos
   * search attributes. Neither takes the LdapProvisioner-config branch, so the
   * legacy path can be driven without provisioner fixtures.
   */
  private function persistedLegacyClaims() {
    return array(
      array(
        'id' => 31,
        'client_id' => 7,
        'claim_name' => 'is_member_of',
        'source_model' => 'CoGroupMember',
        'source_model_claim_value_field' => 'member',
        'claim_value_selection' => 'all',
        'claim_value_json_format' => 'string',
        'claim_multiple_value_serialization' => 'delimited_string',
        'claim_value_string_serialization_delimiter' => ',',
        'Oa4mpClientClaimConstraint' => array(
          array('id' => 95, 'claim_id' => 31, 'constraint_field' => 'owner', 'constraint_value' => 'false'),
        ),
      ),
      array(
        'id' => 32,
        'client_id' => 7,
        'claim_name' => 'gecos',
        'source_model' => 'Name',
        'source_model_claim_value_field' => 'all',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string',
        'claim_multiple_value_serialization' => null,
        'claim_value_string_serialization_delimiter' => null,
        'Oa4mpClientClaimConstraint' => array(
          array('id' => 96, 'claim_id' => 32, 'constraint_field' => 'type', 'constraint_value' => 'all'),
          array('id' => 97, 'claim_id' => 32, 'constraint_field' => 'primary', 'constraint_value' => 'true'),
        ),
      ),
    );
  }

  /**
   * Bug #7, defect 1 (legacy-cfg translation). The QDLv2 and deprecated cfg
   * paths returned raw Oa4mpClientCoLdapConfig descriptors, while the plugin
   * side stores Oa4mpClientClaim rows written by toClaim() at migration time.
   * The two sides were structurally different types, so every legacy-cfg
   * client reported drift on every verify pass. The fix translates the LDAP
   * mappings into claims via buildClaimFromLdapMapping(); this test drives
   * that translation through the unmarshall entry point and compares the
   * result against the claims toClaim() would have persisted.
   *
   * See docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md
   * (Steps 4-5 / "Bug 1 (legacy-format comparator drift)").
   */
  public function testUnmarshalledLegacyCfgClaimsReportInSync() {
    $cfg = $this->qdlv2Cfg(array('isMemberOf' => 'is_member_of', 'gecos' => 'gecos'));

    $serverData = $this->server()->oa4mpUnMarshallContent(
      $this->serverObject($cfg), $this->adminClient());

    $this->assertEqual(2, count($serverData['Oa4mpClientClaim']),
      'each legacy LDAP-to-claim mapping must be translated into a claim');

    $cur = $this->pluginSide($this->persistedLegacyClaims());

    $this->assertTrue($this->server()->isClientDataSynchronized($cur, $serverData),
      'claims translated from a legacy QDLv2 cfg must compare in-sync against'
      . ' the persisted claim rows migration produced for the same attributes');
  }

  /**
   * The negative control for the legacy path: a mapping removed on the OA4MP
   * server side must report out-of-sync rather than being masked by the
   * translation.
   */
  public function testUnmarshalledLegacyCfgMissingMappingReportsOutOfSync() {
    $cfg = $this->qdlv2Cfg(array('isMemberOf' => 'is_member_of'));

    $serverData = $this->server()->oa4mpUnMarshallContent(
      $this->serverObject($cfg), $this->adminClient());

    $cur = $this->pluginSide($this->persistedLegacyClaims());

    $this->assertFalse($this->server()->isClientDataSynchronized($cur, $serverData),
      'a claim mapping present in the plugin but absent from the server cfg'
      . ' must report out-of-sync');
  }

  // ------------------------------------------------------------------
  // The DynamoDB sort key: stored by the plugin, never written into a cfg,
  // and therefore compared by nothing.
  // ------------------------------------------------------------------

  /**
   * Marshall a client whose resolved DynamoDB configuration carries
   * $overrides, read the emitted cfg back through the unmarshaller, and hand
   * both sides to the comparator.
   *
   * One fixture through all three stages on purpose. sort_key was compared and
   * read back while the marshaller emitted it on neither path, so a test that
   * built the server side by hand could agree with the plugin side about a
   * value that never left the building.
   *
   * @param array $overrides Columns to merge into the DynamoDB configuration.
   * @param boolean $perClient Attach the configuration as the client's own
   *                           Oa4mpClientDynamoConfig row rather than as the
   *                           admin client's DefaultDynamoConfig.
   * @return array list($cfg, $serverData, $verdict)
   */
  private function dynamoRoundTrip($overrides, $perClient = false) {
    $server = $this->server();
    $config = array_merge(Oa4mpClaimRows::dynamoConfig(), $overrides);
    $claim = Oa4mpClaimRows::claim();

    $data = Oa4mpClaimRows::data($claim);
    $pluginSide = Oa4mpClaimRows::pluginSide($claim);

    if ($perClient) {
      $data['Oa4mpClientDynamoConfig'] = $config;
      $pluginSide['Oa4mpClientDynamoConfig'] = $config;
    } else {
      $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'] = $config;
      $pluginSide['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'] = $config;
    }

    $cfg = $server->oa4mpMarshallCfgQdl($data);

    $serverData = $server->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    return array($cfg, $serverData,
                 $server->isClientDataSynchronized($pluginSide, $serverData));
  }

  /**
   * A client whose DynamoDB configuration carries a populated sort_key reports
   * IN SYNC.
   *
   * cfg_contract.json's qdl_args group declares neither sort_key nor
   * sort_key_template, which settles that the plugin never writes either into
   * a cfg -- and the marshaller never did. The comparator nonetheless compared
   * both, and the unmarshaller read both back, so the plugin's stored value
   * was put up against the server's permanent null.
   *
   * That is not a theoretical column. View/Oa4mpClientCoAdminClients/fields.inc
   * offers DefaultDynamoConfig.sort_key and .sort_key_template as editable text
   * inputs, and Oa4mpClientCoOidcClientsController copies the whole
   * DefaultDynamoConfig row into a new client's Oa4mpClientDynamoConfig. An
   * operator who filled either field therefore got a client that reported out
   * of sync on every verify pass and that no edit could repair, because the
   * repair the comparison implied -- send the value -- is one the contract says
   * is never sent. Both resolution paths are exercised here for that reason.
   */
  public function testPopulatedSortKeyReportsInSync() {
    list($cfg, $serverData, $verdict) = $this->dynamoRoundTrip(array('sort_key' => 'group_name'));

    // The premise first: the marshaller really does not send it. If it ever
    // starts to, this file is the wrong place to find out, and the verdict
    // below would be right for a different reason.
    $args = $cfg['tokens']['identity']['qdl']['args'];
    $this->assertFalse(array_key_exists('sort_key', $args),
      'the marshaller emits no sort_key arg; the contract declares no such'
      . ' capability, so nothing may compare one');
    $this->assertFalse(array_key_exists('sort_key', $args['dynamo_module_config']),
      'and none inside the DynamoDB module configuration either');
    $this->assertFalse(isset($serverData['Oa4mpClientDynamoConfig']['sort_key']),
      'so the unmarshaller has nothing to read back, and must publish no'
      . ' sort_key at all rather than a null the comparator would read');

    $this->assertTrue($verdict,
      'a client whose stored DynamoDB configuration carries a sort_key must'
      . ' report in sync: the value was never sent, so the comparator must not'
      . ' count it -- comparing it is permanent, unrepairable drift');

    // The per-client row is how the value actually reaches a client: the
    // controller copies the admin default into it on create.
    list($perClientCfg, $perClientServer, $perClientVerdict) =
      $this->dynamoRoundTrip(array('sort_key' => 'group_name'), true);

    $this->assertTrue($perClientVerdict,
      'the same holds for a per-client Oa4mpClientDynamoConfig row, which is'
      . ' where the controller copies the admin default sort_key to');

    // The negative control: the comparator has not stopped looking at the
    // DynamoDB configuration altogether.
    $server = $this->server();
    $drifted = $perClientServer;
    $drifted['Oa4mpClientDynamoConfig']['table_name'] = 'a-different-table';

    $pluginSide = Oa4mpClaimRows::pluginSide(Oa4mpClaimRows::claim());
    $pluginSide['Oa4mpClientDynamoConfig'] =
      array_merge(Oa4mpClaimRows::dynamoConfig(), array('sort_key' => 'group_name'));

    $this->assertFalse($server->isClientDataSynchronized($pluginSide, $drifted),
      'a real DynamoDB difference must still report out of sync, or the'
      . ' verdicts above are the comparator having stopped looking');
  }

  /**
   * The same for sort_key_template, the second of the two columns.
   *
   * Both are asserted rather than one standing in for the other: they were two
   * separate comparisons, they are two separate read-backs, and an operator
   * may fill either without the other.
   */
  public function testPopulatedSortKeyTemplateReportsInSync() {
    list($cfg, $serverData, $verdict) =
      $this->dynamoRoundTrip(array('sort_key_template' => '${group_name}'));

    $args = $cfg['tokens']['identity']['qdl']['args'];
    $this->assertFalse(array_key_exists('sort_key_template', $args),
      'the marshaller emits no sort_key_template arg either');
    $this->assertFalse(isset($serverData['Oa4mpClientDynamoConfig']['sort_key_template']),
      'and the unmarshaller publishes none');

    $this->assertTrue($verdict,
      'a client whose stored DynamoDB configuration carries a'
      . ' sort_key_template must report in sync, for the same reason sort_key'
      . ' must');

    // Both columns populated at once, which is what copying a fully filled
    // admin default into a new client produces.
    list($bothCfg, $bothServer, $bothVerdict) = $this->dynamoRoundTrip(array(
      'sort_key' => 'group_name',
      'sort_key_template' => '${group_name}',
    ), true);

    $this->assertTrue($bothVerdict,
      'a client carrying both columns must report in sync: the controller'
      . ' copies the whole DefaultDynamoConfig row, so both arrive together');
  }
}
