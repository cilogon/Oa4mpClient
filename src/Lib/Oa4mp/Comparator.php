<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP Client Comparator
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
use Oa4mpClient\Lib\Enum\ScopeEnum;
use Throwable;
use UnexpectedValueException;

/**
 * Sync verification: holds the stored client against the OA4MP server's copy
 * of it, to detect a client changed outside the Registry.
 *
 * Ported from normalizeClaimForComparison(), isClientDataSynchronized() and
 * compareToServerObject() in Model/Oa4mpClientOa4mpServer.php of the
 * Registry 4 edition at 7.0.0, with the same array shapes in and out, so the
 * two can be compared line by line.
 *
 * The stored side must be a client as loaded from the database: the
 * comparator decides whether the client has an authorization by the row's
 * id, so unsaved create data reads as having none.
 *
 * Every rule here is the marshaller's, because the marshaller is what went
 * over the wire: what it would not send is not compared, and what it would
 * is. A difference between the two is a client that reports out of sync on
 * every check and that no edit can repair.
 */
class Comparator
{
  /**
   * Where log lines go, or null.
   */
  private ?Closure $log;

  /**
   * Masks credentials in the lines this class logs.
   */
  private Redactor $redactor;

  /**
   * Reads the server's copy into the plugin's array shape.
   */
  private Unmarshaller $unmarshaller;

  /**
   * The first difference the last comparison found, or null.
   */
  private ?string $difference = null;

  /**
   * @param CfgContract $contract The cfg capability contract, which names the
   *                              claim fields compared.
   * @param callable|null $log Called with one message string for each line
   *                           the Registry 4 edition logged.
   * @param Unmarshaller|null $unmarshaller Reads the server's copy; one with
   *        the same contract and log when null.
   */
  public function __construct(
    private CfgContract $contract,
    ?callable $log = null,
    ?Unmarshaller $unmarshaller = null
  ) {
    $this->log = $log === null ? null : Closure::fromCallable($log);
    $this->redactor = new Redactor(contract: $contract, log: $log);
    $this->unmarshaller = $unmarshaller ?? new Unmarshaller(contract: $contract, log: $log);
  }

  /**
   * Compare a client against the OA4MP server's representation of it.
   *
   * The 'error' key is what the catch exists to report. An exception here
   * means the comparison did not happen: the cfg capability contract is
   * unreadable or malformed, the response did not decode, the cfg is in a
   * format this edition does not read, an error fired mid-parse. None of
   * that is evidence the client was changed outside the Registry, and a
   * caller that reads a failed comparison as a mismatch tells the user the
   * client was tampered with. Verdict::fromComparison() keeps them apart.
   *
   * @param array<string, mixed> $curClient The stored client, as loaded from
   *        the database, in the Registry 4 shape.
   * @param mixed $oa4mpObject The server's representation, as
   *        Unmarshaller::decodeServerResponse() returns it.
   * @return array<string, mixed> With keys 'synchronized' (bool),
   *         'oa4mp_server_extra' (the extras value, or null), 'error' (bool:
   *         the comparison did not run) and 'reason' (for the log: the first
   *         difference found, or what stopped the check; null when in sync).
   */
  public function compareToServerObject(array $curClient, mixed $oa4mpObject): array
  {
    $comparison = [
      'synchronized' => false,
      'oa4mp_server_extra' => null,
      'error' => false,
      'reason' => null,
    ];

    try {
      if (!is_array($oa4mpObject)) {
        throw new UnexpectedValueException(
          'The OA4MP server response did not decode to a client representation'
        );
      }

      // Unmarshall the Oa4mp server representation of the client and compare
      // it to the current client to detect if the client has been changed
      // outside of this plugin.
      $oa4mpServerData = $this->unmarshaller->unmarshallContent(oa4mpObject: $oa4mpObject);

      // A cfg in an older format is not translated, so comparing would set
      // the client's claims against nothing. A client that uses a Named
      // configuration is the exception: its cfg is the configuration's own,
      // which the comparison never reads, as in Registry 4.
      $olderFormat = $oa4mpServerData[Unmarshaller::OLDER_CFG_FORMAT_KEY] ?? null;

      if ($olderFormat !== null && !ClientState::usesNamedConfig(data: $curClient)) {
        throw new UnexpectedValueException(
          'The OA4MP server holds this client\'s cfg in the older ' . $olderFormat
            . ' format, which this edition of the plugin does not read'
        );
      }

      $comparison['synchronized'] = $this->isClientDataSynchronized(
        curData: $curClient,
        oa4mpServerData: $oa4mpServerData
      );
      $comparison['reason'] = $comparison['synchronized'] ? null : $this->difference;

      // Capture any extra keys from the OA4MP server response.
      if (!empty($oa4mpServerData['Oa4mpClientCoOidcClient']['oa4mp_server_extra'])) {
        $comparison['oa4mp_server_extra'] =
          $oa4mpServerData['Oa4mpClientCoOidcClient']['oa4mp_server_extra'];
      }
    } catch (Throwable $e) {
      // Name the exception, where it was raised, and what it said. A catch
      // that logs only the message hands the next operator an interpretation
      // with no evidence behind it.
      $comparison['synchronized'] = false;
      $comparison['oa4mp_server_extra'] = null;
      $comparison['error'] = true;
      $comparison['reason'] = get_class($e) . ': ' . $e->getMessage();
      $this->log(
        message: "Caught exception during unmarshall of Oa4mp server object: "
          . get_class($e) . " at " . $e->getFile() . ":" . $e->getLine()
          . " - " . $e->getMessage()
      );
    }

    return $comparison;
  }

  /**
   * Determine if our representation of the client and the Oa4mp server
   * representation of the client is synchronized, in order to detect if the
   * client has been changed outside of this plugin.
   *
   * @param array<string, mixed> $curData The current client data, as loaded
   *        from the database.
   * @param array<string, mixed> $oa4mpServerData The Oa4mp server
   *        representation of the client, as Unmarshaller::unmarshallContent()
   *        returns it.
   * @return bool True if the client data is synchronized, false otherwise.
   */
  public function isClientDataSynchronized(array $curData, array $oa4mpServerData): bool
  {
    $this->difference = null;

    // Compare basic client details.
    $curClient = $curData['Oa4mpClientCoOidcClient'];
    $oa4mpClient = $oa4mpServerData['Oa4mpClientCoOidcClient'];

    if (($curClient['oa4mp_identifier'] ?? null) !== ($oa4mpClient['oa4mp_identifier'] ?? null)) {
      $this->noteDifference(
        message: "Oa4mpClientCoOidcClient oa4mp_identifier is out of sync"
          . " (plugin=" . var_export($curClient['oa4mp_identifier'] ?? null, true)
          . ", oa4mp=" . var_export($oa4mpClient['oa4mp_identifier'] ?? null, true) . ")"
      );

      return false;
    }

    if (($curClient['name'] ?? null) !== ($oa4mpClient['name'] ?? null)) {
      $this->noteDifference(
        message: "Oa4mpClientCoOidcClient name is out of sync"
          . " (plugin=" . var_export($curClient['name'] ?? null, true)
          . ", oa4mp=" . var_export($oa4mpClient['name'] ?? null, true) . ")"
      );

      return false;
    }

    if (($curClient['proxy_limited'] ?? null) != ($oa4mpClient['proxy_limited'] ?? null)) {
      $this->noteDifference(
        message: "Oa4mpClientCoOidcClient proxy_limited is out of sync"
          . " (plugin=" . var_export($curClient['proxy_limited'] ?? null, true)
          . ", oa4mp=" . var_export($oa4mpClient['proxy_limited'] ?? null, true) . ")"
      );

      return false;
    }

    if (($curClient['public_client'] ?? null) != ($oa4mpClient['public_client'] ?? null)) {
      $this->noteDifference(
        message: "Oa4mpClientCoOidcClient public_client is out of sync"
          . " (plugin=" . var_export($curClient['public_client'] ?? null, true)
          . ", oa4mp=" . var_export($oa4mpClient['public_client'] ?? null, true) . ")"
      );

      return false;
    }

    // Compare refresh token lifetime.
    //
    // The state where the OA4MP server has a refresh token lifetime of
    // exactly zero and our representation does not have a value is
    // considered to be synchronized. Both sides are optional: the
    // unmarshaller only sets token_lifetime when the server returned
    // rt_lifetime.
    $curRefreshToken = $curData['Oa4mpClientRefreshToken'] ?? [];
    $oa4mpRefreshToken = $oa4mpServerData['Oa4mpClientRefreshToken'] ?? [];

    $curTokenLifetime = $curRefreshToken['token_lifetime'] ?? null;
    $oa4mpTokenLifetime = $oa4mpRefreshToken['token_lifetime'] ?? null;

    if ($curTokenLifetime != $oa4mpTokenLifetime) {
      if (!(is_null($curTokenLifetime) && ($oa4mpTokenLifetime === 0))) {
        $this->noteDifference(
          message: "Oa4mpClientRefreshToken token_lifetime is out of sync"
            . " (plugin=" . var_export($curTokenLifetime, true)
            . ", oa4mp=" . var_export($oa4mpTokenLifetime, true) . ")"
        );

        return false;
      }
    }

    // Compare email addresses.
    $curEmails = [];
    $oa4mpEmails = [];

    foreach ($curData['Oa4mpClientCoEmailAddress'] ?? [] as $e) {
      $curEmails[] = $e['mail'];
    }

    foreach ($oa4mpServerData['Oa4mpClientCoEmailAddress'] ?? [] as $e) {
      $oa4mpEmails[] = $e['mail'];
    }

    sort($curEmails);
    sort($oa4mpEmails);

    if ($curEmails != $oa4mpEmails) {
      $this->noteDifference(
        message: "Oa4mpClientCoEmailAddress emails are out of sync"
          . " (plugin=" . count($curEmails)
          . ", oa4mp=" . count($oa4mpEmails) . ")"
      );
      $this->log(message: "curEmails: " . print_r($curEmails, true));
      $this->log(message: "oa4mpEmails: " . print_r($oa4mpEmails, true));

      return false;
    }

    // Compare callbacks.
    $curCallbacks = [];
    $oa4mpCallbacks = [];

    foreach ($curData['Oa4mpClientCoCallback'] ?? [] as $cb) {
      $curCallbacks[] = $cb['url'];
    }

    foreach ($oa4mpServerData['Oa4mpClientCoCallback'] ?? [] as $cb) {
      $oa4mpCallbacks[] = $cb['url'];
    }

    sort($curCallbacks);
    sort($oa4mpCallbacks);

    if ($curCallbacks != $oa4mpCallbacks) {
      $this->noteDifference(
        message: "Oa4mpClientCoCallback callbacks are out of sync"
          . " (plugin=" . count($curCallbacks)
          . ", oa4mp=" . count($oa4mpCallbacks) . ")"
      );
      $this->log(message: "curCallbacks: " . print_r($curCallbacks, true));
      $this->log(message: "oa4mpCallbacks: " . print_r($oa4mpCallbacks, true));

      return false;
    }

    // Does this client use a named configuration?
    $usesNamedConfig = ClientState::usesNamedConfig(data: $curData);

    // Compare scopes.
    $curScopes = [];
    $oa4mpScopes = [];

    if ($usesNamedConfig) {
      // Compare the scopes sent by the OA4MP server to the scopes specified
      // as part of the named configuration, leaving out any scope the plugin
      // does not offer, as the marshaller does.
      $usedNamedConfigId = $curData['Oa4mpClientCoOidcClient']['named_config_id'];
      foreach ($curData['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] as $config) {
        if ($config['id'] == $usedNamedConfigId) {
          foreach ($config['Oa4mpClientCoScope'] as $s) {
            if (in_array($s['scope'], ScopeEnum::ALL)) {
              $curScopes[] = $s['scope'];
            }
          }
          break;
        }
      }
    } else {
      // Compare the scopes sent by the OA4MP server to the scopes linked to
      // this OIDC client instance.
      foreach ($curData['Oa4mpClientCoScope'] ?? [] as $s) {
        $curScopes[] = $s['scope'];
      }
    }

    foreach ($oa4mpServerData['Oa4mpClientCoScope'] ?? [] as $s) {
      $oa4mpScopes[] = $s['scope'];
    }

    sort($curScopes);
    sort($oa4mpScopes);

    if ($curScopes != $oa4mpScopes) {
      $this->noteDifference(
        message: "Oa4mpClientCoScope scopes are out of sync"
          . " (plugin=" . count($curScopes)
          . ", oa4mp=" . count($oa4mpScopes) . ")"
      );
      $this->log(message: "curScopes: " . print_r($curScopes, true));
      $this->log(message: "oa4mpScopes: " . print_r($oa4mpScopes, true));

      return false;
    }

    // Compare the comment.
    if (empty($oa4mpClient['comment'])) {
      $this->noteDifference(
        message: "The OA4MP server representation of the client does not include a comment"
      );

      return false;
    }

    if (!str_starts_with((string)$oa4mpClient['comment'], Marshaller::SIGNATURE)) {
      $this->noteDifference(
        message: "Oa4mpClientCoOidcClient comment does not start with the plugin's signature"
      );
      $this->log(message: "The OA4MP server respresentation of the client has comment");
      $this->log(message: (string)$oa4mpClient['comment']);
      $this->log(message: "but the comment should start with");
      $this->log(message: Marshaller::SIGNATURE);

      return false;
    }

    // If this client uses a named configuration then return true here, else
    // continue with more detailed comparison.
    if ($usesNamedConfig) {
      return true;
    }

    // A public client is sent no cfg, because OA4MP rejects a custom
    // configuration on a public client, and the access token, authorization
    // and claim settings travel only in the cfg. Whatever the plugin holds
    // for them never reaches the server, so leave those sections out of the
    // comparison below rather than report drift no edit could repair.
    // Everything else is still compared. (Registry 4 at 7.0.0 leaves out the
    // first two only, so a public client that kept a claim row reads there as
    // out of sync on every check.) The DynamoDB settings need no entry: with
    // no cfg the server side has none, and that comparison is skipped.
    if (ClientState::isPublicClient(data: $curData)) {
      unset($curData['Oa4mpClientAccessToken'], $curData['Oa4mpClientAuthorization'], $curData['Oa4mpClientClaim']);
      unset(
        $oa4mpServerData['Oa4mpClientAccessToken'],
        $oa4mpServerData['Oa4mpClientAuthorization'],
        $oa4mpServerData['Oa4mpClientClaim']
      );
    }

    // Compare access token configuration. Optional on both sides: an
    // omitted section is "no access token configuration", which is what the
    // tests below treat an empty array as.
    $curAccessToken = $curData['Oa4mpClientAccessToken'] ?? [];
    $oa4mpAccessToken = $oa4mpServerData['Oa4mpClientAccessToken'] ?? [];

    if ($curAccessToken && ($curAccessToken['is_jwt'] ?? null) && !$oa4mpAccessToken) {
      $this->noteDifference(
        message: "Oa4mpClientAccessToken plugin has access token configuration but Oa4mp server does not"
      );
      $this->log(message: "curAccessToken: " . print_r($curAccessToken, true));

      return false;
    }

    if (!$curAccessToken && $oa4mpAccessToken) {
      $this->noteDifference(
        message: "Oa4mpClientAccessToken Oa4mp server has access token configuration but plugin does not"
      );
      $this->log(message: "oa4mpAccessToken: " . print_r($oa4mpAccessToken, true));

      return false;
    }

    if ($curAccessToken && $oa4mpAccessToken) {
      if (($curAccessToken['is_jwt'] ?? null) != ($oa4mpAccessToken['is_jwt'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientAccessToken is_jwt is out of sync"
            . " (plugin=" . var_export($curAccessToken['is_jwt'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpAccessToken['is_jwt'] ?? null, true) . ")"
        );

        return false;
      }
    }

    // Compare client authorization configuration. Whether the plugin has an
    // authorization is decided by the row's id: a client with no row is read
    // as an all-null array, not an empty one.
    $curAuthz = $curData['Oa4mpClientAuthorization'] ?? [];
    $oa4mpAuthz = $oa4mpServerData['Oa4mpClientAuthorization'] ?? [];

    // Any gate setting the marshaller would send counts, not only
    // require_active: Registry 4 at 7.0.0 checked require_active alone, so a
    // group-only gate removed on the server read as in sync.
    $curAuthzSent = ClientState::requiresActiveStatus(data: $curData)
      || !empty($curAuthz['authz_co_group_id'])
      || !empty($curAuthz['authz_group_redirect_url'])
      || !empty($curAuthz['require_active_redirect_url'])
      || !empty($curAuthz['no_record_redirect_url']);

    if (
      !empty($curAuthz['id'])
      && empty($oa4mpAuthz)
      && $curAuthzSent
    ) {
      $this->noteDifference(
        message: "Oa4mpClientAuthorization plugin has authorization configuration but Oa4mp server does not"
      );
      $this->log(message: "curAuthorization: " . print_r($curAuthz, true));

      return false;
    }

    if (empty($curAuthz['id']) && !empty($oa4mpAuthz)) {
      $this->noteDifference(
        message: "Oa4mpClientAuthorization Oa4mp server has authorization configuration but plugin does not"
      );
      $this->log(message: "oa4mpAuthorization: " . print_r($oa4mpAuthz, true));

      return false;
    }

    if (!empty($curAuthz['id']) && !empty($oa4mpAuthz)) {
      // Loose comparison throughout, on purpose: the group id is a number in
      // the cfg and may be text on the plugin side.
      if (($curAuthz['require_active'] ?? null) != ($oa4mpAuthz['require_active'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientAuthorization require_active is out of sync"
            . " (plugin=" . var_export($curAuthz['require_active'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpAuthz['require_active'] ?? null, true) . ")"
        );

        return false;
      }

      if (($curAuthz['authz_co_group_id'] ?? null) != ($oa4mpAuthz['authz_co_group_id'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientAuthorization authz_co_group_id is out of sync"
            . " (plugin=" . var_export($curAuthz['authz_co_group_id'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpAuthz['authz_co_group_id'] ?? null, true) . ")"
        );

        return false;
      }

      if (($curAuthz['authz_group_redirect_url'] ?? null) != ($oa4mpAuthz['authz_group_redirect_url'] ?? null)) {
        $this->noteDifference(message: "Oa4mpClientAuthorization authz_group_redirect_url is out of sync");
        $this->log(message: "  plugin: " . var_export($curAuthz['authz_group_redirect_url'] ?? null, true));
        $this->log(message: "  oa4mp:  " . var_export($oa4mpAuthz['authz_group_redirect_url'] ?? null, true));

        return false;
      }

      if (
        ($curAuthz['require_active_redirect_url'] ?? null)
        != ($oa4mpAuthz['require_active_redirect_url'] ?? null)
      ) {
        $this->noteDifference(message: "Oa4mpClientAuthorization require_active_redirect_url is out of sync");
        $this->log(message: "  plugin: " . var_export($curAuthz['require_active_redirect_url'] ?? null, true));
        $this->log(message: "  oa4mp:  " . var_export($oa4mpAuthz['require_active_redirect_url'] ?? null, true));

        return false;
      }

      if (($curAuthz['no_record_redirect_url'] ?? null) != ($oa4mpAuthz['no_record_redirect_url'] ?? null)) {
        $this->noteDifference(message: "Oa4mpClientAuthorization no_record_redirect_url is out of sync");
        $this->log(message: "  plugin: " . var_export($curAuthz['no_record_redirect_url'] ?? null, true));
        $this->log(message: "  oa4mp:  " . var_export($oa4mpAuthz['no_record_redirect_url'] ?? null, true));

        return false;
      }
    }

    // Compare DynamoDB configurations. Resolve the plugin-side config the
    // same way marshalling does, so a client without a per-client row is
    // compared against the DefaultDynamoConfig values that were actually sent
    // to the server, rather than the all-null row a missing one reads as.
    $curDynamo = ClientState::resolveDynamoConfig(data: $curData);
    $oa4mpDynamo = $oa4mpServerData['Oa4mpClientDynamoConfig'] ?? [];

    if (!empty($curDynamo) && !empty($oa4mpDynamo)) {
      if (($curDynamo['aws_region'] ?? null) != ($oa4mpDynamo['aws_region'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientDynamoConfig aws_region is out of sync"
            . " (plugin=" . var_export($curDynamo['aws_region'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpDynamo['aws_region'] ?? null, true) . ")"
        );

        return false;
      }

      if (($curDynamo['aws_access_key_id'] ?? null) != ($oa4mpDynamo['aws_access_key_id'] ?? null)) {
        // aws_access_key_id is a secret credential. Both sides go through
        // the redactor so the masking list stays the single enforcement
        // point.
        $curMasked = $this->redactor->redactSecrets(
          row: ['aws_access_key_id' => $curDynamo['aws_access_key_id'] ?? null]
        );
        $oa4mpMasked = $this->redactor->redactSecrets(
          row: ['aws_access_key_id' => $oa4mpDynamo['aws_access_key_id'] ?? null]
        );
        $this->noteDifference(
          message: "Oa4mpClientDynamoConfig aws_access_key_id is out of sync"
            . " (plugin=" . var_export($curMasked['aws_access_key_id'], true)
            . ", oa4mp=" . var_export($oa4mpMasked['aws_access_key_id'], true) . ")"
        );

        return false;
      }

      if (($curDynamo['table_name'] ?? null) != ($oa4mpDynamo['table_name'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientDynamoConfig table_name is out of sync"
            . " (plugin=" . var_export($curDynamo['table_name'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpDynamo['table_name'] ?? null, true) . ")"
        );

        return false;
      }

      if (($curDynamo['partition_key'] ?? null) != ($oa4mpDynamo['partition_key'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientDynamoConfig partition_key is out of sync"
            . " (plugin=" . var_export($curDynamo['partition_key'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpDynamo['partition_key'] ?? null, true) . ")"
        );

        return false;
      }

      if (($curDynamo['partition_key_template'] ?? null) != ($oa4mpDynamo['partition_key_template'] ?? null)) {
        $this->noteDifference(message: "Oa4mpClientDynamoConfig partition_key_template is out of sync");
        $this->log(message: "  plugin: " . var_export($curDynamo['partition_key_template'] ?? null, true));
        $this->log(message: "  oa4mp:  " . var_export($oa4mpDynamo['partition_key_template'] ?? null, true));

        return false;
      }

      if (($curDynamo['partition_key_claim_name'] ?? null) != ($oa4mpDynamo['partition_key_claim_name'] ?? null)) {
        $this->noteDifference(
          message: "Oa4mpClientDynamoConfig partition_key_claim_name is out of sync"
            . " (plugin=" . var_export($curDynamo['partition_key_claim_name'] ?? null, true)
            . ", oa4mp=" . var_export($oa4mpDynamo['partition_key_claim_name'] ?? null, true) . ")"
        );

        return false;
      }

      // sort_key and sort_key_template are deliberately NOT compared, and
      // neither is the secret access key.
      //
      // The contract's qdl_args group omits both sort key names, so the
      // plugin never writes either into a cfg. They are nonetheless editable
      // columns, copied from the admin client's default into a new client.
      // Comparing them put the plugin's populated value up against the
      // server's permanent null: a client that reported out of sync on every
      // check and that no edit could repair, because the repair the
      // comparison implies, sending the value, is one the contract says is
      // never sent. A name the contract does not declare is compared by
      // nothing.
    }

    // Compare claim mappings.
    $curClaims = $curData['Oa4mpClientClaim'] ?? [];
    $oa4mpClaims = $oa4mpServerData['Oa4mpClientClaim'] ?? [];

    // If one side has claims and the other doesn't, they are out of sync.
    if (empty($curClaims) && !empty($oa4mpClaims)) {
      $this->noteDifference(message: "Oa4mpClientClaim: OA4MP server has claims but plugin does not");
      $this->log(message: "oa4mpClaims: " . print_r($oa4mpClaims, true));

      return false;
    }

    if (!empty($curClaims) && empty($oa4mpClaims)) {
      $this->noteDifference(message: "Oa4mpClientClaim: Plugin has claims but OA4MP server does not");
      $this->log(message: "curClaims: " . print_r($curClaims, true));

      return false;
    }

    // If both sides have claims, compare them.
    if (!empty($curClaims) && !empty($oa4mpClaims)) {
      // Compare the number of claims.
      if (count($curClaims) != count($oa4mpClaims)) {
        $this->noteDifference(
          message: "Oa4mpClientClaim: Number of claims is out of sync"
            . " (plugin=" . count($curClaims)
            . ", oa4mp=" . count($oa4mpClaims) . ")"
        );
        $this->log(message: "curClaims: " . print_r($curClaims, true));
        $this->log(message: "oa4mpClaims: " . print_r($oa4mpClaims, true));

        return false;
      }

      // Identifier for the dropped-constraint log. A half-populated
      // constraint is filtered out of the comparison, so the only trace it
      // leaves is that log line; naming the client is what makes it useful.
      $clientIdentifier = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'] ?? null;

      // The same helper on both sides, deliberately: the two sides have to
      // apply the marshaller's rules identically or the comparison is between
      // different questions.
      $curClaimsNormalized = [];
      foreach ($curClaims as $claim) {
        $curClaimsNormalized[] = $this->normalizeClaimForComparison(
          claim: $claim,
          clientIdentifier: $clientIdentifier,
          side: 'plugin'
        );
      }

      $oa4mpClaimsNormalized = [];
      foreach ($oa4mpClaims as $claim) {
        $oa4mpClaimsNormalized[] = $this->normalizeClaimForComparison(
          claim: $claim,
          clientIdentifier: $clientIdentifier,
          side: 'oa4mp'
        );
      }

      // Sort both arrays by claim_name for consistent comparison.
      $byClaimName = function (array $a, array $b): int {
        return strcmp((string)($a['claim_name'] ?? ''), (string)($b['claim_name'] ?? ''));
      };
      usort($curClaimsNormalized, $byClaimName);
      usort($oa4mpClaimsNormalized, $byClaimName);

      // Compare the normalized claim arrays.
      if ($curClaimsNormalized != $oa4mpClaimsNormalized) {
        $this->noteDifference(message: "Oa4mpClientClaim: Claims are out of sync");
        $this->log(message: "curClaimsNormalized: " . print_r($curClaimsNormalized, true));
        $this->log(message: "oa4mpClaimsNormalized: " . print_r($oa4mpClaimsNormalized, true));

        return false;
      }
    }

    return true;
  }

  /**
   * The first difference the last isClientDataSynchronized() call found, or
   * null when it found none.
   *
   * @return string|null
   */
  public function lastDifference(): ?string
  {
    return $this->difference;
  }

  /**
   * One claim, reduced to what the marshaller would send for it, so the
   * plugin's claim rows and the server's can be compared.
   *
   * Both sides of the comparison are normalized by this one function, and
   * the rules are the marshaller's, because the marshaller is what went over
   * the wire:
   *
   *  - A value that does not count as present (Marshaller::countsAsPresent())
   *    is no value. A string '0' is never sent, so keeping it here compared
   *    it against the server's absent value, and the client reported out of
   *    sync on every check.
   *  - A value the contract does not permit is no value: the marshaller
   *    withholds it.
   *  - A constraint counts only when BOTH of its fields are present, and its
   *    constraint_field carries a value the contract permits.
   *
   * @param array<string, mixed> $claim One claim row, optionally carrying its
   *        Oa4mpClientClaimConstraint rows.
   * @param mixed $clientIdentifier oa4mp_identifier of the client being
   *                                compared, named in the dropped-constraint
   *                                log.
   * @param string $side Which representation this claim came from, 'plugin'
   *                     or 'oa4mp', also for that log.
   * @return array<string, mixed> The normalized claim.
   */
  private function normalizeClaimForComparison(array $claim, mixed $clientIdentifier, string $side): array
  {
    $normalized = [];

    // The fields the contract declares a claim mapping may carry, minus the
    // constraint list, which is normalized separately below. Reading the same
    // declaration the marshaller writes from is the whole point: a
    // hand-copied field list has quietly stopped matching what went over the
    // wire more than once.
    foreach ($this->contract->names(group: 'claim_mapping_fields') as $field) {
      if ($field === Marshaller::CFG_CONSTRAINTS_FIELD) {
        continue;
      }

      // The presence test, then the contract's own value rule: both are the
      // marshaller's, in that order.
      $value = Marshaller::countsAsPresent(value: $claim[$field] ?? null) ? $claim[$field] : null;

      $normalized[$field] = ($value !== null && $this->contract->permitsValue(field: $field, value: $value))
        ? $value
        : null;
    }

    $constraints = [];
    if (!empty($claim['Oa4mpClientClaimConstraint'])) {
      foreach ($claim['Oa4mpClientClaimConstraint'] as $constraint) {
        $hasField = Marshaller::countsAsPresent(value: $constraint['constraint_field'] ?? null);
        $hasValue = Marshaller::countsAsPresent(value: $constraint['constraint_value'] ?? null);

        if ($hasField && $hasValue) {
          if (!$this->contract->permitsValue(field: 'constraint_field', value: $constraint['constraint_field'])) {
            // The marshaller withholds a constraint_field value the contract
            // does not declare, which leaves that constraint half-populated
            // and so unemitted. Both sides have to reach the same conclusion.
            // The field name only, as below: never the value.
            $this->log(
              message: "Oa4mpClientClaim: dropping constraint with an undeclared"
                . " constraint_field from the " . $side . "-side comparison"
                . " (client=" . var_export($clientIdentifier, true)
                . ", constraint_field=" . var_export($constraint['constraint_field'], true) . ")"
            );
            continue;
          }

          // Built from the contract for the same reason the mapping above is:
          // a constraint field added to the declaration is emitted by the
          // marshaller, so it has to be compared here.
          $normalizedConstraint = [];
          foreach ($this->contract->names(group: 'claim_constraint_fields') as $field) {
            $normalizedConstraint[$field] = Marshaller::countsAsPresent(value: $constraint[$field] ?? null)
              ? $constraint[$field]
              : null;
          }

          $constraints[] = $normalizedConstraint;
        } elseif ($hasField || $hasValue) {
          // Dropping the constraint takes it out of the comparison, so this
          // is the only place that state stays visible. The client and the
          // constraint's field name only: never the constraint value.
          $this->log(
            message: "Oa4mpClientClaim: dropping half-populated constraint from"
              . " the " . $side . "-side comparison"
              . " (client=" . var_export($clientIdentifier, true)
              . ", constraint_field=" . var_export($constraint['constraint_field'] ?? null, true) . ")"
          );
        }
      }
    }

    // Sort constraints for consistent comparison.
    usort($constraints, function (array $a, array $b): int {
      $fieldCmp = strcmp((string)($a['constraint_field'] ?? ''), (string)($b['constraint_field'] ?? ''));
      if ($fieldCmp !== 0) {
        return $fieldCmp;
      }

      return strcmp((string)($a['constraint_value'] ?? ''), (string)($b['constraint_value'] ?? ''));
    });
    $normalized['constraints'] = $constraints;

    return $normalized;
  }

  /**
   * Record the first difference this comparison found, and log it.
   *
   * @param string $message The difference, naming the field. Never a
   *                        credential's value.
   * @return void
   */
  private function noteDifference(string $message): void
  {
    $this->difference ??= $message;
    $this->log(message: $message);
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
