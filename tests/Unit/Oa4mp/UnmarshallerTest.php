<?php
/**
 * COmanage Registry Oa4mpClient Plugin Unmarshaller Test
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
use Oa4mpClient\Lib\Oa4mp\Unmarshaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading the OA4MP server's copy of a client back into the plugin's shape.
 *
 * data/client-read-api-version-latest.json is a client read with
 * api_version=latest, carried from the Registry 4 edition's test fixtures:
 * it holds settings the plugin does not model, a list-valued scope, and a
 * cfg whose keys the server has sorted. The two other files hold a cfg in
 * each older format this edition does not read.
 */
class UnmarshallerTest extends TestCase
{
  /**
   * A server response from tests/Unit/Oa4mp/data, decoded.
   *
   * @param string $file File name.
   * @return array<string, mixed>
   */
  public static function response(string $file): array
  {
    $text = file_get_contents(__DIR__ . '/data/' . $file);

    return json_decode((string)$text, true, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * An unmarshaller with the shipped contract.
   *
   * @return \Oa4mpClient\Lib\Oa4mp\Unmarshaller
   */
  private function unmarshaller(): Unmarshaller
  {
    return new Unmarshaller(contract: CfgContract::shipped());
  }

  /**
   * A field the plugin does not model is kept in the extras value, to go
   * back to the server on the next update; a field the plugin models, or the
   * server owns, is not. The client secret rides the extras value, as in
   * Registry 4.
   */
  public function testAFieldThePluginDoesNotKnowIsKeptInTheExtras(): void
  {
    $response = self::response(file: 'client-read-api-version-latest.json');
    $response['client_secret'] = 'a-client-secret-value';
    $response['registration_access_token'] = 'a-registration-token';

    $client = $this->unmarshaller()->unmarshallContent(oa4mpObject: $response);
    $extras = json_decode($client['Oa4mpClientCoOidcClient']['oa4mp_server_extra'], true);

    $this->assertSame(-1, $extras['rt_grace_period']);
    $this->assertSame(-1, $extras['max_rt_lifetime']);
    $this->assertTrue($extras['strict_scopes']);
    $this->assertSame('a-client-secret-value', $extras['client_secret']);
    foreach (Unmarshaller::KNOWN_KEYS as $key) {
      $this->assertArrayNotHasKey($key, $extras, "$key is modelled or the server's own");
    }
  }

  /**
   * The cfg's claim mappings come back under the association the comparator
   * reads, with the fields the contract declares, whatever order the server
   * keeps the keys in; the DynamoDB settings come back under the plugin's
   * column names.
   */
  public function testTheCurrentCfgFormatIsReadBack(): void
  {
    $client = $this->unmarshaller()->unmarshallContent(
      oa4mpObject: self::response(file: 'client-read-api-version-latest.json')
    );

    $this->assertSame(
      [
        [
          'claim_name' => 'groups',
          'source_model' => 'CoGroupMember',
          'source_model_claim_value_field' => 'member',
          'claim_value_selection' => 'all',
          'claim_value_json_format' => 'string',
          'claim_multiple_value_serialization' => 'delimited_string',
          'claim_value_string_serialization_delimiter' => ',',
          'Oa4mpClientClaimConstraint' => [
            ['constraint_field' => 'owner', 'constraint_value' => 'false'],
          ],
        ],
      ],
      $client['Oa4mpClientClaim']
    );
    $this->assertSame('us-east-2', $client['Oa4mpClientDynamoConfig']['aws_region']);
    $this->assertSame('sub', $client['Oa4mpClientDynamoConfig']['partition_key_claim_name']);
    $this->assertSame(1800, $client['Oa4mpClientRefreshToken']['token_lifetime']);
    $this->assertCount(4, $client['Oa4mpClientCoScope']);
    $this->assertArrayNotHasKey(Unmarshaller::OLDER_CFG_FORMAT_KEY, $client);
  }

  /**
   * Public-client cases: the authentication method and the scopes.
   *
   * @return array<string, array<int, mixed>>
   */
  public static function publicClientProvider(): array
  {
    return [
      'none, openid only, as a list' => ['none', ['openid'], true],
      'none, openid only, as a string' => ['none', 'openid', true],
      'none, openid and profile' => ['none', 'openid profile', false],
      'none, profile only' => ['none', 'profile', false],
      'client_secret_basic, openid only' => ['client_secret_basic', 'openid', false],
    ];
  }

  /**
   * A client is read as public if and only if its authentication method is
   * none and its one scope is openid.
   */
  #[DataProvider('publicClientProvider')]
  public function testAClientIsPublicOnlyWithMethodNoneAndTheOpenidScopeAlone(
    string $method,
    array|string $scope,
    bool $public
  ): void {
    $response = [
      'client_id' => 'cilogon:/client_id/public',
      'client_name' => 'Public',
      'token_endpoint_auth_method' => $method,
      'scope' => $scope,
      'redirect_uris' => ['https://app.example.org/callback'],
    ];

    $client = $this->unmarshaller()->unmarshallContent(oa4mpObject: $response);

    $this->assertSame($public, $client['Oa4mpClientCoOidcClient']['public_client']);
  }

  /**
   * A response declared as ISO-8859-1 is transcoded before it is decoded, so
   * a client name with an accented character survives, and the decoded copy
   * compares in sync with the stored client.
   */
  public function testAResponseDeclaredAsLatin1IsDecodedBeforeComparison(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'confidential-minimal');
    $name = "Andr\u{00E9} Test Client";
    $fixture['data']['Oa4mpClientCoOidcClient']['name'] = $name;

    $serverCopy = RoundTripTest::serverCopy(name: 'confidential-minimal', fixture: $fixture);
    $latin1 = mb_convert_encoding(
      (string)json_encode($serverCopy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'ISO-8859-1',
      'UTF-8'
    );

    // Without a byte above 0x7F this test passes against the defect it
    // exists to catch.
    $this->assertSame(1, preg_match('/[\x80-\xFF]/', $latin1));

    $decoded = Unmarshaller::decodeServerResponse(
      body: $latin1,
      contentType: 'application/json; charset=ISO-8859-1'
    );

    $this->assertIsArray($decoded);
    $this->assertSame($name, $decoded['client_name']);
    $stored = RoundTripTest::savedClient(name: 'confidential-minimal', fixture: $fixture);
    $this->assertTrue(RoundTripTest::verdict(stored: $stored, serverCopy: $decoded)->isInSync());
  }

  /**
   * A UTF-8 body, or one with no declared type, is decoded as it is, and a
   * body that is not JSON decodes to null without raising.
   */
  public function testOtherResponsesAreDecodedAsTheyAre(): void
  {
    $body = (string)json_encode(['client_name' => "Andr\u{00E9}"], JSON_UNESCAPED_UNICODE);

    $this->assertSame(
      "Andr\u{00E9}",
      Unmarshaller::decodeServerResponse(body: $body, contentType: 'application/json;charset=UTF-8')['client_name']
    );
    $this->assertSame(
      "Andr\u{00E9}",
      Unmarshaller::decodeServerResponse(body: $body, contentType: null)['client_name']
    );
    $this->assertNull(Unmarshaller::decodeServerResponse(body: '<html>', contentType: 'text/html'));
  }

  /**
   * A cfg in an older format is named in the result and not translated into
   * claims.
   */
  public function testAnOlderCfgFormatIsNamedAndNotRead(): void
  {
    $qdlv2 = $this->unmarshaller()->unmarshallContent(oa4mpObject: self::response(file: 'client-read-qdlv2-cfg.json'));
    $deprecated = $this->unmarshaller()->unmarshallContent(
      oa4mpObject: self::response(file: 'client-read-deprecated-cfg.json')
    );

    $this->assertSame(Unmarshaller::CFG_FORMAT_QDLV2, $qdlv2[Unmarshaller::OLDER_CFG_FORMAT_KEY]);
    $this->assertSame([], $qdlv2['Oa4mpClientClaim']);
    $this->assertSame(Unmarshaller::CFG_FORMAT_DEPRECATED, $deprecated[Unmarshaller::OLDER_CFG_FORMAT_KEY]);
    $this->assertSame([], $deprecated['Oa4mpClientClaim']);
  }

  /**
   * A Named configuration's cfg is neither the current format's claims nor
   * an older format: its args are read without a warning and no format is
   * named.
   */
  public function testANamedConfigurationsCfgIsNotAnOlderFormat(): void
  {
    $fixture = MarshallerParityTest::fixture(name: 'named-config');
    $serverCopy = RoundTripTest::serverCopy(name: 'named-config', fixture: $fixture);

    $client = $this->unmarshaller()->unmarshallContent(oa4mpObject: $serverCopy);

    $this->assertArrayNotHasKey(Unmarshaller::OLDER_CFG_FORMAT_KEY, $client);
    $this->assertSame([], $client['Oa4mpClientClaim']);
  }
}
