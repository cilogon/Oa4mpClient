<?php
/**
 * Fixture hygiene for the claim cfg contract matrix (U5).
 *
 * The stored expected values in Test/Case/Model/ClaimCfgContractTest.php and
 * the seeded rows in Test/Case/Model/ClaimCfgFallbackTest.php were written with
 * synthetic placeholders from the first draft. This file does not scrub them;
 * it asserts the constraint held, and keeps holding.
 *
 * Why it has to be enforced at authoring time and can only be verified here:
 * the secret scan (.github/workflows/hermetic-tests.yml) walks the full
 * history, so a scanner-matching value committed once can never be cleared by
 * editing the working tree. A test that goes red the moment such a value is
 * written is the only cheap moment to catch it.
 *
 * Two independent properties:
 *
 *  - No text in the fixture surface matches the shape the scanner keys on. The
 *    shape is the AWS access-key id, which is the one that actually bites here;
 *    it is cross-checked against the real key id .gitleaks.toml allowlists, so
 *    the pattern below is anchored to the scanner's own ground truth rather
 *    than invented here.
 *  - Every credential-shaped field -- including the admin client's own secret,
 *    seeded by Test/lib/Oa4mpFixtures.php -- holds one of the declared
 *    placeholders. This catches a realistic value that does not happen to have
 *    the AWS shape, which the pattern check alone would miss.
 *
 * Deliberately NOT done here: adding a .gitleaks.toml allowlist entry. The
 * allowlist exempts exactly one documented literal that predates this suite,
 * and Test/Case/CiWorkflowTest.php locks that scoping. A fixture value is kept
 * out of the scan by not being scanner-shaped, never by an exemption.
 *
 * Failure messages name the file, the line, and the field, but never the
 * offending value: a failure prints into CI logs, and a hygiene check must not
 * be the thing that publishes the credential it found.
 *
 * No database and no credential are needed.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U5
 * (R13).
 */

class ClaimCfgFixtureHygieneTest extends Oa4mpTestCase {

  /**
   * The shape gitleaks' built-in aws-access-token rule keys on: a four
   * character prefix plus 16 more. The positive control below checks it
   * against the real key id in .gitleaks.toml, so a drifted copy of the rule
   * cannot sit here matching nothing.
   *
   * AKIAEXAMPLE is safe precisely because it is too short to match.
   */
  const KEY_ID_PATTERN = '/\b(?:A3T[A-Z0-9]|AKIA|ASIA|ABIA|ACCA)[A-Z0-9]{16}\b/';

  /** The synthetic placeholders every credential-shaped field must hold. */
  const PLACEHOLDER_ACCESS_KEY_ID = 'AKIAEXAMPLE';
  const PLACEHOLDER_SECRET = 'not-a-real-secret';

  // Array-literal and class-constant forms of a credential-shaped field,
  // capturing the field name and the single-quoted value it holds.
  const FIELD_PATTERN =
    '/\'([a-z_]*(?:secret|access_key_id|password)[a-z_]*)\'\s*=>\s*\'([^\']*)\'/';
  const CONST_PATTERN =
    '/const\s+([A-Z_]*(?:SECRET|ACCESS_KEY_ID|PASSWORD)[A-Z_]*)\s*=\s*\'([^\']*)\'/';

  /** Relative path => file contents, re-read for every test method. */
  private $sources = null;

  public function setUp() {
    // The runner reuses one instance across methods, so nothing may carry over.
    $this->sources = null;

    $this->sources = array();
    foreach ($this->scannedFiles() as $relative) {
      $this->sources[$relative] = $this->source($relative);
    }
  }

  /**
   * The fixture surface of the claim cfg matrix: the two contract files named
   * by the plan, the two shared libraries their credential-shaped values
   * actually come from (the contract rows delegate to Oa4mpClaimRows, and the
   * fallback rows seed the admin client through Oa4mpFixtures), and this file
   * itself, so the guard covers its own text too.
   */
  private function scannedFiles() {
    return array(
      'Test' . DS . 'Case' . DS . 'Model' . DS . 'ClaimCfgContractTest.php',
      'Test' . DS . 'Case' . DS . 'Model' . DS . 'ClaimCfgFallbackTest.php',
      'Test' . DS . 'Case' . DS . 'Model' . DS . 'ClaimCfgFixtureHygieneTest.php',
      'Test' . DS . 'lib' . DS . 'Oa4mpClaimRows.php',
      'Test' . DS . 'lib' . DS . 'Oa4mpFixtures.php'
    );
  }

  private function source($relative) {
    $path = App::pluginPath('Oa4mpClient') . $relative;
    $this->assertTrue(is_readable($path), "the scanned file exists at $path");

    return file_get_contents($path);
  }

  // ------------------------------------------------------------------
  // Property one: nothing in the fixture surface is scanner-shaped.
  // ------------------------------------------------------------------

  /**
   * No stored expected value and no seeded credential may carry a string the
   * secret scan matches. A hit here is not fixed by editing the file once it is
   * committed -- the scan reads history -- so this must fail before the commit,
   * which is why it is a test and not a review note.
   */
  public function testNoFixtureValueMatchesTheSecretScanKeyPattern() {
    $report = $this->keyShapeReport($this->sources);

    $this->assertEqual('', $report,
      'a credential-shaped literal in a checked-in fixture reddens the secret '
      . 'scan for good: the scan walks full history, so removing it from the '
      . 'working tree does not clear it. Replace it with a placeholder BEFORE '
      . 'committing, and do not exempt it in .gitleaks.toml');
  }

  /**
   * The detector's own positive control, and the reason the short placeholder
   * is safe. The pattern must match the real key id .gitleaks.toml allowlists
   * -- the one piece of scanner ground truth in the repository -- and must not
   * match AKIAEXAMPLE, which is too short to be an access key id.
   *
   * The scan report machinery is exercised too, against a full-length key built
   * at run time so this file never stores one, because a report that cannot
   * name an offender is decoration.
   */
  public function testKeyPatternMatchesARealKeyIdAndNotThePlaceholder() {
    $real = $this->allowlistedKeyId();
    $this->assertTrue((bool)preg_match(self::KEY_ID_PATTERN, $real),
      'the pattern must match the real access key id .gitleaks.toml allowlists; '
      . 'if it does not, this file is checking a shape the scanner does not key on');

    $this->assertFalse((bool)preg_match(self::KEY_ID_PATTERN,
      self::PLACEHOLDER_ACCESS_KEY_ID),
      'the placeholder is safe because it is too short to match, not by exemption');

    // A full-length key, assembled here so no scanner-shaped literal is stored.
    $planted = substr($real, 0, 4) . str_repeat('Z', strlen($real) - 4);
    $this->assertEqual(strlen($real), strlen($planted),
      'the planted key has the length of a real access key id');

    $report = $this->keyShapeReport(array(
      'planted' . DS . 'Fixture.php' => "<?php\n// line two\n\$k = '" . $planted . "';\n"
    ));
    $this->assertContains('planted' . DS . 'Fixture.php', $report,
      'the report names the offending file');
    $this->assertContains('line 3', $report, 'the report names the offending line');
    $this->assertTrue(strpos($report, $planted) === false,
      'the report must not echo the offending value into the CI log');

    // And the real fixture surface is clean, so the control is the difference.
    $this->assertEqual('', $this->keyShapeReport($this->sources),
      'the control, not a pre-existing hit, is what turned the report red');
  }

  // ------------------------------------------------------------------
  // Property two: every credential-shaped field holds a placeholder.
  // ------------------------------------------------------------------

  /**
   * Every credential-shaped field in the fixture surface -- the Dynamo access
   * key id and secret access key on both the array-literal and the class
   * constant form, and the admin client's own secret -- must hold a declared
   * placeholder. A realistic-looking value fails here even when it has no
   * scanner shape.
   *
   * Changing a placeholder is a deliberate act: it fails this test until the
   * new value is declared above, which is what makes it visible to a reviewer.
   */
  public function testEveryCredentialShapedFieldHoldsAPlaceholder() {
    $fields = $this->credentialFields($this->sources);

    // A regex that quietly stops matching would otherwise pass vacuously.
    $this->assertNotEmpty($fields,
      'the credential-shaped fields must still be found; an empty collection '
      . 'means the extraction drifted, not that the fixtures are clean');

    $placeholders = array(self::PLACEHOLDER_ACCESS_KEY_ID, self::PLACEHOLDER_SECRET);

    $report = '';
    foreach ($fields as $field) {
      if (in_array($field['value'], $placeholders, true)) {
        continue;
      }
      // The value is withheld on purpose; its length is enough to identify it.
      $report .= $field['file'] . ' line ' . $field['line'] . ': '
        . $field['name'] . ' holds a ' . strlen($field['value'])
        . '-character non-placeholder value' . "\n";
    }

    $this->assertEqual('', $report,
      'every credential-shaped fixture field must hold one of the declared '
      . 'synthetic placeholders, never a realistic value');
  }

  /**
   * The admin client's own secret is the one credential-shaped field that is
   * not a Dynamo configuration column, and it is seeded from a shared helper
   * rather than from either contract file, so an extraction tuned to the cfg
   * fields could miss it and the test above would still pass. Pin it by name.
   */
  public function testSeededAdminClientSecretIsThePlaceholder() {
    $fixtures = 'Test' . DS . 'lib' . DS . 'Oa4mpFixtures.php';
    $found = array();

    foreach ($this->credentialFields($this->sources) as $field) {
      if ($field['file'] === $fixtures && $field['name'] === 'secret') {
        $found[] = $field['value'];
      }
    }

    $this->assertEqual(1, count($found),
      "the admin client secret seeded by $fixtures must be found exactly once");
    $this->assertEqual(self::PLACEHOLDER_SECRET, $found[0],
      'the admin client is seeded with the placeholder secret, not a realistic one');
  }

  // ------------------------------------------------------------------
  // Helpers.
  // ------------------------------------------------------------------

  /**
   * Every scanner-shaped hit across $sources (relative path => contents), one
   * per line as "path line N", or '' when there is none. The value itself is
   * never included.
   */
  private function keyShapeReport($sources) {
    $report = '';

    foreach ($sources as $relative => $contents) {
      $hits = array();
      if (!preg_match_all(self::KEY_ID_PATTERN, $contents, $hits, PREG_OFFSET_CAPTURE)) {
        continue;
      }

      foreach ($hits[0] as $hit) {
        $report .= $relative . ' line ' . $this->lineAt($contents, $hit[1])
          . ': a ' . strlen($hit[0]) . '-character string with the shape the '
          . 'secret scan keys on' . "\n";
      }
    }

    return $report;
  }

  /**
   * Every credential-shaped field across $sources, as
   * array('file' =>, 'line' =>, 'name' =>, 'value' =>). Both the array-literal
   * form ('aws_secret_access_key' => '...') and the class-constant form
   * (const AWS_SECRET_ACCESS_KEY = '...') are collected; a value that is not a
   * single-quoted literal (a constant reference, a variable) is not a stored
   * value and is not collected.
   */
  private function credentialFields($sources) {
    $fields = array();

    foreach ($sources as $relative => $contents) {
      // Comments are masked here but NOT for the key-shape scan above: a field
      // shown as an example in a docblock holds no value, while a
      // scanner-shaped literal reddens the scan wherever it sits, comment
      // included. Masking preserves line numbers so the report stays accurate.
      $code = $this->maskComments($contents);

      foreach (array(self::FIELD_PATTERN, self::CONST_PATTERN) as $pattern) {
        $hits = array();
        if (!preg_match_all($pattern, $code, $hits, PREG_OFFSET_CAPTURE)) {
          continue;
        }

        foreach ($hits[0] as $index => $hit) {
          $fields[] = array(
            'file' => $relative,
            'line' => $this->lineAt($code, $hit[1]),
            'name' => $hits[1][$index][0],
            'value' => $hits[2][$index][0]
          );
        }
      }
    }

    return $fields;
  }

  /**
   * Replace every PHP comment with as many newlines as it spanned, following
   * Test/Case/CiWorkflowTest.php in not counting prose -- but keeping the line
   * numbering intact, which a plain strip would not. The // rule spares a URL
   * scheme, the same exception Test/Case/Model/ClaimCfgDriftTest.php makes.
   */
  private function maskComments($source) {
    return preg_replace_callback('~/\*.*?\*/|(?<!:)//[^\n]*~s',
      function ($comment) {
        return str_repeat("\n", substr_count($comment[0], "\n"));
      }, $source);
  }

  /** The 1-based line number of byte offset $offset in $contents. */
  private function lineAt($contents, $offset) {
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
  }

  /**
   * The single access key id .gitleaks.toml allowlists, read out of the config
   * rather than copied here. It is the repository's one real sample of what the
   * scanner matches, and CiWorkflowTest.php already locks how it is scoped.
   */
  private function allowlistedKeyId() {
    $path = App::pluginPath('Oa4mpClient') . '.gitleaks.toml';
    $this->assertTrue(is_readable($path), "the gitleaks config exists at $path");

    $hits = array();
    $found = preg_match('/\'\'\'([A-Z0-9]{16,})\'\'\'/',
      file_get_contents($path), $hits);
    $this->assertTrue((bool)$found,
      'the gitleaks allowlist still carries the documented key id literal, '
      . 'which is what anchors the pattern this file checks');

    return $hits[1];
  }
}
