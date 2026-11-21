<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP Transport
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
 * Sends one request to the OA4MP server and hands back its answer.
 *
 * ServerClient builds every request and judges every answer; a transport
 * only carries them. CakeHttpTransport is the one that reaches the network.
 * Tests give ServerClient a transport that answers from a script instead, so
 * no test reaches a server.
 *
 * The request is the array Registry 4's oa4mpBuildRequest() built for
 * HttpSocket, so the two editions' requests can be compared key by key:
 *
 *   method  'GET', 'PUT', 'DELETE' or 'POST'.
 *   uri     scheme, host and path, and a query array for every kind but a
 *           create, which carries no query key at all.
 *   header  Content-Type and Authorization. The Authorization value carries
 *           the Admin client's credential: a transport never logs headers.
 *   body    The JSON request body, for a create and an update only.
 */
interface Transport
{
  /**
   * Send a request and return the server's answer, whatever its status.
   *
   * A status that is not a success is still an answer: judging it is the
   * caller's job, not the transport's.
   *
   * @param array<string, mixed> $request The request, in the shape above.
   * @return array{status: int, body: string, contentType: string|null} The
   *         status code, the body, and the Content-Type header or null when
   *         the response carries none.
   * @throws \RuntimeException When no answer arrived: a timeout, a refused
   *         connection, a failed TLS handshake. The message never carries a
   *         request header.
   */
  public function send(array $request): array;
}
