<?php
/**
 * COmanage Registry Oa4mpClient Plugin Activation Test
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

namespace Oa4mpClient\Test\TestCase;

use App\Lib\Enum\SuspendableStatusEnum;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * tests/run.sh sets up Registry on an empty test database and activates the
 * plugin with Registry's own plugin command before this suite runs. These
 * tests check the result, since a schema step can report success without
 * having created anything.
 */
class ActivationTest extends TestCase
{
  /**
   * Registry knows the plugin from the checkout under test, mounted under
   * local/plugins, and has activated it.
   */
  public function testPluginIsActiveFromTheLocalDirectory(): void
  {
    $plugin = $this->getTableLocator()
      ->get('Plugins')
      ->find()
      ->where(['plugin' => 'Oa4mpClient'])
      ->first();

    $this->assertNotNull($plugin, 'Registry has no record of the plugin');
    $this->assertSame('local', $plugin->location);
    $this->assertSame(SuspendableStatusEnum::Active, $plugin->status);
  }

  /**
   * Every table config/plugin.json declares exists after activation, with
   * every declared column and named index.
   */
  public function testEveryDeclaredTableExists(): void
  {
    $json = file_get_contents(dirname(__DIR__, 2) . '/config/plugin.json');
    $tables = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['schema']['tables'];

    $this->assertNotEmpty($tables, 'config/plugin.json declares no tables');

    $collection = ConnectionManager::get('test')->getSchemaCollection();
    $existing = $collection->listTables();

    foreach ($tables as $name => $table) {
      $this->assertContains($name, $existing, "table $name was not created");

      $schema = $collection->describe($name);

      foreach (array_keys($table['columns']) as $column) {
        $this->assertContains(
          $column,
          $schema->columns(),
          "column $name.$column was not created"
        );
      }

      foreach ($table['indexes'] ?? [] as $index => $config) {
        $this->assertContains(
          $index,
          empty($config['unique']) ? $schema->indexes() : $schema->constraints(),
          "index $index on $name was not created"
        );
      }
    }
  }
}
