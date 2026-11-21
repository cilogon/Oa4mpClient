<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP Client Unmarshaller
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
use Exception;
use LogicException;
use Oa4mpClient\Lib\Enum\ScopeEnum;

/**
 * Reads the OA4MP server's representation of a client back into the plugin's
 * array shape, so the comparator can hold it against the stored client.
 *
 * Ported from oa4mpUnMarshallContent(), oa4mpUnMarshallCfgQdlv3() and
 * decodeServerResponse() in Model/Oa4mpClientOa4mpServer.php of the Registry
 * 4 edition at 7.0.0, with the same array shapes in and out, so the two can
 * be compared line by line.
 *
 * This edition reads only the current cfg format (QDLv3) and a Named
 * configuration. The two older LDAP formats Registry 4 still reads, QDLv2
 * and the deprecated sourceConfig syntax, are recognized and named in the
 * result under OLDER_CFG_FORMAT_KEY but not translated into claims; the
 * comparator turns that into a check that failed.
 */
class Unmarshaller
{
  /**
   * Key set on the unmarshalled client, naming the format, when the server
   * holds the client's cfg in one of the older formats this edition does not
   * read.
   */
  public const OLDER_CFG_FORMAT_KEY = 'older_cfg_format';

  /**
   * The older format whose QDL block is a list, the first entry's args
   * carrying the LDAP server and bind settings.
   */
  public const CFG_FORMAT_QDLV2 = 'QDLv2';

  /**
   * The oldest format, an LDAP source under claims.sourceConfig.
   */
  public const CFG_FORMAT_DEPRECATED = 'deprecated (claims.sourceConfig)';

  /**
   * The keys of a server response that the plugin models, or that are the
   * server's own and are never sent back. Every other key is captured in the
   * extras value and returned to the server on the next update, so settings
   * the plugin does not model are not lost.
   *
   * Deliberately NOT listed: client_secret. An RFC 7592 client-read response
   * carries it, so it lands in the extras value and is echoed back on the
   * next edit. Whether the OA4MP server keeps an existing secret when an
   * update omits it, or treats the omission as a rotation, has not been
   * established, and a silent rotation on every edit is far worse than a
   * secret riding the extras value. The logs that carry it are masked.
   * Revisit only with evidence from a real server about how it treats an
   * omitted secret.
   *
   * registration_client_uri is the server's, built from its own endpoint and
   * the client_id; the plugin has no business asserting it back.
   */
  public const KNOWN_KEYS = [
    'client_id',
    'client_name',
    'client_uri',
    'rt_lifetime',
    'comment',
    'contacts',
    'redirect_uris',
    'scope',
    'token_endpoint_auth_method',
    'cfg',
    'grant_types',
    'response_types',
    // Read-only keys from the OA4MP server that should not be sent back.
    'registration_access_token',
    'client_secret_expires_at',
    'client_id_issued_at',
    'registration_client_uri',
  ];

  /**
   * Where log lines go, or null.
   */
  private ?Closure $log;

  /**
   * Masks credentials in the lines this class logs.
   */
  private Redactor $redactor;

  /**
   * @param CfgContract $contract The cfg capability contract, which names the
   *                              claim mapping and constraint fields read
   *                              back.
   * @param callable|null $log Called with one message string for each line
   *                           the Registry 4 edition logged.
   */
  public function __construct(private CfgContract $contract, ?callable $log = null)
  {
    $this->log = $log === null ? null : Closure::fromCallable($log);
    $this->redactor = new Redactor(contract: $contract, log: $log);
  }

  /**
   * Decode the OA4MP server's representation of a client from a response
   * body, honouring the character encoding the response declares.
   *
   * The server may answer in ISO-8859-1. json_decode() requires UTF-8 and
   * returns null on anything else, so a Latin-1 body carrying a non-ASCII
   * byte, an accented character in a client name or comment, decodes to null
   * unless it is transcoded first. Each arm returns: an unconditional
   * re-decode below the branch once discarded the transcoded value.
   *
   * @param string $body The response body.
   * @param string|null $contentType The response's Content-Type header, or
   *                                  null when it carries none.
   * @return mixed The decoded representation, or null when the body is not
   *               decodable as JSON.
   */
  public static function decodeServerResponse(string $body, ?string $contentType): mixed
  {
    if (str_contains((string)$contentType, 'ISO-8859-1')) {
      return json_decode(mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1'), true);
    }

    return json_decode($body, true);
  }

  /**
   * Read the OA4MP server's representation of a client into the plugin's
   * array shape.
   *
   * @param array<string, mixed> $oa4mpObject The server's response, decoded
   *        to an associative array.
   * @return array<string, mixed> The client, keyed by model name.
   * @throws \LogicException If the representation cannot be read.
   */
  public function unmarshallContent(array $oa4mpObject): array
  {
    $oa4mpClient = [];
    $oa4mpClient['Oa4mpClientCoOidcClient'] = [];
    $oa4mpClient['Oa4mpClientCoAdminClient'] = [];
    $oa4mpClient['Oa4mpClientCoCallback'] = [];
    $oa4mpClient['Oa4mpClientClaim'] = [];
    $oa4mpClient['Oa4mpClientCoScope'] = [];
    $oa4mpClient['Oa4mpClientRefreshToken'] = [];
    $oa4mpClient['Oa4mpClientAccessToken'] = [];

    try {
      // Unmarshall basic client details.
      $oa4mpClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $oa4mpObject['client_id'] ?? null;
      $oa4mpClient['Oa4mpClientCoOidcClient']['name'] = $oa4mpObject['client_name'] ?? null;

      if (array_key_exists('rt_lifetime', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientRefreshToken']['token_lifetime'] = $oa4mpObject['rt_lifetime'];
      }

      if (array_key_exists('comment', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoOidcClient']['comment'] = $oa4mpObject['comment'];
      }

      if (array_key_exists('contacts', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoEmailAddress'] = [];
        foreach ($oa4mpObject['contacts'] as $mail) {
          $oa4mpClient['Oa4mpClientCoEmailAddress'][] = ['mail' => $mail];
        }
      }

      // For now we set proxy_limited to always be false.
      $oa4mpClient['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

      // Unmarshall the callback URIs. The marshaller sends none when the
      // client has no callbacks, so the key may be absent.
      foreach ($oa4mpObject['redirect_uris'] ?? [] as $uri) {
        $oa4mpClient['Oa4mpClientCoCallback'][]['url'] = $uri;
      }

      // Unmarshall the scope details. The server answers with a list or a
      // space-separated string. Every scope is kept as the server spells it,
      // which is what Registry 4's per-scope switch did.
      $scopeObject = $oa4mpObject['scope'] ?? [];
      if (is_string($scopeObject)) {
        $scopeObject = explode(" ", $scopeObject);
      }

      foreach ($scopeObject as $scope) {
        $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = $scope;
      }

      // If and only if the server object has token_endpoint_auth_method value
      // none and the single scope openid then this is a public client.
      $oa4mpClient['Oa4mpClientCoOidcClient']['public_client'] = false;
      if (!empty($oa4mpObject['token_endpoint_auth_method'])) {
        if ($oa4mpObject['token_endpoint_auth_method'] == 'none') {
          if (
            count($oa4mpClient['Oa4mpClientCoScope']) == 1
            && $oa4mpClient['Oa4mpClientCoScope'][0]['scope'] == ScopeEnum::OPENID
          ) {
            $oa4mpClient['Oa4mpClientCoOidcClient']['public_client'] = true;
          }
        }
      }

      // Capture any keys from the OA4MP server response that are not in the
      // known keys list. These are stored and sent back to the OA4MP server
      // during an edit so that configuration details not represented in the
      // plugin's data model are not lost.
      $extraKeys = [];
      foreach ($oa4mpObject as $key => $value) {
        if (!in_array($key, self::KNOWN_KEYS)) {
          $extraKeys[$key] = $value;
        }
      }

      if (!empty($extraKeys)) {
        $oa4mpClient['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = json_encode($extraKeys);
        // Masked JSON, not print_r: the extras value is whatever the server
        // returned outside the known keys, so it is exactly the place an
        // unmodelled credential arrives.
        $this->log(
          message: "Captured extra keys from OA4MP server: "
            . $this->redactor->redactSecretsInLogText(text: (string)json_encode($extraKeys))
        );
      }

      // Unmarshall the cfg object, if any.

      // If no cfg object then we are done.
      if (!isset($oa4mpObject['cfg'])) {
        $this->log(message: "No cfg object found in oa4mpObject");

        return $oa4mpClient;
      }

      $cfg = $oa4mpObject['cfg'];

      // Registry 4 read a cfg that is not an object as no recognized format,
      // which is how it reads a Named configuration.
      if (!is_array($cfg)) {
        $this->log(message: "The cfg from the OA4MP server is not an object, perhaps a NamedConfiguration");

        return $oa4mpClient;
      }

      // The cfg carries the DynamoDB module's access_key_id and
      // secret_access_key, so it goes to the log as masked JSON.
      $this->log(
        message: "Cast JSON cfg from OA4MP server to "
          . $this->redactor->redactSecretsInLogText(text: (string)json_encode($cfg))
      );

      // Try the current cfg format first.
      $configs = $this->unmarshallCfgQdlv3(cfg: $cfg);

      if (!empty($configs)) {
        // The same two AWS credentials, now under the plugin's own aws_*
        // column names.
        $this->log(
          message: "Unmarshalled cfg QDLv3 syntax to "
            . $this->redactor->redactSecretsInLogText(text: (string)json_encode($configs))
        );
        $oa4mpClient = array_merge($oa4mpClient, $configs);

        return $oa4mpClient;
      }

      // Registry 4 tried QDLv2 next and then the deprecated syntax, and
      // translated either into claims. This edition does not read them; it
      // names the format so the comparison can report that it cannot judge
      // the client.
      $olderFormat = $this->olderCfgFormat(cfg: $cfg);

      if ($olderFormat !== null) {
        $this->log(
          message: "The cfg from the OA4MP server is in the older " . $olderFormat
            . " format, which this edition of the plugin does not read"
        );
        $oa4mpClient[self::OLDER_CFG_FORMAT_KEY] = $olderFormat;

        return $oa4mpClient;
      }

      // cfg is set but we are not able to unmarshall it as a defined cfg
      // format. That is ok, however, since it may be a Named Configuration.
      return $oa4mpClient;
    } catch (Exception $e) {
      // JSON and masked, never print_r: the redactor matches a JSON-shaped
      // "key": "value" pair, and this object carries the client_secret, the
      // registration_access_token and the cfg's AWS credentials.
      $this->log(
        message: "oa4mpObject: " . $this->redactor->redactSecretsInLogText(text: (string)json_encode($oa4mpObject))
      );

      throw new LogicException(
        'Error unmarshalling OIDC client object from Oa4mp server: ' . $e->getMessage(),
        0,
        $e
      );
    }
  }

  /**
   * Read a cfg in the current format (QDLv3) into the plugin's array shape.
   *
   * @param array<string, mixed> $cfg The cfg from the OA4MP server.
   * @return array<string, mixed> The sections the cfg carries, keyed by model
   *         name; empty when it carries none of them.
   */
  public function unmarshallCfgQdlv3(array $cfg): array
  {
    $oa4mpClient = [];

    // Unmarshall access token configuration.
    if (!empty($cfg['tokens']['access']['type'])) {
      if ($cfg['tokens']['access']['type'] == 'access') {
        $oa4mpClient['Oa4mpClientAccessToken'] = [];
        $oa4mpClient['Oa4mpClientAccessToken']['is_jwt'] = true;
      }
    }

    // Unmarshall QDL arguments.
    if (!empty($cfg['tokens']['identity']['qdl']['args'])) {
      $qdlArgs = $cfg['tokens']['identity']['qdl']['args'];

      $authz = [];

      // Unmarshall client authorization configuration.
      if (!empty($qdlArgs['require_active_status'])) {
        $authz['require_active'] = $qdlArgs['require_active_status'];
      }
      if (!empty($qdlArgs['authorization_group_id'])) {
        $authz['authz_co_group_id'] = $qdlArgs['authorization_group_id'];
      }
      if (!empty($qdlArgs['authorization_group_redirect_url'])) {
        $authz['authz_group_redirect_url'] = $qdlArgs['authorization_group_redirect_url'];
      }
      if (!empty($qdlArgs['require_active_redirect_url'])) {
        $authz['require_active_redirect_url'] = $qdlArgs['require_active_redirect_url'];
      }
      if (!empty($qdlArgs['no_record_redirect_url'])) {
        $authz['no_record_redirect_url'] = $qdlArgs['no_record_redirect_url'];
      }

      if (!empty($authz)) {
        $oa4mpClient['Oa4mpClientAuthorization'] = $authz;
      }

      // Unmarshall DynamoDB configuration. Read null-safe: a Named
      // configuration's args carry none of these keys.
      $oa4mpClient['Oa4mpClientDynamoConfig']['partition_key_template'] = $qdlArgs['partition_key_template'] ?? null;
      $oa4mpClient['Oa4mpClientDynamoConfig']['partition_key_claim_name'] =
        $qdlArgs['partition_key_claim_name'] ?? null;

      // No sort_key or sort_key_template read-back. The contract's qdl_args
      // group declares neither name, so no cfg the plugin writes carries
      // either; reading them back could only ever produce null, and comparing
      // that null reported an operator-populated sort key permanently out of
      // sync. See the matching note in Comparator::isClientDataSynchronized().

      if (!empty($qdlArgs['dynamo_module_config'])) {
        $dynamoModuleConfig = $qdlArgs['dynamo_module_config'];

        $oa4mpClient['Oa4mpClientDynamoConfig']['aws_region'] = $dynamoModuleConfig['region'] ?? null;
        $oa4mpClient['Oa4mpClientDynamoConfig']['aws_access_key_id'] = $dynamoModuleConfig['access_key_id'] ?? null;
        $oa4mpClient['Oa4mpClientDynamoConfig']['aws_secret_access_key'] =
          $dynamoModuleConfig['secret_access_key'] ?? null;
        $oa4mpClient['Oa4mpClientDynamoConfig']['table_name'] = $dynamoModuleConfig['table_name'] ?? null;
        $oa4mpClient['Oa4mpClientDynamoConfig']['partition_key'] = $dynamoModuleConfig['partition_key'] ?? null;
      }

      // Unmarshall claim mappings.
      if (!empty($qdlArgs['claim_mappings'])) {
        $qdlClaimMappings = $qdlArgs['claim_mappings'];
        $claimMappings = [];

        foreach ($qdlClaimMappings as $qdlClaimMapping) {
          // Read back exactly the fields the contract declares a mapping may
          // carry, from the same group the marshaller emits from. Two
          // hand-written lists were free to drift apart, and did: a field the
          // marshaller emitted and this loop did not read was dropped on the
          // way back and never compared.
          $claimMapping = [];

          foreach ($this->contract->names(group: 'claim_mapping_fields') as $field) {
            if ($field === Marshaller::CFG_CONSTRAINTS_FIELD) {
              // Republished below under the association key the comparator
              // reads, not under its cfg name.
              continue;
            }

            // The marshaller's rule: a field it omitted for being empty is
            // absent here, and one it emitted is present.
            if (!Marshaller::countsAsPresent(value: $qdlClaimMapping[$field] ?? null)) {
              continue;
            }

            $claimMapping[$field] = $qdlClaimMapping[$field];
          }

          if (!empty($qdlClaimMapping[Marshaller::CFG_CONSTRAINTS_FIELD])) {
            $qdlClaimConstraints = $qdlClaimMapping[Marshaller::CFG_CONSTRAINTS_FIELD];
            $claimConstraints = [];

            foreach ($qdlClaimConstraints as $qdlClaimConstraint) {
              $claimConstraint = [];

              foreach ($this->contract->names(group: 'claim_constraint_fields') as $field) {
                if (!Marshaller::countsAsPresent(value: $qdlClaimConstraint[$field] ?? null)) {
                  continue;
                }

                $claimConstraint[$field] = $qdlClaimConstraint[$field];
              }

              $claimConstraints[] = $claimConstraint;
            }
            $claimMapping['Oa4mpClientClaimConstraint'] = $claimConstraints;
          }
          $claimMappings[] = $claimMapping;
        }

        $oa4mpClient['Oa4mpClientClaim'] = $claimMappings;
      }
    }

    return $oa4mpClient;
  }

  /**
   * The older format a cfg the current format does not describe is in, if it
   * is in one.
   *
   * The tests are the ones Registry 4's readers for those formats used to
   * decide they had found something: a QDL block that is a list whose first
   * entry carries args (QDLv2), or an LDAP source under claims.sourceConfig
   * (the deprecated syntax). Anything else may be a Named configuration.
   *
   * @param array<string, mixed> $cfg The cfg from the OA4MP server.
   * @return string|null The format's name, or null.
   */
  public function olderCfgFormat(array $cfg): ?string
  {
    $qdl = $cfg['tokens']['identity']['qdl'] ?? null;

    if (is_array($qdl) && isset($qdl[0]) && is_array($qdl[0]) && !empty($qdl[0]['args'])) {
      return self::CFG_FORMAT_QDLV2;
    }

    $sourceConfigs = $cfg['claims']['sourceConfig'] ?? null;

    if (is_array($sourceConfigs)) {
      foreach ($sourceConfigs as $sourceConfig) {
        if (is_array($sourceConfig) && isset($sourceConfig['ldap'])) {
          return self::CFG_FORMAT_DEPRECATED;
        }
      }
    }

    return null;
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
