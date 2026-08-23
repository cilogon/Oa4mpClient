<?php
/**
 * The capability contract declares what it says it declares (U1).
 *
 * cfg_contract.json is the authority on the vocabulary this plugin may emit
 * into a client's cfg: the QDL arg keys, the dynamo_module_config keys, the
 * claim-mapping and constraint field names, the source_model values, and the
 * constraint_field values. Several enforcement sites read it -- the
 * marshaller's allowlist, the unmarshaller and comparator field lists, the
 * cfg-side half of the log-redaction name list, and the QDL conformance check
 * -- and every one of them is only as good as the file itself.
 *
 * So the file is checked against the shipping sources it claims to mirror,
 * never against a copy of the vocabulary kept here. The source_model values
 * are diffed against the option list the claims tab actually builds, and the
 * claim-mapping field names against the columns Config/Schema/schema.xml
 * actually declares -- both derived from that text at run time. A hand-written
 * expectation in this file would be maintained by the same person editing the
 * contract, so it would agree by construction and report green while the
 * contract drifted away from the plugin.
 *
 * The positive controls follow the convention Test/Case/Model/ClaimCfgDriftTest
 * established: they perturb the DERIVATION SOURCE -- the view text, the
 * contract document -- and not the comparison's inputs. A probe injected
 * downstream of the derivation passes even when the derivation has gone stale,
 * which is the failure a drift check exists to prevent.
 *
 * No database and no credential are needed: everything here is file text.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U1.
 */

class ContractDeclarationTest extends Oa4mpTestCase {

  /** The one claim-mapping field the marshaller synthesises rather than reads
      from a column. It is declared last and is exempt from the column
      correspondence; see KTD5 and the contract's own note on the group. */
  const SYNTHESISED_MAPPING_FIELD = 'claim_constraints';

  /** Columns of cm_oa4mp_client_claims that never reach a cfg. */
  private $nonEmittedColumns = array('id', 'client_id', 'created', 'modified');

  /** Derivation inputs. Re-read for every test method. */
  private $contractText = null;
  private $viewSource = null;
  private $schemaSource = null;

  public function setUp() {
    $this->contractText = $this->source('cfg_contract.json');
    $this->viewSource = $this->stripComments(
      $this->source('View' . DS . 'Oa4mpClientClaims' . DS . 'fields.inc'));
    $this->schemaSource = $this->stripComments(
      $this->source('Config' . DS . 'Schema' . DS . 'schema.xml'));
  }

  // ------------------------------------------------------------------
  // Scenario 1: the document parses and carries a version.
  // ------------------------------------------------------------------

  /**
   * The contract is readable JSON carrying a version and a non-empty
   * capability set.
   *
   * Everything downstream loads this file, so an unparseable or versionless
   * contract has to be a loud failure here rather than an empty array that
   * every consumer quietly treats as "nothing is declared" -- which is exactly
   * the shape an allowlist reads as "emit nothing" and a redaction list reads
   * as "redact nothing".
   */
  public function testContractParsesAndCarriesAVersion() {
    $contract = json_decode($this->contractText, true);

    $this->assertTrue(is_array($contract),
      'cfg_contract.json must parse as JSON: ' . json_last_error_msg());

    $this->assertTrue(isset($contract['contract_version']),
      'the contract carries a version, which is what a cfg is stamped with and'
      . ' what a retirement floor is expressed in');
    $this->assertTrue(is_int($contract['contract_version']),
      'the version is an integer so that "advances" and "at or above" are'
      . ' unambiguous comparisons, not string collation');
    $this->assertTrue($contract['contract_version'] > 0,
      'the version starts above zero, so an unstamped cfg (no version at all)'
      . ' is never mistaken for one built at the first contract');

    $this->assertNotEmpty($contract['capabilities'] ?? array(),
      'the contract declares at least one capability group');

    foreach ($contract['capabilities'] as $group => $block) {
      $this->assertNotEmpty($block['entries'] ?? array(),
        "capability group $group declares at least one entry; an empty group"
        . ' reads to every consumer as "this plugin emits nothing here"');
      $this->assertNotEmpty($block['description'] ?? '',
        "capability group $group says what it governs, since the file is read"
        . ' by people deciding whether a new capability belongs in it');
    }

    // The groups the enforcement sites named in this file's header look up by
    // name. A group renamed without its readers moving is a silent empty read.
    foreach (array('qdl_args', 'dynamo_module_config_keys', 'claim_mapping_fields',
                   'claim_constraint_fields', 'source_model_values',
                   'constraint_field_values') as $group) {
      $this->assertTrue(isset($contract['capabilities'][$group]),
        "the contract declares the $group group, which is looked up by name");
    }
  }

  // ------------------------------------------------------------------
  // Scenario 2: source_model values against the claims tab.
  // ------------------------------------------------------------------

  /**
   * The declared source_model values and the claim sources the claims tab
   * offers are the same set, in both directions.
   *
   * A source the tab offers but the contract omits is a value the plugin can
   * put in a cfg with nothing having declared it, so the QDL conformance check
   * never asks whether the deployed script handles it. A source the contract
   * declares but the tab no longer offers is a capability held open against
   * nothing, which the retirement policy exists to close deliberately rather
   * than by accident.
   */
  public function testDeclaredSourceModelsMatchTheClaimsTabSelectOptions() {
    $offered = $this->viewSelectOptions($this->viewSource, 'source_model');
    $this->assertNotEmpty($offered,
      'the claims tab builds the source_model option list this comparison is'
      . ' derived from; without it the check below would compare against'
      . ' nothing and pass');

    $report = $this->sourceModelReport($this->contractText, $this->viewSource);
    $this->assertEqual('', $report,
      'the contract\'s source_model values and the claim sources the claims'
      . ' tab offers must be the same set');

    // Positive control, view side: a source removed from the option list the
    // derivation reads must be reported as declared-but-not-offered.
    $probe = 'SshKey';
    $perturbedView = $this->viewWithoutOption($this->viewSource, $probe);
    $this->assertFalse($perturbedView === $this->viewSource,
      'the control must actually alter the view text it perturbs');
    $this->assertFalse(
      in_array($probe, $this->viewSelectOptions($perturbedView, 'source_model'), true),
      'and the derivation itself must see the option go, not just the report');

    $viewControl = $this->sourceModelReport($this->contractText, $perturbedView);
    $this->assertContains($probe, $viewControl,
      'a source_model the contract declares but the claims tab no longer'
      . ' offers must be reported, and named');

    // Positive control, contract side: an entry removed from the contract
    // document must be reported as offered-but-not-declared.
    $perturbedContract = $this->contractWithoutEntry($this->contractText, $probe);
    $this->assertFalse($perturbedContract === $this->contractText,
      'the control must actually alter the contract text it perturbs');
    $this->assertTrue(is_array(json_decode($perturbedContract, true)),
      'and must leave it parseable, or the control would be testing a broken'
      . ' file rather than a missing entry');

    $contractControl = $this->sourceModelReport($perturbedContract, $this->viewSource);
    $this->assertContains($probe, $contractControl,
      'a claim source the tab offers but the contract does not declare must be'
      . ' reported, and named');

    // And the unperturbed tree stays clean, so the controls are the difference.
    $this->assertEqual('',
      $this->sourceModelReport($this->contractText, $this->viewSource),
      'the controls, not a pre-existing mismatch, are what turned the report red');
  }

  // ------------------------------------------------------------------
  // Scenario 3: claim-mapping fields against the claim table.
  // ------------------------------------------------------------------

  /**
   * Every column-backed claim-mapping field is a column on
   * cm_oa4mp_client_claims, in the order schema.xml declares them, and
   * claim_constraints is declared last.
   *
   * Order is load-bearing rather than cosmetic: the marshaller builds a
   * mapping from this list, so the list's order is the order of keys in an
   * emitted mapping, and every golden cfg in ClaimCfgContractTest is written
   * in it. claim_constraints has no column at all -- the marshaller
   * synthesises it from the claim's constraint rows and appends it after the
   * columns -- which is why it is exempt from the correspondence and why the
   * contract marks it column_backed false rather than leaving a reader to
   * infer the exception.
   */
  public function testClaimMappingFieldsMatchTheClaimColumnsInOrder() {
    $entries = $this->group($this->contractText, 'claim_mapping_fields');
    $this->assertNotEmpty($entries, 'the contract declares claim-mapping fields');

    $columns = $this->emittedClaimColumns($this->schemaSource);
    $this->assertNotEmpty($columns,
      'schema.xml declares the claim columns this comparison is derived from');

    // claim_constraints is last, and is the only entry exempt from the columns.
    $last = $entries[count($entries) - 1];
    $this->assertEqual(self::SYNTHESISED_MAPPING_FIELD, $last['name'],
      'claim_constraints is declared last, matching where the marshaller'
      . ' appends it to a mapping');
    $this->assertTrue(array_key_exists('column_backed', $last)
      && $last['column_backed'] === false,
      'and is marked column_backed false, which is what exempts it from the'
      . ' column correspondence below');

    $backed = array();
    foreach ($entries as $entry) {
      $this->assertTrue(array_key_exists('column_backed', $entry),
        'every claim-mapping entry says whether a column backs it; without the'
        . ' flag the exemption would have to be inferred from the name');
      if ($entry['column_backed'] === true) {
        $backed[] = $entry['name'];
      }
    }

    $this->assertEqual($columns, $backed,
      'the column-backed claim-mapping fields are exactly the emitted columns'
      . ' of cm_oa4mp_client_claims, in schema.xml column order; a column added'
      . ' to that table without a contract entry is emitted by nothing and a'
      . ' contract entry with no column is declared against nothing');

    $this->assertFalse(in_array(self::SYNTHESISED_MAPPING_FIELD, $columns, true),
      'and claim_constraints is genuinely not a column, which is the premise'
      . ' the exemption rests on');

    $this->assertEqual(count($columns) + 1, count($entries),
      'the group is the emitted columns plus claim_constraints and nothing'
      . ' else');
  }

  // ------------------------------------------------------------------
  // Scenario 4: per-entry provenance.
  // ------------------------------------------------------------------

  /**
   * Every entry in every group carries an introduced-at version, an explicit
   * retired-at value, and an explicit secret-bearing flag.
   *
   * A missing secret_bearing must fail rather than default to false. The
   * cfg-side names the plugin redacts from its logs are derived from that
   * flag, so a defaulted absence would silently declare a credential-carrying
   * capability as safe to log -- and this model logs rows that carry the
   * DynamoDB credentials.
   *
   * retired_in is likewise always present, null while the capability is still
   * emitted. Absent-means-live would make "the version at which a capability
   * stopped being emitted" unrepresentable for anything that had never been
   * retired, and the retirement floor needs it to be a real, readable value.
   */
  public function testEveryEntryCarriesProvenance() {
    $report = $this->provenanceReport($this->contractText);
    $this->assertEqual('', $report,
      'every contract entry records the version it was introduced at, whether'
      . ' it has been retired, and whether it is secret-bearing');

    // The secret-bearing set itself, pinned. Only the two AWS credentials in
    // dynamo_module_config are secret-bearing; nothing else in a cfg is. The
    // redaction derivation reads exactly this set, so a third name appearing
    // here, or one of these two disappearing, is a change worth reddening.
    $secret = $this->secretBearingNames($this->contractText);
    sort($secret);
    $this->assertEqual(array('access_key_id', 'secret_access_key'), $secret,
      'the AWS access key id and secret are the only secret-bearing'
      . ' capabilities a cfg carries');

    // Positive control: an entry whose secret_bearing flag is deleted from the
    // contract document must be reported, not read as false.
    $perturbed = $this->contractWithoutFlag($this->contractText, 'secret_access_key',
      'secret_bearing');
    $this->assertFalse($perturbed === $this->contractText,
      'the control must actually alter the contract text it perturbs');
    $this->assertTrue(is_array(json_decode($perturbed, true)),
      'and must leave it parseable, or the control would be testing a broken'
      . ' file rather than a missing flag');

    $control = $this->provenanceReport($perturbed);
    $this->assertContains('secret_access_key', $control,
      'an entry with no secret_bearing flag is malformed and must be named,'
      . ' never treated as not secret-bearing');
    $this->assertContains('secret_bearing', $control,
      'and the report says which field is missing');

    // The same for retired_in, whose absence must not be read as "still live".
    $perturbedRetired = $this->contractWithoutFlag($this->contractText,
      'partition_key_template', 'retired_in');
    $this->assertFalse($perturbedRetired === $this->contractText,
      'the control must actually alter the contract text it perturbs');

    $retiredControl = $this->provenanceReport($perturbedRetired);
    $this->assertContains('retired_in', $retiredControl,
      'an entry with no retired_in is malformed; absent must not stand in for'
      . ' null, or a retirement version could never be read back');

    $this->assertEqual('', $this->provenanceReport($this->contractText),
      'the controls, not a pre-existing gap, are what turned the report red');
  }

  // ------------------------------------------------------------------
  // Comparisons.
  // ------------------------------------------------------------------

  /**
   * The two-directional diff between the contract's source_model values and
   * the claim sources the claims tab offers. Empty string when they agree.
   *
   * @param string $contractText The contract document text.
   * @param string $viewSource The claims-tab view text, comment-stripped.
   * @return string One problem per line, naming the value and the side.
   */
  private function sourceModelReport($contractText, $viewSource) {
    $declared = $this->names($this->group($contractText, 'source_model_values'));
    $offered = $this->viewSelectOptions($viewSource, 'source_model');

    $lines = array();
    foreach (array_diff($declared, $offered) as $value) {
      $lines[] = 'source_model ' . $value . ' is declared in cfg_contract.json'
        . ' but the claims tab no longer offers it';
    }
    foreach (array_diff($offered, $declared) as $value) {
      $lines[] = 'source_model ' . $value . ' is offered by the claims tab but'
        . ' cfg_contract.json does not declare it';
    }

    return implode("\n", $lines);
  }

  /**
   * Whatever provenance the contract's entries are missing, one problem per
   * line. Empty string when every entry in every group is complete.
   *
   * @param string $contractText The contract document text.
   * @return string One problem per line.
   */
  private function provenanceReport($contractText) {
    $contract = json_decode($contractText, true);
    if (!is_array($contract) || !isset($contract['contract_version'])) {
      return 'cfg_contract.json does not parse into a versioned document';
    }

    $version = $contract['contract_version'];

    $lines = array();
    foreach ($contract['capabilities'] as $group => $block) {
      foreach ($block['entries'] ?? array() as $index => $entry) {
        $where = $group . '[' . $index . ']';
        $name = (is_array($entry) && !empty($entry['name'])) ? $entry['name'] : '';
        if ($name === '') {
          $lines[] = $where . ' has no name';
          continue;
        }
        $where .= ' (' . $name . ')';

        if (!array_key_exists('introduced_in', $entry)) {
          $lines[] = $where . ' is missing introduced_in';
        } elseif (!is_int($entry['introduced_in']) || $entry['introduced_in'] < 1) {
          $lines[] = $where . ' has a non-version introduced_in';
        } elseif ($entry['introduced_in'] > $version) {
          $lines[] = $where . ' was introduced at ' . $entry['introduced_in']
            . ', past the contract version ' . $version;
        }

        if (!array_key_exists('retired_in', $entry)) {
          $lines[] = $where . ' is missing retired_in; null says still emitted,'
            . ' absent says nothing';
        } elseif ($entry['retired_in'] !== null
                  && (!is_int($entry['retired_in']) || $entry['retired_in'] < 1)) {
          $lines[] = $where . ' has a non-version retired_in';
        }

        if (!array_key_exists('secret_bearing', $entry)) {
          $lines[] = $where . ' is missing secret_bearing; it must not default';
        } elseif (!is_bool($entry['secret_bearing'])) {
          $lines[] = $where . ' has a non-boolean secret_bearing';
        }
      }
    }

    return implode("\n", $lines);
  }

  /** Every entry name across every group the contract marks secret-bearing. */
  private function secretBearingNames($contractText) {
    $contract = json_decode($contractText, true);

    $names = array();
    foreach ($contract['capabilities'] as $block) {
      foreach ($block['entries'] ?? array() as $entry) {
        if (($entry['secret_bearing'] ?? null) === true) {
          $names[] = $entry['name'];
        }
      }
    }

    return $names;
  }

  // ------------------------------------------------------------------
  // Derivations.
  // ------------------------------------------------------------------

  /** The entries of one capability group, in declaration order. */
  private function group($contractText, $group) {
    $contract = json_decode($contractText, true);
    $this->assertTrue(is_array($contract),
      'cfg_contract.json must parse as JSON: ' . json_last_error_msg());
    $this->assertTrue(isset($contract['capabilities'][$group]['entries']),
      "the contract declares the $group group");

    return $contract['capabilities'][$group]['entries'];
  }

  /** The 'name' of each entry, in order. */
  private function names($entries) {
    $names = array();
    foreach ($entries as $entry) {
      $names[] = $entry['name'] ?? '';
    }

    return $names;
  }

  /**
   * The option values the claims tab offers for one claim column.
   *
   * Located by the Form helper call that renders the column, then walked back
   * to the '$options = array();' reset that opens the list it consumes, so the
   * values read are the ones actually handed to that widget rather than the
   * nearest ones in the file.
   *
   * @param string $viewSource The view text, comment-stripped.
   * @param string $column The claim column the list is rendered for.
   * @return array The option values, in declaration order.
   */
  private function viewSelectOptions($viewSource, $column) {
    $found = preg_match('~\$this->Form->(?:select|radio|input)\(\s*[\'"]'
      . preg_quote($column, '~') . '[\'"]~', $viewSource, $m, PREG_OFFSET_CAPTURE);
    $this->assertTrue($found === 1,
      "the claims tab renders $column through a Form helper; the offered"
      . ' options cannot be derived without it');

    $at = $m[0][1];
    $reset = strrpos(substr($viewSource, 0, $at), '$options = array();');
    $this->assertTrue($reset !== false,
      "the option list handed to the $column widget opens with an"
      . ' \'$options = array();\' reset; the derivation reads the assignments'
      . ' between that reset and the widget');

    $block = substr($viewSource, $reset, $at - $reset);
    preg_match_all('~\$options\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*=~', $block, $values);

    return $values[1];
  }

  /**
   * The claim columns that reach a cfg: every column of cm_oa4mp_client_claims
   * in schema.xml order, less the four the marshaller never emits.
   *
   * schema.xml declares the table more than once (one block per schema
   * version). The declarations must agree, or the derivation has no single
   * answer to give and says so rather than picking one.
   */
  private function emittedClaimColumns($schemaSource) {
    $found = preg_match_all('~<table\s+name="oa4mp_client_claims"\s*>(.*?)</table>~s',
      $schemaSource, $tables);
    $this->assertNotEmpty($found,
      'the claim table is declared in schema.xml; the claim-mapping field'
      . ' correspondence cannot be derived without it');

    $sets = array();
    foreach ($tables[1] as $block) {
      preg_match_all('~<field\s+name="([^"]+)"~', $block, $fields);
      $sets[] = $fields[1];
    }

    foreach ($sets as $set) {
      if ($set !== $sets[0]) {
        $this->fail('the oa4mp_client_claims declarations in schema.xml disagree: '
          . implode(',', $sets[0]) . ' vs ' . implode(',', $set));
      }
    }

    return array_values(array_diff($sets[0], $this->nonEmittedColumns));
  }

  // ------------------------------------------------------------------
  // Perturbations, for the positive controls.
  // ------------------------------------------------------------------

  /** The view text with one $options assignment removed. */
  private function viewWithoutOption($viewSource, $value) {
    return preg_replace('~\$options\[\s*[\'"]' . preg_quote($value, '~')
      . '[\'"]\s*\]\s*=[^;]*;~', '', $viewSource, 1);
  }

  /**
   * The contract text with one entry line removed.
   *
   * Entries are written one per line, so dropping the line named leaves the
   * surrounding array well-formed -- provided the entry is not the last in its
   * group, which would strand a trailing comma. Every caller checks the result
   * still parses, so a control that picks a last entry fails loudly rather
   * than proving the derivation rejects broken JSON.
   */
  private function contractWithoutEntry($contractText, $name) {
    return preg_replace('~^.*"name"\s*:\s*"' . preg_quote($name, '~')
      . '".*\R~m', '', $contractText, 1);
  }

  /** The contract text with one field removed from one named entry. */
  private function contractWithoutFlag($contractText, $name, $field) {
    $matched = preg_match('~^.*"name"\s*:\s*"' . preg_quote($name, '~') . '".*$~m',
      $contractText, $m);
    $this->assertTrue($matched === 1,
      "the contract declares an entry named $name for the control to perturb");

    $stripped = preg_replace('~,\s*"' . preg_quote($field, '~') . '"\s*:\s*[^,}]+~',
      '', $m[0], 1);
    $this->assertFalse($stripped === $m[0],
      "the $name entry carries a $field for the control to remove");

    return str_replace($m[0], $stripped, $contractText);
  }

  // ------------------------------------------------------------------
  // Source-text plumbing.
  // ------------------------------------------------------------------

  /** Read a file from the plugin tree, failing if it is not there. */
  private function source($relative) {
    $path = App::pluginPath('Oa4mpClient') . $relative;
    $this->assertTrue(is_readable($path), "the derivation source exists at $path");

    return file_get_contents($path);
  }

  /**
   * Strip comments so a name mentioned only in prose is not a match: XML/HTML
   * comments, leading block comments, and // to end of line (but not the // in
   * a URL scheme). Follows ClaimCfgDriftTest, which reads the same two files.
   */
  private function stripComments($source) {
    $source = preg_replace('~<!--.*?-->~s', '', $source);
    $source = preg_replace('~^[ \t]*/\*.*?\*/~ms', '', $source);
    $source = preg_replace('~(?<!:)//.*$~m', '', $source);

    return $source;
  }
}
