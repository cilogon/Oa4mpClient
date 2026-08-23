<?php
/**
 * The QDL conformance check (U7, R8-R10).
 *
 * bin/qdl-conformance.php answers whether a named tier's dynamodb_claims.qdl
 * implements every capability cfg_contract.json declares. It is a plain `php`
 * script rather than a Console shell (KTD9) because it reads two files and
 * needs no Registry bootstrap; the comparison lives in a class with no I/O so
 * most of this file can drive it from fixtures it writes and removes, with no
 * git, no configuration-repository checkout, and no network.
 *
 * The resolution half is the exception, and deliberately so. gitShow() is the
 * only code implementing R8's distinguishing property -- that the check
 * evaluates a NAMED TIER's QDL, out of the object store, rather than whatever
 * is checked out in a working copy that is shared and sits on a branch of its
 * own. A regression that read the working tree, swallowed an unknown tier, or
 * returned '' where it means null would produce a confident verdict about the
 * wrong file, and no fixture-driven test would notice.
 *
 * There is no git binary in the image this suite runs in, and there must not
 * be: Test/docker/docker-compose.yml digest-pins that image precisely so that
 * a pass or a fail is attributable to the pull request's code rather than to
 * image drift, and installing a package would trade that for a network
 * dependency at test time. The shell-out is therefore a SEAM. gitShow() takes
 * an optional runner and defaults to QdlConformance::runGit(); the tests below
 * hand it a stub that records the command it was given and answers with a
 * scripted status and output.
 *
 * That pins what is this script's to get right: that the command composed is
 * `git show <tier>:<path>` against the repository it was pointed at and not a
 * read of the checkout, that a tier the repository does not carry stops before
 * any read is attempted, and which of the two nulls comes back. The working
 * tree those tests are told apart from is real -- a directory under the system
 * temporary directory carrying DIFFERENT text at the same path -- so a
 * regression that read the checkout returns that text and the assertion says
 * which one it got. Every such directory is removed in tearDown().
 *
 * What a stub cannot cover is git's own behaviour: that `git show` on a path a
 * commit does not carry exits non-zero, and that `rev-parse --git-dir` fails
 * outside a repository, are git's contract rather than this script's. The
 * second of those is still exercised for real -- Scenario 16 deliberately runs
 * the DEFAULT runner against a directory that is not a repository, which is an
 * error whether git is present or absent -- and the first is covered by
 * running the check against the real configuration repository, the command
 * AGENTS.md records.
 *
 * Four properties are load-bearing and are asserted separately, because
 * collapsing any of them is how this gate would disarm itself:
 *
 *  - Direction (R9). A contract capability the QDL does not implement FAILS.
 *    A capability the QDL implements that the contract does not declare does
 *    NOT: during a staggered rollout the tier is deployed first, so a QDL
 *    running ahead of the plugin is the expected steady state, and a check
 *    that reddened on it would be red for the whole of every rollout.
 *  - Naming (R10). A failure names the capability, not merely that the two
 *    sides differ.
 *  - Fail-closed cross-check (KTD7). The declaration block is the vocabulary
 *    AUTHORITY and pattern extraction from the code is a cross-check against
 *    it. A read form the extractor does not recognize reddens the run rather
 *    than being skipped -- a skipped form is a form whose undeclared literals
 *    go unnoticed. This is not hypothetical: the first run against the real
 *    us-east-2-dev QDL reddened on the extraction operator `x.\'cm_given'`,
 *    which the extractor did not yet know, and that is how the form was found.
 *  - Bounded output. The verdict is pasted into pull requests on
 *    github.com/cilogon/Oa4mpClient, which is PUBLIC, while the configuration
 *    repository it reads is PRIVATE. Nothing but capability names, the tier,
 *    counts and a verdict may reach that text.
 *
 * Three outcomes are deliberately distinct verdicts and are asserted as such:
 * a conformant QDL (PASS), a tier with no QDL at that path (QDL_ABSENT), and a
 * QDL carrying no declaration block at all (NOTHING_TO_COMPARE). Reporting an
 * absent file as every capability missing would send a reader hunting for a
 * vocabulary gap that does not exist.
 *
 * No database and no credential are needed. The one credential-shaped string
 * below holds the placeholder Test/Case/Model/ClaimCfgFixtureHygieneTest.php
 * declares, and exists only so a test can prove it never reaches the output.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U7.
 */

require_once App::pluginPath('Oa4mpClient') . 'bin' . DS . 'qdl-conformance.php';

class QdlConformanceTest extends Oa4mpTestCase {

  /** Temporary fixture files written by a test, removed in tearDown(). */
  private $temporaryFiles = array();

  /** Throwaway git repositories built by a test, removed in tearDown(). */
  private $temporaryDirectories = array();

  public function setUp() {
    // The runner reuses one instance across methods, so nothing may carry over.
    $this->temporaryFiles = array();
    $this->temporaryDirectories = array();
    $this->gitCommands = array();
  }

  public function tearDown() {
    foreach ($this->temporaryFiles as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    foreach ($this->temporaryDirectories as $path) {
      $this->removeTree($path);
    }
    $this->temporaryFiles = array();
    $this->temporaryDirectories = array();
    $this->gitCommands = array();
  }

  /**
   * Remove a directory tree this file created, and only such a tree.
   *
   * Recursion in PHP rather than `rm -rf`, and refusing anything that is not
   * under the system temporary directory: a helper that shells out to a
   * recursive delete is one bad variable away from removing something that
   * matters.
   *
   * @param string $path
   * @return void
   */
  private function removeTree($path) {
    $temporary = realpath(sys_get_temp_dir());
    $real = realpath($path);
    if ($real === false || $temporary === false
        || strpos($real, $temporary . DIRECTORY_SEPARATOR) !== 0) {
      return;
    }
    foreach (scandir($real) as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $child = $real . DIRECTORY_SEPARATOR . $entry;
      if (is_dir($child) && !is_link($child)) {
        $this->removeTree($child);
      } else {
        unlink($child);
      }
    }
    rmdir($real);
  }

  // ------------------------------------------------------------------
  // Fixtures.
  // ------------------------------------------------------------------

  /** Write a fixture file and register it for removal. */
  private function fixture($suffix, $contents) {
    $path = tempnam(sys_get_temp_dir(), 'oa4mpqdl');
    $path .= $suffix;
    file_put_contents($path, $contents);
    $this->temporaryFiles[] = $path;
    $this->temporaryFiles[] = substr($path, 0, -strlen($suffix));

    return $path;
  }

  /**
   * A miniature contract in cfg_contract.json's shape.
   *
   * Small on purpose: this file tests the comparison, not the real contract's
   * contents, which Test/Case/Model/ContractDeclarationTest.php owns. One
   * retired entry is always present so that "retired entries are not required
   * of the QDL" is exercised by every scenario rather than by one.
   *
   * $extraGroups adds whole capability groups beyond the two mapped ones, as
   * group name => list of capability names. That is how a contract group with
   * no declaration variable -- the growth the contract is designed for, and
   * the edit that would otherwise leave the QDL never asked to implement it --
   * is put in front of the check.
   */
  private function contractJson($qdlArgs, $sourceModels = array('EmailAddress'),
      $extraGroups = array()) {
    $entries = array();
    foreach ($qdlArgs as $name) {
      $entries[] = array(
        'name' => $name, 'introduced_in' => 1, 'retired_in' => null,
        'secret_bearing' => false,
      );
    }
    $entries[] = array(
      'name' => 'long_gone_arg', 'introduced_in' => 1, 'retired_in' => 2,
      'secret_bearing' => false,
    );
    $models = array();
    foreach ($sourceModels as $name) {
      $models[] = array(
        'name' => $name, 'introduced_in' => 1, 'retired_in' => null,
        'secret_bearing' => false,
      );
    }

    $capabilities = array(
      'qdl_args' => array('entries' => $entries),
      'source_model_values' => array('entries' => $models),
    );
    foreach ($extraGroups as $group => $names) {
      $groupEntries = array();
      foreach ($names as $name) {
        $groupEntries[] = array(
          'name' => $name, 'introduced_in' => 1, 'retired_in' => null,
          'secret_bearing' => false,
        );
      }
      $capabilities[$group] = array('entries' => $groupEntries);
    }

    return json_encode(array(
      'contract_version' => 7,
      'capabilities' => $capabilities,
    ));
  }

  /**
   * A miniature QDL: a declaration block, then code that reads it.
   *
   * The comment carries a credential-shaped placeholder and a sentence of
   * prose so that the bounded-output test has something recognizable to look
   * for in the rendered verdict.
   */
  private function qdlSource($declaredArgs, $body = '') {
    $quoted = array();
    foreach ($declaredArgs as $name) {
      $quoted[] = "'" . $name . "'";
    }

    return "// PRIVATE CONFIGURATION REPOSITORY CONTENT, must never be printed.\n"
      . "// secret_access_key example value not-a-real-secret in a comment.\n"
      . "logger(10);\n"
      . 'claims_contract_qdl_args. := [' . implode(",\n                              ", $quoted)
      . "];\n"
      . "claims_contract_source_model_values. := ['EmailAddress'];\n"
      . "config_params. := script_args(0);\n"
      . $body;
  }

  /** Evaluate a contract and a QDL written to fixture files, as the CLI does. */
  private function evaluateFixtures($contractJson, $qdlSource, $tier = 'a-tier') {
    $contractPath = $this->fixture('.json', $contractJson);
    $contractText = QdlConformance::readFileOrNull($contractPath);
    $this->assertNotEmpty($contractText, 'the contract fixture was written');

    $qdlText = null;
    if ($qdlSource !== null) {
      $qdlPath = $this->fixture('.qdl', $qdlSource);
      $qdlText = QdlConformance::readFileOrNull($qdlPath);
      $this->assertNotEmpty($qdlText, 'the QDL fixture was written');
    }

    return QdlConformance::evaluate($contractText, $qdlText, $tier);
  }

  // ------------------------------------------------------------------
  // The resolution half: a stub runner, and a real working tree to differ
  // from. See the file header for why there is no git binary here.
  // ------------------------------------------------------------------

  /** Every command the stub runner was handed, in the order it was handed them. */
  private $gitCommands = array();

  /** An empty directory under the system temporary directory. */
  private function temporaryDirectory() {
    $path = tempnam(sys_get_temp_dir(), 'oa4mpgitrepo');
    unlink($path);
    $this->assertTrue(mkdir($path, 0700), "the temporary directory $path was created");
    $this->temporaryDirectories[] = $path;

    return $path;
  }

  /**
   * A directory holding working-tree files, and nothing else.
   *
   * There is no object store and no branch here on purpose: what these files
   * are for is to DIFFER from the text the stub answers `git show` with, so
   * that a gitShow() reading the checkout instead of composing a read of the
   * named tier returns this text and the assertion says which one it got.
   *
   * @param array $files Repository-relative path => file contents.
   * @return string The directory.
   */
  private function workingTree($files = array()) {
    $repo = $this->temporaryDirectory();
    foreach ($files as $path => $contents) {
      $full = $repo . DIRECTORY_SEPARATOR . $path;
      $directory = dirname($full);
      if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
      }
      file_put_contents($full, $contents);
    }

    return $repo;
  }

  /**
   * A stub for gitShow()'s command runner, in QdlConformance::runGit()'s shape.
   *
   * It records every command it is handed -- in $this->gitCommands, which the
   * tests assert on, since WHICH command is composed is the property R8 turns
   * on -- and answers from a script keyed by a fragment of the command.
   *
   * A command matching no fragment comes back the way git does when it is
   * asked for something the repository does not have: exit 128, nothing on
   * standard output. That default is what expresses "this tier is not there"
   * and "that commit carries no such file", so a scripted fragment is only
   * ever needed for what the repository DOES have. It also means a gitShow()
   * that composed the wrong command -- one naming no tier, or naming the
   * fixture's working tree -- fails to match and reads as absent, rather than
   * quietly getting the right answer for the wrong reason.
   *
   * @param array $script command fragment => array(status, output lines).
   *   Fragments are tried in the order given.
   * @return callable
   */
  private function stubGit($script) {
    $this->gitCommands = array();

    return function ($command) use ($script) {
      $this->gitCommands[] = $command;
      foreach ($script as $fragment => $answer) {
        if (strpos($command, $fragment) !== false) {
          return array(
            'status' => $answer[0],
            'output' => isset($answer[1]) ? $answer[1] : array(),
          );
        }
      }

      return array('status' => 128, 'output' => array());
    };
  }

  /** The script entries every scenario where the repository itself is fine shares. */
  private function repositoryIsFine() {
    return array(
      'rev-parse --git-dir' => array(0, array('.git')),
      'rev-parse --verify' => array(0, array('0123456789abcdef0123456789abcdef01234567')),
    );
  }

  /** Every command the stub was handed, as one string to search. */
  private function commandLog() {
    return implode("\n", $this->gitCommands);
  }

  // ------------------------------------------------------------------
  // Direction and naming.
  // ------------------------------------------------------------------

  /**
   * Scenario 1: a contract capability the QDL does not declare fails, and the
   * failure names it (R10).
   *
   * The control matters as much as the failure: the same contract against a
   * QDL that declares all three passes, so the red comes from the seeded gap
   * and not from the fixture being unworkable.
   */
  public function testAContractCapabilityAbsentFromTheDeclarationFailsAndIsNamed() {
    $contract = $this->contractJson(array('require_active_status',
      'partition_key_template', 'claim_mappings'));

    $conformant = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status', 'partition_key_template',
        'claim_mappings')));
    $this->assertEqual(QdlConformance::VERDICT_PASS, $conformant['verdict'],
      'the control: a QDL declaring every contract capability passes, so the'
      . ' red below is the seeded gap and not the fixture');

    $result = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status', 'claim_mappings')));

    $this->assertEqual(QdlConformance::VERDICT_FAIL, $result['verdict'],
      'a capability the contract declares and the QDL does not implement is a'
      . ' failure');
    $rendered = QdlConformance::render($result);
    $this->assertContains('partition_key_template', $rendered,
      'the failure names the missing capability rather than reporting only'
      . ' that the two sides differ');
    $this->assertContains('MISSING', $rendered,
      'and says the capability is missing from the QDL');
    $this->assertContains('qdl_args', $rendered,
      'naming the capability group it belongs to');
    $this->assertEqual(1, QdlConformance::exitCode($result['verdict']),
      'a failing check exits non-zero so CI or a shell can act on it');
  }

  /**
   * Scenario 2: a QDL that implements more than the contract declares is NOT a
   * failure (R9).
   *
   * This is the expected steady state, not an anomaly: the QDL is deployed to
   * a tier before any plugin release emits a capability introduced with it, so
   * a check that reddened here would be red for the whole of every rollout.
   */
  public function testACapabilityTheQdlImplementsBeyondTheContractDoesNotFail() {
    $result = $this->evaluateFixtures(
      $this->contractJson(array('require_active_status', 'claim_mappings')),
      $this->qdlSource(array('require_active_status', 'claim_mappings',
        'a_capability_no_plugin_emits_yet')));

    $this->assertEqual(QdlConformance::VERDICT_PASS, $result['verdict'],
      'a tier running ahead of the plugin is conformant, not failing');
    $this->assertEqual(0, QdlConformance::exitCode($result['verdict']),
      'and exits zero');
    $rendered = QdlConformance::render($result);
    $this->assertContains('a_capability_no_plugin_emits_yet', $rendered,
      'the extra capability is still reported, so a reader can see the'
      . ' rollout gap');
    $this->assertContains('not a failure', $rendered,
      'labelled as expected rather than as a problem');
    $this->assertEmpty($result['missing'],
      'nothing is missing in this direction');
  }

  // ------------------------------------------------------------------
  // The fail-closed cross-check (KTD7).
  // ------------------------------------------------------------------

  /**
   * Scenario 3: the declaration block is the authority, and code that reads a
   * literal the block omits fails the cross-check.
   *
   * Without this, the declaration could drift into fiction: a handler added
   * below without its name being added above would leave the plugin's
   * conformance answer describing a QDL that no longer exists.
   */
  public function testALiteralReadByTheCodeButOmittedFromTheDeclarationFailsTheCrossCheck() {
    $contract = $this->contractJson(array('require_active_status'));

    $clean = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status'),
        "require_active := has_key('require_active_status', config_params.)\n"));
    $this->assertEqual(QdlConformance::VERDICT_PASS, $clean['verdict'],
      'the control: code reading only declared literals passes');
    $this->assertTrue($clean['cross_checked'] > 0,
      'and the cross-check actually extracted something, so the pass above is'
      . ' not vacuous');

    $result = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status'),
        "require_active := has_key('require_active_status', config_params.)\n"
        . "undeclared := config_params.'an_undeclared_arg';\n"));

    $this->assertEqual(QdlConformance::VERDICT_FAIL, $result['verdict'],
      'a literal the code reads that the declaration omits fails the'
      . ' cross-check');
    $rendered = QdlConformance::render($result);
    $this->assertContains('an_undeclared_arg', $rendered,
      'and the undeclared literal is named');
    $this->assertContains('CROSS-CHECK', $rendered,
      'attributed to the cross-check, not to the contract comparison, since'
      . ' the two are fixed by editing different files');
    $this->assertEmpty($result['missing'],
      'the contract comparison itself is clean here');
  }

  /**
   * Scenario 4: a read form the extractor does not recognize fails closed.
   *
   * The whole cross-check rests on extraction seeing every way the QDL reads a
   * cfg key. A form it does not know cannot be assumed harmless, because the
   * failure it hides is exactly the one the cross-check exists to catch. The
   * bare-identifier dynamic key below is such a form.
   */
  public function testAnUnrecognizedReadFormFailsClosedRatherThanReportingConformance() {
    $contract = $this->contractJson(array('require_active_status'));
    $recognized = "flag := config_params.'require_active_status';\n";

    $clean = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status'), $recognized));
    $this->assertEqual(QdlConformance::VERDICT_PASS, $clean['verdict'],
      'the control: every read form here is recognized');

    $result = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status'),
        $recognized . "dynamic := config_params.some_computed_key;\n"));

    $this->assertEqual(QdlConformance::VERDICT_FAIL, $result['verdict'],
      'an unrecognized read form reddens rather than being skipped; a skipped'
      . ' form is one whose undeclared literals go unnoticed');
    $rendered = QdlConformance::render($result);
    $this->assertContains('unrecognized read form', $rendered,
      'and says so, so the fix is understood to be teaching the extractor the'
      . ' form rather than editing the QDL');
    $this->assertContains('failing closed', $rendered,
      'naming the behaviour explicitly');
    $this->assertNotEmpty($result['unrecognized'],
      'the unrecognized form is recorded on the result, not only in the text');
  }

  // ------------------------------------------------------------------
  // Distinct outcomes.
  // ------------------------------------------------------------------

  /**
   * Scenario 5: a tier whose QDL file does not exist is reported distinctly.
   *
   * Reporting it as every capability missing would read as a vocabulary gap
   * and send a reader looking for one; the actual answer is that the tier has
   * no such file, which is fixed somewhere else entirely.
   */
  public function testATierWhoseQdlFileDoesNotExistIsReportedDistinctly() {
    $contract = $this->contractJson(array('require_active_status',
      'claim_mappings'));
    $contractPath = $this->fixture('.json', $contract);

    // A path that existed and then did not, so the absent branch is reached
    // through the same reader the CLI uses rather than by passing null.
    $qdlPath = $this->fixture('.qdl', 'irrelevant');
    unlink($qdlPath);
    $absent = QdlConformance::readFileOrNull($qdlPath);
    $this->assertNull($absent, 'the reader reports an absent file as null');

    $result = QdlConformance::evaluate(
      QdlConformance::readFileOrNull($contractPath), $absent, 'no-such-tier');

    $this->assertEqual(QdlConformance::VERDICT_QDL_ABSENT, $result['verdict'],
      'an absent QDL is its own verdict');
    $this->assertEmpty($result['missing'],
      'and is NOT reported as every capability missing');
    $rendered = QdlConformance::render($result);
    $this->assertContains('no-such-tier', $rendered,
      'the report names the tier it evaluated (R8)');
    $this->assertContains('carries no QDL', $rendered,
      'and says the file is absent');
    $this->assertTrue(strpos($rendered, 'MISSING from the QDL') === false,
      'no capability is reported missing, because none was compared');
    $this->assertEqual(3, QdlConformance::exitCode($result['verdict']),
      'with an exit code of its own, so a caller can tell the two apart'
      . ' without parsing text');
  }

  /**
   * Scenario 6: a pass is distinguishable from having found nothing to
   * compare.
   *
   * This is the failure shape of
   * docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md,
   * where a scanner with no rules loaded printed what a clean repository
   * prints. A QDL with no declaration block gives this check nothing to
   * compare, and that must not render as conformance.
   */
  public function testASuccessIsDistinguishableFromHavingNothingToCompare() {
    $contract = $this->contractJson(array('require_active_status'));

    $passed = $this->evaluateFixtures($contract,
      $this->qdlSource(array('require_active_status')));
    $empty = $this->evaluateFixtures($contract,
      "// A QDL carrying no declaration block at all.\nlogger(10);\n");

    $this->assertEqual(QdlConformance::VERDICT_PASS, $passed['verdict'],
      'the conformant fixture passes');
    $this->assertEqual(QdlConformance::VERDICT_NOTHING_TO_COMPARE,
      $empty['verdict'],
      'a QDL with no declaration block reaches no verdict about conformance');

    $passedText = QdlConformance::render($passed);
    $emptyText = QdlConformance::render($empty);
    $this->assertTrue($passedText !== $emptyText,
      'the two reports are not the same text');
    $this->assertContains('VERDICT: PASS', $passedText,
      'the conformant run says so plainly');
    $this->assertTrue(strpos($emptyText, 'VERDICT: PASS') === false,
      'and the empty run never prints a pass');
    $this->assertContains('no claims_contract_', $emptyText,
      'it says what was not found instead');
    $this->assertTrue(
      QdlConformance::exitCode($empty['verdict'])
        !== QdlConformance::exitCode($passed['verdict']),
      'the exit codes differ too, so a shell can tell them apart');
  }

  // ------------------------------------------------------------------
  // Bounded output.
  // ------------------------------------------------------------------

  /**
   * Scenario 7: the report carries no content read from the QDL beyond
   * capability names.
   *
   * The verdict is pasted into pull requests on a PUBLIC repository while the
   * QDL lives in a PRIVATE one. The fixture below carries comment prose, a
   * credential-shaped placeholder and an absolute fixture path, and the report
   * is checked line by line: every non-empty line of the QDL must be absent
   * from it. The failing case is checked as well as the passing one, since a
   * failure report is the one that has the most to say.
   */
  public function testTheReportCarriesNoContentReadFromTheQdl() {
    $contract = $this->contractJson(array('require_active_status',
      'partition_key_template'));
    $contractPath = $this->fixture('.json', $contract);
    $qdlText = $this->qdlSource(array('require_active_status'),
      "// A second comment naming an internal host, private-host.example.org.\n"
      . "flag := config_params.'require_active_status';\n");
    $qdlPath = $this->fixture('.qdl', $qdlText);

    $reports = array(
      'failing' => QdlConformance::render(QdlConformance::evaluate(
        QdlConformance::readFileOrNull($contractPath),
        QdlConformance::readFileOrNull($qdlPath), 'a-tier')),
      'passing' => QdlConformance::render(QdlConformance::evaluate(
        QdlConformance::readFileOrNull($contractPath),
        $this->qdlSource(array('require_active_status', 'partition_key_template')),
        'a-tier')),
    );

    foreach ($reports as $which => $report) {
      $this->assertNotEmpty($report, "the $which report has text to inspect");
      $this->assertTrue(strpos($report, 'not-a-real-secret') === false,
        "the $which report carries no credential-shaped value from the QDL");
      $this->assertTrue(strpos($report, 'PRIVATE CONFIGURATION') === false,
        "the $which report carries no comment prose from the QDL");
      $this->assertTrue(strpos($report, 'private-host.example.org') === false,
        "the $which report carries no host named in the QDL");
      $this->assertTrue(strpos($report, $qdlPath) === false,
        "the $which report carries no absolute path to the QDL it read");
      $this->assertTrue(strpos($report, sys_get_temp_dir()) === false,
        "nor any part of the directory it was read from");
      $this->assertTrue(strpos($report, 'logger(10)') === false,
        "the $which report carries no QDL source line");

      foreach (explode("\n", $qdlText) as $line) {
        $line = trim($line);
        if (strlen($line) < 12) {
          // Too short to be distinctive; a capability name could contain it.
          continue;
        }
        $this->assertTrue(strpos($report, $line) === false,
          "the $which report reproduces no line of the QDL: " . substr($line, 0, 20));
      }
    }

    // Not a vacuous pass: the failing report does name the capability, which
    // is the one thing that may cross from one repository to the other.
    $this->assertContains('partition_key_template', $reports['failing'],
      'the capability name itself is reported, since R10 requires naming the'
      . ' gap; it is a name the contract already declares publicly');
  }

  // ------------------------------------------------------------------
  // Coverage of the contract's growth.
  // ------------------------------------------------------------------

  /**
   * Scenario 8: a contract group this script maps to no QDL declaration
   * variable FAILS rather than passing with a note.
   *
   * The contract is designed to grow, so adding a capability group to
   * cfg_contract.json and not adding it to groupDeclarations() is the likeliest
   * future edit -- and it is the one that would leave the QDL never asked to
   * implement the group while the check still printed PASS. That is the
   * fail-open shape the whole check exists to prevent, so the unmapped group
   * is a failure and the report names it.
   */
  public function testAnUnmappedContractGroupFailsRatherThanPassingWithANote() {
    $mapped = $this->contractJson(array('require_active_status'));
    $qdl = $this->qdlSource(array('require_active_status'));

    $control = $this->evaluateFixtures($mapped, $qdl);
    $this->assertEqual(QdlConformance::VERDICT_PASS, $control['verdict'],
      'the control: the same contract and QDL pass while every group the'
      . ' contract declares is mapped to a declaration variable');

    $result = $this->evaluateFixtures(
      $this->contractJson(array('require_active_status'),
        array('EmailAddress'),
        array('a_group_nobody_mapped' => array('some_new_capability'))),
      $qdl);

    $this->assertEqual(QdlConformance::VERDICT_FAIL, $result['verdict'],
      'a contract group with no declaration variable is a failure: the QDL was'
      . ' never asked to implement it, so a pass would answer a question that'
      . ' was never put');
    $this->assertEqual(1, QdlConformance::exitCode($result['verdict']),
      'and exits non-zero, so CI reddens on the edit that added the group');
    $rendered = QdlConformance::render($result);
    $this->assertContains('a_group_nobody_mapped', $rendered,
      'the report names the unmapped group (R10), since the fix is an edit to'
      . ' that group by name');
    $this->assertContains('UNMAPPED', $rendered,
      'and says the group was never compared, rather than reporting it as a'
      . ' capability the QDL is missing');
    $this->assertContains('groupDeclarations', $rendered,
      'pointing at where the mapping goes');
    $this->assertEmpty($result['missing'],
      'nothing is reported missing from the QDL: the group was not compared at'
      . ' all, which is a different fault from a vocabulary gap');
  }

  /**
   * Scenario 9: a group listed in groupsNotRequired() still passes, with its
   * note.
   *
   * The excuse above has to remain available or the check becomes a permanent
   * false red: dynamo_module_config's keys are handed to the dynamo module
   * unopened and the QDL reads none of them. What Scenario 8 requires is that
   * excusing a group is a deliberate, named line -- not the default for
   * anything nobody mapped.
   */
  public function testAGroupTheQdlIsNotRequiredToImplementStillPassesWithItsNote() {
    $excused = QdlConformance::groupsNotRequired();
    $this->assertNotEmpty($excused,
      'some group is excused by name, which is what keeps Scenario 8 from'
      . ' being a permanent red on dynamo_module_config_keys');
    $names = array_keys($excused);
    $group = $names[0];

    $result = $this->evaluateFixtures(
      $this->contractJson(array('require_active_status'),
        array('EmailAddress'),
        array($group => array('a_key_handed_over_unopened'))),
      $this->qdlSource(array('require_active_status')));

    $this->assertEqual(QdlConformance::VERDICT_PASS, $result['verdict'],
      'a group excused by name is not a failure');
    $this->assertEqual(0, QdlConformance::exitCode($result['verdict']),
      'and exits zero');
    $this->assertEmpty($result['unmapped_groups'],
      'it is not recorded as an unmapped group');
    $rendered = QdlConformance::render($result);
    $this->assertContains('not required of the QDL', $rendered,
      'the note still says the group was not compared, so a reader is never'
      . ' left thinking it was');
    $this->assertContains($group, $rendered,
      'naming the group it excused');
  }

  /**
   * Scenario 10: a contract entry the model would raise on is unreadable here
   * too, rather than being silently skipped.
   *
   * Oa4mpClientOa4mpServer::cfgContractNames() raises on an entry that does
   * not name itself and on an entry with no retired_in at all -- a missing
   * retired_in is malformed and never an implied null. A contract this check
   * quietly accepted while every marshalling call raised on it would report
   * conformance about a plugin that cannot emit anything at all.
   */
  public function testAMalformedContractEntryIsUnreadableRatherThanSilentlySkipped() {
    $good = json_decode($this->contractJson(array('require_active_status')), true);

    $wellFormed = $this->evaluateFixtures(json_encode($good),
      $this->qdlSource(array('require_active_status')));
    $this->assertEqual(QdlConformance::VERDICT_PASS, $wellFormed['verdict'],
      'the control: the same contract with well-formed entries passes');

    $malformed = array(
      'an entry that does not name itself' => array(
        'introduced_in' => 1, 'retired_in' => null, 'secret_bearing' => false),
      'an entry with no retired_in at all' => array(
        'name' => 'require_active_status', 'introduced_in' => 1,
        'secret_bearing' => false),
    );
    foreach ($malformed as $which => $entry) {
      $contract = $good;
      $contract['capabilities']['qdl_args']['entries'][] = $entry;
      $result = $this->evaluateFixtures(json_encode($contract),
        $this->qdlSource(array('require_active_status')));

      $this->assertEqual(QdlConformance::VERDICT_CONTRACT_UNREADABLE,
        $result['verdict'],
        "$which makes the contract unreadable, matching the model, which"
        . ' raises on it');
      $this->assertTrue(QdlConformance::exitCode($result['verdict']) !== 0,
        "$which does not exit zero");
      $this->assertTrue(
        strpos(QdlConformance::render($result), 'VERDICT: PASS') === false,
        "$which never renders a pass");
    }
  }

  // ------------------------------------------------------------------
  // The item namespace, in every read form.
  // ------------------------------------------------------------------

  /**
   * Scenario 11: a DynamoDB item field is excluded by PREFIX, in every read
   * form, not only in the one the real QDL happened to use first.
   *
   * The item the QDL reads mirrors Registry records and its keys are all
   * `cm_`-prefixed; they are not cfg vocabulary and the contract declares
   * none of them. `x` is bound to a mapping, to a constraint and to an item in
   * different places in the same file, which is why the exclusion is by prefix
   * rather than by variable -- and why it has to hold in whichever form the
   * name is read. It did not: the extraction operator was excluded and the
   * presence test was not, so a QDL doing has_key('cm_given', x.) failed the
   * cross-check and blocked a contract bump on a QDL that was conformant.
   */
  public function testAnItemFieldIsExcludedByPrefixInEveryReadForm() {
    $contract = $this->contractJson(array('require_active_status'));
    $declared = array('require_active_status');

    $forms = array(
      'the extraction operator' => "given := x.\\'cm_given';\n",
      'a presence test' => "has_given := has_key('cm_given', x.);\n",
      'dotted string access' => "family := x.'cm_family';\n",
    );
    foreach ($forms as $which => $body) {
      $result = $this->evaluateFixtures($contract,
        $this->qdlSource($declared, $body));

      $this->assertEqual(QdlConformance::VERDICT_PASS, $result['verdict'],
        "an item field read by $which is not cfg vocabulary and carries no"
        . ' contract obligation');
      $this->assertEmpty($result['undeclared_reads'],
        "reading an item field by $which is not an undeclared literal");
      $this->assertEmpty($result['unrecognized'],
        "and the form itself is recognized, so it fails closed on nothing");
    }

    // Not a vacuous pass: the same forms still redden on a name outside the
    // item namespace, so the guard excludes a namespace and not a branch.
    foreach (array("undeclared := has_key('an_undeclared_field', x.);\n",
        "undeclared := x.'an_undeclared_field';\n") as $body) {
      $red = $this->evaluateFixtures($contract,
        $this->qdlSource($declared, $body));

      $this->assertEqual(QdlConformance::VERDICT_FAIL, $red['verdict'],
        'a name outside the item namespace read off the same variable is'
        . ' still cross-checked, so the prefix guard did not disarm the form');
      $this->assertContains('an_undeclared_field', QdlConformance::render($red),
        'and is named');
    }
  }

  /**
   * Scenario 12: a block comment is stripped, and an apostrophe inside one
   * cannot swallow the file behind it.
   *
   * The comment stripper is quote-aware because the declaration block's own
   * comment carries an apostrophe. Handling only `//` left the same trap open
   * one syntax over: a block comment saying "the mapping's fields" would put
   * the tracker inside a string for the rest of the file, and the declaration
   * block behind it would simply vanish. That does not fail closed the way an
   * unrecognized read form does -- it lands as NOTHING_TO_COMPARE or as a
   * silently shrunken cross-check -- so the form is stripped rather than
   * documented as a limitation.
   */
  public function testABlockCommentIsStrippedAndItsApostropheHidesNothing() {
    $source = "/* The claim mapping's own fields, in a block comment. */\n"
      . "claims_contract_qdl_args. := ['require_active_status'];\n"
      . "claims_contract_source_model_values. := ['EmailAddress'];\n"
      . "/* commented out: undeclared := config_params.'a_commented_out_arg'; */\n"
      . "flag := config_params.'require_active_status';\n";

    $stripped = QdlConformance::stripComments($source);
    $this->assertEqual(strlen($source), strlen($stripped),
      'stripping preserves offsets, so the line numbers the report cites stay'
      . ' true');
    $this->assertEqual(substr_count($source, "\n"), substr_count($stripped, "\n"),
      'and preserves the newlines inside the comment for the same reason');
    $this->assertTrue(strpos($stripped, 'block comment') === false,
      'the block comment is gone');
    $this->assertContains("claims_contract_qdl_args", $stripped,
      'and the declaration block BEHIND the apostrophe survives, which is the'
      . ' whole point: a tracker that read it as a string would drop this');

    $result = $this->evaluateFixtures(
      $this->contractJson(array('require_active_status')), $source);

    $this->assertEqual(QdlConformance::VERDICT_PASS, $result['verdict'],
      'the QDL is read as conformant rather than as having no declaration'
      . ' block at all');
    $this->assertTrue($result['cross_checked'] > 0,
      'and the cross-check still extracted the code below the comments, so the'
      . ' pass is not the vacuous one of having seen nothing');
    $this->assertEmpty($result['undeclared_reads'],
      'a literal that is only inside a comment is not a read of the code');
    $this->assertTrue(
      strpos(QdlConformance::render($result), 'a_commented_out_arg') === false,
      'and is not reported at all');
  }

  // ------------------------------------------------------------------
  // Resolution: the NAMED tier, not the working tree (R8).
  // ------------------------------------------------------------------

  /** The path the fixture repositories carry their QDL at. */
  private function fixtureQdlPath() {
    return 'roles/oa4mp-server/files/qdl/dynamodb_claims.qdl';
  }

  /**
   * Scenario 13: the QDL comes from the NAMED TIER's branch, not from
   * whatever the working copy holds.
   *
   * This is R8's distinguishing property and gitShow() is the only code that
   * implements it. The configuration repository checkout is shared and sits on
   * a branch of its own, so the check must never depend on -- or change --
   * what is checked out there. Two things are asserted, and they are different
   * claims: that the COMMAND composed is a read of the named tier out of the
   * object store, spelled out here in full; and that the text handed back is
   * the one that read produced rather than the DIFFERENT text sitting in the
   * fixture's real working tree at the same path, which is what a regression
   * reading the checkout would return.
   */
  public function testGitShowReadsTheNamedTiersQdlAndNotTheWorkingTree() {
    $path = $this->fixtureQdlPath();
    $repo = $this->workingTree(
      array($path => "// IN THE WORKING TREE, WHICH IS NOT WHAT IS EVALUATED\n"));
    $runner = $this->stubGit($this->repositoryIsFine() + array(
      'show ' . escapeshellarg('a-tier:' . $path)
        => array(0, array('// ON THE TIER BRANCH', 'logger(10);')),
      'show ' . escapeshellarg('another-tier:' . $path)
        => array(0, array('// ON THE OTHER BRANCH', 'logger(20);')),
    ));

    $error = 'not reset';
    $text = QdlConformance::gitShow($repo, 'a-tier', $path, $error, $runner);

    $this->assertEqual('', $error, 'reading an existing tier is not an error');
    $this->assertNotEmpty($text, 'the tier branch carries the QDL');
    $this->assertContains('ON THE TIER BRANCH', $text,
      'the text comes from the branch that was NAMED (R8)');
    $this->assertTrue(strpos($text, 'IN THE WORKING TREE') === false,
      'and NOT from the working copy, which is shared and sits on a branch of'
      . ' its own; a check that read it would answer about the wrong file');

    $expected = 'git -C ' . escapeshellarg($repo) . ' show '
      . escapeshellarg('a-tier:' . $path);
    $this->assertTrue(in_array($expected, $this->gitCommands, true),
      'the QDL is read with `git show <tier>:<path>` against the repository it'
      . ' was pointed at -- out of the object store, which is the only way to'
      . ' read a NAMED tier without depending on what is checked out. The'
      . ' commands composed were: ' . $this->commandLog());
    foreach (array('checkout', 'switch', 'reset', 'fetch', 'pull', 'stash')
        as $mutating) {
      $this->assertTrue(strpos($this->commandLog(), ' ' . $mutating) === false,
        "nothing asks git to $mutating: the checkout belongs to somebody else"
        . ' and this check is read-only in it');
    }

    $other = QdlConformance::gitShow($repo, 'another-tier', $path, $error,
      $runner);
    $this->assertContains('ON THE OTHER BRANCH', $other,
      'naming a different tier reads that tier');
    $this->assertTrue($other !== $text,
      'the two tiers are told apart, so the tier argument is not decorative:'
      . ' it is what the composed command names');

    // The read leaves the checkout exactly as it was: no checkout, no switch.
    $this->assertContains('IN THE WORKING TREE',
      file_get_contents($repo . DIRECTORY_SEPARATOR . $path),
      'the working tree is untouched by the read');
  }

  /**
   * Scenario 14: a tier the repository does not carry is an error, not a
   * quiet null.
   *
   * Null alone means "that tier has no QDL at that path", which is a verdict
   * of its own. A misspelled tier that returned it would report a conformance
   * outcome about a tier that does not exist.
   */
  public function testGitShowRefusesATierTheRepositoryDoesNotCarry() {
    $path = $this->fixtureQdlPath();
    $repo = $this->workingTree(array($path => "logger(10);\n"));
    // Only a-tier resolves. Every other command -- the verify of a tier this
    // repository does not carry among them -- gets the stub's default, which
    // is what git answers for something it does not have.
    $runner = $this->stubGit(array(
      'rev-parse --git-dir' => array(0, array('.git')),
      'rev-parse --verify --quiet ' . escapeshellarg('a-tier^{commit}')
        => array(0, array('0123456789abcdef0123456789abcdef01234567')),
    ));

    $error = '';
    $text = QdlConformance::gitShow($repo, 'no-such-tier', $path, $error,
      $runner);

    $this->assertNull($text, 'no text comes back for a tier that is not there');
    $this->assertNotEmpty($error,
      'and it is an ERROR, distinct from the null that means the tier carries'
      . ' no such file');
    $this->assertContains('no-such-tier', $error,
      'the message names the tier the operator typed');
    $this->assertTrue(strlen($error) < 200,
      'the message stays bounded, since it can reach a PUBLIC pull request');
    $this->assertTrue(strpos($error, $repo) === false,
      'and carries no path into the configuration repository');
    $this->assertTrue(strpos($this->commandLog(), ' show ') === false,
      'no read is attempted at all once the tier does not resolve: a check that'
      . ' asked anyway would turn a misspelled tier into a conformance verdict'
      . ' about a tier that does not exist');

    $runner = $this->stubGit(array());
    $error = '';
    $rejected = QdlConformance::gitShow($repo, 'a-tier; rm -rf /', $path, $error,
      $runner);
    $this->assertNull($rejected,
      'a tier name that is not a plausible branch name is refused outright');
    $this->assertNotEmpty($error, 'with an error of its own');
    $this->assertTrue(strpos($error, 'rm -rf') === false,
      'and the refused value is not echoed back into the output');
    $this->assertEmpty($this->gitCommands,
      'and no command was composed from it at all, so the implausible name'
      . ' never reaches anything that would run it');
  }

  /**
   * Scenario 15: a tier that exists but carries no file at the path is the
   * null that means absent, with NO error, so evaluate() reaches QDL_ABSENT.
   *
   * The two nulls are the whole reason gitShow() takes $error by reference. If
   * this one arrived with a message set, the CLI would exit 64 on an
   * environment error rather than reporting the distinct QDL_ABSENT outcome
   * Scenario 5 pins.
   */
  public function testGitShowReportsAQdlAbsentOnTheTierAsAbsentRatherThanAsAnError() {
    $path = $this->fixtureQdlPath();
    $repo = $this->workingTree(
      array('roles/oa4mp-server/files/README' => "nothing here\n"));
    // The tier resolves; the read of the QDL does not. That is git's exit 128
    // with its explanation on standard error, which is discarded rather than
    // captured, since it names a path inside a PRIVATE repository.
    $runner = $this->stubGit($this->repositoryIsFine() + array(
      'show ' => array(128, array()),
    ));

    $error = 'not reset';
    $text = QdlConformance::gitShow($repo, 'a-tier', $path, $error, $runner);

    $this->assertNull($text, 'no text comes back');
    $this->assertEqual('', $error,
      'and NO error is set: the tier is fine, it simply carries no QDL there,'
      . ' which is a verdict of its own rather than an environment failure');
    // The null is the absent-file one and not an early return from an earlier
    // stage: every stage before this one sets $error, which is empty above.
    // Which command was composed is Scenario 13's property, and asserting it
    // here as well would make one regression redden two tests and say the same
    // thing twice.

    $result = QdlConformance::evaluate(
      $this->contractJson(array('require_active_status')), $text, 'a-tier');
    $this->assertEqual(QdlConformance::VERDICT_QDL_ABSENT, $result['verdict'],
      'so the null carries all the way into the QDL_ABSENT verdict');
    $this->assertEqual(3, QdlConformance::exitCode($result['verdict']),
      'with the exit code that outcome owns, not the 64 an error would give');
  }

  /**
   * Scenario 16: a --config-repo that is not a git repository is an
   * environment error, named as one.
   *
   * Without the check, `git show` fails for a reason that has nothing to do
   * with the tier and the run would report the tier as carrying no QDL.
   *
   * This one deliberately passes NO runner, so it is the DEFAULT one --
   * QdlConformance::runGit(), which really starts a process -- that answers.
   * That keeps the path the CLI actually takes covered rather than assumed by
   * every stub-driven test above, and it is the one environment question that
   * has the same answer with a git binary and without one: a directory that is
   * not a repository is an error either way.
   */
  public function testGitShowReportsANonRepositoryPathAsAnEnvironmentError() {
    $notARepo = $this->temporaryDirectory();

    // The default runner is a real process runner, not a stand-in: it reports
    // a command's status and its output lines. Asserted with a shell builtin,
    // since the point is that runGit() runs things, not that any particular
    // program is installed.
    $ran = QdlConformance::runGit("printf 'ran\\n'");
    $this->assertEqual(0, $ran['status'],
      'the default runner runs a command and reports its exit status');
    $this->assertEqual(array('ran'), $ran['output'],
      'and hands back its output as lines, which is what gitShow() reassembles'
      . ' the QDL from');

    $error = '';
    $text = QdlConformance::gitShow($notARepo, 'a-tier', $this->fixtureQdlPath(),
      $error);

    $this->assertNull($text, 'nothing is read');
    $this->assertNotEmpty($error, 'and it is reported as an error');
    $this->assertContains('not a git repository', $error,
      'named as the environment problem it is, rather than as a tier that'
      . ' carries no QDL');
    $this->assertTrue(strpos($error, $notARepo) === false,
      'without printing the path it was pointed at');
    $this->assertEqual(64, QdlConformance::exitCode('anything else'),
      'an environment error is the CLI\'s 64, not a conformance verdict');
  }

  // ------------------------------------------------------------------
  // The CLI itself.
  // ------------------------------------------------------------------

  /**
   * Scenario 17: an operator-supplied --qdl-path that is not
   * repository-relative is refused, and cannot reach the rendered report.
   *
   * The report is pasted into pull requests on a PUBLIC repository while the
   * configuration repository is PRIVATE, and the path is the one part of the
   * text an operator types. An absolute path names a directory on the machine
   * the check ran on and, through the checkout's layout, the private
   * repository itself. This drives the real script as a process, because
   * main() is where the option is read and the guard has to hold there.
   *
   * No git is involved, and that is the point rather than a concession: the
   * guard runs BEFORE the configuration repository is touched, so a refused
   * path never reaches an environment, let alone a report. The control is the
   * same invocation with a repository-relative path, which gets PAST the guard
   * and fails afterwards on the environment with a different message -- so the
   * refusals below are the guard doing the work and not a blanket 64. What the
   * process cannot show without git is the other half, that the value which
   * does get through is the one the report names; that is asserted directly on
   * render() at the end, since the path reaches the text only through it.
   */
  public function testAnAbsoluteQdlPathNeverReachesTheRenderedReport() {
    $relative = 'qdl/COmanageRegistry/default/dynamodb_claims.qdl';
    $notARepo = $this->temporaryDirectory();
    $contractPath = $this->fixture('.json',
      $this->contractJson(array('require_active_status')));
    $absolute = $this->fixture('.qdl',
      $this->qdlSource(array('require_active_status')));

    $control = $this->runCli($notARepo, $contractPath, $relative);
    $this->assertEqual(64, $control['status'],
      'the control run also ends at 64 -- but on the environment, one stage'
      . ' further on');
    $this->assertContains('not a git repository', $control['output'],
      'a repository-relative path is ACCEPTED and the run goes on to resolve'
      . ' the tier, which is where this one stops');
    $this->assertTrue(strpos($control['output'], 'repository-relative') === false,
      'and it is never refused for its shape, so the refusals below are the'
      . ' guard and not a blanket rejection of every run');

    foreach (array('an absolute path' => $absolute,
        'a path escaping the repository' => '../../etc/passwd') as $which => $path) {
      $refused = $this->runCli($notARepo, $contractPath, $path);

      $this->assertEqual(64, $refused['status'],
        "$which is refused as a usage error");
      $this->assertTrue(strpos($refused['output'], 'VERDICT') === false,
        "$which never reaches a rendered report at all");
      $this->assertTrue(strpos($refused['output'], $path) === false,
        "and $which is not echoed back into the output, which would put it in"
        . ' the very text the guard exists to keep it out of');
      $this->assertContains('repository-relative', $refused['output'],
        "$which is refused in terms an operator can act on");
      $this->assertTrue(
        strpos($refused['output'], 'not a git repository') === false,
        "$which is refused BEFORE the configuration repository is touched at"
        . ' all, so no operator-typed path can reach anything that reads it');
    }

    // The other half of the property: the value that does get through is the
    // one the report names, and it reaches the text only through render().
    $rendered = QdlConformance::render(QdlConformance::evaluate(
      QdlConformance::readFileOrNull($contractPath),
      $this->qdlSource(array('require_active_status')), 'a-tier', $relative));
    $this->assertContains('VERDICT: PASS', $rendered,
      'the same contract and QDL reach a verdict');
    $this->assertContains('qdl: ' . $relative, $rendered,
      'and the report names the validated relative path it was given');
    $this->assertTrue(strpos($rendered, sys_get_temp_dir()) === false,
      'with no part of the directory the fixtures live in');
    $this->assertTrue(strpos($control['output'], sys_get_temp_dir()) === false,
      'and no run printed any part of it either');
  }

  /**
   * Run bin/qdl-conformance.php as a process, capturing output and status.
   *
   * @param string $repo --config-repo.
   * @param string $contractPath --contract.
   * @param string $qdlPath --qdl-path.
   * @return array 'output' and 'status'.
   */
  private function runCli($repo, $contractPath, $qdlPath) {
    $script = App::pluginPath('Oa4mpClient') . 'bin' . DS . 'qdl-conformance.php';
    $this->assertTrue(is_readable($script), "the script exists at $script");

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
      . ' --tier a-tier'
      . ' --config-repo ' . escapeshellarg($repo)
      . ' --contract ' . escapeshellarg($contractPath)
      . ' --qdl-path ' . escapeshellarg($qdlPath)
      . ' 2>&1';
    $output = array();
    $status = 0;
    exec($command, $output, $status);

    return array('output' => implode("\n", $output), 'status' => $status);
  }
}
