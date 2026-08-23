<?php
/**
 * Drift checks for the claim configuration surface (U4).
 *
 * Two independent halves, both of which must derive what they check from the
 * shipping source rather than from a list kept here:
 *
 *  - Half A, row-set coverage. Every enumerated option the claims tab offers
 *    (View/Oa4mpClientClaims/fields.inc) must be pinned by a row the matrix
 *    declares (Oa4mpClaimRows::declaredRows()) or named by an explicit
 *    exemption (Oa4mpClaimRows::declaredExemptions()).
 *
 *  - Half B, comparator whitelist drift. oa4mpMarshallCfgQdl() copies the whole
 *    claim row ($mapping = $claim) and only strips a fixed set of keys, so it
 *    emits every claim column there is. oa4mpUnMarshallCfgQdlv3() and both
 *    claim normalizations in isClientDataSynchronized() are fixed whitelists.
 *    A column added to the claim table is therefore emitted, dropped on the way
 *    back, and never compared -- the round trip reports "in sync" whatever the
 *    new field does. This half makes that silent.
 *
 * The load-bearing constraint is that nothing here declares the field set. The
 * emitted set is read out of Config/Schema/schema.xml minus the keys the
 * marshaller's own unset() block names; the three comparator lists are read out
 * of Model/Oa4mpClientOa4mpServer.php. A hand-maintained copy of either would be
 * updated by the same person who adds the column, so the check would never fire
 * -- worse than useless, because it would report green.
 *
 * That is also why the positive controls below perturb the DERIVATION SOURCE
 * (the schema text, the view text) and not the test's own inputs: a probe
 * injected downstream of the derivation passes even when the derivation has
 * gone stale, which is precisely the failure this file exists to prevent.
 *
 * Source text is read with the comment lines stripped first, following
 * Test/Case/CiWorkflowTest.php, so a field named only in prose is not a match.
 * No database and no credential are needed.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U4.
 */

class ClaimCfgDriftTest extends Oa4mpTestCase {

  /** Derivation inputs, comment-stripped. Re-read for every test method. */
  private $schemaSource = null;
  private $modelSource = null;
  private $viewSource = null;

  /** A field name no shipping source uses, for the positive controls. */
  const PROBE = 'zzz_drift_probe';

  public function setUp() {
    $this->schemaSource = null;
    $this->modelSource = null;
    $this->viewSource = null;

    $this->schemaSource = $this->stripComments(
      $this->source('Config' . DS . 'Schema' . DS . 'schema.xml'));
    $this->modelSource = $this->stripComments(
      $this->source('Model' . DS . 'Oa4mpClientOa4mpServer.php'));
    $this->viewSource = $this->stripComments(
      $this->source('View' . DS . 'Oa4mpClientClaims' . DS . 'fields.inc'));
  }

  // ------------------------------------------------------------------
  // Half B: comparator whitelist drift.
  // ------------------------------------------------------------------

  /**
   * Every claim column the marshaller emits must be read back by the QDLv3
   * unmarshaller and by both claim normalizations in the sync comparator. A
   * field missing from any one of the three is named in the failure.
   */
  public function testEveryEmittedClaimFieldReachesAllThreeComparatorLists() {
    $report = $this->comparatorDriftReport($this->schemaSource, $this->modelSource);

    $this->assertEqual('', $report,
      'a claim column the marshaller emits but a comparator cannot see is'
      . ' round-tripped as "in sync" no matter what it holds; add it to the'
      . ' unmarshaller and to both normalizations in isClientDataSynchronized()');
  }

  /**
   * The positive control for Half B, injected into the derivation source.
   *
   * A column added to the claim table in schema.xml -- the same edit a
   * claims-tab feature makes -- must show up as emitted and uncovered, in all
   * three comparator lists, with the field named. If this control ever passes
   * while the schema is unperturbed, the derivation has gone stale and the
   * green above means nothing.
   */
  public function testComparatorDriftIsDetectedWhenTheSchemaGainsAColumn() {
    $perturbed = $this->schemaWithExtraClaimColumn($this->schemaSource, self::PROBE);
    $this->assertFalse($perturbed === $this->schemaSource,
      'the control must actually alter the schema text it perturbs');

    // The derivation itself has to see the new column, not just the report.
    $emitted = $this->emittedClaimFields($perturbed, $this->modelSource);
    $this->assertTrue(in_array(self::PROBE, $emitted, true),
      'a column added to the claim table is emitted by the marshaller, which'
      . ' copies the row whole and strips only the keys it names');

    $report = $this->comparatorDriftReport($perturbed, $this->modelSource);
    $this->assertContains(self::PROBE, $report,
      'the drift report must name the uncovered field');
    $this->assertEqual(3, substr_count($report, self::PROBE),
      'the uncovered field must be reported against each of the three'
      . ' comparator field lists');

    // And the unperturbed tree stays clean, so the control is the difference.
    $this->assertEqual('',
      $this->comparatorDriftReport($this->schemaSource, $this->modelSource),
      'the control, not a pre-existing gap, is what turned the report red');
  }

  // ------------------------------------------------------------------
  // Half A: row-set coverage.
  // ------------------------------------------------------------------

  /**
   * Every enumerated option the claims tab offers must have a matrix row that
   * pins it, or an explicit exemption naming it.
   *
   * The claim sources have two authorities in the view -- the source_model
   * select and the $sourceModelConfigs behaviour map the tab's JavaScript is
   * built from -- and they must agree, or one of them is offering a source the
   * other cannot handle.
   */
  public function testEveryViewOptionIsCoveredByARowOrAnExemption() {
    $offered = $this->viewOptionSets($this->viewSource, $this->claimColumns($this->schemaSource));
    $selectSources = isset($offered['source_model']) ? $offered['source_model'] : array();
    $configuredSources = $this->arrayTopLevelKeys($this->viewSource, '$sourceModelConfigs = array(');

    $this->assertNotEmpty($configuredSources,
      'the claim-source behaviour map $sourceModelConfigs is where the view'
      . ' declares what each source does; the drift check reads it');

    sort($selectSources);
    sort($configuredSources);
    $this->assertEqual($selectSources, $configuredSources,
      'the source_model select and the $sourceModelConfigs behaviour map are'
      . ' the two authorities for the claim sources the tab offers; a source in'
      . ' one and not the other is offered without behaviour, or configured'
      . ' without being reachable');

    $report = $this->rowCoverageReport($this->viewSource);
    $this->assertEqual('', $report,
      'every option the claims tab offers needs a matrix row in'
      . ' Oa4mpClaimRows::declaredRows() or an explicit exemption in'
      . ' Oa4mpClaimRows::declaredExemptions()');
  }

  /**
   * The positive control for Half A, injected into the derivation source: an
   * option added to the view's own option list must come back uncovered.
   */
  public function testRowCoverageDriftIsDetectedWhenTheViewGainsAnOption() {
    $perturbed = $this->viewWithExtraOption($this->viewSource, self::PROBE);
    $this->assertFalse($perturbed === $this->viewSource,
      'the control must actually alter the view text it perturbs');

    $report = $this->rowCoverageReport($perturbed);
    $this->assertContains(self::PROBE, $report,
      'an option the view offers with no row and no exemption must be named');

    $this->assertEqual('', $this->rowCoverageReport($this->viewSource),
      'the control, not a pre-existing gap, is what turned the report red');
  }

  // ------------------------------------------------------------------
  // Half B derivation.
  // ------------------------------------------------------------------

  /**
   * Fields present in the emitted set and absent from a comparator list, one
   * per line, naming both. Empty string when there is no drift.
   */
  private function comparatorDriftReport($schema, $model) {
    $emitted = $this->emittedClaimFields($schema, $model);
    $this->assertNotEmpty($emitted, 'the marshaller emits at least one claim field');

    $lists = $this->comparatorFieldLists($model);
    $lists['oa4mpUnMarshallCfgQdlv3()'] = $this->unmarshallerFields($model);

    $lines = array();
    foreach ($emitted as $field) {
      foreach ($lists as $label => $fields) {
        if (!in_array($field, $fields, true)) {
          $lines[] = $field . ' is emitted by oa4mpMarshallCfgQdl() but never read by ' . $label;
        }
      }
    }

    return implode("\n", $lines);
  }

  /**
   * The claim fields the marshaller sends to the server: every column the claim
   * table declares, minus the keys the marshaller's own unset() block strips.
   * Both halves of that come from source, neither from a list kept here.
   */
  private function emittedClaimFields($schema, $model) {
    $columns = $this->claimColumns($schema);
    $stripped = $this->marshallerStrippedKeys($model);

    return array_values(array_diff($columns, $stripped));
  }

  /**
   * The columns Config/Schema/schema.xml declares for the claim table.
   *
   * The table is declared twice with identical field sets; tolerate the repeat,
   * but refuse to guess if the two ever disagree.
   */
  private function claimColumns($schema) {
    $found = preg_match_all('~<table\s+name="oa4mp_client_claims"\s*>(.*?)</table>~s',
      $schema, $tables);
    $this->assertNotEmpty($found,
      'the claim table is declared in schema.xml; the emitted field set is'
      . ' derived from that declaration and cannot be derived without it');

    $sets = array();
    foreach ($tables[1] as $block) {
      preg_match_all('~<field\s+name="([^"]+)"~', $block, $fields);
      $sets[] = $fields[1];
    }

    foreach ($sets as $set) {
      if ($set !== $sets[0]) {
        $this->fail('the cm_oa4mp_client_claims declarations in schema.xml disagree: '
          . implode(',', $sets[0]) . ' vs ' . implode(',', $set));
      }
    }

    $this->assertNotEmpty($sets[0], 'the claim table declares columns');

    return $sets[0];
  }

  /**
   * The keys oa4mpMarshallCfgQdl() strips from the copied claim row.
   *
   * Derived by finding the claim loop, then the whole-row copy inside it, then
   * the unset() calls on whatever variable that copy went into. Deriving it
   * this way also asserts the premise: if the marshaller stops copying the row
   * whole, this fails rather than reporting a stale emitted set as green.
   */
  private function marshallerStrippedKeys($model) {
    $body = $this->functionBody($model, 'oa4mpMarshallCfgQdl');
    $this->assertNotEmpty($body,
      'oa4mpMarshallCfgQdl() is where a claim row becomes an emitted mapping;'
      . ' the emitted field set cannot be derived without it');

    $loop = $this->foreachLoop($body, '$data[\'Oa4mpClientClaim\']');
    $this->assertNotEmpty($loop,
      'oa4mpMarshallCfgQdl() loops over $data[\'Oa4mpClientClaim\'] to build the'
      . ' claim mappings; the drift check locates the emitted set by that loop');

    if (!preg_match('~\$(\w+)\s*=\s*\$' . $loop['var'] . '\s*;~', $loop['body'], $copy)) {
      $this->fail('oa4mpMarshallCfgQdl() no longer copies the claim row whole'
        . ' ($mapping = $claim). The emitted set is derived from that copy minus'
        . ' the unset() keys, so this derivation is now stale and must be redone'
        . ' against whatever the marshaller does instead.');
    }

    $keys = array();
    preg_match_all('~unset\s*\(\s*\$' . $copy[1] . '\[\'([^\']+)\'\]\s*\)~',
      $loop['body'], $unsets);
    foreach ($unsets[1] as $key) {
      $keys[] = $key;
    }

    $this->assertNotEmpty($keys,
      'the marshaller strips the surrogate and timestamp keys from the copied'
      . ' claim row; finding none means the derivation missed the block');

    return $keys;
  }

  /** The claim fields oa4mpUnMarshallCfgQdlv3() reads back off the wire. */
  private function unmarshallerFields($model) {
    $body = $this->functionBody($model, 'oa4mpUnMarshallCfgQdlv3');
    $this->assertNotEmpty($body,
      'oa4mpUnMarshallCfgQdlv3() is the QDLv3 read-back path; its field list is'
      . ' one of the three the emitted set is checked against');

    if (!preg_match('~\$(\w+)\s*=\s*\$\w+\[\'claim_mappings\'\]~', $body, $m)) {
      $this->fail('oa4mpUnMarshallCfgQdlv3() no longer reads the claim mappings'
        . ' out of the claim_mappings QDL argument, so its field list cannot be'
        . ' located; redo this derivation against the new shape.');
    }

    $loop = $this->foreachLoop($body, '$' . $m[1]);
    $this->assertNotEmpty($loop,
      'oa4mpUnMarshallCfgQdlv3() loops over the claim mappings; that loop is'
      . ' where its whitelist lives');

    return $this->subscriptKeys($loop['body'], $loop['var']);
  }

  /**
   * The claim fields each normalization in isClientDataSynchronized() reads,
   * keyed by the claim list it normalizes. There are two -- the plugin side and
   * the OA4MP server side -- and both are located from the association key they
   * read the claims out of, not from their own names.
   */
  private function comparatorFieldLists($model) {
    $body = $this->functionBody($model, 'isClientDataSynchronized');
    $this->assertNotEmpty($body,
      'isClientDataSynchronized() is the sync comparator; two of the three'
      . ' field lists the emitted set is checked against live in it');

    preg_match_all('~\$(\w+)\s*=\s*\$\w+\[\'Oa4mpClientClaim\'\]~', $body, $m);

    $lists = array();
    foreach ($m[1] as $listVar) {
      $loop = $this->foreachLoop($body, '$' . $listVar);
      if (empty($loop)) {
        $this->fail('isClientDataSynchronized() reads claims into $' . $listVar
          . ' but never normalizes them in a foreach loop; the drift check'
          . ' locates each normalization by that loop.');
      }
      $lists['the ' . $listVar . ' normalization in isClientDataSynchronized()'] =
        $this->subscriptKeys($loop['body'], $loop['var']);
    }

    if (count($lists) !== 2) {
      $this->fail('expected the two claim normalizations in'
        . ' isClientDataSynchronized() (plugin side and OA4MP server side), found '
        . count($lists) . '. The derivation is stale; redo it before trusting'
        . ' this check.');
    }

    return $lists;
  }

  /** Add a column to the claim table wherever schema.xml declares it. */
  private function schemaWithExtraClaimColumn($schema, $column) {
    $probeField = '<field name="' . $column . '" type="C" size="32" />';

    return preg_replace_callback('~(<table\s+name="oa4mp_client_claims"\s*>)(.*?)(</table>)~s',
      function($m) use ($probeField) {
        return $m[1] . $m[2] . '        ' . $probeField . "\n    " . $m[3];
      },
      $schema);
  }

  // ------------------------------------------------------------------
  // Half A derivation.
  // ------------------------------------------------------------------

  /**
   * Options the claims tab offers that no matrix row pins and no exemption
   * names, one per line. Empty string when the row set covers everything.
   */
  private function rowCoverageReport($view) {
    $offered = $this->viewOptionSets($view, $this->claimColumns($this->schemaSource));
    $this->assertNotEmpty($offered,
      'the claims tab offers enumerated options; finding none means the'
      . ' derivation no longer recognizes how the view renders them');

    // The claim sources have a second authority in the view: the behaviour map
    // the tab's JavaScript is built from. Cover the union, so a source added to
    // either place needs a row.
    foreach ($this->arrayTopLevelKeys($view, '$sourceModelConfigs = array(') as $source) {
      $offered['source_model'][] = $source;
    }

    $rows = Oa4mpClaimRows::declaredRows();
    $exemptions = Oa4mpClaimRows::declaredExemptions();
    $this->assertNotEmpty($rows, 'the matrix declares its rows for this check to read');

    $lines = array();
    foreach ($offered as $column => $values) {
      foreach (array_unique($values) as $value) {
        if ($this->optionIsCovered($column, $value, $rows, $exemptions)) {
          continue;
        }
        $lines[] = $column . '=' . $value . ' is offered by the claims tab but no'
          . ' matrix row pins it and no exemption names it';
      }
    }

    return implode("\n", $lines);
  }

  /**
   * True when a matrix row pins the option -- either in the values it declares
   * or as the row's own claim source -- or an exemption names it as
   * "column=value" in an 'option' key.
   */
  private function optionIsCovered($column, $value, $rows, $exemptions) {
    foreach ($rows as $row) {
      if (isset($row['values'][$column]) && $row['values'][$column] === $value) {
        return true;
      }
      if (isset($row[$column]) && $row[$column] === $value) {
        return true;
      }
    }

    foreach ($exemptions as $exemption) {
      if (isset($exemption['option']) && $exemption['option'] === $column . '=' . $value) {
        return true;
      }
    }

    return false;
  }

  /**
   * The enumerated options the view offers, by claim column.
   *
   * The columns come from the schema, so a new enumerated column is picked up
   * without being named here. For each select or radio the view renders for a
   * column, the options are the $options[...] assignments since the nearest
   * reset -- which is how every one of these blocks is written.
   */
  private function viewOptionSets($view, $columns) {
    $sets = array();

    foreach ($columns as $column) {
      $rendered = false;

      foreach (array('select', 'radio') as $widget) {
        $loose = 'Form->' . $widget . '(\'' . $column . '\'';
        $strict = $loose . ', $options';
        $from = 0;

        while (($at = strpos($view, $loose, $from)) !== false) {
          $from = $at + 1;
          $rendered = true;

          if (strpos($view, $strict, $at) !== $at) {
            continue;
          }

          $reset = strrpos(substr($view, 0, $at), '$options = array();');
          if ($reset === false) {
            continue;
          }

          preg_match_all('~\$options\[\'([^\']*)\'\]\s*=~',
            substr($view, $reset, $at - $reset), $m);
          foreach ($m[1] as $value) {
            if ($value === '') {
              continue;
            }
            $sets[$column][] = $value;
          }
        }
      }

      if ($rendered && empty($sets[$column])) {
        $this->fail('the claims tab renders a select or radio for ' . $column
          . ' but its options could not be derived, so an option added there'
          . ' would go unchecked. Redo this derivation against how the view'
          . ' now builds that list.');
      }
    }

    return $sets;
  }

  /** Add an option to the claim_value_selection list the view builds. */
  private function viewWithExtraOption($view, $option) {
    $anchor = '$options[\'all\'] = \'All values found\';';
    $probe = $anchor . "\n          " . '$options[\'' . $option . '\'] = \'Drift probe\';';

    return str_replace($anchor, $probe, $view);
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
   * Strip comments so a field or option named only in prose is not a match:
   * XML/HTML comments, leading block comments, and // to end of line (but not
   * the // in a URL scheme).
   */
  private function stripComments($source) {
    $source = preg_replace('~<!--.*?-->~s', '', $source);
    $source = preg_replace('~^[ \t]*/\*.*?\*/~ms', '', $source);
    $source = preg_replace('~(?<!:)//.*$~m', '', $source);

    return $source;
  }

  /** The body of a named function, braces included, or '' if it is gone. */
  private function functionBody($php, $name) {
    $at = strpos($php, 'function ' . $name . '(');
    if ($at === false) {
      return '';
    }

    return $this->braceBlock($php, strpos($php, '{', $at));
  }

  /**
   * The loop variable and body of `foreach(<subject> as $x) { ... }`, or an
   * empty array when there is no such loop.
   */
  private function foreachLoop($source, $subject) {
    $pattern = '~foreach\s*\(\s*' . preg_quote($subject, '~') . '\s+as\s+\$(\w+)\s*\)~';
    if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
      return array();
    }

    $body = $this->braceBlock($source, strpos($source, '{', $m[0][1]));
    if ($body === '') {
      return array();
    }

    return array('var' => $m[1][0], 'body' => $body);
  }

  /** The brace-balanced block starting at $open, or '' if it does not close. */
  private function braceBlock($source, $open) {
    if ($open === false) {
      return '';
    }

    $depth = 0;
    $len = strlen($source);
    for ($i = $open; $i < $len; $i++) {
      if ($source[$i] === '{') {
        $depth++;
      } elseif ($source[$i] === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($source, $open, $i - $open + 1);
        }
      }
    }

    return '';
  }

  /** Every string key read off $<var> in the given source, deduplicated. */
  private function subscriptKeys($source, $var) {
    preg_match_all('~\$' . $var . '\[\'([^\']+)\'\]~', $source, $m);

    return array_values(array_unique($m[1]));
  }

  /**
   * The top-level keys of an array literal, located by its assignment text.
   * Paren depth is tracked so nested keys are not mistaken for top-level ones.
   */
  private function arrayTopLevelKeys($source, $assignment) {
    $at = strpos($source, $assignment);
    if ($at === false) {
      return array();
    }

    $keys = array();
    $depth = 1;
    $len = strlen($source);
    for ($i = $at + strlen($assignment); $i < $len && $depth > 0; $i++) {
      $c = $source[$i];
      if ($c === '(') {
        $depth++;
      } elseif ($c === ')') {
        $depth--;
      } elseif ($c === '\'' && $depth === 1) {
        if (preg_match('~^\'([^\']+)\'\s*=>~', substr($source, $i, 96), $m)) {
          $keys[] = $m[1];
        }
      }
    }

    return $keys;
  }
}
