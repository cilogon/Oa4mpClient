<?php
/**
 * COmanage Registry Oa4mpClient Plugin Fake OA4MP Transport
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

use LogicException;
use Oa4mpClient\Lib\Oa4mp\Transport;
use RuntimeException;
use Throwable;

/**
 * A transport that answers from a script and records every request it is
 * given, so a test sees exactly what ServerClient would have sent and no
 * test reaches a server.
 *
 * Each answer is used once, in order. An answer that is a Throwable is
 * thrown instead of returned, which is how a timeout looks. A request with
 * no answer left fails the test rather than inventing one.
 */
final class FakeTransport implements Transport
{
  /**
   * Every request sent, in order.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $requests = [];

  /**
   * The answers still to give.
   *
   * @var array<int, array<string, mixed>|\Throwable>
   */
  private array $answers;

  /**
   * @param array<string, mixed>|\Throwable ...$answers The answers, in order.
   */
  public function __construct(array|Throwable ...$answers)
  {
    $this->answers = $answers;
  }

  /**
   * An answer with a status and a body.
   *
   * @param int $status The status code.
   * @param string $body The body.
   * @param string|null $contentType The Content-Type header, or null.
   * @return array{status: int, body: string, contentType: string|null}
   */
  public static function answer(
    int $status,
    string $body = '',
    ?string $contentType = 'application/json;charset=UTF-8'
  ): array {
    return ['status' => $status, 'body' => $body, 'contentType' => $contentType];
  }

  /**
   * What a transport throws when the server does not answer in time.
   *
   * @return \RuntimeException
   */
  public static function timeout(): RuntimeException
  {
    return new RuntimeException('Operation timed out after 30001 milliseconds with 0 bytes received');
  }

  /**
   * @inheritDoc
   */
  public function send(array $request): array
  {
    $this->requests[] = $request;

    if (empty($this->answers)) {
      throw new LogicException(
        'FakeTransport has no answer left for request ' . count($this->requests)
        . ' (' . ($request['method'] ?? '?') . ')'
      );
    }

    $answer = array_shift($this->answers);

    if ($answer instanceof Throwable) {
      throw $answer;
    }

    return $answer;
  }
}
