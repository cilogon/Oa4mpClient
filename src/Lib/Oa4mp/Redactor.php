<?php
/**
 * COmanage Registry Oa4mpClient Plugin Log Redactor
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
use RuntimeException;
use Throwable;

/**
 * Masks credentials in what the plugin logs.
 *
 * Ported from the redaction helpers of Model/Oa4mpClientOa4mpServer.php in
 * the Registry 4 edition (redactSecrets(), secretFieldNames(),
 * uncontractedSecretFieldNames(), loggingSecretFieldNames(),
 * fallbackSecretFieldNames() and redactSecretsInLogText()).
 *
 * The secret names are a union of two halves. The cfg-side half is derived
 * from cfg_contract.json's secret_bearing flag, so a credential-carrying
 * capability cannot be declared without being redacted. The other half is
 * literal, because none of its names is a cfg capability the contract could
 * declare.
 */
class Redactor
{
  /**
   * The value a masked secret is replaced with.
   */
  public const REDACTED = '[REDACTED]';

  /**
   * Where a failure to derive the names is reported, or null.
   */
  private ?Closure $log;

  /**
   * @param CfgContract $contract The contract the cfg-side names come from.
   * @param callable|null $log Called with one message string when the
   *                           contract cannot be consulted.
   */
  public function __construct(private CfgContract $contract, ?callable $log = null)
  {
    $this->log = $log === null ? null : Closure::fromCallable($log);
  }

  /**
   * A copy of $row with known secret-bearing field values replaced by
   * REDACTED. A missing key stays missing; an explicit null stays null.
   *
   * @param array<string, mixed> $row An associative array, such as a
   *                                  DynamoDB configuration row.
   * @return array<string, mixed>
   */
  public function redactSecrets(array $row): array
  {
    foreach ($this->loggingSecretFieldNames() as $field) {
      if (isset($row[$field])) {
        $row[$field] = self::REDACTED;
      }
    }

    return $row;
  }

  /**
   * $text with the value of every secret-bearing JSON key masked.
   *
   * The rendered text is redacted rather than the structure, so a body that
   * a dump repeats elsewhere in the same line is masked everywhere. Callers
   * pass json_encode() output, never print_r(), which this cannot see into.
   *
   * @param string $text Text about to be logged.
   * @return string The text with secret values replaced by REDACTED.
   */
  public function redactSecretsInLogText(string $text): string
  {
    foreach ($this->loggingSecretFieldNames() as $field) {
      // Match "field": "value" and replace only the value, leaving the
      // surrounding JSON intact. The value pattern tolerates escaped
      // characters so a backslash inside a secret cannot end the match early.
      $pattern = '/("' . preg_quote($field, '/') . '"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/';
      $text = preg_replace($pattern, '${1}' . self::REDACTED . '${2}', $text);
    }

    return $text;
  }

  /**
   * Names whose values are credentials and must never reach a log: the
   * contract's secret-bearing names and the literal ones.
   *
   * An empty cfg-side derivation raises rather than quietly leaving the
   * literals alone. The contract declares two secret-bearing keys, so
   * deriving none means the reader or the document is broken, and without
   * the raise that disarmed state looks exactly like a healthy one.
   *
   * @return array<int, string> Field and JSON key names treated as secret.
   * @throws \RuntimeException If the contract cannot be read, or declares no
   *                           secret-bearing capability at all.
   */
  public function secretFieldNames(): array
  {
    $cfgNames = $this->contract->secretNames();

    if (empty($cfgNames)) {
      throw new RuntimeException(
        "Oa4mpClient cfg capability contract at " . $this->contract->path()
        . " declares no secret-bearing capability, so the cfg-side half of the"
        . " log-redaction list derived from it is empty. The contract declares"
        . " the two AWS credential keys; deriving none is a defect, not a cfg"
        . " vocabulary that carries no credentials"
      );
    }

    return array_values(array_unique(array_merge(
      $cfgNames,
      $this->uncontractedSecretFieldNames()
    )));
  }

  /**
   * The secret names with no cfg counterpart, and so not derivable from the
   * contract. Each is matched as a whole JSON or array key, so password does
   * not reach bind_password's value or a neighbouring password_expires_at.
   *
   * @return array<int, string>
   */
  public function uncontractedSecretFieldNames(): array
  {
    return [
      // The DynamoDB configuration's column names, as the plugin persists
      // them. The cfg spells the same two credentials access_key_id and
      // secret_access_key; those come from the contract.
      'aws_access_key_id',
      'aws_secret_access_key',
      // Credentials the OA4MP server issues and returns in a response body:
      // the client's own secret, and the token that authorizes managing the
      // client at the registration endpoint.
      'client_secret',
      'registration_access_token',
      // The LDAP bind credential, under both names a cfg written before
      // QDLv3 spells it. This edition never emits one, but a client whose
      // cfg was written by an older plugin still carries it, and reading
      // that client back logs it.
      'password',
      'bind_password',
    ];
  }

  /**
   * The redaction list as the logging helpers must have it: never raising.
   *
   * Both callers are about to log a body that carries credentials, so an
   * exception would either abort a client save or leave the text unredacted.
   * On any failure the full literal list is used instead, cfg-side names
   * included, and the failure is logged rather than swallowed.
   *
   * @return array<int, string>
   */
  private function loggingSecretFieldNames(): array
  {
    try {
      return $this->secretFieldNames();
    } catch (Throwable $e) {
      // Throwable, not Exception: a malformed document can fail in ways that
      // are Errors rather than Exceptions, and nothing about a broken
      // contract may stop this line from being masked.
      if ($this->log !== null) {
        ($this->log)(
          "Oa4mpClient could not derive the cfg-side log-redaction names from the"
          . " capability contract; falling back to the literal list so this line is"
          . " still redacted: " . $e->getMessage()
        );
      }

      return $this->fallbackSecretFieldNames();
    }
  }

  /**
   * The redaction list used when the contract cannot be consulted. The
   * cfg-side names are literal here and only here, for exactly that case.
   *
   * @return array<int, string>
   */
  private function fallbackSecretFieldNames(): array
  {
    return array_merge(
      [
        // The two credentials as a cfg spells them; normally derived from
        // cfg_contract.json's secret_bearing flag.
        'access_key_id',
        'secret_access_key',
      ],
      $this->uncontractedSecretFieldNames()
    );
  }
}
