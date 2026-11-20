<?php
/**
 * COmanage Registry Oa4mpClient Plugin Schema Declaration Test
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

namespace Oa4mpClient\Test\TestCase\Schema;

use Cake\TestSuite\TestCase;

/**
 * Checks the schema in config/plugin.json the way Registry will read it.
 *
 * Columns declared as {} inherit their definition, foreign key included,
 * from the column library in Registry's config/schema/schema.json, so the
 * checks resolve each column against that library first.
 *
 * Each check has a control below that feeds it a broken schema, to show the
 * check fires rather than passing on everything.
 */
class PluginSchemaTest extends TestCase
{
  /**
   * PHP's JSON decoder keeps the last of two equal keys and says nothing, so
   * a table (or column, or index) declared twice silently loses one
   * definition. Registry decodes plugin.json the same way.
   */
  public function testNoKeyIsDeclaredTwice(): void
  {
    $this->assertSame([], $this->duplicateKeys($this->pluginJson()));
  }

  /**
   * Every table a foreign key names is declared earlier in the file (or is
   * the table itself), or is a Registry core table.
   *
   * Registry creates every table of one schema before adding any foreign
   * key, so the order is not needed to apply the schema today. It keeps the
   * file readable from the top down, and it catches a foreign key to a table
   * no schema declares, which Registry would only find at activation.
   */
  public function testReferencedTablesAreDeclaredFirst(): void
  {
    $this->assertSame([], $this->referenceProblems($this->pluginTables()));
  }

  /**
   * Registry refuses a schema in which a foreign key column has no explicit
   * named index: the database layer would generate an unnamed one. Only a
   * non-unique index on that column alone stands in for the generated one.
   */
  public function testEveryForeignKeyHasANamedIndex(): void
  {
    $this->assertSame([], $this->unindexedForeignKeys($this->pluginTables()));
  }

  /**
   * Control: a table declared twice, and a column declared twice inside
   * another table, are both reported.
   */
  public function testDuplicateCheckFindsARepeatedTableAndColumn(): void
  {
    $json = '{"schema": {"tables": {'
      . '"a": {"columns": {"id": {}, "x": {}, "x": {}}},'
      . '"b": {"columns": {"id": {}}},'
      . '"a": {"columns": {"id": {}}}'
      . '}}}';

    $this->assertSame(
      ['schema.tables.a.columns.x', 'schema.tables.a'],
      $this->duplicateKeys($json)
    );
  }

  /**
   * Control: a reference to a plugin table declared later, and to a table
   * nobody declares, are both reported; a core table and the table itself
   * are not.
   */
  public function testReferenceCheckFindsALaterAndAnUnknownTable(): void
  {
    $tables = [
      'a' => ['columns' => [
        'id' => [],
        'server_id' => [],
        'a_id' => $this->foreignKeyTo('a'),
        'b_id' => $this->foreignKeyTo('b'),
        'nowhere_id' => $this->foreignKeyTo('nowhere'),
      ]],
      'b' => ['columns' => ['id' => []]],
    ];

    $this->assertSame(
      [
        'a.b_id references b, which is declared after it',
        'a.nowhere_id references nowhere, which neither the plugin nor '
        . 'Registry declares',
      ],
      $this->referenceProblems($tables)
    );
  }

  /**
   * Control: a foreign key inherited from the column library and one
   * declared in place are both reported when unindexed, including when the
   * only index is unique or spans more columns.
   */
  public function testIndexCheckFindsUnindexedForeignKeys(): void
  {
    $tables = [
      'a' => [
        'columns' => [
          'id' => [],
          'server_id' => [],
          'b_id' => $this->foreignKeyTo('b'),
          'c_id' => $this->foreignKeyTo('c'),
          'd_id' => $this->foreignKeyTo('d'),
        ],
        'indexes' => [
          'a_i1' => ['columns' => ['b_id', 'server_id']],
          'a_i2' => ['columns' => ['c_id'], 'unique' => true],
          'a_i3' => ['columns' => ['d_id']],
        ],
      ],
    ];

    $this->assertSame(
      ['a.server_id', 'a.b_id', 'a.c_id'],
      $this->unindexedForeignKeys($tables)
    );
  }

  /**
   * Find keys that appear twice in the same JSON object.
   *
   * @param string $json JSON text
   * @return array<int, string> Dotted path of each repeated key
   */
  private function duplicateKeys(string $json): array
  {
    preg_match_all(
      '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,"]+/',
      $json,
      $matches
    );
    $tokens = $matches[0];

    $duplicates = [];
    // One frame per open object or array: its path, the keys seen so far
    // (objects only) and the key whose value is being read.
    $frames = [];

    foreach ($tokens as $i => $token) {
      $top = count($frames) - 1;

      if ($token === '{' || $token === '[') {
        $path = $top < 0 ? '' : $frames[$top]['path'];

        if ($top >= 0 && $frames[$top]['current'] !== null) {
          $path = ltrim($path . '.' . $frames[$top]['current'], '.');
        }

        $frames[] = ['path' => $path, 'keys' => [], 'current' => null];
      } elseif ($token === '}' || $token === ']') {
        array_pop($frames);
      } elseif (
        $token[0] === '"'
        && ($tokens[$i + 1] ?? null) === ':'
        && $top >= 0
      ) {
        $key = json_decode($token);

        if (in_array($key, $frames[$top]['keys'], true)) {
          $duplicates[] = ltrim($frames[$top]['path'] . '.' . $key, '.');
        }

        $frames[$top]['keys'][] = $key;
        $frames[$top]['current'] = $key;
      }
    }

    return $duplicates;
  }

  /**
   * Find foreign keys to tables that are declared later or not at all.
   *
   * @param array $tables Plugin tables, in declaration order
   * @return array<int, string> One message per problem
   */
  private function referenceProblems(array $tables): array
  {
    $coreTables = array_keys($this->registrySchema()['tables']);
    $declared = [];
    $problems = [];

    foreach ($tables as $name => $table) {
      foreach ($table['columns'] as $column => $config) {
        $target = $this->foreignKey($column, $config)['table'] ?? null;

        if ($target === null || $target === $name || in_array($target, $declared, true)) {
          continue;
        }

        if (array_key_exists($target, $tables)) {
          $problems[] = "$name.$column references $target, which is declared after it";
        } elseif (!in_array($target, $coreTables, true)) {
          $problems[] = "$name.$column references $target, which neither the "
            . 'plugin nor Registry declares';
        }
      }

      $declared[] = $name;
    }

    return $problems;
  }

  /**
   * Find foreign key columns with no non-unique named index on that column
   * alone.
   *
   * @param array $tables Plugin tables
   * @return array<int, string> table.column of each unindexed foreign key
   */
  private function unindexedForeignKeys(array $tables): array
  {
    $unindexed = [];

    foreach ($tables as $name => $table) {
      foreach ($table['columns'] as $column => $config) {
        if ($this->foreignKey($column, $config) === null) {
          continue;
        }

        $indexed = false;

        foreach ($table['indexes'] ?? [] as $index) {
          if (empty($index['unique']) && ($index['columns'] ?? []) === [$column]) {
            $indexed = true;
          }
        }

        if (!$indexed) {
          $unindexed[] = "$name.$column";
        }
      }
    }

    return $unindexed;
  }

  /**
   * A column's foreign key, after inheriting from the column library the way
   * Registry's schema manager does.
   *
   * @param string $column Column name
   * @param array  $config Column definition from plugin.json
   * @return array|null    The foreignkey definition, or null if none
   */
  private function foreignKey(string $column, array $config): ?array
  {
    $library = $this->registrySchema()['columnLibrary']['columns'];

    return array_merge($library[$column] ?? [], $config)['foreignkey'] ?? null;
  }

  /**
   * A column definition with a foreign key to a table's id.
   *
   * @param string $table Referenced table
   * @return array        Column definition
   */
  private function foreignKeyTo(string $table): array
  {
    return [
      'type' => 'integer',
      'foreignkey' => ['table' => $table, 'column' => 'id'],
    ];
  }

  /**
   * @return string The text of config/plugin.json
   */
  private function pluginJson(): string
  {
    $json = file_get_contents(dirname(__DIR__, 3) . '/config/plugin.json');
    $this->assertIsString($json, 'cannot read config/plugin.json');

    return $json;
  }

  /**
   * @return array The tables config/plugin.json declares, in order
   */
  private function pluginTables(): array
  {
    $plugin = json_decode($this->pluginJson(), true, flags: JSON_THROW_ON_ERROR);

    return $plugin['schema']['tables'];
  }

  /**
   * @return array Registry's core schema, column library included
   */
  private function registrySchema(): array
  {
    static $schema = null;

    $schema ??= json_decode(
      file_get_contents(ROOT . DS . 'config' . DS . 'schema' . DS . 'schema.json'),
      true,
      flags: JSON_THROW_ON_ERROR
    );

    return $schema;
  }
}
