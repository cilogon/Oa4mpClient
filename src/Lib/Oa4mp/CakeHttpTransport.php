<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP HTTP Transport
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

use Cake\Http\Client;
use Cake\Http\Client\Request;
use Exception;
use LogicException;
use RuntimeException;

/**
 * The transport that reaches the OA4MP server, over CakePHP's HTTP client.
 *
 * The only part of the OA4MP code that depends on CakePHP. It refuses to be
 * constructed while a test suite runs, so a test that forgets to pass a fake
 * transport fails at once instead of contacting a real server.
 */
final class CakeHttpTransport implements Transport
{
  /**
   * The environment variable that marks a test run. tests/run.sh sets it
   * for both suites, and tests/bootstrap.php refuses to run without it, so
   * it is set whenever a test runs and never in a deployment.
   */
  public const TEST_SUITE_VARIABLE = 'OA4MP_TEST_SUITE';

  /**
   * @param int $timeout Seconds to wait for an answer.
   * @throws \LogicException While a test suite runs.
   */
  public function __construct(private int $timeout = 30)
  {
    if (self::underTest()) {
      throw new LogicException(
        'The OA4MP HTTP transport is not available while a test suite runs ('
        . self::TEST_SUITE_VARIABLE . ' is set). Give ServerClient a fake transport.'
      );
    }
  }

  /**
   * Whether a test suite is running.
   *
   * @return bool
   */
  public static function underTest(): bool
  {
    $suite = getenv(self::TEST_SUITE_VARIABLE);

    return $suite !== false && $suite !== '';
  }

  /**
   * @inheritDoc
   */
  public function send(array $request): array
  {
    // Built as Registry's HttpServersTable::createHttpClient() builds its
    // client, with the request handed over whole so the address, headers and
    // body are exactly the ones ServerClient built.
    $client = new Client(config: ['timeout' => $this->timeout]);

    $httpRequest = new Request(
      url: ServerClient::requestUrl(uri: $request['uri']),
      method: $request['method'],
      headers: $request['header'] ?? [],
      data: $request['body'] ?? null
    );

    try {
      $response = $client->sendRequest(request: $httpRequest);
    } catch (Exception $e) {
      // Not chained: CakePHP's request exceptions hold the request, and the
      // request holds the Authorization header.
      throw new RuntimeException(get_class($e) . ': ' . $e->getMessage());
    }

    $contentType = $response->getHeaderLine('Content-Type');

    return [
      'status' => $response->getStatusCode(),
      'body' => $response->getStringBody(),
      'contentType' => $contentType === '' ? null : $contentType,
    ];
  }
}
