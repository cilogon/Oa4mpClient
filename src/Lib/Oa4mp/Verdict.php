<?php
/**
 * COmanage Registry Oa4mpClient Plugin Sync Verification Verdict
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

/**
 * The answer Sync verification gives about one client: in sync, out of sync,
 * or the check failed.
 *
 * Ported from verdictFromComparison() in Model/Oa4mpClientOa4mpServer.php of
 * the Registry 4 edition at 7.0.0, whose bare form returned true, false, or
 * null for a check that did not run. The three answers have different
 * remedies: out of sync tells the user the client was changed outside the
 * Registry, while a failed check says nothing about the client at all and
 * usually means a fault in the deployment. A caller reads the outcome
 * through checkFailed() before anything else, so a failed check is never
 * shown as tampering.
 */
final class Verdict
{
  /**
   * The stored client and the server's copy agree.
   */
  public const IN_SYNC = 'in_sync';

  /**
   * The comparison ran and found a difference.
   */
  public const OUT_OF_SYNC = 'out_of_sync';

  /**
   * The comparison did not run to a conclusion.
   */
  public const CHECK_FAILED = 'check_failed';

  /**
   * @param string $outcome One of the three outcome constants.
   * @param string|null $reason For the log, never for a page: the first
   *        difference found, or what stopped the check.
   * @param string|null $serverExtra The extras value read from the server's
   *        copy: settings the plugin does not model, as JSON, to be stored
   *        and sent back on the next update. Null when there are none or the
   *        check failed.
   */
  private function __construct(
    public readonly string $outcome,
    public readonly ?string $reason,
    public readonly ?string $serverExtra
  ) {
  }

  /**
   * The verdict a comparison reaches.
   *
   * A comparison that reports an error is a failed check whatever else it
   * says, and anything but a synchronized value of exactly true is out of
   * sync, so a malformed comparison fails closed.
   *
   * @param array<string, mixed> $comparison As returned by
   *        Comparator::compareToServerObject().
   * @return self
   */
  public static function fromComparison(array $comparison): self
  {
    if (!empty($comparison['error'])) {
      return new self(
        outcome: self::CHECK_FAILED,
        reason: $comparison['reason'] ?? null,
        serverExtra: null
      );
    }

    $serverExtra = $comparison['oa4mp_server_extra'] ?? null;

    return new self(
      outcome: ($comparison['synchronized'] ?? null) === true ? self::IN_SYNC : self::OUT_OF_SYNC,
      reason: $comparison['reason'] ?? null,
      serverExtra: is_string($serverExtra) ? $serverExtra : null
    );
  }

  /**
   * Whether the stored client and the server's copy agree.
   *
   * @return bool
   */
  public function isInSync(): bool
  {
    return $this->outcome === self::IN_SYNC;
  }

  /**
   * Whether the comparison ran and found a difference.
   *
   * @return bool
   */
  public function isOutOfSync(): bool
  {
    return $this->outcome === self::OUT_OF_SYNC;
  }

  /**
   * Whether the comparison did not run to a conclusion.
   *
   * @return bool
   */
  public function checkFailed(): bool
  {
    return $this->outcome === self::CHECK_FAILED;
  }
}
