<?php
/**
 * COmanage Registry Oa4mpClient Plugin cfg Capability Contract
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

use RuntimeException;

/**
 * The cfg capability contract, cfg_contract.json: every name the plugin may
 * put into a cfg, and the version a cfg is stamped with.
 *
 * Ported from the contract helpers of Model/Oa4mpClientOa4mpServer.php in the
 * Registry 4 edition (cfgContract(), cfgContractVersion(), cfgContractNames(),
 * cfgContractValueGroup(), cfgContractPermitsValue() and
 * cfgContractSecretNames()). Both editions read the same document, so the
 * rules stay the same: an unreadable or unusable contract raises rather than
 * resolving to an empty one, because an allowlist reads an empty contract as
 * "emit nothing" and a redaction list reads it as "redact nothing".
 */
class CfgContract
{
  /**
   * The decoded document, once read.
   *
   * @var array<string, mixed>|null
   */
  private ?array $contract = null;

  /**
   * @param string $path Path to the contract document.
   */
  public function __construct(private string $path)
  {
  }

  /**
   * The contract the plugin ships, cfg_contract.json at the plugin root.
   *
   * @return self
   */
  public static function shipped(): self
  {
    return new self(path: dirname(__DIR__, 3) . '/cfg_contract.json');
  }

  /**
   * Path to the contract document this instance reads.
   *
   * @return string
   */
  public function path(): string
  {
    return $this->path;
  }

  /**
   * The decoded contract, read once.
   *
   * @return array<string, mixed>
   * @throws \RuntimeException If the document cannot be read or is not a
   *                           usable contract.
   */
  public function document(): array
  {
    if ($this->contract !== null) {
      return $this->contract;
    }

    $text = is_readable($this->path) ? file_get_contents($this->path) : false;

    if ($text === false) {
      throw new RuntimeException(
        "Oa4mpClient cfg capability contract cannot be read at " . $this->path
      );
    }

    $contract = json_decode($text, true);

    if (
      !is_array($contract)
      || !isset($contract['contract_version'])
      || empty($contract['capabilities'])
    ) {
      throw new RuntimeException(
        "Oa4mpClient cfg capability contract at " . $this->path
        . " is not a usable contract document: " . json_last_error_msg()
      );
    }

    $this->contract = $contract;

    return $contract;
  }

  /**
   * The version of the contract a cfg marshalled now is built to.
   *
   * @return mixed The declared contract_version.
   */
  public function version(): mixed
  {
    return $this->document()['contract_version'];
  }

  /**
   * The names one capability group declares, in declaration order, excluding
   * any entry that has been retired.
   *
   * Declaration order is load-bearing: it is the order a marshalled mapping
   * carries. Retired entries stay in the document so an older cfg remains
   * interpretable, but they are not emitted, so they are not returned.
   *
   * @param string $group Capability group name, e.g. 'claim_mapping_fields'.
   * @return array<int, string> Declared names, in contract order.
   * @throws \RuntimeException If the group is absent or carries a malformed
   *                           entry.
   */
  public function names(string $group): array
  {
    $contract = $this->document();

    if (!isset($contract['capabilities'][$group]['entries'])) {
      throw new RuntimeException(
        "Oa4mpClient cfg capability contract declares no " . $group
        . " group, which the plugin looks up by name"
      );
    }

    $names = [];

    foreach ($contract['capabilities'][$group]['entries'] as $entry) {
      // A missing retired_in is a malformed entry and never an implied null;
      // see the contract's own entry_fields note. Defaulting it here would
      // emit a capability nothing declared was still live.
      if (!isset($entry['name']) || !array_key_exists('retired_in', $entry)) {
        throw new RuntimeException(
          "Oa4mpClient cfg capability contract carries a malformed " . $group
          . " entry: every entry names itself and states retired_in"
        );
      }

      if ($entry['retired_in'] !== null) {
        continue;
      }

      $names[] = $entry['name'];
    }

    return $names;
  }

  /**
   * Whether the contract permits $field to carry $value.
   *
   * The marshaller and the comparator both ask this, which is the point of
   * it being one function: a value the marshaller withholds never reaches
   * the server, so a comparator that kept it would report the client out of
   * sync on every verify pass.
   *
   * Free-text fields (a claim_name, a constraint_value) are permitted
   * whatever they carry: the contract enumerates names for them, not values.
   *
   * @param string $field A declared field name.
   * @param mixed $value The value the row carries.
   * @return bool
   */
  public function permitsValue(string $field, mixed $value): bool
  {
    $group = $this->valueGroup(field: $field);

    if ($group === '') {
      return true;
    }

    return in_array($value, $this->names(group: $group), true);
  }

  /**
   * The cfg-side secret names: every entry, in any group, flagged
   * secret_bearing.
   *
   * Every group is scanned, so a credential declared in some future group is
   * redacted the day it is declared. A missing flag is a malformed entry and
   * never an implied false. Unlike names(), a retired entry is kept: a cfg
   * written before the retirement is still stored on an OA4MP server and is
   * still logged when read back.
   *
   * @return array<int, string> Secret-bearing names, deduplicated.
   * @throws \RuntimeException If the contract cannot be read, or carries an
   *                           entry with no secret_bearing flag.
   */
  public function secretNames(): array
  {
    $names = [];

    foreach ($this->document()['capabilities'] as $group => $declaration) {
      if (empty($declaration['entries'])) {
        continue;
      }

      foreach ($declaration['entries'] as $entry) {
        if (!isset($entry['name']) || !array_key_exists('secret_bearing', $entry)) {
          throw new RuntimeException(
            "Oa4mpClient cfg capability contract carries a malformed " . $group
            . " entry: every entry names itself and states secret_bearing,"
            . " which must not default to false"
          );
        }

        if ($entry['secret_bearing'] !== true) {
          continue;
        }

        $names[] = $entry['name'];
      }
    }

    return array_values(array_unique($names));
  }

  /**
   * The capability group enumerating the values $field may carry, or ''
   * when the contract enumerates no values for it.
   *
   * An enumerated-value group is named for the field it constrains, so
   * source_model is constrained by source_model_values. A further such group
   * added to the contract is enforced the day it is declared.
   *
   * @param string $field A declared field name.
   * @return string The value group's name, or ''.
   */
  private function valueGroup(string $field): string
  {
    $group = $field . '_values';

    return isset($this->document()['capabilities'][$group]['entries']) ? $group : '';
  }
}
