<?php
/**
 * Config/Schema/schema.xml declares each table once, after the tables it
 * references.
 *
 * A second copy of seven tables (oa4mp_client_dynamo_configs through
 * oa4mp_client_access_controls) was appended to the file. On a fresh database
 * `cake database` issued a second CREATE TABLE for each and warned "Possibly
 * failed to update database schema" while still returning success, which
 * Test/README.md filed as a known wrinkle rather than a defect. Every later
 * column change also had to be made twice, and a change made to only one copy
 * would have left two disagreeing declarations of the same table. See
 * cilogon/Oa4mpClient#44.
 *
 * Order is checked alongside uniqueness because `cake database` creates the
 * tables in file order, and a REFERENCES constraint naming a plugin table not
 * yet created fails on a fresh install.
 *
 * The positive controls follow the convention Test/Case/Model/ClaimCfgDriftTest
 * established: they perturb the schema text the checks derive from, so a check
 * that has stopped reading the file goes red instead of passing vacuously.
 *
 * No database and no credential are needed: everything here is file text.
 */

class SchemaDeclarationTest extends Oa4mpTestCase {

  /** The prefix Registry puts on every table name in schema.xml. */
  const TABLE_PREFIX = 'cm_';

  /** The plugin's own tables; a reference outside them is to Registry. */
  const PLUGIN_TABLE_PREFIX = 'oa4mp_client_';

  private $schemaSource;

  public function setUp() {
    $path = App::pluginPath('Oa4mpClient') . 'Config' . DS . 'Schema' . DS . 'schema.xml';
    $this->assertTrue(is_readable($path), "the schema exists at $path");

    $this->schemaSource = preg_replace('~<!--.*?-->~s', '', file_get_contents($path));
  }

  public function testEachTableIsDeclaredOnce() {
    $this->assertNotEmpty($this->tables($this->schemaSource),
      'schema.xml declares the tables this check is derived from');

    $this->assertEqual(array(), $this->duplicateTables($this->schemaSource),
      'each table is declared once in schema.xml; a second declaration has to be'
      . ' kept in step with the first by hand');
  }

  public function testEachReferencedPluginTableIsDeclaredEarlier() {
    $this->assertEqual(array(), $this->misorderedReferences($this->schemaSource),
      'every plugin table a REFERENCES constraint names is declared before the'
      . ' table that references it, so a fresh `cake database` can create it');
  }

  public function testDuplicateCheckDetectsARepeatedTable() {
    $repeated = $this->firstTableBlock('oa4mp_client_claims');
    $perturbed = str_replace('</schema>', $repeated . "\n</schema>", $this->schemaSource);

    $this->assertEqual(array('oa4mp_client_claims'), $this->duplicateTables($perturbed),
      'a second declaration of oa4mp_client_claims is reported');
  }

  public function testOrderCheckDetectsATableDeclaredAfterItsReferrer() {
    // Move oa4mp_client_claims to the end, behind
    // oa4mp_client_claim_constraints, which references it.
    $claims = $this->firstTableBlock('oa4mp_client_claims');
    $perturbed = str_replace($claims, '', $this->schemaSource);
    $perturbed = str_replace('</schema>', $claims . "\n</schema>", $perturbed);

    $this->assertEqual(array('oa4mp_client_claim_constraints -> oa4mp_client_claims'),
      $this->misorderedReferences($perturbed),
      'a referenced table declared after its referrer is reported');
  }

  // ------------------------------------------------------------------
  // Derivations from the schema text.
  // ------------------------------------------------------------------

  /** Each table's name and body, in file order. */
  private function tables($source) {
    preg_match_all('~<table\s+name="([^"]+)"[^>]*>(.*?)</table>~s', $source, $matches,
      PREG_SET_ORDER);

    $tables = array();
    foreach ($matches as $match) {
      $tables[] = array('name' => $match[1], 'body' => $match[2]);
    }

    return $tables;
  }

  /** Table names declared more than once. */
  private function duplicateTables($source) {
    $counts = array_count_values(array_column($this->tables($source), 'name'));

    return array_keys(array_filter($counts, function ($count) {
      return $count > 1;
    }));
  }

  /** "referrer -> referenced" for each plugin table referenced before it is declared. */
  private function misorderedReferences($source) {
    $declared = array();
    $misordered = array();

    foreach ($this->tables($source) as $table) {
      preg_match_all('~REFERENCES\s+' . self::TABLE_PREFIX . '(\w+)\s*\(~',
        $table['body'], $references);

      foreach ($references[1] as $referenced) {
        if (strpos($referenced, self::PLUGIN_TABLE_PREFIX) === 0
            && !isset($declared[$referenced])) {
          $misordered[] = $table['name'] . ' -> ' . $referenced;
        }
      }

      $declared[$table['name']] = true;
    }

    return $misordered;
  }

  /** The full text of the first declaration of $name. */
  private function firstTableBlock($name) {
    $found = preg_match('~<table\s+name="' . preg_quote($name, '~') . '"[^>]*>.*?</table>~s',
      $this->schemaSource, $match);
    $this->assertTrue($found === 1, "schema.xml declares $name");

    return $match[0];
  }
}
