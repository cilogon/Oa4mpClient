<?php
/**
 * COmanage Registry Oa4mpClient Plugin Scope Enum
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

namespace Oa4mpClient\Lib\Enum;

/**
 * The OIDC scopes the plugin offers, as Oa4mpClientScopeEnum in the Registry 4
 * edition's Lib/enum.php declares them. Framework-free, so the marshaller can
 * use it without the Registry application. The constant names follow PSR-1
 * rather than the Registry 4 enum's camel case; the values are unchanged.
 */
class ScopeEnum
{
  public const OPENID = 'openid';
  public const PROFILE = 'profile';
  public const EMAIL = 'email';
  public const ORG_CILOGON_USERINFO = 'org.cilogon.userinfo';
  public const GETCERT = 'edu.uiuc.ncsa.myproxy.getcert';

  /**
   * Every scope above, in declaration order.
   *
   * @var array<int, string>
   */
  public const ALL = [
    self::OPENID,
    self::PROFILE,
    self::EMAIL,
    self::ORG_CILOGON_USERINFO,
    self::GETCERT,
  ];
}
