<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP Client Marshaller
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @link          https://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugins
 * @since         Oa4mpClient v8.0.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

declare(strict_types=1);

namespace Oa4mpClient\Lib\Oa4mp;

use Closure;
use LogicException;
use Oa4mpClient\Lib\Enum\ScopeEnum;

/**
 * Builds the client representation sent to the OA4MP server, cfg included.
 *
 * Ported from oa4mpMarshallCfgQdl() and oa4mpMarshallContent() in
 * Model/Oa4mpClientOa4mpServer.php of the Registry 4 edition at 7.0.0, with
 * the same array shapes in and out, so the two can be compared line by line.
 * For the same client settings both editions send OA4MP the same bytes; the
 * Parity check in tests/Unit/Oa4mp/MarshallerParityTest.php holds this code
 * to the bytes Registry 4 recorded.
 *
 * What the Registry 4 code asked its framework for is passed in instead:
 * the two Registry addresses (it asked the Router), the default QDL script
 * path (it read COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT at call time), and
 * where to log (it logged through the model).
 */
class Marshaller
{
  /**
   * The prefix every withheld-value signal line carries, so the line can be
   * recognized without matching the whole sentence.
   */
  public const CFG_WITHHELD_SIGNAL = 'Oa4mpClientClaim: cfg capability contract';

  /**
   * The one claim-mapping field the marshaller builds rather than reading
   * off a claim column: the mapping's claim_constraints list, built from the
   * claim's Oa4mpClientClaimConstraint rows. The contract declares it last
   * and marks it column_backed false.
   */
  public const CFG_CONSTRAINTS_FIELD = 'claim_constraints';

  /**
   * The text the comment sent to OA4MP starts with. Byte for byte the
   * Registry 4 edition's 'pl.oa4mp_client_co_oidc_client.signature' text:
   * both editions write it, and the comparator accepts a client whose
   * comment starts with it.
   */
  public const SIGNATURE = 'Created by COmanage Oa4mpClient Plugin';

  /**
   * The QDL script loaded when neither the admin client nor the deployment
   * names one.
   */
  public const DEFAULT_QDL_CLAIM_SOURCE = 'COmanageRegistry/default/dynamodb_claims.qdl';

  /**
   * Where log lines go, or null.
   */
  private ?Closure $log;

  /**
   * Masks credentials in the lines this class logs.
   */
  private Redactor $redactor;

  /**
   * @param CfgContract $contract The cfg capability contract.
   * @param callable|null $log Called with one message string for each line
   *                           the Registry 4 edition logged.
   * @param string|null $qdlClaimDefault The deployment's default QDL script
   *        path, which Registry 4 read from
   *        COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT; used when the admin
   *        client names none.
   */
  public function __construct(
    private CfgContract $contract,
    ?callable $log = null,
    private ?string $qdlClaimDefault = null
  ) {
    $this->log = $log === null ? null : Closure::fromCallable($log);
    $this->redactor = new Redactor(contract: $contract, log: $log);
  }

  /**
   * Whether a value counts as present: emitted into a cfg, read back from
   * one, and compared.
   *
   * The marshaller, the unmarshaller and the comparator all ask this one
   * function, so "would this have been sent" has one answer. It is PHP's
   * empty(), as Registry 4 applies it, so a string '0' is absent: it is
   * never sent, and a comparator that kept it would report the client out
   * of sync on every check.
   *
   * @param mixed $value The value a row or a cfg carries.
   * @return bool
   */
  public static function countsAsPresent(mixed $value): bool
  {
    return !empty($value);
  }

  /**
   * Build the cfg for a client.
   *
   * @param array<string, mixed> $data Client data, in the Registry 4 shape.
   * @param string|null $namedConfigAddress The address of the Named
   *        configuration's page, written into the cfg metadata when the
   *        client uses one.
   * @return array<string, mixed> cfg object to be sent to the OA4MP server.
   * @throws \LogicException If the client uses a Named configuration and no
   *         address for it was given.
   */
  public function marshallCfgQdl(array $data, ?string $namedConfigAddress = null): array
  {
    // Construct the OA4MP cfg object.
    $cfg = [];

    // Access token configuration. Note that access token configuration is
    // orthogonal to using a named configuration. That is, a client can
    // use a named configuration and still have an access token configuration.
    if (!empty($data['Oa4mpClientAccessToken']) && $data['Oa4mpClientAccessToken']['is_jwt']) {
      $cfg['tokens']['access']['type'] = 'access';
    }

    // The names the contract declares for tokens.identity.qdl.args, in
    // declaration order. Every place this function writes into that block
    // goes through it, so a new arg is emitted only once it is declared, and
    // is withheld and named in the signal until it is.
    $declaredQdlArgs = $this->contract->names(group: 'qdl_args');

    // Field names whose values this pass withheld. Names only, never values.
    // Accumulated across every block below and reported once, at the end.
    $withheldFields = [];

    // Client authorization configuration. A client that uses a named
    // configuration sends none of these args: the named configuration
    // replaces the client's authorization settings and points at its own QDL
    // script, which does not read them. The args are built either way and
    // dropped below.
    //
    // Each of the five args is CONDITIONAL: it is emitted only when the
    // authorization row carries the value it names. The conditionality lives
    // in whether the key is put on the row below, never in an empty value
    // reaching marshallDeclaredRow(), which is why that call passes
    // $dropEmpty false.
    $authzArgs = [];

    if (ClientState::requiresActiveStatus(data: $data)) {
      $authzArgs['require_active_status'] = $data['Oa4mpClientAuthorization']['require_active'];
    }

    if (
      !empty($data['Oa4mpClientAuthorization'])
      && !empty($data['Oa4mpClientAuthorization']['authz_co_group_id'])
    ) {
      // An integer whatever the source: a form posts it as a string, and the
      // QDL compares it with cm_group_id, which DynamoDB returns as a number.
      $authzArgs['authorization_group_id'] = (int)$data['Oa4mpClientAuthorization']['authz_co_group_id'];
    }

    if (
      !empty($data['Oa4mpClientAuthorization'])
      && !empty($data['Oa4mpClientAuthorization']['authz_group_redirect_url'])
    ) {
      $authzArgs['authorization_group_redirect_url'] =
        $data['Oa4mpClientAuthorization']['authz_group_redirect_url'];
    }

    if (
      !empty($data['Oa4mpClientAuthorization'])
      && !empty($data['Oa4mpClientAuthorization']['require_active_redirect_url'])
    ) {
      $authzArgs['require_active_redirect_url'] =
        $data['Oa4mpClientAuthorization']['require_active_redirect_url'];
    }

    if (
      !empty($data['Oa4mpClientAuthorization'])
      && !empty($data['Oa4mpClientAuthorization']['no_record_redirect_url'])
    ) {
      $authzArgs['no_record_redirect_url'] = $data['Oa4mpClientAuthorization']['no_record_redirect_url'];
    }

    $authzArgs = $this->marshallDeclaredRow(
      row: $authzArgs,
      declared: $declaredQdlArgs,
      withheld: $withheldFields,
      synthesised: [],
      dropEmpty: false
    );

    // Only when something survived, and never for a named-configuration
    // client (see above). The named-configuration branch below
    // array_merge_recursive()s this cfg, so creating an empty args array here
    // would put one into every named-configuration cfg.
    if (!empty($authzArgs) && !ClientState::usesNamedConfig(data: $data)) {
      $cfg['tokens']['identity']['qdl']['args'] = $authzArgs;
    }

    // If using a named configuration then just add the cfg for that
    // named configuration and then return the cfg.
    if (!empty($data['Oa4mpClientCoOidcClient']['named_config_id'])) {
      $namedCfg = null;

      foreach ($data['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] as $config) {
        if ($config['id'] == $data['Oa4mpClientCoOidcClient']['named_config_id']) {
          // Cast as Registry 4's non-strict call coerced it: a missing
          // document decodes as '' does, to null.
          $jsonString = (string)$config['config'];
          $namedCfg = json_decode($jsonString, true);
          break;
        }
      }

      // The stored JSON is operator-authored and unvalidated, so the metadata
      // container it may or may not carry is made an array before anything
      // writes into it.
      $namedCfg = $this->normalizeCfgMetadataContainer(cfg: $namedCfg);

      // Add metadata with the address of the named configuration if not
      // already present.
      if ($namedCfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] ?? true) {
        if ($namedConfigAddress === null) {
          throw new LogicException(
            'The client uses a Named configuration, and no address for it was given'
          );
        }

        $namedCfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] = $namedConfigAddress;
      }

      $cfg = array_merge_recursive($cfg, $namedCfg);

      // After the merge, never before it: see stampCfgContractVersion().
      $cfg = $this->stampCfgContractVersion(cfg: $cfg);

      return $cfg;
    }

    // Older admin clients may not have the QDL path set so use the configured
    // default, or a hard-coded default as a last resort.
    if (!empty($data['Oa4mpClientCoAdminClient']['qdl_claim_source'])) {
      $qdlClaimSourcePath = $data['Oa4mpClientCoAdminClient']['qdl_claim_source'];
    } elseif (!empty($this->qdlClaimDefault)) {
      $qdlClaimSourcePath = $this->qdlClaimDefault;
    } else {
      $qdlClaimSourcePath = self::DEFAULT_QDL_CLAIM_SOURCE;
    }

    // Identity token configuration.
    $cfg['tokens']['identity']['type'] = 'identity';

    $qdl = $cfg['tokens']['identity']['qdl'] ?? [];

    // Configure the QDL script file to load.
    $qdl['load'] = $qdlClaimSourcePath;

    // Configure the execution phases.
    $qdl['xmd'] = [];
    $qdl['xmd']['exec_phase'] = [];
    $qdl['xmd']['exec_phase'][] = 'post_auth';
    $qdl['xmd']['exec_phase'][] = 'post_refresh';
    $qdl['xmd']['exec_phase'][] = 'post_token';
    $qdl['xmd']['exec_phase'][] = 'post_user_info';

    // Configure the arguments to pass to the QDL script.
    $qdl['args'] = $qdl['args'] ?? [];

    // The per-client DynamoDB configuration, falling back to the admin
    // client's DefaultDynamoConfig. The comparator resolves it the same way.
    $dynamoConfig = ClientState::resolveDynamoConfig(data: $data);

    // Add the Dynamo module configuration. The five keys are emitted
    // unconditionally, null values included, which is what every stored cfg
    // carries; a sixth key is withheld and named unless the contract
    // declares it.
    $dynamoModuleConfigRow = [];
    $dynamoModuleConfigRow['region'] = $dynamoConfig['aws_region'] ?? null;
    $dynamoModuleConfigRow['access_key_id'] = $dynamoConfig['aws_access_key_id'] ?? null;
    $dynamoModuleConfigRow['secret_access_key'] = $dynamoConfig['aws_secret_access_key'] ?? null;
    $dynamoModuleConfigRow['table_name'] = $dynamoConfig['table_name'] ?? null;
    $dynamoModuleConfigRow['partition_key'] = $dynamoConfig['partition_key'] ?? null;

    $dynamoModuleConfig = $this->marshallDeclaredRow(
      row: $dynamoModuleConfigRow,
      declared: $this->contract->names(group: 'dynamo_module_config_keys'),
      withheld: $withheldFields,
      synthesised: [],
      dropEmpty: false
    );

    // The module configuration and the partition key pattern and claim name,
    // through the same declaration, which orders them after the
    // authorization args and before claim_mappings.
    $qdlArgsRow = [];
    $qdlArgsRow['dynamo_module_config'] = $dynamoModuleConfig;
    $qdlArgsRow['partition_key_template'] = $dynamoConfig['partition_key_template'] ?? null;
    $qdlArgsRow['partition_key_claim_name'] = $dynamoConfig['partition_key_claim_name'] ?? null;

    $qdl['args'] = array_merge(
      $qdl['args'],
      $this->marshallDeclaredRow(
        row: $qdlArgsRow,
        declared: $declaredQdlArgs,
        withheld: $withheldFields,
        synthesised: [],
        dropEmpty: false
      )
    );

    // Add the claims configurations.
    //
    // Every key a claim mapping may carry, every key a constraint may carry,
    // and every value an enumerated one of those may hold, is declared in
    // cfg_contract.json. Nothing else reaches the OA4MP server. The
    // declaration also fixes the ORDER: the seven claim columns with the
    // built claim_constraints list last.
    $declaredClaimFields = $this->contract->names(group: 'claim_mapping_fields');
    $declaredConstraintFields = $this->contract->names(group: 'claim_constraint_fields');

    $claimMappings = [];
    foreach ($data['Oa4mpClientClaim'] as $claim) {
      // Add the claim constraints. Built before the mapping so the mapping can
      // carry them in the position the contract declares them in.
      $claimConstraints = [];

      // The same emptiness test the comparator applies to this association:
      // both sides have to ask one question about whether a claim has
      // constraints at all.
      if (!empty($claim['Oa4mpClientClaimConstraint'])) {
        foreach ($claim['Oa4mpClientClaimConstraint'] as $constraint) {
          $constraintMapping = $this->marshallDeclaredRow(
            row: $constraint,
            declared: $declaredConstraintFields,
            withheld: $withheldFields
          );

          // Only emit a constraint when BOTH fields are populated. A
          // constraint with only a field or only a value is meaningless to
          // the OA4MP server's QDL. A constraint whose constraint_field value
          // the contract does not declare arrives here already missing that
          // field, so this same guard drops it.
          if (
            self::countsAsPresent(value: $constraintMapping['constraint_field'] ?? null)
            && self::countsAsPresent(value: $constraintMapping['constraint_value'] ?? null)
          ) {
            $claimConstraints[] = $constraintMapping;
          }
        }
      }

      $claimMappings[] = $this->marshallDeclaredRow(
        row: $claim,
        declared: $declaredClaimFields,
        withheld: $withheldFields,
        synthesised: [self::CFG_CONSTRAINTS_FIELD => $claimConstraints]
      );
    }

    // The claim mappings through the same declaration as everything else in
    // the args block; the contract declares claim_mappings LAST. $dropEmpty
    // is false because a client with no claims has always sent an empty
    // claim_mappings list, not an absent key.
    $qdl['args'] = array_merge(
      $qdl['args'],
      $this->marshallDeclaredRow(
        row: ['claim_mappings' => $claimMappings],
        declared: $declaredQdlArgs,
        withheld: $withheldFields,
        synthesised: [],
        dropEmpty: false
      )
    );

    // One line per marshalling pass, always, so "nothing was withheld" and
    // "this code never ran" are different observations. The count is of
    // VALUES, and the names appended after it are the distinct FIELDS those
    // values sat under. The values themselves never are.
    $withheldNames = array_values(array_unique($withheldFields));
    sort($withheldNames);

    $this->log(
      message: self::CFG_WITHHELD_SIGNAL . " version "
        . var_export($this->contract->version(), true) . ": "
        . count($withheldFields) . " values withheld from the cfg"
        . (empty($withheldNames) ? "" : ": " . implode(', ', $withheldNames))
    );

    $cfg['tokens']['identity']['qdl'] = $qdl;

    $cfg = $this->stampCfgContractVersion(cfg: $cfg);

    return $cfg;
  }

  /**
   * Build the request body for a client: what is sent to the OA4MP server,
   * after JSON encoding with json_encode()'s default flags.
   *
   * @param array<string, mixed> $adminClient The admin client, in the
   *        Registry 4 shape.
   * @param array<string, mixed> $data Client data, in the Registry 4 shape.
   * @param string $clientIndexAddress The address of the CO's OIDC client
   *        list, written into the comment after the signature.
   * @param string|null $namedConfigAddress The address of the Named
   *        configuration's page, when the client uses one.
   * @return array<string, mixed> Content to be sent to the OA4MP server.
   */
  public function marshallContent(
    array $adminClient,
    array $data,
    string $clientIndexAddress,
    ?string $namedConfigAddress = null
  ): array {
    $content = [];

    // Default is a non-public client.
    $content['token_endpoint_auth_method'] = 'client_secret_basic';

    if (ClientState::isPublicClient(data: $data)) {
      $content['token_endpoint_auth_method'] = 'none';
    }

    $content['grant_types'] = [];
    $content['grant_types'][] = 'authorization_code';
    $content['response_types'] = 'code';
    $content['client_name'] = $data['Oa4mpClientCoOidcClient']['name'];
    $content['client_uri'] = $data['Oa4mpClientCoOidcClient']['home_url'];

    // Client metadata per RFC 7591.
    // https://tools.ietf.org/html/rfc7591#section-2
    if (!empty($data['Oa4mpClientCoCallback'])) {
      $content['redirect_uris'] = [];
      foreach ($data['Oa4mpClientCoCallback'] as $cb) {
        $content['redirect_uris'][] = $cb['url'];
      }
    }

    if (!empty($data['Oa4mpClientRefreshToken']['token_lifetime'])) {
      if (is_numeric($data['Oa4mpClientRefreshToken']['token_lifetime'])) {
        $content['grant_types'][] = 'refresh_token';
        $content['rt_lifetime'] = $data['Oa4mpClientRefreshToken']['token_lifetime'];
      }
    }

    // Determine if the client uses a named configuration.
    $usesNamedConfig = ClientState::usesNamedConfig(data: $data);

    $scopeString = "";

    if ($usesNamedConfig) {
      // If this client uses a named configuration then create the scope
      // string from the named configuration. A scope the plugin does not
      // offer is left out.
      $usedNamedConfigId = $data['Oa4mpClientCoOidcClient']['named_config_id'];

      foreach ($adminClient['Oa4mpClientCoNamedConfig'] as $config) {
        if ($usedNamedConfigId == $config['id']) {
          foreach ($config['Oa4mpClientCoScope'] as $s) {
            if (in_array($s['scope'], ScopeEnum::ALL)) {
              $scopeString = $scopeString . " " . $s['scope'];
            }
          }
          break;
        }
      }
    } else {
      // If this client does not use a named configuration then create
      // the scope string from the scopes associated with this client.
      if (!empty($data['Oa4mpClientCoScope'])) {
        $scopeString = "";

        foreach ($data['Oa4mpClientCoScope'] as $s) {
          $scopeString = $scopeString . " " . $s['scope'];
        }
      }
    }

    if (!empty($scopeString)) {
      $scopeString = trim($scopeString);
      $content['scope'] = $scopeString;
    }

    // Today OA4MP only supports a single contact though we send
    // it in a JSON list.
    if (!empty($data['Oa4mpClientCoEmailAddress'][0])) {
      $content['contacts'] = [];
      $content['contacts'][] = $data['Oa4mpClientCoEmailAddress'][0]['mail'];
    }

    // Include a comment that begins with a constant static string
    // appended with the address of the client list, since a new client's
    // id is not known yet.
    $content['comment'] = self::SIGNATURE . ': ' . $clientIndexAddress;

    // OA4MP rejects custom configurations (cfg) on public clients with
    // "custom configurations not permitted in public clients", so only
    // marshall and attach a cfg for confidential (non-public) clients.
    // Registry 4 also counted an LDAP configuration here; this edition has
    // none.
    if (
      !ClientState::isPublicClient(data: $data)
      && (
        !empty($data['Oa4mpClientCoOidcClient']['named_config_id'])
        || !empty($data['Oa4mpClientAccessToken'])
        || !empty($data['Oa4mpClientAuthorization'])
        || !empty($data['Oa4mpClientClaim'])
      )
    ) {
      $cfg = $this->marshallCfgQdl(data: $data, namedConfigAddress: $namedConfigAddress);
      if (!empty($cfg)) {
        $content['cfg'] = $cfg;
      }
    }

    // Merge any extra keys that were stored from a previous OA4MP server
    // response. These are keys that are not represented in the plugin's
    // data model but need to be sent back to the OA4MP server so that
    // those configuration details are not lost.
    if (!empty($data['Oa4mpClientCoOidcClient']['oa4mp_server_extra'])) {
      $extraKeys = json_decode((string)$data['Oa4mpClientCoOidcClient']['oa4mp_server_extra'], true);
      if (!empty($extraKeys) && is_array($extraKeys)) {
        // Merge extra keys but do not overwrite any keys that were already set.
        foreach ($extraKeys as $key => $value) {
          if (!array_key_exists($key, $content)) {
            $content[$key] = $value;
          }
        }
        // Masked JSON, not print_r, because the redactor cannot see into
        // print_r output.
        $this->log(
          message: "Merged extra keys into content for OA4MP server: "
            . $this->redactor->redactSecretsInLogText(text: (string)json_encode($extraKeys))
        );
      }
    }

    return $content;
  }

  /**
   * Ensure a cfg carries an array-valued metadata.Oa4mpClient container.
   *
   * A Named configuration's stored JSON is operator-authored and
   * unvalidated, so a non-array value at either level, or a document that
   * did not decode to an array at all, is replaced with an empty array.
   *
   * @param mixed $cfg The cfg being marshalled.
   * @return array<string, mixed> The cfg with metadata.Oa4mpClient an array.
   */
  private function normalizeCfgMetadataContainer(mixed $cfg): array
  {
    if (!is_array($cfg)) {
      $cfg = [];
    }

    if (!isset($cfg['metadata']) || !is_array($cfg['metadata'])) {
      $cfg['metadata'] = [];
    }

    if (!isset($cfg['metadata']['Oa4mpClient']) || !is_array($cfg['metadata']['Oa4mpClient'])) {
      $cfg['metadata']['Oa4mpClient'] = [];
    }

    return $cfg;
  }

  /**
   * Record the capability contract version this cfg was marshalled against,
   * at metadata.Oa4mpClient.contract_version.
   *
   * The write is unconditional and overwrites, and it runs AFTER a Named
   * configuration has been merged: array_merge_recursive() merges rather
   * than overwrites, so a stamp written before the merge would leave an
   * array of two values where a scalar version belongs.
   *
   * @param array<string, mixed> $cfg The cfg being marshalled.
   * @return array<string, mixed> The same cfg carrying the contract version.
   */
  private function stampCfgContractVersion(array $cfg): array
  {
    $cfg = $this->normalizeCfgMetadataContainer(cfg: $cfg);

    $cfg['metadata']['Oa4mpClient']['contract_version'] = $this->contract->version();

    return $cfg;
  }

  /**
   * Keys a stored claim or constraint row carries that are never candidates
   * for a cfg: surrogate and foreign keys, timestamps, and the contained
   * constraint association. This list only decides which withheld keys are
   * worth a signal; the contract decides what is emitted.
   *
   * @return array<int, string>
   */
  private function cfgNonEmittedRowKeys(): array
  {
    return [
      'id',
      'client_id',
      'claim_id',
      'created',
      'modified',
      'Oa4mpClientClaimConstraint',
    ];
  }

  /**
   * The values one row contributes to a cfg, in the order the contract
   * declares them.
   *
   * Three rules, all also the comparator's: a field the contract does not
   * declare is never copied; an empty() value is omitted when $dropEmpty
   * (so a string '0' is omitted too); an enumerated field may only carry a
   * value the contract declares.
   *
   * A value withheld by the last rule, and any value under an undeclared key
   * that would otherwise have been emitted, appends its FIELD NAME to
   * $withheld. Never its value: these rows can carry DynamoDB credentials,
   * and the signal built from $withheld is logged.
   *
   * $dropEmpty is false for the QDL args block and the DynamoDB module
   * configuration, whose keys have always been emitted unconditionally, null
   * values included.
   *
   * @param array<string, mixed> $row One row: a claim, a constraint, the QDL
   *        args block, or the DynamoDB module configuration.
   * @param array<int, string> $declared The names the contract declares, in
   *        order.
   * @param array<int, string> $withheld Collects the name of every field
   *        whose value was withheld. Names only, never values.
   * @param array<string, mixed> $synthesised Values the marshaller supplies
   *        rather than reading off the row, keyed by declared field name.
   * @param bool $dropEmpty Whether an empty value is omitted.
   * @return array<string, mixed> The values to emit, in contract order.
   */
  private function marshallDeclaredRow(
    array $row,
    array $declared,
    array &$withheld,
    array $synthesised = [],
    bool $dropEmpty = true
  ): array {
    $marshalled = [];

    foreach ($declared as $field) {
      if (array_key_exists($field, $synthesised)) {
        $value = $synthesised[$field];
      } elseif (array_key_exists($field, $row)) {
        $value = $row[$field];
      } else {
        continue;
      }

      if ($dropEmpty && !self::countsAsPresent(value: $value)) {
        continue;
      }

      if (!$this->contract->permitsValue(field: $field, value: $value)) {
        $withheld[] = $field;
        continue;
      }

      $marshalled[$field] = $value;
    }

    // Whatever the row carries beyond the declaration, tested with the same
    // emptiness rule the copy above uses, so the two sides of "would this
    // have been emitted" ask one question.
    foreach ($row as $key => $value) {
      if (
        in_array($key, $declared, true)
        || in_array($key, $this->cfgNonEmittedRowKeys(), true)
        || ($dropEmpty && !self::countsAsPresent(value: $value))
      ) {
        continue;
      }

      $withheld[] = $key;
    }

    return $marshalled;
  }

  /**
   * Send one line to the log, if there is one.
   *
   * @param string $message The line.
   * @return void
   */
  private function log(string $message): void
  {
    if ($this->log !== null) {
      ($this->log)($message);
    }
  }
}
