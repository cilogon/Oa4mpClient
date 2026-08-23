<?php
/**
 * The QDL conformance check (U7, R8-R10).
 *
 * bin/qdl-conformance.php answers whether a named tier's dynamodb_claims.qdl
 * implements every capability cfg_contract.json declares. It is a plain `php`
 * script rather than a Console shell (KTD9) because it reads two files and
 * needs no Registry bootstrap; the comparison lives in a class with no I/O so
 * this file can drive it from fixtures it writes and removes, with no git, no
 * configuration-repository checkout, and no network.
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

  public function setUp() {
    // The runner reuses one instance across methods, so nothing may carry over.
    $this->temporaryFiles = array();
  }

  public function tearDown() {
    foreach ($this->temporaryFiles as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    $this->temporaryFiles = array();
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
   */
  private function contractJson($qdlArgs, $sourceModels = array('EmailAddress')) {
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

    return json_encode(array(
      'contract_version' => 7,
      'capabilities' => array(
        'qdl_args' => array('entries' => $entries),
        'source_model_values' => array('entries' => $models),
      ),
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
}
