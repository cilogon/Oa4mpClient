<?php
/**
 * COmanage Registry Oa4mpClient Plugin Fast Suite Isolation Test
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

namespace Oa4mpClient\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The fast suite tests framework-free code. It must run without the Registry
 * application, so a test that quietly leans on Registry configuration or a
 * database fails here instead of passing by accident.
 */
class BootstrapIsolationTest extends TestCase
{
  /**
   * The fast suite runs without Registry's application bootstrap, which is
   * what defines CONFIG and loads the database configuration.
   */
  public function testRegistryApplicationIsNotBootstrapped(): void
  {
    $this->assertFalse(
      defined('CONFIG'),
      'the fast suite loaded the Registry application; it must run '
      . 'framework-free, with OA4MP_TEST_SUITE=unit'
    );
  }
}
