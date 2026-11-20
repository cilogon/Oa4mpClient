<?php
/**
 * COmanage Registry Oa4mpClient Plugin cfg Capability Contract Test
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
use Oa4mpClient\Lib\Oa4mp\Marshaller;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The cfg capability contract decides what the marshaller may emit, and
 * every cfg is stamped with the contract's version.
 */
class CfgContractTest extends TestCase
{
  /**
   * Contract copies written by a test, removed afterwards.
   *
   * @var array<int, string>
   */
  private array $tempFiles = [];

  /**
   * Remove the contract copies this test wrote.
   *
   * @return void
   */
  protected function tearDown(): void
  {
    foreach ($this->tempFiles as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }

    $this->tempFiles = [];
  }

  /**
   * Every cfg, for every fixture that sends one, carries the
   * contract_version the contract document declares, and is not a number
   * fixed in code: a contract with another version stamps that version.
   */
  public function testEveryCfgCarriesTheContractVersion(): void
  {
    $shipped = CfgContract::shipped();
    $document = $this->shippedDocument();
    $document['contract_version'] = 9999;
    $other = new CfgContract(path: $this->writeContract(document: $document));

    $cases = [
      [$shipped, $this->shippedDocument()['contract_version']],
      [$other, 9999],
    ];
    $checked = 0;

    foreach (MarshallerParityTest::fixtureNames() as $name) {
      $fixture = MarshallerParityTest::fixture(name: $name);

      foreach ($cases as [$contract, $version]) {
        $content = MarshallerParityTest::marshal(
          name: $name,
          fixture: $fixture,
          marshaller: new Marshaller(contract: $contract)
        );

        if (!isset($content['cfg'])) {
          continue;
        }

        $this->assertSame(
          $version,
          $content['cfg']['metadata']['Oa4mpClient']['contract_version'] ?? null,
          "the cfg for $name is not stamped with the contract's version"
        );
        $checked++;
      }
    }

    $this->assertGreaterThan(0, $checked, 'no fixture sends a cfg');
  }

  /**
   * A field the contract does not declare is not sent, and neither is a
   * value an enumerated field does not allow. The one log line of the pass
   * counts them and names each field, and never carries a value.
   */
  public function testUndeclaredFieldIsWithheldAndLoggedByNameOnly(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'claims-email-address');
    $fixture['data']['Oa4mpClientClaim'][0]['undeclared_column'] = 'value-must-not-be-logged';
    $fixture['data']['Oa4mpClientClaim'][1]['Oa4mpClientClaimConstraint'][0]['undeclared_flag'] =
      'constraint-value-must-not-be-logged';
    $fixture['data']['Oa4mpClientClaim'][1]['source_model'] = 'NotARegistryModel';

    $lines = [];
    $marshaller = new Marshaller(
      contract: CfgContract::shipped(),
      log: function (string $message) use (&$lines): void {
        $lines[] = $message;
      }
    );

    $content = MarshallerParityTest::marshal(
      name: 'claims-email-address',
      fixture: $fixture,
      marshaller: $marshaller
    );
    $mappings = $content['cfg']['tokens']['identity']['qdl']['args']['claim_mappings'];

    $this->assertArrayNotHasKey('undeclared_column', $mappings[0]);
    $this->assertArrayNotHasKey('source_model', $mappings[1]);
    $this->assertArrayNotHasKey('undeclared_flag', $mappings[1]['claim_constraints'][0]);

    $signal = array_values(array_filter(
      $lines,
      fn (string $line): bool => str_starts_with($line, Marshaller::CFG_WITHHELD_SIGNAL)
    ));

    $this->assertCount(1, $signal, 'one withheld-value line per marshalling pass');
    $this->assertSame(
      Marshaller::CFG_WITHHELD_SIGNAL . ' version 2: 3 values withheld from the cfg:'
      . ' source_model, undeclared_column, undeclared_flag',
      $signal[0]
    );

    $log = implode("\n", $lines);
    $this->assertStringNotContainsString('value-must-not-be-logged', $log);
    $this->assertStringNotContainsString('NotARegistryModel', $log);
  }

  /**
   * A contract the plugin cannot read, or one with no version, stops the
   * marshaller rather than reading as an empty allowlist.
   */
  public function testUnusableContractRaises(): void
  {
    $missing = new CfgContract(path: sys_get_temp_dir() . '/no-such-contract-' . uniqid() . '.json');

    try {
      $missing->version();
      $this->fail('an unreadable contract was accepted');
    } catch (RuntimeException $e) {
      $this->assertStringContainsString('cannot be read', $e->getMessage());
    }

    $document = $this->shippedDocument();
    unset($document['contract_version']);
    $unversioned = new CfgContract(path: $this->writeContract(document: $document));

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('is not a usable contract document');
    $unversioned->names(group: 'qdl_args');
  }

  /**
   * A retired entry is not emitted, and an entry that does not state
   * retired_in is refused rather than read as live.
   */
  public function testRetiredEntriesAreNotEmittedAndMalformedOnesRefused(): void
  {
    $document = $this->shippedDocument();

    foreach ($document['capabilities']['qdl_args']['entries'] as $i => $entry) {
      if ($entry['name'] === 'no_record_redirect_url') {
        $document['capabilities']['qdl_args']['entries'][$i]['retired_in'] = 3;
      }
    }

    $retired = new CfgContract(path: $this->writeContract(document: $document));
    $this->assertNotContains('no_record_redirect_url', $retired->names(group: 'qdl_args'));
    $this->assertContains('no_record_redirect_url', CfgContract::shipped()->names(group: 'qdl_args'));

    unset($document['capabilities']['qdl_args']['entries'][0]['retired_in']);
    $malformed = new CfgContract(path: $this->writeContract(document: $document));

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('malformed qdl_args entry');
    $malformed->names(group: 'qdl_args');
  }

  /**
   * The shipped contract, decoded independently of CfgContract.
   *
   * @return array<string, mixed>
   */
  private function shippedDocument(): array
  {
    $text = file_get_contents(dirname(__DIR__, 3) . '/cfg_contract.json');

    return json_decode((string)$text, true, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Write a contract document to a temporary file.
   *
   * @param array<string, mixed> $document The document.
   * @return string Its path.
   */
  private function writeContract(array $document): string
  {
    $path = tempnam(sys_get_temp_dir(), 'oa4mp-contract-');
    $this->assertIsString($path);
    $this->tempFiles[] = $path;
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

    return $path;
  }
}
