<?php
/**
 * COmanage Registry Oa4mpClient Plugin Comparator Test
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
use Oa4mpClient\Lib\Oa4mp\Unmarshaller;
use Oa4mpClient\Lib\Oa4mp\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Sync verification's three answers, and the rules it shares with the
 * marshaller so a client the plugin wrote never reads as drifted.
 */
class ComparatorTest extends TestCase
{
  /**
   * When the contract cannot be read, the comparison does not run, and that
   * is a failed check: nothing about it says the client was changed outside
   * the Registry.
   */
  public function testAnExceptionInsideTheComparisonIsACheckFailedNotOutOfSync(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'claims-identifier');
    $serverCopy = RoundTripTest::serverCopy(name: 'claims-identifier', fixture: $fixture);
    $log = [];

    $comparator = new Comparator(
      contract: new CfgContract(path: '/nonexistent/cfg_contract.json'),
      log: function (string $message) use (&$log): void {
        $log[] = $message;
      }
    );
    $comparison = $comparator->compareToServerObject(curClient: $fixture['data'], oa4mpObject: $serverCopy);
    $verdict = Verdict::fromComparison(comparison: $comparison);

    $this->assertTrue($comparison['error']);
    $this->assertTrue($verdict->checkFailed());
    $this->assertFalse($verdict->isOutOfSync());
    $this->assertNull($verdict->serverExtra);
    $this->assertStringContainsString('cannot be read', (string)$verdict->reason);
    $this->assertStringContainsString('Caught exception during unmarshall', implode("\n", $log));
  }

  /**
   * An error rather than an exception, here a TypeError from the
   * unmarshaller, is a failed check too and never escapes the comparison.
   */
  public function testAnErrorInsideTheComparisonIsACheckFailed(): void
  {
    $contract = CfgContract::shipped();
    $unmarshaller = new class (contract: $contract) extends Unmarshaller {
      /**
       * @param array<string, mixed> $oa4mpObject Ignored.
       * @return array<string, mixed>
       */
      public function unmarshallContent(array $oa4mpObject): array
      {
        throw new TypeError('raised mid-parse');
      }
    };
    $fixture = MarshallerParityTest::fixture(name: 'confidential-minimal');

    $comparator = new Comparator(contract: $contract, unmarshaller: $unmarshaller);
    $verdict = Verdict::fromComparison(
      comparison: $comparator->compareToServerObject(
        curClient: RoundTripTest::savedClient(name: 'confidential-minimal', fixture: $fixture),
        oa4mpObject: RoundTripTest::serverCopy(name: 'confidential-minimal', fixture: $fixture)
      )
    );

    $this->assertTrue($verdict->checkFailed());
    $this->assertStringContainsString('TypeError', (string)$verdict->reason);
  }

  /**
   * A response that did not decode reaches the comparison as null, and that
   * is a failed check, not a client that differs.
   */
  public function testAResponseThatDidNotDecodeIsACheckFailed(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'confidential-minimal');

    $verdict = RoundTripTest::verdict(
      stored: RoundTripTest::savedClient(name: 'confidential-minimal', fixture: $fixture),
      serverCopy: null
    );

    $this->assertTrue($verdict->checkFailed());
  }

  /**
   * An error reported beside a synchronized value is still a failed check,
   * and a synchronized value that is not exactly true is out of sync.
   */
  public function testTheVerdictReadsTheErrorFirstAndFailsClosed(): void
  {
    $this->assertTrue(
      Verdict::fromComparison(comparison: ['synchronized' => true, 'error' => true])->checkFailed()
    );
    $this->assertTrue(
      Verdict::fromComparison(comparison: ['synchronized' => 1, 'error' => false])->isOutOfSync()
    );
    $this->assertTrue(
      Verdict::fromComparison(comparison: ['synchronized' => true, 'error' => false])->isInSync()
    );
  }

  /**
   * The Authorized User Group goes to OA4MP as a number, and the stored id
   * may be text: the two compare equal, both ways round.
   */
  public function testAGroupIdThatIsTextOnOneSideAndANumberOnTheOtherComparesEqual(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'authorization-group');
    $groupId = $fixture['data']['Oa4mpClientAuthorization']['authz_co_group_id'];
    $this->assertNotEmpty($groupId);

    $serverCopy = RoundTripTest::serverCopy(name: 'authorization-group', fixture: $fixture);
    $args = &$serverCopy['cfg']['tokens']['identity']['qdl']['args'];
    $this->assertIsInt($args['authorization_group_id']);

    $stored = $fixture['data'];
    $stored['Oa4mpClientAuthorization']['authz_co_group_id'] = (string)$groupId;
    $this->assertTrue(RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy)->isInSync());

    $args['authorization_group_id'] = (string)$groupId;
    $stored['Oa4mpClientAuthorization']['authz_co_group_id'] = (int)$groupId;
    $this->assertTrue(RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy)->isInSync());

    $args['authorization_group_id'] = (int)$groupId + 1;
    $verdict = RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy);
    $this->assertTrue($verdict->isOutOfSync());
    $this->assertStringContainsString('authz_co_group_id', (string)$verdict->reason);
  }

  /**
   * The two older cfg formats.
   *
   * @return array<string, array<int, string>>
   */
  public static function olderFormatProvider(): array
  {
    return [
      'QDLv2' => ['client-read-qdlv2-cfg.json', Unmarshaller::CFG_FORMAT_QDLV2],
      'deprecated' => ['client-read-deprecated-cfg.json', Unmarshaller::CFG_FORMAT_DEPRECATED],
    ];
  }

  /**
   * A cfg in an older format is not read by this edition, so the client
   * cannot be judged: the check fails and names the format, whatever the
   * rest of the client says.
   */
  #[DataProvider('olderFormatProvider')]
  public function testACfgInAnOlderFormatIsACheckFailedNamingTheFormat(string $file, string $format): void
  {
    $serverCopy = UnmarshallerTest::response(file: $file);
    $stored = $this->storedClientMatching(serverCopy: $serverCopy);

    $verdict = RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy);

    $this->assertTrue($verdict->checkFailed(), "the $format cfg reads as " . $verdict->outcome);
    $this->assertStringContainsString('older ' . $format . ' format', (string)$verdict->reason);
  }

  /**
   * A client that uses a Named configuration is compared without its cfg,
   * as in Registry 4, so a configuration written in an older shape does not
   * fail its check.
   */
  #[DataProvider('olderFormatProvider')]
  public function testANamedConfigurationClientIsNotFailedForItsCfgFormat(string $file, string $format): void
  {
    $serverCopy = UnmarshallerTest::response(file: $file);
    $stored = $this->storedClientMatching(serverCopy: $serverCopy);
    $stored['Oa4mpClientCoOidcClient']['named_config_id'] = 3;
    $stored['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] = [
      [
        'id' => 3,
        'Oa4mpClientCoScope' => [['scope' => 'openid'], ['scope' => 'profile']],
      ],
    ];

    $verdict = RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy);

    $this->assertTrue($verdict->isInSync(), "the $format Named configuration reads as " . $verdict->outcome);
  }

  /**
   * A string '0' in a claim's value field is never sent, so it compares
   * equal to the server's absent value.
   */
  public function testAStringZeroValueFieldIsNotDrift(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'claims-identifier');
    $fixture['data']['Oa4mpClientClaim'][0]['claim_value_string_serialization_delimiter'] = '0';

    $serverCopy = RoundTripTest::serverCopy(name: 'claims-identifier', fixture: $fixture);
    $mapping = $serverCopy['cfg']['tokens']['identity']['qdl']['args']['claim_mappings'][0];
    $this->assertArrayNotHasKey('claim_value_string_serialization_delimiter', $mapping);

    $this->assertTrue(RoundTripTest::verdict(stored: $fixture['data'], serverCopy: $serverCopy)->isInSync());
  }

  /**
   * A half-populated constraint is never sent, and is dropped from the
   * comparison on both sides; the drop is logged without the value.
   */
  public function testAHalfPopulatedConstraintIsNotDrift(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'claims-identifier');
    $fixture['data']['Oa4mpClientClaim'][1]['Oa4mpClientClaimConstraint'][] =
      ['id' => 9, 'claim_id' => 162, 'constraint_field' => 'type', 'constraint_value' => ''];
    $serverCopy = RoundTripTest::serverCopy(name: 'claims-identifier', fixture: $fixture);
    $serverCopy['cfg']['tokens']['identity']['qdl']['args']['claim_mappings'][0]['claim_constraints'] =
      [['constraint_value' => 'a-half-populated-value']];
    $log = [];

    $verdict = RoundTripTest::verdict(stored: $fixture['data'], serverCopy: $serverCopy, log: $log);

    $this->assertTrue($verdict->isInSync(), (string)$verdict->reason);
    $drops = implode("\n", preg_grep('/dropping half-populated constraint/', $log));
    $this->assertStringContainsString('from the plugin-side', $drops);
    $this->assertStringContainsString('from the oa4mp-side', $drops);
    $this->assertStringNotContainsString('a-half-populated-value', $drops);
  }

  /**
   * A claim whose constraint differs on the server is out of sync: the
   * normalization does not hide a real difference.
   */
  public function testADifferentConstraintOnTheServerIsDrift(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'claims-identifier');
    $serverCopy = RoundTripTest::serverCopy(name: 'claims-identifier', fixture: $fixture);
    $serverCopy['cfg']['tokens']['identity']['qdl']['args']['claim_mappings'][1]['claim_constraints'][0]
      ['constraint_value'] = 'official';

    $verdict = RoundTripTest::verdict(stored: $fixture['data'], serverCopy: $serverCopy);

    $this->assertTrue($verdict->isOutOfSync());
    $this->assertSame('Oa4mpClientClaim: Claims are out of sync', $verdict->reason);
  }

  /**
   * A public client is sent no cfg, so a JWT access token or an
   * authorization it stores is not compared; the rest of it still is.
   */
  public function testAPublicClientsCfgOnlySettingsAreNotCompared(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'public-client');
    $stored = $fixture['data'];
    $stored['Oa4mpClientAccessToken'] = ['id' => 4, 'client_id' => 11, 'is_jwt' => true];
    $stored['Oa4mpClientAuthorization'] = [
      'id' => 4,
      'client_id' => 11,
      'authz_co_group_id' => null,
      'require_active' => true,
      'authz_group_redirect_url' => null,
      'require_active_redirect_url' => null,
      'no_record_redirect_url' => null,
    ];
    $serverCopy = RoundTripTest::serverCopy(name: 'public-client', fixture: $fixture);
    $this->assertArrayNotHasKey('cfg', $serverCopy);

    $this->assertTrue(RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy)->isInSync());

    $stored['Oa4mpClientCoOidcClient']['name'] = 'Renamed in the Registry only';
    $this->assertTrue(RoundTripTest::verdict(stored: $stored, serverCopy: $serverCopy)->isOutOfSync());
  }

  /**
   * A stored client with no refresh token lifetime matches a server lifetime
   * of exactly zero, and no other.
   */
  public function testNoStoredRefreshLifetimeMatchesOnlyAServerLifetimeOfZero(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'refresh-tokens-off');
    $this->assertNull($fixture['data']['Oa4mpClientRefreshToken']['token_lifetime']);
    $serverCopy = RoundTripTest::serverCopy(name: 'refresh-tokens-off', fixture: $fixture);

    $serverCopy['rt_lifetime'] = 0;
    $this->assertTrue(RoundTripTest::verdict(stored: $fixture['data'], serverCopy: $serverCopy)->isInSync());

    $serverCopy['rt_lifetime'] = 3600;
    $verdict = RoundTripTest::verdict(stored: $fixture['data'], serverCopy: $serverCopy);
    $this->assertTrue($verdict->isOutOfSync());
    $this->assertStringContainsString('token_lifetime', (string)$verdict->reason);
  }

  /**
   * A stored client that agrees with a server copy on every section the
   * comparison reads before the cfg: a confidential client with the copy's
   * name, callbacks, scopes and contacts, and nothing in the cfg sections.
   *
   * @param array<string, mixed> $serverCopy A decoded server copy.
   * @return array<string, mixed>
   */
  private function storedClientMatching(array $serverCopy): array
  {
    $fixture = MarshallerParityTest::fixture(name: 'confidential-minimal');
    $stored = RoundTripTest::savedClient(name: 'confidential-minimal', fixture: $fixture);

    $stored['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $serverCopy['client_id'];
    $stored['Oa4mpClientCoOidcClient']['name'] = $serverCopy['client_name'];
    $stored['Oa4mpClientCoCallback'] = [];
    foreach ($serverCopy['redirect_uris'] as $url) {
      $stored['Oa4mpClientCoCallback'][] = ['url' => $url];
    }
    $stored['Oa4mpClientCoScope'] = [];
    foreach ($serverCopy['scope'] as $scope) {
      $stored['Oa4mpClientCoScope'][] = ['scope' => $scope];
    }
    $stored['Oa4mpClientCoEmailAddress'] = [];
    foreach ($serverCopy['contacts'] as $mail) {
      $stored['Oa4mpClientCoEmailAddress'][] = ['mail' => $mail];
    }

    return $stored;
  }
}
