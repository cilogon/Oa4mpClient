<?php
/**
 * QDL conformance check: does a named tier's QDL implement every capability
 * the plugin's cfg capability contract declares? (U7, R8-R10)
 *
 * The plugin declares in cfg_contract.json everything it may emit into an
 * OA4MP client cfg. The script that consumes those cfgs --
 * dynamodb_claims.qdl -- lives in a different repository
 * (cilogon-service-config-us), is deployed once per tier, and serves every
 * plugin version at once. A capability the plugin emits that the tier's QDL
 * does not implement is a silently ignored cfg key, which is why this check
 * exists and why it names a TIER rather than reading whatever happens to be
 * checked out: the QDL is resolved with `git show <tier>:<path>`, read-only,
 * never with a checkout, because that working copy is shared.
 *
 * Direction matters (R9). A contract capability the QDL does not implement is
 * a FAILURE. A capability the QDL implements that the contract does not
 * declare is NOT a failure: during a staggered rollout the tier is expected to
 * run ahead of the plugin, and that is the ordering the contract's
 * version_policy.on_raising requires.
 *
 * Authority (KTD7). The QDL's `claims_contract_*` declaration block is the
 * vocabulary AUTHORITY; pattern extraction from the code beneath it is a
 * FAIL-CLOSED CROSS-CHECK against that block, not a second opinion about what
 * the QDL implements. The QDL reads its vocabulary in several syntactic forms
 * (`var.'name'`, `has_key('name', var.)`, the extraction operator `var.\'name'`,
 * and passing the whole stem) across more than one variable name; a read form
 * this script does not recognize fails the run rather than being skipped,
 * because a missed form would let an undeclared literal through unnoticed. The
 * fourth form was found by exactly that fail-closed behaviour reddening on the
 * real QDL rather than reporting conformance.
 *
 * OUTPUT IS BOUNDED ON PURPOSE. This verdict is pasted into pull requests on
 * github.com/cilogon/Oa4mpClient, which is PUBLIC, while the configuration
 * repository it reads is PRIVATE. Nothing is printed except capability names,
 * the tier name, the repository-relative QDL path (a constant of this script,
 * not read from that checkout), counts, and a verdict. No QDL source line, no
 * surrounding context, no absolute path into the configuration repository, and
 * no git output ever reaches stdout or stderr.
 *
 * Usage:
 *
 *   php bin/qdl-conformance.php --tier us-east-2-dev --config-repo <path>
 *
 * Exit codes: 0 pass, 1 fail, 2 nothing to compare, 3 QDL absent for the
 * named tier, 64 usage or environment error.
 *
 * The comparison logic is a class with no I/O so that
 * Test/Case/Lib/QdlConformanceTest.php can drive it from in-memory fixtures
 * without git or the configuration repository. The CLI path runs only when
 * this file is the entry script, so requiring it is side-effect free.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U7.
 */

class QdlConformance {

  /** Verdicts. Each maps to exactly one exit code; see exitCode(). */
  const VERDICT_PASS = 'PASS';
  const VERDICT_FAIL = 'FAIL';
  const VERDICT_NOTHING_TO_COMPARE = 'NOTHING_TO_COMPARE';
  const VERDICT_QDL_ABSENT = 'QDL_ABSENT';
  const VERDICT_CONTRACT_UNREADABLE = 'CONTRACT_UNREADABLE';

  /** Where the QDL lives inside the configuration repository, tier-independent. */
  const QDL_PATH = 'roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl';

  /**
   * Contract capability group => the QDL variable that declares it.
   *
   * The QDL's declaration block is a plain list assignment per group,
   * `claims_contract_<group>. := ['a', 'b'];`. The mapping is spelled out
   * rather than derived from the group name so that renaming a contract group
   * cannot silently start comparing against a variable that does not exist.
   */
  public static function groupDeclarations() {
    return array(
      'qdl_args' => 'claims_contract_qdl_args',
      'claim_mapping_fields' => 'claims_contract_claim_mapping_fields',
      'claim_constraint_fields' => 'claims_contract_claim_constraint_fields',
      'source_model_values' => 'claims_contract_source_model_values',
      'constraint_field_values' => 'claims_contract_constraint_field_values',
    );
  }

  /**
   * Groups the QDL is not expected to implement, and why.
   *
   * dynamo_module_config's own keys are handed to the dynamo module unopened;
   * the QDL never reads a key inside that block, so requiring it to declare
   * them would be a permanent false red. Reported as a note, never a failure.
   */
  public static function groupsNotRequired() {
    return array(
      'dynamo_module_config_keys' =>
        'handed to the dynamo module unopened; the QDL reads no key inside it',
    );
  }

  /**
   * QDL variables that carry cfg vocabulary, and the contract groups a literal
   * read off each may legitimately come from.
   *
   * config_params is the cfg itself; claim_mapping is one claim_mappings
   * entry; x is the parameter name the utility functions and the constraint
   * -picking lambdas bind a mapping or a constraint to. A literal read off a
   * mapping may be a mapping field or a constraint field, so those two groups
   * are checked as a union.
   */
  public static function vocabularyVariables() {
    return array(
      'config_params' => array('qdl_args'),
      'claim_mapping' => array('claim_mapping_fields', 'claim_constraint_fields'),
      'x' => array('claim_mapping_fields', 'claim_constraint_fields'),
    );
  }

  /**
   * The namespace of the DynamoDB item's own fields, which are NOT cfg
   * vocabulary and carry no contract obligation.
   *
   * The item the QDL reads out of DynamoDB mirrors Registry records and its
   * keys are all `cm_`-prefixed (cm_emailAddresses, cm_given, cm_status).
   * Those names reach the same read forms the cfg vocabulary does -- the
   * name-handling code binds `x` to an item inside a for_each and extracts
   * cm_given, cm_middle and cm_family from it -- so the forms are recognized
   * (an unrecognized form still fails closed) and the names are then excluded
   * from the cfg-vocabulary comparison by this prefix. Excluding them by
   * prefix rather than by which variable they were read off is what keeps the
   * exclusion honest: `x` is bound to a mapping, to a constraint, and to an
   * item in different places in the same file.
   */
  const ITEM_FIELD_PREFIX = 'cm_';

  /** Longest capability name this script will ever print, in characters. */
  const MAX_NAME_LENGTH = 64;

  /** Most entries printed per reported list, so output stays bounded. */
  const MAX_LISTED = 25;

  // ------------------------------------------------------------------
  // Contract side.
  // ------------------------------------------------------------------

  /**
   * The capabilities the contract requires of the QDL.
   *
   * Retired entries (retired_in non-null) are not required: the plugin has
   * stopped emitting them, so a tier that dropped the handler is conformant.
   * Groups with no declaration variable (see groupsNotRequired()) are excluded
   * here and reported as a note instead.
   *
   * @param string $json cfg_contract.json's text.
   * @return array|null group => list of names, or null when unreadable.
   */
  public static function requiredCapabilities($json) {
    $contract = json_decode((string)$json, true);
    if (!is_array($contract) || !isset($contract['capabilities'])
        || !is_array($contract['capabilities'])) {
      return null;
    }

    $declarations = self::groupDeclarations();
    $required = array();
    $retired = 0;

    foreach ($contract['capabilities'] as $group => $body) {
      if (!isset($declarations[$group])) {
        // Either a group the QDL is not expected to implement, or a group
        // added to the contract without a declaration variable here. Both are
        // reported by the caller; neither is silently required.
        continue;
      }
      $entries = isset($body['entries']) && is_array($body['entries'])
        ? $body['entries'] : array();
      $required[$group] = array();
      foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['name'])) {
          continue;
        }
        if (array_key_exists('retired_in', $entry) && $entry['retired_in'] !== null) {
          $retired++;
          continue;
        }
        $required[$group][] = (string)$entry['name'];
      }
    }

    return array(
      'groups' => $required,
      'retired' => $retired,
      'version' => isset($contract['contract_version'])
        ? (int)$contract['contract_version'] : null,
      'unmapped_groups' => array_values(array_diff(
        array_keys($contract['capabilities']), array_keys($declarations))),
    );
  }

  // ------------------------------------------------------------------
  // QDL side.
  // ------------------------------------------------------------------

  /**
   * Blank out `//` comments, preserving offsets and line numbers.
   *
   * Quote-aware, and deliberately so: the declaration block's own comment
   * carries an apostrophe ("dynamo_module_config's own keys"), which a naive
   * quote tracker would read as the start of a string and then swallow the
   * rest of the file. Once inside a comment, quoting stops mattering and the
   * scan runs to the newline.
   *
   * @param string $source QDL text.
   * @return string Same length, comments replaced by spaces.
   */
  public static function stripComments($source) {
    $out = '';
    $length = strlen($source);
    $quote = null;
    for ($i = 0; $i < $length; $i++) {
      $c = $source[$i];
      if ($quote !== null) {
        $out .= $c;
        if ($c === $quote) {
          $quote = null;
        }
        continue;
      }
      if ($c === "'" || $c === '"') {
        $quote = $c;
        $out .= $c;
        continue;
      }
      if ($c === '/' && $i + 1 < $length && $source[$i + 1] === '/') {
        while ($i < $length && $source[$i] !== "\n") {
          $out .= ' ';
          $i++;
        }
        if ($i < $length) {
          $out .= "\n";
        }
        continue;
      }
      $out .= $c;
    }

    return $out;
  }

  /**
   * The QDL's declaration block: the vocabulary AUTHORITY (KTD7).
   *
   * @param string $source Comment-stripped QDL text.
   * @return array declaration variable name => list of declared literals.
   */
  public static function parseDeclarations($source) {
    $declared = array();
    $matched = preg_match_all(
      '/\b(claims_contract_[a-z0-9_]+)\.\s*:=\s*\[(.*?)\]\s*;/s', $source, $m,
      PREG_SET_ORDER);
    if (!$matched) {
      return $declared;
    }
    foreach ($m as $one) {
      $names = array();
      if (preg_match_all("/'([^']*)'/", $one[2], $items)) {
        $names = $items[1];
      }
      $declared[$one[1]] = $names;
    }

    return $declared;
  }

  /**
   * Pattern-extract every vocabulary literal the QDL's CODE reads, and every
   * read form this script does not recognize.
   *
   * The recognized forms per vocabulary variable:
   *
   *   1. `var.'name'`            -- dotted string access, reads one name.
   *   2. `has_key('name', var.)` -- presence test, reads one name.
   *   3. `var.` handed on whole  -- passed to a function, assigned, or bound
   *                                 as a parameter. Reads no single name.
   *   4. `var.\'name'`           -- QDL's extraction operator, reads one name.
   *
   * Anything else -- a bare-identifier dynamic key, an index, a computed key
   * -- is UNRECOGNIZED and fails the run closed, because a form that is
   * skipped is a form whose undeclared literals go unnoticed.
   *
   * A name in the item namespace (ITEM_FIELD_PREFIX) is recognized and then
   * dropped: it is a DynamoDB item field, not cfg vocabulary.
   *
   * Two value forms are extracted as well: `source_model == 'V'` and
   * `'constraint_field' == 'V'`, which is how the QDL branches on the
   * enumerated-value groups.
   *
   * @param string $source Comment-stripped QDL text.
   * @return array 'reads' => list of array(name, groups, line),
   *               'unrecognized' => list of array(variable, line).
   */
  public static function extractReads($source) {
    $reads = array();
    $unrecognized = array();

    foreach (self::vocabularyVariables() as $variable => $groups) {
      $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($variable, '/') . '\./';
      $offset = 0;
      while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $m[0][1];
        $after = $start + strlen($m[0][0]);
        $offset = $after;
        $line = self::lineOf($source, $start);
        $tail = substr($source, $after, 128);
        $head = substr($source, max(0, $start - 128), min(128, $start));

        // Form 1: var.'name'  Form 4: var.\'name'
        if (preg_match("/^\\\\?'([A-Za-z0-9_]+)'/", $tail, $hit)) {
          if (strpos($hit[1], self::ITEM_FIELD_PREFIX) !== 0) {
            $reads[] = array($hit[1], $groups, $line);
          }
          continue;
        }
        // Form 2: has_key('name', var.)
        if (substr($tail, 0, 1) === ')'
            && preg_match("/has_key\(\s*'([A-Za-z0-9_]+)'\s*,\s*$/", $head, $hit)) {
          $reads[] = array($hit[1], $groups, $line);
          continue;
        }
        // Form 3: the whole stem is handed on, naming nothing.
        if ($tail === '' || preg_match('/^[\s,;\)\]]/', $tail)) {
          continue;
        }

        $unrecognized[] = array($variable, $line);
      }
    }

    // Enumerated values the QDL branches on.
    $valueForms = array(
      'source_model_values' => "/source_model\s*==\s*'([A-Za-z0-9_]+)'/",
      'constraint_field_values' => "/'constraint_field'\s*==\s*'([A-Za-z0-9_]+)'/",
    );
    foreach ($valueForms as $group => $pattern) {
      if (preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $one) {
          $reads[] = array($one[1][0], array($group), self::lineOf($source, $one[0][1]));
        }
      }
    }

    return array('reads' => $reads, 'unrecognized' => $unrecognized);
  }

  /** 1-based line number of a byte offset. */
  private static function lineOf($source, $offset) {
    return substr_count(substr($source, 0, $offset), "\n") + 1;
  }

  // ------------------------------------------------------------------
  // The comparison.
  // ------------------------------------------------------------------

  /**
   * Compare a contract against a tier's QDL.
   *
   * @param string $contractJson cfg_contract.json's text.
   * @param string|null $qdlSource The QDL's text, or null when the named tier
   *                               has no such file -- reported distinctly
   *                               rather than as every capability missing.
   * @param string $tier The tier whose QDL this is. Named in the output.
   * @return array Result, for render().
   */
  public static function evaluate($contractJson, $qdlSource, $tier) {
    $result = array(
      'tier' => (string)$tier,
      'qdl_path' => self::QDL_PATH,
      'verdict' => self::VERDICT_PASS,
      'contract_version' => null,
      'required_count' => 0,
      'compared_groups' => array(),
      'missing' => array(),
      'beyond' => array(),
      'undeclared_reads' => array(),
      'unrecognized' => array(),
      'cross_checked' => 0,
      'retired_skipped' => 0,
      'notes' => array(),
    );

    $contract = self::requiredCapabilities($contractJson);
    if ($contract === null) {
      $result['verdict'] = self::VERDICT_CONTRACT_UNREADABLE;
      $result['notes'][] = 'the capability contract did not parse as JSON with a'
        . ' capabilities object';

      return $result;
    }
    $result['contract_version'] = $contract['version'];
    $result['retired_skipped'] = $contract['retired'];

    $notRequired = self::groupsNotRequired();
    foreach ($contract['unmapped_groups'] as $group) {
      $result['notes'][] = isset($notRequired[$group])
        ? "not required of the QDL: $group (" . $notRequired[$group] . ')'
        : "no declaration variable is mapped for contract group $group";
    }

    if ($qdlSource === null) {
      $result['verdict'] = self::VERDICT_QDL_ABSENT;
      $result['notes'][] = 'the named tier has no QDL at the expected path, so'
        . ' nothing was compared; this is not the same as a QDL missing every'
        . ' capability';

      return $result;
    }

    $stripped = self::stripComments((string)$qdlSource);
    $declared = self::parseDeclarations($stripped);

    if (empty($declared)) {
      $result['verdict'] = self::VERDICT_NOTHING_TO_COMPARE;
      $result['notes'][] = 'the QDL carries no claims_contract_* declaration'
        . ' block, so its vocabulary could not be read; the check reached no'
        . ' verdict about conformance';

      return $result;
    }

    // Contract -> QDL. A capability the QDL does not implement is a failure.
    $declarations = self::groupDeclarations();
    $declaredByGroup = array();
    foreach ($contract['groups'] as $group => $names) {
      $variable = $declarations[$group];
      $groupDeclared = isset($declared[$variable]) ? $declared[$variable] : null;
      if ($groupDeclared === null) {
        $result['notes'][] = "the QDL declares no $variable, so every"
          . " $group capability counts as missing";
        $groupDeclared = array();
      } else {
        $result['compared_groups'][] = $group;
      }
      $declaredByGroup[$group] = $groupDeclared;
      $result['required_count'] += count($names);
      foreach ($names as $name) {
        if (!in_array($name, $groupDeclared, true)) {
          $result['missing'][] = $group . '/' . self::clip($name);
        }
      }
      // QDL -> contract. NOT a failure: the tier is expected to run ahead of
      // the plugin during a staggered rollout (R9).
      foreach ($groupDeclared as $name) {
        if (!in_array($name, $names, true)) {
          $result['beyond'][] = $group . '/' . self::clip($name);
        }
      }
    }

    // The fail-closed cross-check against the authority above (KTD7).
    $extracted = self::extractReads($stripped);
    foreach ($extracted['unrecognized'] as $one) {
      $result['unrecognized'][] = self::clip($one[0]) . ' (line ' . $one[1] . ')';
    }
    $result['cross_checked'] = count($extracted['reads']);
    foreach ($extracted['reads'] as $read) {
      list($name, $groups, $line) = $read;
      $found = false;
      foreach ($groups as $group) {
        if (isset($declaredByGroup[$group])
            && in_array($name, $declaredByGroup[$group], true)) {
          $found = true;
          break;
        }
      }
      if (!$found) {
        $key = implode('|', $groups) . '/' . self::clip($name) . ' (line ' . $line . ')';
        if (!in_array($key, $result['undeclared_reads'], true)) {
          $result['undeclared_reads'][] = $key;
        }
      }
    }

    if ($result['missing'] || $result['undeclared_reads'] || $result['unrecognized']) {
      $result['verdict'] = self::VERDICT_FAIL;
    }

    return $result;
  }

  /** Keep any printed name inside a fixed bound. */
  private static function clip($name) {
    $name = (string)$name;

    return strlen($name) > self::MAX_NAME_LENGTH
      ? substr($name, 0, self::MAX_NAME_LENGTH) . '...'
      : $name;
  }

  // ------------------------------------------------------------------
  // Reporting.
  // ------------------------------------------------------------------

  /**
   * Render a result as the text pasted into a PUBLIC pull request.
   *
   * Everything here is either a constant of this script, a count, the tier
   * name the operator typed, or a capability name. No QDL source line and no
   * absolute path can reach this string.
   *
   * @param array $result From evaluate().
   * @return string
   */
  public static function render($result) {
    $out = array();
    $out[] = 'qdl-conformance: tier ' . $result['tier'];
    $out[] = 'qdl: ' . $result['qdl_path'];
    $out[] = 'contract version: '
      . ($result['contract_version'] === null ? 'unknown' : $result['contract_version']);

    if ($result['verdict'] === self::VERDICT_QDL_ABSENT) {
      $out[] = 'the named tier carries no QDL at that path';
    } elseif ($result['verdict'] !== self::VERDICT_CONTRACT_UNREADABLE) {
      $out[] = 'groups compared: '
        . ($result['compared_groups']
          ? implode(', ', $result['compared_groups']) : 'none');
      $out[] = 'capabilities required: ' . $result['required_count'];
      $out[] = 'retired capabilities not required: ' . $result['retired_skipped'];
      $out[] = 'cross-checked literals read by the QDL: '
        . $result['cross_checked'];
    }

    foreach ($result['notes'] as $note) {
      $out[] = 'note: ' . $note;
    }
    foreach (self::listed($result['missing']) as $entry) {
      $out[] = 'MISSING from the QDL: ' . $entry;
    }
    foreach (self::listed($result['undeclared_reads']) as $entry) {
      $out[] = 'CROSS-CHECK undeclared literal read by the QDL: ' . $entry;
    }
    foreach (self::listed($result['unrecognized']) as $entry) {
      $out[] = 'CROSS-CHECK unrecognized read form, failing closed: ' . $entry;
    }
    foreach (self::listed($result['beyond']) as $entry) {
      $out[] = 'implemented beyond the contract, not a failure: ' . $entry;
    }

    $out[] = 'VERDICT: ' . $result['verdict'];

    return implode("\n", $out) . "\n";
  }

  /** Bound a printed list, saying so when it was cut. */
  private static function listed($entries) {
    if (count($entries) <= self::MAX_LISTED) {
      return $entries;
    }
    $shown = array_slice($entries, 0, self::MAX_LISTED);
    $shown[] = '... and ' . (count($entries) - self::MAX_LISTED) . ' more';

    return $shown;
  }

  /** The process exit code a verdict maps to. */
  public static function exitCode($verdict) {
    switch ($verdict) {
      case self::VERDICT_PASS:
        return 0;
      case self::VERDICT_FAIL:
        return 1;
      case self::VERDICT_NOTHING_TO_COMPARE:
        return 2;
      case self::VERDICT_QDL_ABSENT:
        return 3;
      default:
        return 64;
    }
  }

  // ------------------------------------------------------------------
  // Resolution. Read-only, and silent about the checkout it reads.
  // ------------------------------------------------------------------

  /**
   * Read a file, returning null when it is absent.
   *
   * The null return is what carries "absent" into evaluate(), which is how the
   * tests reach the QDL_ABSENT verdict without git.
   *
   * @param string $path
   * @return string|null
   */
  public static function readFileOrNull($path) {
    if (!is_file($path) || !is_readable($path)) {
      return null;
    }
    $text = file_get_contents($path);

    return $text === false ? null : $text;
  }

  /**
   * Resolve the QDL from a tier's branch WITHOUT touching the working copy.
   *
   * `git show <tier>:<path>` reads out of the object store; the checkout is
   * shared and sits on a branch of its own, so a checkout or switch there is
   * unacceptable. git's own output is captured and never printed: it would
   * carry paths and content out of a private repository.
   *
   * @param string $repo Path to the configuration repository.
   * @param string $tier Branch name.
   * @param string $path Repository-relative path to the QDL.
   * @param string $error Set to a bounded message on failure.
   * @return string|null The QDL text; null when the tier carries no such file.
   */
  public static function gitShow($repo, $tier, $path, &$error) {
    $error = '';
    if (!preg_match('~^[A-Za-z0-9._/-]+$~', $tier)) {
      $error = 'the tier name is not a plausible branch name';

      return null;
    }
    $base = 'git -C ' . escapeshellarg($repo) . ' ';
    exec($base . 'rev-parse --git-dir 2>/dev/null', $ignored, $status);
    if ($status !== 0) {
      $error = 'the configuration repository path is not a git repository';

      return null;
    }
    $ignored = array();
    exec($base . 'rev-parse --verify --quiet ' . escapeshellarg($tier . '^{commit}')
      . ' 2>/dev/null', $ignored, $status);
    if ($status !== 0) {
      $error = 'the configuration repository has no branch or commit named '
        . $tier;

      return null;
    }
    $lines = array();
    exec($base . 'show ' . escapeshellarg($tier . ':' . $path) . ' 2>/dev/null',
      $lines, $status);
    if ($status !== 0) {
      // Absent at that path on that branch. Not an error: evaluate() reports
      // it distinctly from every capability missing.
      return null;
    }

    return implode("\n", $lines) . "\n";
  }

  // ------------------------------------------------------------------
  // CLI.
  // ------------------------------------------------------------------

  /** Usage text. */
  public static function usage() {
    return "usage: php bin/qdl-conformance.php --tier <tier>"
      . " --config-repo <path> [--qdl-path <path>] [--contract <path>]\n"
      . "\n"
      . "  --tier         the tier branch whose QDL is evaluated, e.g."
      . " us-east-2-dev\n"
      . "  --config-repo  path to a checkout of the configuration repository;"
      . " it is read\n"
      . "                 with `git show` and never modified\n"
      . "  --qdl-path     repository-relative path to the QDL (defaults to the"
      . " DynamoDB\n"
      . "                 claims script)\n"
      . "  --contract     path to cfg_contract.json (defaults to this"
      . " plugin's)\n"
      . "\n"
      . "exit: 0 pass, 1 fail, 2 nothing to compare, 3 QDL absent, 64 usage\n";
  }

  /**
   * Run the CLI.
   *
   * @param array $argv
   * @return int Exit code.
   */
  public static function main($argv) {
    $options = array(
      'tier' => '',
      'config-repo' => '',
      'qdl-path' => self::QDL_PATH,
      'contract' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cfg_contract.json',
    );
    $arguments = array_slice($argv, 1);
    for ($i = 0; $i < count($arguments); $i++) {
      $argument = $arguments[$i];
      if ($argument === '-h' || $argument === '--help') {
        fwrite(STDOUT, self::usage());

        return 0;
      }
      $name = null;
      $value = null;
      if (strpos($argument, '--') === 0) {
        $body = substr($argument, 2);
        if (strpos($body, '=') !== false) {
          list($name, $value) = explode('=', $body, 2);
        } else {
          $name = $body;
          $value = isset($arguments[$i + 1]) ? $arguments[$i + 1] : null;
          $i++;
        }
      } elseif ($options['tier'] === '') {
        // A bare first argument is the tier.
        $name = 'tier';
        $value = $argument;
      }
      if ($name === null || !array_key_exists($name, $options) || $value === null) {
        fwrite(STDERR, "unrecognized or incomplete argument\n" . self::usage());

        return 64;
      }
      $options[$name] = $value;
    }

    if ($options['tier'] === '' || $options['config-repo'] === '') {
      fwrite(STDERR, "both --tier and --config-repo are required\n" . self::usage());

      return 64;
    }

    $contractJson = self::readFileOrNull($options['contract']);
    if ($contractJson === null) {
      fwrite(STDERR, "the capability contract could not be read\n");

      return 64;
    }

    $error = '';
    $qdl = self::gitShow($options['config-repo'], $options['tier'],
      $options['qdl-path'], $error);
    if ($error !== '') {
      fwrite(STDERR, $error . "\n");

      return 64;
    }

    $result = self::evaluate($contractJson, $qdl, $options['tier']);
    $result['qdl_path'] = $options['qdl-path'];
    fwrite(STDOUT, self::render($result));

    return self::exitCode($result['verdict']);
  }
}

// Run only as the entry script. Requiring this file -- which the test case
// does -- must not execute anything.
if (PHP_SAPI === 'cli' && isset($argv[0]) && isset($_SERVER['argv'])
    && realpath($argv[0]) === realpath(__FILE__)) {
  exit(QdlConformance::main($argv));
}
