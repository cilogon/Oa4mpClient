<?php
/**
 * COmanage Registry Oa4mpClient Plugin Round Trip Test
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
use Oa4mpClient\Lib\Oa4mp\Comparator;
use Oa4mpClient\Lib\Oa4mp\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Settings, marshalled, sent, read back and compared: a client the plugin
 * itself wrote reports no drift, and a change on the server side does.
 *
 * Each Parity fixture is the stored client as Registry 4 loads it. What the
 * marshaller builds for it is what OA4MP stores, so the server's answer to a
 * read is that request body with the server's own keys added.
 */
class RoundTripTest extends TestCase
{
  /**
   * The server's answer to a read of a client the plugin wrote: the request
   * body, through JSON as it travels, with the keys the server adds.
   *
   * @param string $name Fixture name, for its recorded addresses.
   * @param array<string, mixed> $fixture The fixture, possibly altered.
   * @return array<string, mixed>
   */
  public static function serverCopy(string $name, array $fixture): array
  {
    $content = MarshallerParityTest::marshal(name: $name, fixture: $fixture);
    $response = json_decode((string)json_encode($content), true, 512, JSON_THROW_ON_ERROR);

    $response['client_id'] = self::identifier(name: $name, fixture: $fixture);
    $response['client_id_issued_at'] = 1787601383;
    $response['registration_client_uri'] = 'https://oa4mp.example.org/oauth2/oidc-cm?client_id='
      . $response['client_id'];

    return $response;
  }

  /**
   * The client's OA4MP identifier: the fixture's, or for a fixture that
   * records a client before its create, the one the server would assign.
   *
   * @param string $name Fixture name.
   * @param array<string, mixed> $fixture The fixture.
   * @return string
   */
  public static function identifier(string $name, array $fixture): string
  {
    return $fixture['data']['Oa4mpClientCoOidcClient']['oa4mp_identifier']
      ?? 'cilogon:/client_id/parity-' . $name;
  }

  /**
   * The fixture's client as the database hands it back after a save, which
   * is what the comparison reads.
   *
   * Most fixtures already are that. One records a client as the add page
   * marshals it, before the create: it has no ids, no OA4MP identifier and
   * none of the columns left at their defaults. The comparator decides
   * whether the client has an authorization by the row's id, so that data
   * would read as having none, and the server's copy as drift. This fills in
   * what the save and the reload add, as Registry 4's create path does.
   *
   * @param string $name Fixture name.
   * @param array<string, mixed> $fixture The fixture.
   * @return array<string, mixed>
   */
  public static function savedClient(string $name, array $fixture): array
  {
    $data = $fixture['data'];

    if (!empty($data['Oa4mpClientCoOidcClient']['id'])) {
      return $data;
    }

    $data['Oa4mpClientCoOidcClient'] += [
      'id' => 99,
      'oa4mp_identifier' => self::identifier(name: $name, fixture: $fixture),
      'error_url' => null,
      'proxy_limited' => false,
      'refresh_token_lifetime' => null,
      'named_config_id' => null,
      'oa4mp_server_extra' => null,
    ];
    $data['Oa4mpClientAccessToken'] ??= ['id' => null, 'client_id' => null, 'is_jwt' => null];
    $data['Oa4mpClientRefreshToken'] ??= ['id' => null, 'client_id' => null, 'token_lifetime' => null];

    if (isset($data['Oa4mpClientAuthorization'])) {
      $data['Oa4mpClientAuthorization'] += [
        'id' => 99,
        'client_id' => 99,
        'authz_co_group_id' => null,
        'require_active' => null,
        'authz_group_redirect_url' => null,
        'require_active_redirect_url' => null,
        'no_record_redirect_url' => null,
      ];
    }

    if (isset($data['Oa4mpClientDynamoConfig'])) {
      $data['Oa4mpClientDynamoConfig'] = ['id' => 99, 'client_id' => 99, 'admin_id' => null]
        + $data['Oa4mpClientDynamoConfig'];
    }

    return $data;
  }

  /**
   * Compare a stored client with a server copy, collecting the log.
   *
   * @param array<string, mixed> $stored The stored client.
   * @param mixed $serverCopy The decoded server copy.
   * @param array<int, string> $log Collects the lines logged.
   * @return \Oa4mpClient\Lib\Oa4mp\Verdict
   */
  public static function verdict(array $stored, mixed $serverCopy, array &$log = []): Verdict
  {
    $comparator = new Comparator(
      contract: CfgContract::shipped(),
      log: function (string $message) use (&$log): void {
        $log[] = $message;
      }
    );

    return Verdict::fromComparison(
      comparison: $comparator->compareToServerObject(curClient: $stored, oa4mpObject: $serverCopy)
    );
  }

  /**
   * One data set per Parity fixture.
   *
   * @return array<string, array<int, string>>
   */
  public static function fixtureProvider(): array
  {
    return MarshallerParityTest::fixtureProvider();
  }

  /**
   * For every Parity fixture, what the plugin sends reads back in sync with
   * the stored client: the plugin never reports drift on a client it wrote.
   */
  #[DataProvider('fixtureProvider')]
  public function testWhatThePluginSendsReadsBackInSync(string $name): void
  {
    $fixture = MarshallerParityTest::fixture(name: $name);
    $log = [];

    $verdict = self::verdict(
      stored: self::savedClient(name: $name, fixture: $fixture),
      serverCopy: self::serverCopy(name: $name, fixture: $fixture),
      log: $log
    );

    $this->assertTrue(
      $verdict->isInSync(),
      "$name reads back as " . $verdict->outcome . ': ' . var_export($verdict->reason, true)
    );
    $this->assertNull($verdict->reason);
  }

  /**
   * The control for the test above, on every fixture: a callback changed on
   * the server side is out of sync, and the reason names the callbacks.
   */
  #[DataProvider('fixtureProvider')]
  public function testACallbackChangedOnTheServerIsOutOfSyncAndNamed(string $name): void
  {
    $fixture = MarshallerParityTest::fixture(name: $name);
    $serverCopy = self::serverCopy(name: $name, fixture: $fixture);
    $this->assertNotEmpty($serverCopy['redirect_uris'], "$name sends no callback to change");
    $serverCopy['redirect_uris'][0] = 'https://elsewhere.example.org/callback';

    $verdict = self::verdict(stored: self::savedClient(name: $name, fixture: $fixture), serverCopy: $serverCopy);

    $this->assertTrue($verdict->isOutOfSync(), "$name reads back as " . $verdict->outcome);
    $this->assertStringContainsString('Oa4mpClientCoCallback', (string)$verdict->reason);
  }

  /**
   * The server keeps settings the plugin does not model, and a read hands
   * them back in the extras value even when the client is in sync, so the
   * next update sends them back unchanged.
   */
  public function testServerSettingsThePluginDoesNotModelComeBackAsExtras(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'confidential-minimal');
    $serverCopy = self::serverCopy(name: 'confidential-minimal', fixture: $fixture);
    $serverCopy['rt_grace_period'] = -1;

    $verdict = self::verdict(
      stored: self::savedClient(name: 'confidential-minimal', fixture: $fixture),
      serverCopy: $serverCopy
    );

    $this->assertTrue($verdict->isInSync());
    $this->assertSame(['rt_grace_period' => -1], json_decode((string)$verdict->serverExtra, true));
  }

  /**
   * Comparing a client logs its cfg and the extras, and none of those lines
   * carries the DynamoDB secret access key the cfg holds.
   */
  public function testNoLoggedLineCarriesTheSecretAccessKey(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'dynamo-per-client');
    $secret = $fixture['data']['Oa4mpClientDynamoConfig']['aws_secret_access_key'];
    $this->assertNotEmpty($secret);

    $serverCopy = self::serverCopy(name: 'dynamo-per-client', fixture: $fixture);
    $serverCopy['client_secret'] = 'a-client-secret-value';
    $serverCopy['cfg']['tokens']['identity']['qdl']['args']['dynamo_module_config']['access_key_id'] =
      'AKIAOTHEREXAMPLE';
    $log = [];

    $verdict = self::verdict(stored: $fixture['data'], serverCopy: $serverCopy, log: $log);

    $this->assertTrue($verdict->isOutOfSync());
    $this->assertNotEmpty($log);
    foreach ($log as $line) {
      $this->assertStringNotContainsString($secret, $line);
      $this->assertStringNotContainsString('a-client-secret-value', $line);
      $this->assertStringNotContainsString('AKIAOTHEREXAMPLE', $line);
    }
  }
}
