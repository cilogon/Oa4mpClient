<?php
/**
 * COmanage Registry Oa4mpClient Plugin OA4MP Server Client
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

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Makes every call the plugin makes to the OA4MP server: create, read
 * (Sync verification), update and delete a client.
 *
 * Ported from oa4mpNewClient(), oa4mpVerifyClient(), oa4mpEditClient(),
 * oa4mpDeleteClient(), oa4mpBuildRequest(), oa4mpRequestQuery() and
 * oa4mpInitializeRequest() in Model/Oa4mpClientOa4mpServer.php of the
 * Registry 4 edition at 7.0.0. The requests are built in that edition's
 * array shape and judged by its rules: which kinds carry api_version=latest,
 * and which status codes count as success. The request body comes from the
 * Marshaller, and a read is judged by the Comparator.
 *
 * Registry 4 sent through an HttpSocket it constructed inline. Here the
 * Transport is passed in, so tests replace it and never reach the network.
 *
 * The Admin client is an input, in the Registry 4 shape: an array whose
 * Oa4mpClientCoAdminClient entry carries serverurl, admin_identifier and
 * secret. Nothing this class logs carries that secret: headers are never
 * logged, and bodies are masked by the Redactor.
 *
 * This class makes the server call only. Trimming and validating before it,
 * saving locally after it, and keeping it outside any database transaction
 * are the caller's.
 */
class ServerClient
{
  /**
   * editClient(): the server did not accept the update, or the check before
   * it could not complete.
   */
  public const EDIT_FAILED = 0;

  /**
   * editClient(): the server accepted the update.
   */
  public const EDIT_OK = 1;

  /**
   * editClient(): the server's copy differs from the stored client, so the
   * client was changed outside the Registry and the update was not sent.
   */
  public const EDIT_OUT_OF_SYNC = 2;

  /**
   * The HTTP method of each kind of request.
   */
  public const METHODS = [
    'read' => 'GET',
    'update' => 'PUT',
    'delete' => 'DELETE',
    'create' => 'POST',
  ];

  /**
   * Where log lines go, or null.
   */
  private ?Closure $log;

  /**
   * Masks credentials in the lines this class logs.
   */
  private Redactor $redactor;

  /**
   * Builds the request body.
   */
  private Marshaller $marshaller;

  /**
   * Judges the server's copy of a client against the stored one.
   */
  private Comparator $comparator;

  /**
   * @param Transport $transport Carries each request to the server.
   * @param CfgContract $contract The cfg capability contract.
   * @param callable|null $log Called with one message string for each line
   *                           the Registry 4 edition logged.
   * @param Marshaller|null $marshaller Builds request bodies; one with the
   *        same contract and log when null.
   * @param Comparator|null $comparator Judges reads; one with the same
   *        contract and log when null.
   */
  public function __construct(
    private Transport $transport,
    CfgContract $contract,
    ?callable $log = null,
    ?Marshaller $marshaller = null,
    ?Comparator $comparator = null
  ) {
    $this->log = $log === null ? null : Closure::fromCallable($log);
    $this->redactor = new Redactor(contract: $contract, log: $log);
    $this->marshaller = $marshaller ?? new Marshaller(contract: $contract, log: $log);
    $this->comparator = $comparator ?? new Comparator(contract: $contract, log: $log);
  }

  /**
   * Build the complete request for one kind of OA4MP server request. Callers
   * add a body where the kind has one; everything else is settled here.
   *
   * @param string $kind One of 'read', 'update', 'delete', 'create'.
   * @param array<string, mixed> $adminClient The Admin client, in the
   *        Registry 4 shape.
   * @param string|null $clientIdentifier The OA4MP client_id, or null for
   *        'create'.
   * @return array<string, mixed> The request, as Transport describes it.
   * @throws \InvalidArgumentException On an unrecognised request kind.
   */
  public static function buildRequest(string $kind, array $adminClient, ?string $clientIdentifier = null): array
  {
    if (!array_key_exists($kind, self::METHODS)) {
      throw new InvalidArgumentException("Unknown OA4MP request kind '" . $kind . "'");
    }

    $request = self::initializeRequest(adminClient: $adminClient);
    $request['method'] = self::METHODS[$kind];

    $query = self::requestQuery(kind: $kind, clientIdentifier: $clientIdentifier);

    // An empty query is left off the request entirely rather than assigned
    // as an empty array, so the create request carries no query key at all.
    if (!empty($query)) {
      $request['uri']['query'] = $query;
    }

    return $request;
  }

  /**
   * Build the URI query for one kind of OA4MP server request.
   *
   * Only the read carries api_version. The server answers a read without it
   * with an older representation that omits rt_grace_period and several
   * sibling settings, so they never reach the extras value and are dropped
   * from the next update. The update deliberately does not carry it: whether
   * the server needs it there was never established, and this is the one
   * place that changes if it does.
   *
   * @param string $kind One of 'read', 'update', 'delete', 'create'.
   * @param string|null $clientIdentifier The OA4MP client_id, or null for
   *        'create'.
   * @return array<string, string|null> The query; empty for a create.
   * @throws \InvalidArgumentException On an unrecognised request kind.
   */
  public static function requestQuery(string $kind, ?string $clientIdentifier = null): array
  {
    switch ($kind) {
      case 'read':
        return ['client_id' => $clientIdentifier, 'api_version' => 'latest'];
      case 'update':
      case 'delete':
        return ['client_id' => $clientIdentifier];
      case 'create':
        return [];
      default:
        throw new InvalidArgumentException("Unknown OA4MP request kind '" . $kind . "'");
    }
  }

  /**
   * The parts every request shares: the Admin client's server address and
   * its credential.
   *
   * Only the scheme, host and path of the server URL are kept, as Registry
   * 4 kept them for HttpSocket.
   *
   * @param array<string, mixed> $adminClient The Admin client, in the
   *        Registry 4 shape.
   * @return array<string, mixed> The request with method GET and no query.
   */
  public static function initializeRequest(array $adminClient): array
  {
    $request = [];
    $request['method'] = 'GET';

    $parsedUrl = parse_url((string)$adminClient['Oa4mpClientCoAdminClient']['serverurl']);
    $request['uri']['scheme'] = $parsedUrl['scheme'] ?? null;
    $request['uri']['host'] = $parsedUrl['host'] ?? null;
    $request['uri']['path'] = $parsedUrl['path'] ?? null;

    $request['header']['Content-Type'] = 'application/json; charset=UTF-8';

    $aclientId = $adminClient['Oa4mpClientCoAdminClient']['admin_identifier'];
    $aclientSecret = $adminClient['Oa4mpClientCoAdminClient']['secret'];
    $bearerToken = base64_encode($aclientId . ":" . $aclientSecret);

    $request['header']['Authorization'] = "Bearer $bearerToken";

    return $request;
  }

  /**
   * The address a request is sent to, rendered as HttpSocket rendered it:
   * the query encoded by http_build_query() with '&' between pairs.
   *
   * @param array<string, mixed> $uri The request's uri entry.
   * @return string
   */
  public static function requestUrl(array $uri): string
  {
    $url = $uri['scheme'] . '://' . $uri['host'] . ($uri['path'] ?? '');

    if (!empty($uri['query'])) {
      $url .= '?' . http_build_query($uri['query'], '', '&');
    }

    return $url;
  }

  /**
   * Ask the server to create a client.
   *
   * During OA4MP server evolution both 200 and 201 count as success.
   *
   * @param array<string, mixed> $adminClient The Admin client, in the
   *        Registry 4 shape.
   * @param array<string, mixed> $data Client data, in the Registry 4 shape.
   * @param string $clientIndexAddress The address of the CO's OIDC client
   *        list, written into the comment the server stores.
   * @param string|null $namedConfigAddress The address of the Named
   *        configuration's page, when the client uses one.
   * @return array<string, string> 'clientId', and 'secret' when the server
   *         issued one (a public client has none); empty when the server did
   *         not create the client or its answer names none.
   */
  public function newClient(
    array $adminClient,
    array $data,
    string $clientIndexAddress,
    ?string $namedConfigAddress = null
  ): array {
    $ret = [];

    $request = self::buildRequest(kind: 'create', adminClient: $adminClient);

    $body = $this->marshaller->marshallContent(
      adminClient: $adminClient,
      data: $data,
      clientIndexAddress: $clientIndexAddress,
      namedConfigAddress: $namedConfigAddress
    );

    $request['body'] = json_encode($body);

    $response = $this->send(request: $request);

    if ($response === null) {
      $this->log(
        message: "No answer to the create request: the OA4MP server may have created a client"
          . " that the Registry does not record"
      );

      return $ret;
    }

    if ($response['status'] == 200 || $response['status'] == 201) {
      // Decoded as a read is, honouring a Latin-1 Content-Type: a body that
      // decodes to null here would lose the identifier of a client the
      // server has already created.
      $decoded = Unmarshaller::decodeServerResponse(
        body: $response['body'],
        contentType: $response['contentType']
      );

      if (!is_array($decoded) || empty($decoded['client_id']) || !is_string($decoded['client_id'])) {
        $this->log(
          message: "The OA4MP server answered the create request with status " . $response['status']
            . " and no client_id"
        );

        return $ret;
      }

      $ret['clientId'] = $decoded['client_id'];

      if (!empty($decoded['client_secret'])) {
        $ret['secret'] = $decoded['client_secret'];
      }
    }

    return $ret;
  }

  /**
   * Read the server's copy of a client and judge it against the stored one
   * (Sync verification).
   *
   * Only an answer of 200 is a client representation. Any other answer, or
   * none, is a check that failed, never a client changed outside the
   * Registry.
   *
   * @param array<string, mixed> $adminClient The Admin client, in the
   *        Registry 4 shape.
   * @param array<string, mixed> $curClient The stored client, as loaded from
   *        the database, in the Registry 4 shape.
   * @return Verdict
   */
  public function verifyClient(array $adminClient, array $curClient): Verdict
  {
    $clientId = $curClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request = self::buildRequest(kind: 'read', adminClient: $adminClient, clientIdentifier: $clientId);

    $response = $this->send(request: $request);

    if ($response === null) {
      return Verdict::fromComparison(
        comparison: ['error' => true, 'reason' => 'The OA4MP server did not answer the client read']
      );
    }

    if ($response['status'] != 200) {
      return Verdict::fromComparison(
        comparison: [
          'error' => true,
          'reason' => 'The OA4MP server answered the client read with status ' . $response['status'],
        ]
      );
    }

    $oa4mpObject = Unmarshaller::decodeServerResponse(
      body: $response['body'],
      contentType: $response['contentType']
    );

    return Verdict::fromComparison(
      comparison: $this->comparator->compareToServerObject(curClient: $curClient, oa4mpObject: $oa4mpObject)
    );
  }

  /**
   * Update a client on the server, after checking that the server's copy
   * still matches the stored client.
   *
   * Settings the server holds that the plugin does not model come back from
   * the check in the extras value and are sent back with the update, so
   * they are not lost. Only an answer of 200 counts as success.
   *
   * @param array<string, mixed> $adminClient The Admin client, in the
   *        Registry 4 shape.
   * @param array<string, mixed> $curData The stored client, as loaded from
   *        the database, in the Registry 4 shape.
   * @param array<string, mixed> $data The client as edited, in the Registry
   *        4 shape.
   * @param string $clientIndexAddress The address of the CO's OIDC client
   *        list, written into the comment the server stores.
   * @param string|null $namedConfigAddress The address of the Named
   *        configuration's page, when the client uses one.
   * @return int EDIT_OK, EDIT_FAILED or EDIT_OUT_OF_SYNC.
   */
  public function editClient(
    array $adminClient,
    array $curData,
    array $data,
    string $clientIndexAddress,
    ?string $namedConfigAddress = null
  ): int {
    $verdict = $this->verifyClient(adminClient: $adminClient, curClient: $curData);

    // A check that did not complete is not a tampering verdict: the page for
    // a client changed outside the Registry sends the user to support, when
    // the cause is the server or the deployment.
    if ($verdict->checkFailed()) {
      return self::EDIT_FAILED;
    }

    if (!$verdict->isInSync()) {
      return self::EDIT_OUT_OF_SYNC;
    }

    if (!empty($verdict->serverExtra)) {
      $data['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $verdict->serverExtra;
    }

    $clientId = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request = self::buildRequest(kind: 'update', adminClient: $adminClient, clientIdentifier: $clientId);

    $body = $this->marshaller->marshallContent(
      adminClient: $adminClient,
      data: $data,
      clientIndexAddress: $clientIndexAddress,
      namedConfigAddress: $namedConfigAddress
    );

    $request['body'] = json_encode($body);

    $response = $this->send(request: $request);

    if ($response !== null && $response['status'] == 200) {
      return self::EDIT_OK;
    }

    return self::EDIT_FAILED;
  }

  /**
   * Delete a client from the server. Only an answer of 204 counts as
   * success.
   *
   * @param array<string, mixed> $adminClient The Admin client, in the
   *        Registry 4 shape.
   * @param array<string, mixed> $oidcClient The stored client, in the
   *        Registry 4 shape.
   * @return bool Whether the server deleted the client.
   */
  public function deleteClient(array $adminClient, array $oidcClient): bool
  {
    $clientId = $oidcClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request = self::buildRequest(kind: 'delete', adminClient: $adminClient, clientIdentifier: $clientId);

    $response = $this->send(request: $request);

    return $response !== null && $response['status'] == 204;
  }

  /**
   * Send a request through the transport, logging it and the answer.
   *
   * Headers are never logged: the Authorization header carries the Admin
   * client's credential. Bodies are logged as JSON through the Redactor,
   * never print_r, which the Redactor cannot see into.
   *
   * @param array<string, mixed> $request The request.
   * @return array{status: int, body: string, contentType: string|null}|null
   *         The answer, or null when none arrived.
   */
  private function send(array $request): ?array
  {
    $this->log(message: "Request URI is " . self::requestUrl(uri: $request['uri']));
    $this->log(message: "Request method is " . $request['method']);
    $this->log(
      message: "Request body is "
        . $this->redactor->redactSecretsInLogText(text: (string)($request['body'] ?? ''))
    );

    try {
      $response = $this->transport->send(request: $request);
    } catch (RuntimeException $e) {
      $this->log(
        message: "No answer from the OA4MP server: " . get_class($e) . " - "
          . $this->redactor->redactSecretsInLogText(text: $e->getMessage())
      );

      return null;
    }

    $this->log(
      message: "Response is status " . $response['status'] . ", body "
        . $this->redactor->redactSecretsInLogText(text: $response['body'])
    );

    return $response;
  }

  /**
   * Send one line to the log, if there is one.
   *
   * @param string $message The line.
   * @return void
   */
  private function log(string $message): void
  {
    if ($this->log !== null) {
      ($this->log)($message);
    }
  }
}
