<?php
/**
 * Shared row builder for the claim cfg contract matrix.
 *
 * Test/Case/Model/ClaimCfgContractTest.php drives one test method per matrix
 * row; this class supplies the data each row starts from, so a row differs
 * from its neighbours only in the overrides it names. The runner shell
 * requires every file under Test/lib, so a test case needs no require of its
 * own.
 *
 * Two things about the shapes here are deliberate:
 *
 *  - The baseline claim carries the surrogate and timestamp columns (id,
 *    client_id, created, modified) and the associated constraint rows, because
 *    that is what Containable hands the marshaller. Those keys exercise the
 *    marshaller's unset() block; dropping them from the fixture would make the
 *    golden values pass for the wrong reason.
 *  - The claim's key order follows the column order declared for
 *    cm_oa4mp_client_claims in Config/Schema/schema.xml. The marshaller copies
 *    the claim whole and then unsets, so the emitted key order is the input's
 *    key order, and the thin runner's assertEqual is key-order sensitive.
 *
 * Every credential-shaped value here is a synthetic placeholder. The secret
 * scan (.github/workflows/hermetic-tests.yml) walks full history, so a
 * scanner-matching value committed once can never be cleared by editing the
 * working tree.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U1
 * (R1, R2, R3, R4, R5, R13, R19) and U2 (R2, R4).
 */

class Oa4mpClaimRows {

  // Synthetic placeholders (R13). AKIAEXAMPLE is the same short form already
  // used by Test/Case/Controller/AdminClientEditSaveTest.php.
  const AWS_ACCESS_KEY_ID = 'AKIAEXAMPLE';
  const AWS_SECRET_ACCESS_KEY = 'not-a-real-secret';

  // Pinned so the emitted cfg does not depend on
  // COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT or on the hard-coded fallback.
  const QDL_CLAIM_SOURCE = 'COmanageRegistry/test/dynamodb_claims.qdl';

  const CLIENT_IDENTIFIER = 'https://example.org/oidc/client/abc';
  const CLIENT_NAME = 'claim matrix client';
  const CLIENT_HOME_URL = 'https://example.org/';
  const CALLBACK_URL = 'https://example.org/callback';

  const TIMESTAMP = '2026-01-02 03:04:05';

  /** The named configuration the configuration-shape rows resolve to. */
  const NAMED_CONFIG_ID = 3;

  /** The admin client's default Dynamo configuration, used by every row. */
  public static function dynamoConfig() {
    return array(
      'aws_region' => 'us-east-2',
      'aws_access_key_id' => self::AWS_ACCESS_KEY_ID,
      'aws_secret_access_key' => self::AWS_SECRET_ACCESS_KEY,
      'table_name' => 'registry',
      'partition_key' => 'sub',
      'partition_key_template' => '${sub}',
      'partition_key_claim_name' => 'sub',
    );
  }

  /** The admin client as Containable reads it alongside the OIDC client. */
  public static function adminClient() {
    return array(
      'co_id' => 1,
      'qdl_claim_source' => self::QDL_CLAIM_SOURCE,
      'DefaultDynamoConfig' => self::dynamoConfig(),
    );
  }

  /** The admin-client argument oa4mpUnMarshallContent() takes. */
  public static function adminClientContext() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  /** One persisted constraint row, in database read shape. */
  public static function constraint($id, $field, $value) {
    return array(
      'id' => $id,
      'claim_id' => 41,
      'constraint_field' => $field,
      'constraint_value' => $value,
      'created' => self::TIMESTAMP,
      'modified' => self::TIMESTAMP,
    );
  }

  /**
   * The baseline claim, with every field that reaches the cfg populated.
   *
   * @param array $overrides Column => value, replacing the baseline in place
   *              (array_merge keeps the baseline's key order). Pass
   *              'Oa4mpClientClaimConstraint' to change the constraint set.
   * @return array
   */
  public static function claim($overrides = array()) {
    $claim = array(
      'id' => 41,
      'client_id' => 7,
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'created' => self::TIMESTAMP,
      'modified' => self::TIMESTAMP,
      'Oa4mpClientClaimConstraint' => array(
        self::constraint(90, 'owner', 'false'),
      ),
    );

    return array_merge($claim, $overrides);
  }

  /**
   * A confidential client carrying exactly the given claim, in the shape
   * oa4mpMarshallCfgQdl() takes.
   */
  public static function data($claim, $clientOverrides = array()) {
    return array(
      'Oa4mpClientCoOidcClient' => array_merge(array(
        'oa4mp_identifier' => self::CLIENT_IDENTIFIER,
        'name' => self::CLIENT_NAME,
        'home_url' => self::CLIENT_HOME_URL,
        'public_client' => false,
        'proxy_limited' => false,
      ), $clientOverrides),
      'Oa4mpClientCoAdminClient' => self::adminClient(),
      'Oa4mpClientClaim' => array($claim),
    );
  }

  /**
   * The stored content of the named configuration, decoded.
   *
   * Deliberately not the shape the claim loop builds: the load path, the
   * execution phases and the args block all differ from the QDL shape, so a
   * configuration-shape row can tell the two apart by their content and not
   * merely by the presence of a key.
   *
   * @return array
   */
  public static function namedConfigContent() {
    return array(
      'tokens' => array(
        'identity' => array(
          'type' => 'identity',
          'qdl' => array(
            'load' => 'COmanageRegistry/test/named_config.qdl',
            'xmd' => array(
              'exec_phase' => array('post_auth'),
            ),
            'args' => array(
              'named_config_marker' => 'from-named-config',
            ),
          ),
        ),
      ),
    );
  }

  /**
   * A confidential client that uses a named configuration and also carries the
   * given claim, in the shape oa4mpMarshallCfgQdl() takes.
   *
   * The claim is present on purpose. The named-configuration branch returns
   * before the claim loop runs, so the claim never reaches the server; that is
   * the deferred defect the named-configuration row characterizes (Q1 in
   * docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md).
   *
   * @param array $claim The claim row, from claim().
   * @return array
   */
  public static function namedConfigData($claim) {
    $data = self::data($claim, array('named_config_id' => self::NAMED_CONFIG_ID));

    // The marshaller looks the named configuration up in the admin client's
    // list, by id, and json_decode()s its config column.
    $data['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] = array(
      array(
        'id' => self::NAMED_CONFIG_ID,
        'admin_id' => 1,
        'config_name' => 'matrix named configuration',
        'config' => json_encode(self::namedConfigContent()),
      ),
    );

    // The metadata URL the branch appends is built from this top-level key,
    // not from the list above. Controller-supplied in production; supplied
    // here so the row does not marshal through an undefined-key warning.
    $data['Oa4mpClientCoNamedConfig'] = array('id' => self::NAMED_CONFIG_ID);

    return $data;
  }

  /**
   * The OA4MP server representation of that client, as oa4mpVerifyClient()
   * json_decode()s it before calling oa4mpUnMarshallContent(). The cfg is
   * supplied by the caller so a row can feed back its own emitted cfg.
   */
  public static function serverObject($cfg) {
    return array(
      'client_id' => self::CLIENT_IDENTIFIER,
      'client_name' => self::CLIENT_NAME,
      'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': ' . self::CLIENT_HOME_URL,
      'rt_lifetime' => 3600,
      'redirect_uris' => array(self::CALLBACK_URL),
      'scope' => 'openid email',
      'cfg' => $cfg,
    );
  }

  /**
   * The persisted (plugin) side matching serverObject(), carrying the same
   * claim row. Built independently of the unmarshaller so the round trip only
   * reports in sync if the unmarshaller really produces the persisted shape.
   */
  public static function pluginSide($claim) {
    return array(
      'Oa4mpClientCoOidcClient' => array(
        'oa4mp_identifier' => self::CLIENT_IDENTIFIER,
        'name' => self::CLIENT_NAME,
        'home_url' => self::CLIENT_HOME_URL,
        'proxy_limited' => false,
        'public_client' => false,
        'comment' => _txt('pl.oa4mp_client_co_oidc_client.signature') . ': ' . self::CLIENT_HOME_URL,
      ),
      'Oa4mpClientCoAdminClient' => self::adminClient(),
      'Oa4mpClientRefreshToken' => array('token_lifetime' => 3600),
      'Oa4mpClientCoCallback' => array(array('url' => self::CALLBACK_URL)),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid'), array('scope' => 'email')),
      'Oa4mpClientAccessToken' => array(),
      'Oa4mpClientClaim' => array($claim),
    );
  }

  /**
   * The declared matrix rows, machine-readable.
   *
   * The per-row test methods live in ClaimCfgContractTest.php and are not
   * machine-readable, so the row set is declared here for U4's drift check to
   * read: it compares this list against the authoritative option lists in
   * View/Oa4mpClientClaims/fields.inc and fails on an option no row covers.
   *
   * Each entry carries:
   *   row          the test method's row name, as it appears in failure messages
   *   source_model the claim source the row exercises
   *   dimension    the matrix dimension the row covers
   *   values       the enumerated option values the row pins, by column
   *
   * Keep this in step with the test methods: a row added there and not here is
   * invisible to the drift check, which is the one thing that check cannot
   * detect for itself.
   *
   * @return array
   */
  public static function declaredRows() {
    $rows = array();

    // Emptiness axis: every claim column that reaches the cfg, three ways.
    // The value each populated row pins is recorded so the drift check can see
    // which enumerated options the emptiness axis already covers.
    $emptinessColumns = array(
      'claim_name' => 'eduperson_entitlement',
      'source_model' => 'CoPersonRole',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => ',',
    );
    foreach($emptinessColumns as $column => $populated) {
      foreach(array('populated', 'empty', 'string_zero') as $state) {
        $rows[] = array(
          'row' => $column . '/' . $state,
          'source_model' => 'CoGroupMember',
          'dimension' => 'emptiness:' . $column,
          'values' => ($state === 'populated') ? array($column => $populated) : array(),
        );
      }
    }

    // Claim source x constraint count.
    $sourceConstraintCounts = array(
      'EmailAddress' => 2,
      'Name' => 2,
      'CoGroupMember' => 1,
      'Identifier' => 1,
      'CoPersonRole' => 1,
      'SshKey' => 1,
      'CoTAndCAgreement' => 0,
      'UnixClusterAccount' => 0,
    );
    foreach($sourceConstraintCounts as $source => $count) {
      $rows[] = array(
        'row' => 'source/' . $source . '/constraints:' . $count,
        'source_model' => $source,
        'dimension' => 'source_x_constraint_count',
        // Every constraint-count row runs on the string JSON format, which is
        // how that enumerated option is covered.
        'values' => array('source_model' => $source, 'claim_value_json_format' => 'string'),
      );
    }

    // Claim source x value format.
    $rows[] = array(
      'row' => 'source/Identifier/format:number',
      'source_model' => 'Identifier',
      'dimension' => 'source_x_value_format',
      'values' => array('claim_value_json_format' => 'number'),
    );
    $rows[] = array(
      'row' => 'source/UnixClusterAccount/format:number',
      'source_model' => 'UnixClusterAccount',
      'dimension' => 'source_x_value_format',
      'values' => array('claim_value_json_format' => 'number'),
    );
    $rows[] = array(
      'row' => 'source/EmailAddress/format:number',
      'source_model' => 'EmailAddress',
      'dimension' => 'source_x_value_format',
      'values' => array('claim_value_json_format' => 'number'),
    );

    // Claim source x value selection.
    $rows[] = array(
      'row' => 'source/CoGroupMember/selection:all',
      'source_model' => 'CoGroupMember',
      'dimension' => 'source_x_value_selection',
      'values' => array('claim_value_selection' => 'all'),
    );
    $rows[] = array(
      'row' => 'source/CoTAndCAgreement/selection:all',
      'source_model' => 'CoTAndCAgreement',
      'dimension' => 'source_x_value_selection',
      'values' => array('claim_value_selection' => 'all'),
    );
    $rows[] = array(
      'row' => 'source/EmailAddress/selection:first',
      'source_model' => 'EmailAddress',
      'dimension' => 'source_x_value_selection',
      'values' => array('claim_value_selection' => 'first'),
    );
    $rows[] = array(
      'row' => 'source/EmailAddress/selection:all',
      'source_model' => 'EmailAddress',
      'dimension' => 'source_x_value_selection',
      'values' => array('claim_value_selection' => 'all'),
    );

    // Claim source x serialization mode.
    $rows[] = array(
      'row' => 'source/CoTAndCAgreement/serialization:delimited_string',
      'source_model' => 'CoTAndCAgreement',
      'dimension' => 'source_x_serialization_mode',
      'values' => array('claim_multiple_value_serialization' => 'delimited_string'),
    );
    $rows[] = array(
      'row' => 'source/EmailAddress/serialization:delimited_string',
      'source_model' => 'EmailAddress',
      'dimension' => 'source_x_serialization_mode',
      'values' => array('claim_multiple_value_serialization' => 'delimited_string'),
    );
    $rows[] = array(
      'row' => 'source/SshKey/serialization:json_array',
      'source_model' => 'SshKey',
      'dimension' => 'source_x_serialization_mode',
      'values' => array('claim_multiple_value_serialization' => 'json_array'),
    );

    // Serialization mode x delimiter field.
    $rows[] = array(
      'row' => 'serialization:json_array/delimiter:populated',
      'source_model' => 'CoGroupMember',
      'dimension' => 'serialization_x_delimiter',
      'values' => array('claim_multiple_value_serialization' => 'json_array'),
    );
    $rows[] = array(
      'row' => 'serialization:json_array/delimiter:empty',
      'source_model' => 'CoGroupMember',
      'dimension' => 'serialization_x_delimiter',
      'values' => array('claim_multiple_value_serialization' => 'json_array'),
    );

    // Configuration shape: which cfg a client resolves to, ahead of any
    // question of what the QDL shape contains.
    $rows[] = array(
      'row' => 'cfg_shape/public_client',
      'source_model' => 'CoGroupMember',
      'dimension' => 'cfg_shape',
      'values' => array(),
    );
    $rows[] = array(
      'row' => 'cfg_shape/named_config',
      'source_model' => 'CoGroupMember',
      'dimension' => 'cfg_shape',
      'values' => array(),
    );
    $rows[] = array(
      'row' => 'cfg_shape/confidential_no_named_config',
      'source_model' => 'CoGroupMember',
      'dimension' => 'cfg_shape',
      'values' => array(),
    );

    // Round trip and cfg envelope.
    $rows[] = array(
      'row' => 'round_trip/EmailAddress/two_constraints',
      'source_model' => 'EmailAddress',
      'dimension' => 'round_trip',
      'values' => array(),
    );
    $rows[] = array(
      'row' => 'cfg_envelope/CoGroupMember',
      'source_model' => 'CoGroupMember',
      'dimension' => 'cfg_envelope',
      'values' => array(),
    );

    return $rows;
  }

  /**
   * Declared exemptions: matrix cells that deliberately carry a narrower
   * assertion than the rest, with the reason. U4's drift check reads this so
   * an uncovered cell is either a row or an explicit, reviewed decision --
   * never a silent gap.
   *
   * @return array
   */
  public static function declaredExemptions() {
    return array(
      array(
        'row' => 'claim_name/empty',
        'exempt_from' => 'round_trip',
        'reason' => 'The marshaller strips an empty claim_name, leaving a nameless'
          . ' mapping. Both the QDLv3 unmarshaller and the sync comparator index'
          . ' claim_name unconditionally, so the round trip yields an'
          . ' undefined-key warning rather than a verdict. The row asserts the'
          . ' structural fact instead.',
      ),
      array(
        'row' => 'claim_name/string_zero',
        'exempt_from' => 'round_trip',
        'reason' => 'Same as claim_name/empty: empty(\'0\') is true, so the'
          . ' marshaller strips a string-zero claim_name the same way.',
      ),
      array(
        'row' => 'source_model/empty',
        'exempt_from' => 'round_trip',
        'reason' => 'Same unconditional read as claim_name: the unmarshaller and'
          . ' the comparator both index source_model directly.',
      ),
      array(
        'row' => 'source_model/string_zero',
        'exempt_from' => 'round_trip',
        'reason' => 'Same as source_model/empty.',
      ),
      array(
        'row' => 'cfg_shape/public_client',
        'exempt_from' => 'round_trip',
        'reason' => 'A public client is sent no cfg at all, so there is no'
          . ' emitted cfg to unmarshall and compare. The row asserts the'
          . ' absence instead.',
      ),
      array(
        'row' => 'cfg_shape/named_config',
        'exempt_from' => 'round_trip',
        'reason' => 'The named-configuration branch returns the stored named'
          . ' configuration and never emits claim mappings, so a round trip'
          . ' would compare the claim against nothing. The comparator exempts'
          . ' named-configuration clients from the claim comparison outright;'
          . ' that exemption is characterized in its own test, not here. The'
          . ' row asserts the merged shape instead.',
      ),
      array(
        'row' => 'cfg_shape/confidential_no_named_config',
        'exempt_from' => 'round_trip',
        'reason' => 'The positive control for the two rows above, asserted at'
          . ' the outer marshaller so it controls the public row directly. Its'
          . ' claim round trip is already covered by every QDL-shape row, which'
          . ' runs on exactly this client shape.',
      ),
    );
  }
}
