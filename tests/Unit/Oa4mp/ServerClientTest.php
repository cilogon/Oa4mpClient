<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP Server Client Test
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

use InvalidArgumentException;
use LogicException;
use Oa4mpClient\Lib\Oa4mp\CakeHttpTransport;
use Oa4mpClient\Lib\Oa4mp\CfgContract;
use Oa4mpClient\Lib\Oa4mp\ServerClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every call the plugin makes to OA4MP, through a fake transport: the
 * request each one sends, which answers count as success, and what reaches
 * the log.
 *
 * The expected requests are written out in full, in the shape Registry 4's
 * oa4mpBuildRequest() built at 7.0.0, rather than computed by the code
 * under test. The client is the dynamo-per-client Parity fixture, whose
 * cfg carries DynamoDB credentials, so the log checks have something to
 * find.
 */
class ServerClientTest extends TestCase
{
  /**
   * The fixture every test uses.
   */
  private const FIXTURE = 'dynamo-per-client';

  /**
   * The Admin client's credential. The Parity fixtures record none.
   */
  private const ADMIN_SECRET = 'admin-placeholder-credential';

  /**
   * The client secret a create answer carries.
   */
  private const CLIENT_SECRET = 'SECRET-A';

  /**
   * The fixture's OA4MP identifier.
   */
  private const CLIENT_ID = 'cilogon:/client_id/parity-dynamo-per-client';

  /**
   * The Admin client, in the Registry 4 shape, with its credential.
   *
   * @return array<string, mixed>
   */
  private static function adminClient(): array
  {
    $adminClient = MarshallerParityTest::fixture(name: self::FIXTURE)['adminClient'];
    $adminClient['Oa4mpClientCoAdminClient']['secret'] = self::ADMIN_SECRET;

    return $adminClient;
  }

  /**
   * The stored client.
   *
   * @return array<string, mixed>
   */
  private static function client(): array
  {
    return MarshallerParityTest::fixture(name: self::FIXTURE)['data'];
  }

  /**
   * The address of the CO's client list that Registry 4 recorded.
   *
   * @return string
   */
  private static function clientIndexAddress(): string
  {
    return MarshallerParityTest::addresses(name: self::FIXTURE)['client_index_address'];
  }

  /**
   * The answer to a read of the fixture's client, matching what is stored.
   *
   * @param array<string, mixed> $extra Keys the server adds.
   * @return array{status: int, body: string, contentType: string|null}
   */
  private static function inSyncRead(array $extra = []): array
  {
    $fixture = MarshallerParityTest::fixture(name: self::FIXTURE);
    $serverCopy = RoundTripTest::serverCopy(name: self::FIXTURE, fixture: $fixture) + $extra;

    return FakeTransport::answer(status: 200, body: (string)json_encode($serverCopy));
  }

  /**
   * A client over a fake transport, logging into $log.
   *
   * @param FakeTransport $transport The transport.
   * @param array<int, string> $log Collects the lines logged.
   * @return ServerClient
   */
  private static function serverClient(FakeTransport $transport, array &$log = []): ServerClient
  {
    return new ServerClient(
      transport: $transport,
      contract: CfgContract::shipped(),
      log: function (string $message) use (&$log): void {
        $log[] = $message;
      }
    );
  }

  /**
   * The request Registry 4 built for each kind, written out in full.
   *
   * @param string $kind The request kind.
   * @return array<string, mixed>
   */
  private static function expectedRequest(string $kind): array
  {
    $uri = [
      'scheme' => 'https',
      'host' => 'oa4mp.example.org',
      'path' => '/oauth2/oidc-cm',
    ];

    $query = [
      'read' => ['client_id' => self::CLIENT_ID, 'api_version' => 'latest'],
      'update' => ['client_id' => self::CLIENT_ID],
      'delete' => ['client_id' => self::CLIENT_ID],
      'create' => null,
    ][$kind];

    if ($query !== null) {
      $uri['query'] = $query;
    }

    return [
      'method' => ['read' => 'GET', 'update' => 'PUT', 'delete' => 'DELETE', 'create' => 'POST'][$kind],
      'uri' => $uri,
      'header' => [
        'Content-Type' => 'application/json; charset=UTF-8',
        'Authorization' => 'Bearer ' . base64_encode('admin-client-parity:' . self::ADMIN_SECRET),
      ],
    ];
  }

  /**
   * Values that must never reach the log.
   *
   * @return array<int, string>
   */
  private static function secrets(): array
  {
    $dynamo = self::client()['Oa4mpClientDynamoConfig'];

    return [
      self::ADMIN_SECRET,
      base64_encode('admin-client-parity:' . self::ADMIN_SECRET),
      self::CLIENT_SECRET,
      $dynamo['aws_secret_access_key'],
      $dynamo['aws_access_key_id'],
    ];
  }

  /**
   * Assert that no logged line carries a secret.
   *
   * @param array<int, string> $log The lines logged.
   * @return void
   */
  private function assertLogCarriesNoSecret(array $log): void
  {
    $this->assertNotEmpty($log, 'nothing was logged, so the check proves nothing');

    foreach ($log as $line) {
      foreach (self::secrets() as $secret) {
        $this->assertStringNotContainsString($secret, $line);
      }
    }
  }

  /**
   * One data set per request kind.
   *
   * @return array<string, array<int, string>>
   */
  public static function kindProvider(): array
  {
    return [
      'read' => ['read'],
      'update' => ['update'],
      'delete' => ['delete'],
      'create' => ['create'],
    ];
  }

  /**
   * Each kind builds the method, address, query and headers Registry 4
   * built: only a read carries api_version=latest, and a create carries no
   * query key at all.
   */
  #[DataProvider('kindProvider')]
  public function testEachKindBuildsTheRequestRegistry4Built(string $kind): void
  {
    $request = ServerClient::buildRequest(
      kind: $kind,
      adminClient: self::adminClient(),
      clientIdentifier: $kind === 'create' ? null : self::CLIENT_ID
    );

    $this->assertSame(self::expectedRequest(kind: $kind), $request);
  }

  /**
   * An unrecognised kind raises, from both builders, naming the kind.
   */
  public function testAnUnknownKindRaises(): void
  {
    foreach (['query', 'request'] as $builder) {
      try {
        if ($builder === 'query') {
          ServerClient::requestQuery(kind: 'sideways', clientIdentifier: self::CLIENT_ID);
        } else {
          ServerClient::buildRequest(kind: 'sideways', adminClient: self::adminClient());
        }
        $this->fail("the $builder builder accepted an unknown kind");
      } catch (InvalidArgumentException $e) {
        $this->assertStringContainsString('sideways', $e->getMessage());
      }
    }
  }

  /**
   * The address is rendered as HttpSocket rendered it, and a create's has
   * no query string.
   */
  public function testTheAddressIsRenderedAsHttpSocketRenderedIt(): void
  {
    $this->assertSame(
      'https://oa4mp.example.org/oauth2/oidc-cm?client_id=cilogon%3A%2Fclient_id%2Fparity-dynamo-per-client'
        . '&api_version=latest',
      ServerClient::requestUrl(uri: self::expectedRequest(kind: 'read')['uri'])
    );
    $this->assertSame(
      'https://oa4mp.example.org/oauth2/oidc-cm',
      ServerClient::requestUrl(uri: self::expectedRequest(kind: 'create')['uri'])
    );
  }

  /**
   * A create sends a POST whose body is byte for byte the body Registry 4
   * recorded for the same client.
   */
  public function testACreateSendsThePostRegistry4Sent(): void
  {
    $transport = new FakeTransport(
      FakeTransport::answer(status: 201, body: '{"client_id":"' . self::CLIENT_ID . '"}')
    );

    self::serverClient(transport: $transport)->newClient(
      adminClient: self::adminClient(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $expected = self::expectedRequest(kind: 'create');
    $expected['body'] = file_get_contents(
      MarshallerParityTest::parityDir() . '/expected/' . self::FIXTURE . '/request-body.json'
    );

    $this->assertSame([$expected], $transport->requests);
  }

  /**
   * A read sends a GET carrying api_version=latest, and no body.
   */
  public function testAReadSendsTheGetWithTheNewestRepresentation(): void
  {
    $transport = new FakeTransport(self::inSyncRead());

    $verdict = self::serverClient(transport: $transport)->verifyClient(
      adminClient: self::adminClient(),
      curClient: self::client()
    );

    $this->assertTrue($verdict->isInSync(), (string)$verdict->reason);
    $this->assertSame([self::expectedRequest(kind: 'read')], $transport->requests);
  }

  /**
   * An update reads first, then sends a PUT carrying the edited client.
   */
  public function testAnUpdateReadsThenSendsThePut(): void
  {
    $edited = self::client();
    $edited['Oa4mpClientCoOidcClient']['name'] = 'Renamed client';
    $transport = new FakeTransport(self::inSyncRead(), FakeTransport::answer(status: 200, body: '{}'));

    $result = self::serverClient(transport: $transport)->editClient(
      adminClient: self::adminClient(),
      curData: self::client(),
      data: $edited,
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(ServerClient::EDIT_OK, $result);
    $this->assertCount(2, $transport->requests);
    $this->assertSame(self::expectedRequest(kind: 'read'), $transport->requests[0]);

    $expected = self::expectedRequest(kind: 'update');
    $expected['body'] = json_encode(MarshallerParityTest::marshal(name: self::FIXTURE, fixture: [
      'adminClient' => self::adminClient(),
      'data' => $edited,
    ]));
    $this->assertSame($expected, $transport->requests[1]);
    $this->assertStringContainsString('"client_name":"Renamed client"', (string)$expected['body']);
  }

  /**
   * A delete sends a DELETE with no body.
   */
  public function testADeleteSendsTheDelete(): void
  {
    $transport = new FakeTransport(FakeTransport::answer(status: 204));

    $deleted = self::serverClient(transport: $transport)->deleteClient(
      adminClient: self::adminClient(),
      oidcClient: self::client()
    );

    $this->assertTrue($deleted);
    $this->assertSame([self::expectedRequest(kind: 'delete')], $transport->requests);
  }

  /**
   * Both success statuses a create may be answered with.
   *
   * @return array<string, array<int, int>>
   */
  public static function createSuccessProvider(): array
  {
    return ['200' => [200], '201' => [201]];
  }

  /**
   * A create answered with 200 or 201 returns the client ID and secret.
   */
  #[DataProvider('createSuccessProvider')]
  public function testACreateAnsweredWithSuccessReturnsTheIdAndSecret(int $status): void
  {
    $transport = new FakeTransport(FakeTransport::answer(
      status: $status,
      body: '{"client_id":"' . self::CLIENT_ID . '","client_secret":"' . self::CLIENT_SECRET
        . '","client_id_issued_at":1787484004,"client_secret_expires_at":0}'
    ));
    $log = [];

    $created = self::serverClient(transport: $transport, log: $log)->newClient(
      adminClient: self::adminClient(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(['clientId' => self::CLIENT_ID, 'secret' => self::CLIENT_SECRET], $created);
    $this->assertLogCarriesNoSecret(log: $log);
  }

  /**
   * Statuses that are not one of the two successes a create counts.
   *
   * @return array<string, array<int, int>>
   */
  public static function createFailureProvider(): array
  {
    return [
      'status 202' => [202],
      'status 204' => [204],
      'status 400' => [400],
      'status 500' => [500],
    ];
  }

  /**
   * A create answered with any other status is a failure, even when the
   * answer names a client.
   */
  #[DataProvider('createFailureProvider')]
  public function testACreateAnsweredWithAnotherStatusIsAFailure(int $status): void
  {
    $transport = new FakeTransport(FakeTransport::answer(
      status: $status,
      body: '{"client_id":"' . self::CLIENT_ID . '","client_secret":"' . self::CLIENT_SECRET . '"}'
    ));

    $created = self::serverClient(transport: $transport)->newClient(
      adminClient: self::adminClient(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame([], $created);
  }

  /**
   * A public client is issued no secret, and the result carries none.
   */
  public function testACreateAnsweredWithoutASecretReturnsTheIdAlone(): void
  {
    $transport = new FakeTransport(FakeTransport::answer(status: 201, body: '{"client_id":"' . self::CLIENT_ID . '"}'));

    $created = self::serverClient(transport: $transport)->newClient(
      adminClient: self::adminClient(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(['clientId' => self::CLIENT_ID], $created);
  }

  /**
   * A success that names no client is not a created client.
   */
  public function testACreateAnsweredWithoutAClientIdIsAFailure(): void
  {
    $transport = new FakeTransport(FakeTransport::answer(status: 201, body: '{"error":"nope"}'));

    $created = self::serverClient(transport: $transport)->newClient(
      adminClient: self::adminClient(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame([], $created);
  }

  /**
   * A create answered in ISO-8859-1 still yields the identifier of the
   * client the server created.
   */
  public function testACreateAnsweredInLatin1IsDecoded(): void
  {
    $body = mb_convert_encoding(
      '{"client_id":"' . self::CLIENT_ID . '","client_name":"Caf' . "\u{e9}" . '"}',
      'ISO-8859-1',
      'UTF-8'
    );
    $transport = new FakeTransport(
      FakeTransport::answer(status: 201, body: $body, contentType: 'application/json;charset=ISO-8859-1')
    );

    $created = self::serverClient(transport: $transport)->newClient(
      adminClient: self::adminClient(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(['clientId' => self::CLIENT_ID], $created);
  }

  /**
   * Statuses that are not the one success an update counts.
   *
   * @return array<string, array<int, int>>
   */
  public static function updateFailureProvider(): array
  {
    return [
      '201' => [201],
      '202' => [202],
      '204' => [204],
      '400' => [400],
      '409' => [409],
      '500' => [500],
    ];
  }

  /**
   * An update answered with anything but 200 is a failure.
   */
  #[DataProvider('updateFailureProvider')]
  public function testAnUpdateAnsweredWithAnythingBut200Fails(int $status): void
  {
    $transport = new FakeTransport(self::inSyncRead(), FakeTransport::answer(status: $status, body: '{}'));

    $result = self::serverClient(transport: $transport)->editClient(
      adminClient: self::adminClient(),
      curData: self::client(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(ServerClient::EDIT_FAILED, $result);
    $this->assertCount(2, $transport->requests);
  }

  /**
   * A client changed on the server is reported as such, and no update is
   * sent over it.
   */
  public function testAnUpdateOfAClientChangedOnTheServerIsNotSent(): void
  {
    $fixture = MarshallerParityTest::fixture(name: self::FIXTURE);
    $serverCopy = RoundTripTest::serverCopy(name: self::FIXTURE, fixture: $fixture);
    $serverCopy['redirect_uris'][0] = 'https://elsewhere.example.org/callback';
    $transport = new FakeTransport(FakeTransport::answer(status: 200, body: (string)json_encode($serverCopy)));

    $result = self::serverClient(transport: $transport)->editClient(
      adminClient: self::adminClient(),
      curData: self::client(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(ServerClient::EDIT_OUT_OF_SYNC, $result);
    $this->assertCount(1, $transport->requests);
  }

  /**
   * A check that could not complete is a failed edit, never a client
   * changed outside the Registry, and no update is sent.
   */
  public function testAnUpdateWhoseCheckFailsIsNotSent(): void
  {
    $transport = new FakeTransport(
      FakeTransport::answer(status: 500, body: 'Internal Server Error', contentType: null)
    );

    $result = self::serverClient(transport: $transport)->editClient(
      adminClient: self::adminClient(),
      curData: self::client(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(ServerClient::EDIT_FAILED, $result);
    $this->assertCount(1, $transport->requests);
  }

  /**
   * Settings the server holds that the plugin does not model are sent back
   * with the update.
   */
  public function testAnUpdateSendsBackTheServerExtras(): void
  {
    $transport = new FakeTransport(
      self::inSyncRead(extra: ['rt_grace_period' => -1]),
      FakeTransport::answer(status: 200, body: '{}')
    );

    $result = self::serverClient(transport: $transport)->editClient(
      adminClient: self::adminClient(),
      curData: self::client(),
      data: self::client(),
      clientIndexAddress: self::clientIndexAddress()
    );

    $this->assertSame(ServerClient::EDIT_OK, $result);
    $body = json_decode((string)$transport->requests[1]['body'], true);
    $this->assertSame(-1, $body['rt_grace_period']);
  }

  /**
   * Statuses that are not the one success a delete counts.
   *
   * @return array<string, array<int, int>>
   */
  public static function deleteFailureProvider(): array
  {
    return [
      '200' => [200],
      '202' => [202],
      '404' => [404],
      '500' => [500],
    ];
  }

  /**
   * A delete answered with anything but 204 is a failure.
   */
  #[DataProvider('deleteFailureProvider')]
  public function testADeleteAnsweredWithAnythingBut204Fails(int $status): void
  {
    $transport = new FakeTransport(FakeTransport::answer(status: $status, body: '{}'));

    $deleted = self::serverClient(transport: $transport)->deleteClient(
      adminClient: self::adminClient(),
      oidcClient: self::client()
    );

    $this->assertFalse($deleted);
  }

  /**
   * Every call, answered with a 401, a 500, or not at all.
   *
   * @return array<string, array<int, mixed>>
   */
  public static function errorProvider(): array
  {
    $answers = [
      '401' => FakeTransport::answer(status: 401, body: '{"error":"invalid_client"}'),
      '500' => FakeTransport::answer(status: 500, body: 'Internal Server Error', contentType: 'text/html'),
      'timeout' => FakeTransport::timeout(),
    ];

    $sets = [];

    foreach (['create', 'read', 'update', 'delete'] as $operation) {
      foreach ($answers as $name => $answer) {
        $sets["$operation answered $name"] = [$operation, $answer];
      }
    }

    return $sets;
  }

  /**
   * A 401, a 500 and a timeout each return an error result, and the log
   * carries no secret: not the Admin client's credential, not the bearer
   * token built from it, and not the DynamoDB credentials in the body.
   *
   * @param string $operation The call.
   * @param array<string, mixed>|\Throwable $answer The answer to it.
   */
  #[DataProvider('errorProvider')]
  public function testAnErrorAnswerIsAnErrorResultAndLogsNoSecret(string $operation, array|Throwable $answer): void
  {
    $log = [];

    switch ($operation) {
      case 'create':
        $transport = new FakeTransport($answer);
        $result = self::serverClient(transport: $transport, log: $log)->newClient(
          adminClient: self::adminClient(),
          data: self::client(),
          clientIndexAddress: self::clientIndexAddress()
        );
        $this->assertSame([], $result);
        break;
      case 'read':
        $transport = new FakeTransport($answer);
        $verdict = self::serverClient(transport: $transport, log: $log)->verifyClient(
          adminClient: self::adminClient(),
          curClient: self::client()
        );
        $this->assertTrue($verdict->checkFailed(), 'reported as ' . $verdict->outcome);
        break;
      case 'update':
        $transport = new FakeTransport(self::inSyncRead(), $answer);
        $result = self::serverClient(transport: $transport, log: $log)->editClient(
          adminClient: self::adminClient(),
          curData: self::client(),
          data: self::client(),
          clientIndexAddress: self::clientIndexAddress()
        );
        $this->assertSame(ServerClient::EDIT_FAILED, $result);
        $this->assertCount(2, $transport->requests);
        break;
      default:
        $transport = new FakeTransport($answer);
        $deleted = self::serverClient(transport: $transport, log: $log)->deleteClient(
          adminClient: self::adminClient(),
          oidcClient: self::client()
        );
        $this->assertFalse($deleted);
    }

    $this->assertLogCarriesNoSecret(log: $log);
  }

  /**
   * The real transport cannot be constructed while a test suite runs, so a
   * test that forgets the fake fails instead of reaching a server.
   */
  public function testTheRealTransportRefusesToBeBuiltUnderTest(): void
  {
    $this->assertNotFalse(getenv(CakeHttpTransport::TEST_SUITE_VARIABLE));

    $this->expectException(LogicException::class);
    $this->expectExceptionMessage(CakeHttpTransport::TEST_SUITE_VARIABLE);

    new CakeHttpTransport();
  }

  /**
   * The refusal follows the test-suite variable, and nothing else, so the
   * transport is not refused in a deployment.
   */
  public function testTheRefusalFollowsTheTestSuiteVariable(): void
  {
    $name = CakeHttpTransport::TEST_SUITE_VARIABLE;
    $saved = getenv($name);

    try {
      putenv($name);
      $this->assertFalse(CakeHttpTransport::underTest());

      putenv("$name=");
      $this->assertFalse(CakeHttpTransport::underTest());

      putenv("$name=unit");
      $this->assertTrue(CakeHttpTransport::underTest());
    } finally {
      putenv($saved === false ? $name : "$name=$saved");
    }
  }
}
