<?php
/**
 * Regression tests for cfg marshalling and sync verification
 * (Model/Oa4mpClientOa4mpServer.php). Covers documented bugs:
 *  - oa4mp-dynamo-config-hasone-phantom-null-array-2026-06-30 (resolveDynamoConfig)
 *  - oa4mp-public-client-cfg-rejected-2026-08-03 (oa4mpMarshallContent)
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U4 (R2, R3, R5).
 */

class CfgMarshallingTest extends Oa4mpTestCase {

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * Bug: a missing per-client hasOne Oa4mpClientDynamoConfig is read by
   * Containable as an all-null array (phantom), not empty. A bare !empty()
   * guard therefore selected the null config and skipped the admin default.
   * The fix keys the guard on aws_region.
   */
  public function testResolveDynamoConfigFallsBackWhenPerClientIsPhantomNull() {
    $data = array(
      'Oa4mpClientDynamoConfig' => array(
        'id' => null,
        'client_id' => null,
        'aws_region' => null,
        'aws_access_key_id' => null,
        'aws_secret_access_key' => null,
        'table_name' => null,
        'partition_key' => null,
      ),
      'Oa4mpClientCoAdminClient' => array(
        'DefaultDynamoConfig' => array(
          'aws_region' => 'us-east-2',
          'table_name' => 'registry',
          'partition_key' => 'sub',
        ),
      ),
    );

    $resolved = $this->server()->resolveDynamoConfig($data);

    $this->assertEqual('us-east-2', $resolved['aws_region'],
      'phantom all-null per-client config must not be selected; admin default must be used');
  }

  /**
   * A real per-client Dynamo config (aws_region present) is used over the
   * admin default.
   */
  public function testResolveDynamoConfigUsesPerClientWhenRealValuesPresent() {
    $data = array(
      'Oa4mpClientDynamoConfig' => array(
        'aws_region' => 'eu-west-1',
        'table_name' => 'per_client',
      ),
      'Oa4mpClientCoAdminClient' => array(
        'DefaultDynamoConfig' => array('aws_region' => 'us-east-2'),
      ),
    );

    $resolved = $this->server()->resolveDynamoConfig($data);

    $this->assertEqual('eu-west-1', $resolved['aws_region'],
      'a real per-client config must be used when present');
  }

  private function adminClient() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  /**
   * Bug: the marshaller sent a cfg for a public client and OA4MP rejected it
   * ("custom configurations not permitted in public clients"). The fix skips
   * the cfg for public clients. Here claims are present, so a *confidential*
   * client would carry a cfg -- a public client must not.
   */
  public function testMarshalledContentHasNoCfgForPublicClient() {
    $data = array(
      'Oa4mpClientCoOidcClient' => array(
        'public_client' => true,
        'name' => 'public test client',
        'home_url' => 'https://example.org/',
      ),
      'Oa4mpClientClaim' => array(
        array('claim_name' => 'email', 'source_model' => 'EmailAddress'),
      ),
    );

    $content = $this->server()->oa4mpMarshallContent($this->adminClient(), $data);

    $this->assertEqual('none', $content['token_endpoint_auth_method'],
      'a public client uses token_endpoint_auth_method none');
    $this->assertFalse(isset($content['cfg']),
      'a public client must not carry a cfg (OA4MP rejects it)');

    // Positive control: the same claim data on a confidential client (only
    // public_client flipped) must still produce a cfg. Without this, the
    // assertion above would pass for the wrong reason if cfg stopped being
    // emitted for everyone, not just public clients.
    $confidentialData = $data;
    $confidentialData['Oa4mpClientCoOidcClient']['public_client'] = false;

    $confidential = $this->server()->oa4mpMarshallContent($this->adminClient(), $confidentialData);

    $this->assertTrue(isset($confidential['cfg']),
      'a confidential client with the same claim data must carry a cfg');
  }

  /**
   * A confidential client with no claim/config sources marshals with secret
   * auth and no cfg (the cfg block is entered only when there is something to
   * configure). Confirms the non-public path sets the expected auth method.
   */
  public function testMarshalledContentUsesSecretAuthForConfidentialClient() {
    $data = array(
      'Oa4mpClientCoOidcClient' => array(
        'public_client' => false,
        'name' => 'confidential test client',
        'home_url' => 'https://example.org/',
      ),
    );

    $content = $this->server()->oa4mpMarshallContent($this->adminClient(), $data);

    $this->assertEqual('client_secret_basic', $content['token_endpoint_auth_method'],
      'a confidential client uses token_endpoint_auth_method client_secret_basic');
  }

  /**
   * Bug: a valid QDLv2 cfg lacking the optional 'list_attributes' key made
   * in_array($key, null) throw a PHP 8 TypeError, which a defensive catch
   * swallowed -- the unmarshaller then returned empty and logged the misleading
   * "not a defined format" message, so the claim mappings silently vanished.
   * The fix defaults list_attributes to an empty array.
   */
  public function testUnmarshallQdlv2WithoutListAttributesDoesNotSwallowMappings() {
    $cfg = array(
      'tokens' => array(
        'identity' => array(
          'qdl' => array(
            0 => array(
              'args' => array(
                'server_fqdn' => 'ldap.example.org',
                'server_port' => 389,
                'bind_dn' => 'cn=service,dc=example,dc=org',
                'bind_password' => 'secret',
                'search_base' => 'ou=people,dc=example,dc=org',
                'search_attribute' => 'uid',
                // 'list_attributes' deliberately absent -- the pre-fix trigger.
                'ldap_to_claim_mappings' => array('isMemberOf' => 'is_member_of'),
              ),
            ),
          ),
        ),
      ),
    );

    $result = $this->server()->oa4mpUnMarshallCfgQdlv2($cfg);

    $this->assertNotEmpty($result,
      'a valid QDLv2 cfg without list_attributes must not be swallowed as "not a defined format"');
    $sa = $result[0]['Oa4mpClientCoSearchAttribute'][0];
    $this->assertEqual('isMemberOf', $sa['name']);
    $this->assertEqual('is_member_of', $sa['return_name']);
    $this->assertFalse($sa['return_as_list'],
      'an attribute absent from list_attributes defaults to return_as_list false');
  }

  /** Recursively collect every constraint_value in a marshalled cfg. */
  private function collectConstraintValues($node, &$out) {
    if (!is_array($node)) {
      return;
    }
    foreach ($node as $key => $value) {
      if ($key === 'constraint_value') {
        $out[] = $value;
      } elseif (is_array($value)) {
        $this->collectConstraintValues($value, $out);
      }
    }
  }

  /**
   * Bug: the cfg writer used || where AND was intended, so a degenerate
   * constraint (constraint_field 'type', constraint_value '') could be
   * serialized to the OA4MP server. The fix emits a constraint only when BOTH
   * the field and the value are non-empty.
   */
  public function testEmptyConstraintValueIsNotSerialized() {
    $data = array(
      'Oa4mpClientCoOidcClient' => array(
        'public_client' => false,
        'name' => 'confidential',
        'home_url' => 'https://example.org/',
      ),
      'Oa4mpClientCoAdminClient' => array(
        'co_id' => 1,
        'DefaultDynamoConfig' => array(
          'aws_region' => 'us-east-2',
          'aws_access_key_id' => 'AKIA',
          'aws_secret_access_key' => 'secret',
          'table_name' => 'registry',
          'partition_key' => 'sub',
          'partition_key_template' => '${sub}',
          'partition_key_claim_name' => 'sub',
        ),
      ),
      'Oa4mpClientClaim' => array(
        array(
          'claim_name' => 'vo_person_id',
          'source_model' => 'Identifier',
          'source_model_claim_value_field' => 'identifier',
          'Oa4mpClientClaimConstraint' => array(
            array('constraint_field' => 'type', 'constraint_value' => 'orcid'),
            // Degenerate: a field but an empty value. Must be dropped.
            array('constraint_field' => 'type', 'constraint_value' => ''),
          ),
        ),
      ),
    );

    $cfg = $this->server()->oa4mpMarshallCfgQdl($data);

    $values = array();
    $this->collectConstraintValues($cfg, $values);

    $this->assertContains('orcid', implode(',', $values),
      'the valid constraint must be serialized');
    $this->assertFalse(in_array('', $values, true),
      'a constraint with an empty constraint_value must not be serialized');
  }
}
