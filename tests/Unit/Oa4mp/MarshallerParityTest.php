<?php
/**
 * COmanage Registry Oa4mpClient Plugin Marshaller Parity Test
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Parity check: for every fixture in tests/Parity/fixtures, this
 * edition's marshaller sends OA4MP exactly the bytes the Registry 4 edition
 * recorded in tests/Parity/expected. bin/parity-sync.php copies both from a
 * commit of the Registry 4 line and records that commit in
 * tests/Parity/SOURCE.
 *
 * The two Registry addresses cannot match between the editions, so the
 * values Registry 4 recorded in each addresses.json are handed in.
 *
 * The tests after the Parity check lock, one by one, rules the fixtures
 * exercise, so a break names the rule rather than only a changed byte.
 */
class MarshallerParityTest extends TestCase
{
  /**
   * The Parity directory.
   *
   * @return string
   */
  public static function parityDir(): string
  {
    return dirname(__DIR__, 2) . '/Parity';
  }

  /**
   * A fixture, decoded.
   *
   * @param string $name Fixture name, without .json.
   * @return array<string, mixed> description, adminClient and data.
   */
  public static function fixture(string $name): array
  {
    $text = file_get_contents(self::parityDir() . '/fixtures/' . $name . '.json');

    return json_decode((string)$text, true, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * The addresses Registry 4 recorded for a fixture.
   *
   * @param string $name Fixture name.
   * @return array<string, string|null> client_index_address and
   *         named_config_address.
   */
  public static function addresses(string $name): array
  {
    $text = file_get_contents(self::parityDir() . '/expected/' . $name . '/addresses.json');

    return json_decode((string)$text, true, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Marshal a fixture's settings as the plugin would send them.
   *
   * @param string $name Fixture name, for its recorded addresses.
   * @param array<string, mixed> $fixture The fixture, possibly altered.
   * @param \Oa4mpClient\Lib\Oa4mp\Marshaller|null $marshaller The marshaller
   *        to use; one with the shipped contract when null.
   * @return array<string, mixed> The request content.
   */
  public static function marshal(string $name, array $fixture, ?Marshaller $marshaller = null): array
  {
    $marshaller ??= new Marshaller(contract: CfgContract::shipped());
    $addresses = self::addresses(name: $name);

    return $marshaller->marshallContent(
      adminClient: $fixture['adminClient'],
      data: $fixture['data'],
      clientIndexAddress: $addresses['client_index_address'],
      namedConfigAddress: $addresses['named_config_address']
    );
  }

  /**
   * The names of the fixtures, sorted.
   *
   * @return array<int, string>
   */
  public static function fixtureNames(): array
  {
    $names = [];

    foreach (glob(self::parityDir() . '/fixtures/*.json') ?: [] as $path) {
      $names[] = basename($path, '.json');
    }

    sort($names);

    return $names;
  }

  /**
   * One data set per fixture, keyed by its name.
   *
   * @return array<string, array<int, string>>
   */
  public static function fixtureProvider(): array
  {
    $sets = [];

    foreach (self::fixtureNames() as $name) {
      $sets[$name] = [$name];
    }

    return $sets;
  }

  /**
   * The request body is byte for byte what Registry 4 sends, as json_encode()
   * with its default flags renders it, and the cfg, rendered as the exporter
   * renders cfg.json, is byte for byte what it recorded. A fixture with no
   * cfg.json sends no cfg.
   */
  #[DataProvider('fixtureProvider')]
  public function testFixtureMarshalsToTheRecordedRequest(string $name): void
  {
    $expectedDir = self::parityDir() . '/expected/' . $name;
    $this->assertFileExists($expectedDir . '/request-body.json');

    $content = self::marshal(name: $name, fixture: self::fixture(name: $name));

    $this->assertSame(
      file_get_contents($expectedDir . '/request-body.json'),
      json_encode($content),
      "the request body for $name differs from what Registry 4 recorded"
    );

    if (!is_file($expectedDir . '/cfg.json')) {
      $this->assertArrayNotHasKey('cfg', $content, "$name sends a cfg Registry 4 does not");

      return;
    }

    $this->assertArrayHasKey('cfg', $content, "$name sends no cfg");
    $this->assertSame(
      file_get_contents($expectedDir . '/cfg.json'),
      json_encode(
        $content['cfg'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
      ) . "\n",
      "the cfg for $name differs from what Registry 4 recorded"
    );
  }

  /**
   * SOURCE names the Registry 4 commit the fixtures came from, so a reader
   * can tell which output this edition is held to.
   */
  public function testParitySourceRecordsTheCommit(): void
  {
    $source = self::parityDir() . '/SOURCE';

    $this->assertFileExists($source, 'tests/Parity/SOURCE is missing; run bin/parity-sync.php');
    $this->assertMatchesRegularExpression(
      '/\A[0-9a-f]{40}\n\z/',
      (string)file_get_contents($source),
      'tests/Parity/SOURCE must hold one full commit SHA'
    );
  }

  /**
   * Every fixture has expected output and every expected directory has a
   * fixture. A fixture without expected output would otherwise be checked
   * against nothing, and an orphaned directory would hide a dropped case.
   */
  public function testFixturesAndExpectedOutputMatchOneToOne(): void
  {
    $expected = [];

    foreach (glob(self::parityDir() . '/expected/*', GLOB_ONLYDIR) ?: [] as $path) {
      $expected[] = basename($path);
    }

    sort($expected);

    $this->assertNotEmpty(self::fixtureNames(), 'there are no Parity fixtures');
    $this->assertSame(self::fixtureNames(), $expected);
  }

  /**
   * OA4MP rejects a cfg on a public client. The public client fixture carries
   * a claim, so only the client type keeps the cfg out; flipping the type
   * alone brings it back.
   */
  public function testPublicClientCarriesNoCfg(): void
  {
    $fixture = self::fixture(name: 'public-client');
    $this->assertNotEmpty($fixture['data']['Oa4mpClientClaim']);

    $content = self::marshal(name: 'public-client', fixture: $fixture);
    $this->assertArrayNotHasKey('cfg', $content);
    $this->assertSame('none', $content['token_endpoint_auth_method']);

    $fixture['data']['Oa4mpClientCoOidcClient']['public_client'] = false;
    $this->assertArrayHasKey('cfg', self::marshal(name: 'public-client', fixture: $fixture));
  }

  /**
   * The QDL compares the Authorized User Group with a number from DynamoDB,
   * and in QDL a string never equals a number, so the id goes out as a
   * number even when a form posted it as text.
   */
  public function testAuthorizedUserGroupIsSentAsANumber(): void
  {
    $fixture = self::fixture(name: 'authorization-group');
    $fixture['data']['Oa4mpClientAuthorization']['authz_co_group_id'] = '12';

    $content = self::marshal(name: 'authorization-group', fixture: $fixture);
    $args = $content['cfg']['tokens']['identity']['qdl']['args'];

    $this->assertSame(12, $args['authorization_group_id']);
    $this->assertStringContainsString('"authorization_group_id":12,', json_encode($content));
  }

  /**
   * The "any type" choice the Claims page offers for a type constraint is
   * stored as the explicit value all, and goes to OA4MP as that value.
   */
  public function testAnyTypeConstraintIsSentAsAll(): void
  {
    $fixture = self::fixture(name: 'claims-identifier');
    $claim = $this->claimWithConstraints(fixture: $fixture);
    $fixture['data']['Oa4mpClientClaim'][$claim]['Oa4mpClientClaimConstraint'] = [
      ['id' => 1, 'claim_id' => 1, 'constraint_field' => 'type', 'constraint_value' => 'all'],
    ];

    $mapping = $this->mappings(name: 'claims-identifier', fixture: $fixture)[$claim];

    $this->assertSame(
      [['constraint_field' => 'type', 'constraint_value' => 'all']],
      $mapping['claim_constraints']
    );
  }

  /**
   * A constraint missing its field or its value means nothing to the QDL and
   * is dropped, as the comparator drops it. An empty type value is dropped
   * too, not turned into all: Registry 4 at 7.0.0 sends nothing for it.
   */
  public function testConstraintMissingItsFieldOrValueIsDropped(): void
  {
    $fixture = self::fixture(name: 'claims-identifier');
    $claim = $this->claimWithConstraints(fixture: $fixture);
    $fixture['data']['Oa4mpClientClaim'][$claim]['Oa4mpClientClaimConstraint'] = [
      ['id' => 1, 'claim_id' => 1, 'constraint_field' => 'type', 'constraint_value' => ''],
      ['id' => 2, 'claim_id' => 1, 'constraint_field' => '', 'constraint_value' => 'official'],
      ['id' => 3, 'claim_id' => 1, 'constraint_field' => 'type', 'constraint_value' => null],
      ['id' => 4, 'claim_id' => 1, 'constraint_value' => 'official'],
      ['id' => 5, 'claim_id' => 1, 'constraint_field' => 'type', 'constraint_value' => 'official'],
    ];

    $mapping = $this->mappings(name: 'claims-identifier', fixture: $fixture)[$claim];

    $this->assertSame(
      [['constraint_field' => 'type', 'constraint_value' => 'official']],
      $mapping['claim_constraints']
    );
  }

  /**
   * The index of the fixture's first claim that carries constraints.
   *
   * @param array<string, mixed> $fixture A claims fixture.
   * @return int
   */
  private function claimWithConstraints(array $fixture): int
  {
    foreach ($fixture['data']['Oa4mpClientClaim'] as $i => $claim) {
      if (!empty($claim['Oa4mpClientClaimConstraint'])) {
        return $i;
      }
    }

    $this->fail('the fixture has no claim with constraints');
  }

  /**
   * The claim mappings a fixture's cfg carries.
   *
   * @param string $name Fixture name.
   * @param array<string, mixed> $fixture The fixture, possibly altered.
   * @return array<int, array<string, mixed>>
   */
  private function mappings(string $name, array $fixture): array
  {
    $content = self::marshal(name: $name, fixture: $fixture);

    return $content['cfg']['tokens']['identity']['qdl']['args']['claim_mappings'];
  }
}
