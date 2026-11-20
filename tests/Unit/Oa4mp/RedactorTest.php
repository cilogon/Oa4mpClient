<?php
/**
 * COmanage Registry Oa4mpClient Plugin Log Redactor Test
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

namespace Oa4mpClient\Test\Unit\Oa4mp;

use Oa4mpClient\Lib\Oa4mp\CfgContract;
use Oa4mpClient\Lib\Oa4mp\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Credentials never reach a log: every secret-bearing name the contract
 * declares, and every literal one, is masked in a JSON log line.
 */
class RedactorTest extends TestCase
{
  /**
   * The secret names with no cfg counterpart, written out here so a name
   * dropped from the Redactor's own list turns this test red.
   */
  private const LITERAL_SECRET_NAMES = [
    'aws_access_key_id',
    'aws_secret_access_key',
    'client_secret',
    'registration_access_token',
    'password',
    'bind_password',
  ];

  /**
   * A contract copy written by a test, removed afterwards.
   */
  private ?string $tempFile = null;

  /**
   * Remove the contract copy this test wrote.
   *
   * @return void
   */
  protected function tearDown(): void
  {
    if ($this->tempFile !== null && is_file($this->tempFile)) {
      unlink($this->tempFile);
    }

    $this->tempFile = null;
  }

  /**
   * Every secret-bearing name, nested at any depth in a JSON log line and
   * holding a value with an escaped quote and backslash, has its value
   * masked, and the line stays valid JSON with its other values intact.
   */
  public function testEverySecretNameIsMaskedInAJsonLogLine(): void
  {
    $names = array_values(array_unique(array_merge(
      $this->contractSecretNames(),
      self::LITERAL_SECRET_NAMES
    )));
    $this->assertContains('secret_access_key', $names);

    $line = $this->logLine(names: $names);
    $redacted = (new Redactor(contract: CfgContract::shipped()))->redactSecretsInLogText(text: $line);

    foreach ($names as $name) {
      $this->assertStringNotContainsString("value-of-$name", $redacted, "$name was not masked");
    }

    $decoded = json_decode($redacted, true, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Parity client', $decoded['client_name']);
    $this->assertSame('not secret', $decoded['cfg']['password_expires_at']);
    $this->assertSame(count($names) * 2, substr_count($redacted, Redactor::REDACTED));
  }

  /**
   * A credential declared in the contract is masked the day it is declared,
   * with no change to the Redactor.
   */
  public function testSecretDeclaredInTheContractIsMasked(): void
  {
    $document = $this->shippedDocument();
    $document['capabilities']['qdl_args']['entries'][] = [
      'name' => 'new_api_token',
      'introduced_in' => 3,
      'retired_in' => null,
      'secret_bearing' => true,
    ];
    $this->tempFile = (string)tempnam(sys_get_temp_dir(), 'oa4mp-contract-');
    file_put_contents($this->tempFile, json_encode($document, JSON_THROW_ON_ERROR));

    $redactor = new Redactor(contract: new CfgContract(path: $this->tempFile));
    $redacted = $redactor->redactSecretsInLogText(text: '{"new_api_token":"value-of-token"}');

    $this->assertSame('{"new_api_token":"[REDACTED]"}', $redacted);
  }

  /**
   * A contract the Redactor cannot read does not leave a line unmasked: the
   * literal list, the two cfg credentials included, is used instead, and
   * the failure is logged.
   */
  public function testUnreadableContractFallsBackToTheLiteralList(): void
  {
    $lines = [];
    $redactor = new Redactor(
      contract: new CfgContract(path: sys_get_temp_dir() . '/no-such-contract-' . uniqid() . '.json'),
      log: function (string $message) use (&$lines): void {
        $lines[] = $message;
      }
    );

    $names = array_merge(['access_key_id', 'secret_access_key'], self::LITERAL_SECRET_NAMES);
    $redacted = $redactor->redactSecretsInLogText(text: $this->logLine(names: $names));

    foreach ($names as $name) {
      $this->assertStringNotContainsString("value-of-$name", $redacted, "$name was not masked");
    }

    $this->assertNotEmpty($lines);
    $this->assertStringContainsString('falling back to the literal list', $lines[0]);
  }

  /**
   * A row's secret values are masked by key; other values, and a secret key
   * holding null, are left as they are.
   */
  public function testRowSecretsAreMasked(): void
  {
    $row = (new Redactor(contract: CfgContract::shipped()))->redactSecrets(row: [
      'aws_region' => 'us-east-2',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-secret',
      'client_secret' => null,
    ]);

    $this->assertSame([
      'aws_region' => 'us-east-2',
      'aws_access_key_id' => Redactor::REDACTED,
      'aws_secret_access_key' => Redactor::REDACTED,
      'client_secret' => null,
    ], $row);
  }

  /**
   * A JSON log line carrying each name twice, at the top level and nested,
   * each value naming its key so a leak is easy to spot.
   *
   * @param array<int, string> $names Secret names.
   * @return string
   */
  private function logLine(array $names): string
  {
    $top = ['client_name' => 'Parity client'];
    $nested = ['password_expires_at' => 'not secret'];

    foreach ($names as $name) {
      $top[$name] = "value-of-$name with \"quote\" and \\ backslash";
      $nested[$name] = "value-of-$name";
    }

    $top['cfg'] = $nested;

    return json_encode($top, JSON_THROW_ON_ERROR);
  }

  /**
   * The names the shipped contract flags secret_bearing, read independently
   * of CfgContract.
   *
   * @return array<int, string>
   */
  private function contractSecretNames(): array
  {
    $names = [];

    foreach ($this->shippedDocument()['capabilities'] as $declaration) {
      foreach ($declaration['entries'] ?? [] as $entry) {
        if (($entry['secret_bearing'] ?? false) === true) {
          $names[] = $entry['name'];
        }
      }
    }

    return $names;
  }

  /**
   * The shipped contract, decoded.
   *
   * @return array<string, mixed>
   */
  private function shippedDocument(): array
  {
    $text = file_get_contents(dirname(__DIR__, 3) . '/cfg_contract.json');

    return json_decode((string)$text, true, 512, JSON_THROW_ON_ERROR);
  }
}
