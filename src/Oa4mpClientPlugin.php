<?php
/**
 * COmanage Registry Oa4mpClient Plugin
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

namespace Oa4mpClient;

use Cake\Core\BasePlugin;
use Cake\Routing\RouteBuilder;

/**
 * Manages OIDC clients on an OA4MP server from COmanage Registry.
 */
class Oa4mpClientPlugin extends BasePlugin
{
  /**
   * Add routes for the plugin.
   *
   * @param RouteBuilder $routes The route builder to update
   */
  public function routes(RouteBuilder $routes): void
  {
    $routes->plugin(
      'Oa4mpClient',
      ['path' => '/oa4mp-client'],
      function (RouteBuilder $builder) {
        $builder->fallbacks();
      }
    );

    parent::routes($routes);
  }
}
