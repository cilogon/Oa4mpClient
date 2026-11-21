<?php
/**
 * COmanage Registry Oa4mpClient Plugin Client State Predicates
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
 * Questions about a client that the marshaller and the client pages both
 * ask, answered once so what is sent and what is shown cannot drift apart.
 *
 * Ported from the client-state predicates of Model/Oa4mpClientOa4mpServer.php
 * in the Registry 4 edition. Each takes client data in that edition's array
 * shape, keyed by model name. A missing association there reads as an array
 * of null-valued fields, so each predicate tests the value it names, never
 * the presence of the association.
 */
class ClientState
{
  /**
   * The effective DynamoDB configuration for a client: its own row when that
   * row is real, otherwise the admin client's DefaultDynamoConfig.
   *
   * aws_region is required on any real row, so its presence tells a real
   * per-client row from the null-valued one a missing row reads as. The
   * marshaller and the comparator must resolve the configuration the same
   * way, or a client without its own row is sent the default and compared
   * against the nulls.
   *
   * @param array<string, mixed> $data Client data including
   *        Oa4mpClientDynamoConfig and
   *        Oa4mpClientCoAdminClient.DefaultDynamoConfig.
   * @return array<string, mixed> The effective configuration, or [] if none.
   */
  public static function resolveDynamoConfig(array $data): array
  {
    if (!empty($data['Oa4mpClientDynamoConfig']['aws_region'])) {
      return $data['Oa4mpClientDynamoConfig'];
    }

    return $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'] ?? [];
  }

  /**
   * Whether client data describes a public client. Posted form data carries
   * '0' or '1'; stored data carries a boolean.
   *
   * @param array<string, mixed> $data Client data including
   *        Oa4mpClientCoOidcClient.
   * @return bool
   */
  public static function isPublicClient(array $data): bool
  {
    return !empty($data['Oa4mpClientCoOidcClient']['public_client']);
  }

  /**
   * Whether client data requires an active CO Person record (Require Active
   * Status).
   *
   * @param array<string, mixed> $data Client data including
   *        Oa4mpClientAuthorization.
   * @return bool
   */
  public static function requiresActiveStatus(array $data): bool
  {
    return !empty($data['Oa4mpClientAuthorization']['require_active']);
  }

  /**
   * Whether client data describes a client that uses a Named configuration.
   * While it holds, the client's own claims, scopes and authorization
   * settings are not sent.
   *
   * @param array<string, mixed> $data Client data including
   *        Oa4mpClientCoOidcClient.
   * @return bool
   */
  public static function usesNamedConfig(array $data): bool
  {
    return !empty($data['Oa4mpClientCoOidcClient']['named_config_id']);
  }
}
