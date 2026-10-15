<?php
/**
 * A client that uses a Named configuration sends none of its own
 * authorization settings to the OA4MP server.
 *
 * A Named configuration points the client at a different QDL script, which
 * almost certainly does not read the plugin-built authorization args
 * (require_active_status, authorization_group_id and the three redirect URLs).
 * The Authorization tab presents those settings as ignored while a Named
 * configuration is applied, so oa4mpMarshallCfgQdl() leaves them out of the
 * cfg. The stored authorization row is kept, and the args come back as soon as
 * the Named configuration is removed.
 *
 * isClientDataSynchronized() returns early for a Named-configuration client,
 * before it compares authorization settings, so a client whose server cfg
 * still carries args from an earlier save is not reported out of sync; its
 * next save simply drops them.
 *
 * See docs/plans/2026-10-14-1946-feat-named-config-read-only-tabs-plan.md
 * (R5, R7, R8; AE2-AE4).
 */

class NamedConfigAuthorizationCfgTest extends Oa4mpTestCase {

  /**
   * The five args the authorization block builds.
   */
  private static $authorizationArgs = array(
    'require_active_status',
    'authorization_group_id',
    'authorization_group_redirect_url',
    'require_active_redirect_url',
    'no_record_redirect_url',
  );

  /**
   * The runner reuses one instance across every method in this file. Nothing
   * here holds instance state; setUp() says so explicitly.
   */
  public function setUp() {
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * A saved authorization row carrying every setting the block can emit.
   *
   * @return array
   */
  private function authorizationRow() {
    return array(
      'id' => 55,
      'client_id' => 1,
      'require_active' => true,
      'require_active_redirect_url' => 'https://example.org/inactive',
      'authz_co_group_id' => 12,
      'authz_group_redirect_url' => 'https://example.org/not-a-member',
      'no_record_redirect_url' => 'https://example.org/enroll',
    );
  }

  /**
   * A Named-configuration client that also carries a saved authorization row.
   *
   * @return array
   */
  private function namedConfigClient() {
    $data = Oa4mpClaimRows::namedConfigData(Oa4mpClaimRows::claim());
    $data['Oa4mpClientAuthorization'] = $this->authorizationRow();

    return $data;
  }

  /**
   * Covers AE2. With a Named configuration applied, none of the five
   * authorization args reaches the cfg, while the Named configuration's own
   * content and the contract-version stamp do.
   */
  public function testNamedConfigClientSendsNoAuthorizationArgs() {
    $cfg = $this->server()->oa4mpMarshallCfgQdl($this->namedConfigClient());
    $args = $cfg['tokens']['identity']['qdl']['args'] ?? array();

    foreach(self::$authorizationArgs as $name) {
      $this->assertFalse(array_key_exists($name, $args),
        "a Named-configuration client must not send $name, got "
        . var_export($args, true));
    }

    $this->assertEqual('from-named-config', $args['named_config_marker'] ?? null,
      'the Named configuration\'s own args must still reach the cfg');
    $this->assertEqual('COmanageRegistry/test/named_config.qdl',
      $cfg['tokens']['identity']['qdl']['load'] ?? null,
      'the cfg must load the Named configuration\'s script');
    $this->assertNotEmpty($cfg['metadata']['Oa4mpClient']['contract_version'] ?? null,
      'the contract-version stamp must still be written');
  }

  /**
   * An arg the Named configuration's own JSON defines reaches the cfg as
   * stored. The client's own authorization_group_id must not be merged into
   * the same key, where array_merge_recursive() would turn the two into an
   * array.
   */
  public function testNamedConfigOwnArgsReachCfgUnchanged() {
    $content = Oa4mpClaimRows::namedConfigContent();
    $content['tokens']['identity']['qdl']['args']['authorization_group_id'] = 99;

    $data = $this->namedConfigClient();
    $data['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'][0]['config'] = json_encode($content);

    $cfg = $this->server()->oa4mpMarshallCfgQdl($data);
    $args = $cfg['tokens']['identity']['qdl']['args'] ?? array();

    $this->assertTrue(($args['authorization_group_id'] ?? null) === 99,
      'the Named configuration\'s own authorization_group_id must be sent as'
      . ' stored, got ' . var_export($args['authorization_group_id'] ?? null, true));
  }

  /**
   * Covers AE3. The same saved client with its Named configuration removed
   * sends its authorization args again.
   */
  public function testRemovingNamedConfigRestoresAuthorizationArgs() {
    $data = $this->namedConfigClient();
    $data['Oa4mpClientCoOidcClient']['named_config_id'] = null;

    $cfg = $this->server()->oa4mpMarshallCfgQdl($data);
    $args = $cfg['tokens']['identity']['qdl']['args'] ?? array();

    foreach(self::$authorizationArgs as $name) {
      $this->assertTrue(array_key_exists($name, $args),
        "a client without a Named configuration must send $name, got "
        . var_export($args, true));
    }

    $this->assertTrue($args['authorization_group_id'] === 12,
      'the group id must still leave as an integer');
  }

  /**
   * The predicate tests the named_config_id value, never the presence of the
   * Oa4mpClientCoNamedConfig association, which Containable returns as an
   * array of nulls when the client has none.
   */
  public function testUsesNamedConfigReadsTheId() {
    $server = $this->server();

    $this->assertTrue($server->usesNamedConfig($this->namedConfigClient()),
      'a client with named_config_id set uses a Named configuration');

    foreach(array(null, '', 0) as $value) {
      $data = Oa4mpClaimRows::data(Oa4mpClaimRows::claim(), array('named_config_id' => $value));
      $data['Oa4mpClientCoNamedConfig'] = array('id' => null, 'config_name' => null, 'config' => null);

      $this->assertFalse($server->usesNamedConfig($data),
        'named_config_id ' . var_export($value, true) . ' with a phantom'
        . ' association must not count as a Named configuration');
    }
  }

  /**
   * The read-only tabs name the Named configuration by its name, and by its
   * id when the configuration is no longer found, never with an empty name.
   */
  public function testNamedConfigLabelFallsBackToTheId() {
    $server = $this->server();

    $data = $this->namedConfigClient();
    $data['Oa4mpClientCoNamedConfig'] = array('id' => Oa4mpClaimRows::NAMED_CONFIG_ID,
                                              'config_name' => "Alice's configuration");
    $this->assertEqual("Alice's configuration", $server->namedConfigLabel($data),
      'the label is the configuration name, unescaped');

    $data['Oa4mpClientCoNamedConfig'] = array('id' => null, 'config_name' => null);
    $this->assertEqual('#' . Oa4mpClaimRows::NAMED_CONFIG_ID, $server->namedConfigLabel($data),
      'a configuration no longer found is named by its id');
  }

  /**
   * Covers AE4. A saved Named-configuration client with a retained
   * authorization row reports in sync whether or not the server's cfg still
   * carries authorization args from an earlier save.
   */
  public function testRetainedAuthorizationDoesNotBreakSync() {
    $cur = Oa4mpClaimRows::pluginSide(Oa4mpClaimRows::claim());
    $cur['Oa4mpClientCoOidcClient']['named_config_id'] = Oa4mpClaimRows::NAMED_CONFIG_ID;
    $cur['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] = array(
      array(
        'id' => Oa4mpClaimRows::NAMED_CONFIG_ID,
        'config_name' => 'retained authorization named configuration',
        'Oa4mpClientCoScope' => array(
          array('scope' => 'openid'),
          array('scope' => 'email'),
        ),
      ),
    );
    $cur['Oa4mpClientAuthorization'] = $this->authorizationRow();

    $server = array(
      'Oa4mpClientCoOidcClient' => array(
        'oa4mp_identifier' => Oa4mpClaimRows::CLIENT_IDENTIFIER,
        'name' => Oa4mpClaimRows::CLIENT_NAME,
        'proxy_limited' => false,
        'public_client' => false,
        'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': ' . Oa4mpClaimRows::CLIENT_HOME_URL,
      ),
      'Oa4mpClientRefreshToken' => array('token_lifetime' => 3600),
      'Oa4mpClientCoCallback' => array(array('url' => Oa4mpClaimRows::CALLBACK_URL)),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid'), array('scope' => 'email')),
      'Oa4mpClientAccessToken' => array(),
      'Oa4mpClientClaim' => array(),
    );

    $this->assertTrue($this->server()->isClientDataSynchronized($cur, $server),
      'a Named-configuration client with a retained authorization row must'
      . ' report in sync against a server cfg carrying no authorization args');

    $server['Oa4mpClientAuthorization'] = array(
      'require_active' => true,
      'authz_co_group_id' => 7,
      'authz_group_redirect_url' => 'https://example.org/elsewhere',
    );

    $this->assertTrue($this->server()->isClientDataSynchronized($cur, $server),
      'a Named-configuration client must report in sync against a server cfg'
      . ' that still carries leftover authorization args');
  }
}
