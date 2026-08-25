<?php
/**
 * Fixture hygiene for the suite's checked-in credential-shaped values (U5).
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
 *  - No text anywhere under Test/ matches a shape the scanner keys on. The
 *    scanned set is walked out of the tree, never declared here: a
 *    hand-maintained file list is extended by the same person who adds the file
 *    that needs covering, which is the failure mode
 *    Test/Case/Model/ClaimCfgDriftTest.php refuses for its own inputs. The walk
 *    is not restricted to PHP -- a JSON fixture, a compose file or a shell
 *    script reddens the scan exactly as a test case does.
 *  - Every credential-shaped field in that same surface holds one of the
 *    placeholders declared below. This catches a realistic value that does not
 *    happen to have a modelled shape, which the pattern check alone would miss.
 *
 * What this file does NOT do, stated plainly because Test/README.md should not
 * be read as claiming otherwise: it does not reproduce gitleaks. .gitleaks.toml
 * sets useDefault = true, so CI runs the whole default ruleset, and the
 * patterns here model only the common high-signal shapes -- the AWS access key
 * id, a private-key PEM header, the GitHub and Slack token prefixes, and the
 * JWT shape. The default set's entropy-driven generic-api-key rule and its long
 * tail of vendor-specific rules are NOT modelled and cannot be: a shape check
 * has no entropy budget, and a rule-for-rule copy would be a second
 * hand-maintained list drifting against the scanner. This is a high-signal
 * pre-commit net, not a replacement for the scan.
 *
 * Deliberately NOT done here: adding a .gitleaks.toml allowlist entry. The
 * allowlist exempts exactly one documented literal that predates this suite,
 * and Test/Case/CiWorkflowTest.php locks that scoping. A fixture value is kept
 * out of the scan by not being scanner-shaped, never by an exemption.
 *
 * Failure messages name the file, the line, and the field, but never the
 * offending value: a failure prints into CI logs, and a hygiene check must not
 * be the thing that publishes the credential it found. For the same reason no
 * scanner-matching literal is stored in this file either; every sample below is
 * assembled at run time from pieces that match nothing on their own.
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

  /**
   * The synthetic placeholders every credential-shaped field must hold.
   *
   * There are six because the suite grew three spellings before this guard went
   * tree-wide, not because six were wanted. Declaring each one is the point:
   * introducing a seventh fails this file until it is written down here, which
   * is what puts a new placeholder in front of a reviewer.
   */
  const PLACEHOLDER_ACCESS_KEY_ID = 'AKIAEXAMPLE';
  const PLACEHOLDER_SECRET = 'not-a-real-secret';
  const PLACEHOLDER_KEY = 'not-a-real-key';
  const PLACEHOLDER_PASSWORD = 'not-a-real-password';

  // The short forms Test/Case/Model/CfgMarshallingTest.php uses in its
  // round-trip payloads, where the value is never read and only has to be a
  // string. Both are too short and too plainly synthetic to be a credential.
  const PLACEHOLDER_MARSHALLING_KEY_ID = 'AKIA';
  const PLACEHOLDER_MARSHALLING_SECRET = 'secret';

  // Array-literal and class-constant forms of a credential-shaped field,
  // capturing the field name and the quoted value it holds. Either quote style
  // is accepted -- a single-quote-only pattern lets a double-quoted assignment
  // through both properties at once, since it is neither collected here nor
  // necessarily shaped like anything the pattern set above matches.
  const FIELD_PATTERN =
    '~([\'"])(?P<name>[a-z_]*(?:secret|access_key_id|password)[a-z_]*)\1\s*=>'
    . '\s*(?P<q>[\'"])(?P<value>(?:(?!(?P=q)).)*)(?P=q)~';
  const CONST_PATTERN =
    '~const\s+(?P<name>[A-Z_]*(?:SECRET|ACCESS_KEY_ID|PASSWORD)[A-Z_]*)\s*='
    . '\s*(?P<q>[\'"])(?P<value>(?:(?!(?P=q)).)*)(?P=q)~';
  // The JSON-member form of the same field, applied to .json files only. The
  // two patterns above both key on `=>`, which is PHP syntax, so a
  // credential-named member of a captured server response under Test/fixtures/
  // was collected by neither -- the file was walked, scanned for credential
  // SHAPES, and then skipped by the placeholder check entirely. Captured
  // responses are exactly where an unredacted credential arrives, so the
  // placeholder half has to read them too.
  const JSON_PATTERN =
    '~"(?P<name>[a-z_]*(?:secret|access_key_id|password)[a-z_]*)"\s*:'
    . '\s*(?P<q>")(?P<value>(?:(?!(?P=q)).)*)(?P=q)~';

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

  // ------------------------------------------------------------------
  // The scan surface itself.
  // ------------------------------------------------------------------

  /**
   * The surface is walked out of the tree, so a file added to Test/ is covered
   * by the act of adding it.
   *
   * Three things have to hold for that to mean anything: the walk finds
   * something, it finds this guard's own text, and the one filter it applies
   * (the .gitignore-derived exclusion) drops no source. That filter exists
   * because the real scan reads git history and an ignored path was never in
   * it -- Test/.env carries a real dev.cilogon.org secret by design -- but an
   * over-broad ignore entry silently shrinking the surface would turn this file
   * green while covering nothing, so the exclusion is checked, not trusted.
   */
  public function testTheScanSurfaceIsWalkedOutOfTheTreeRatherThanDeclared() {
    $scanned = array_keys($this->sources);
    $this->assertNotEmpty($scanned,
      'the scan surface is the Test/ tree; finding no files means the walk '
      . 'broke, not that there is nothing to scan');

    $self = 'Test' . DS . 'Case' . DS . 'Model' . DS . 'ClaimCfgFixtureHygieneTest.php';
    $this->assertTrue(in_array($self, $scanned, true),
      'the guard covers its own text too; if it cannot find itself the walk is '
      . 'not reaching the tree it claims to scan');

    $report = '';
    foreach ($this->testTreeFiles() as $relative) {
      if (substr($relative, -4) !== '.php') {
        continue;
      }
      if (!in_array($relative, $scanned, true)) {
        $report .= $relative . ' is in the tree but excluded from the scan' . "\n";
      }
    }
    $this->assertEqual('', $report,
      'the .gitignore-derived exclusion may only drop paths the secret scan can '
      . 'never see; dropping a checked-in source file would make this guard '
      . 'report green over text it never read');

    $beyondPhp = 0;
    foreach ($scanned as $relative) {
      if (substr($relative, -4) !== '.php') {
        $beyondPhp++;
      }
    }
    $this->assertTrue($beyondPhp > 0,
      'the surface is every file under Test/, not just the PHP: a credential in '
      . 'a JSON fixture, a compose file or a shell script reddens the real scan '
      . 'just the same');
  }

  // ------------------------------------------------------------------
  // Property one: nothing in the tree is scanner-shaped.
  // ------------------------------------------------------------------

  /**
   * No stored expected value and no seeded credential may carry a string the
   * secret scan matches. A hit here is not fixed by editing the file once it is
   * committed -- the scan reads history -- so this must fail before the commit,
   * which is why it is a test and not a review note.
   */
  public function testNoFileUnderTestMatchesAModelledSecretShape() {
    $report = $this->keyShapeReport($this->sources);

    $this->assertEqual('', $report,
      'a credential-shaped literal in a checked-in file reddens the secret '
      . 'scan for good: the scan walks full history, so removing it from the '
      . 'working tree does not clear it. Replace it with a placeholder BEFORE '
      . 'committing, and do not exempt it in .gitleaks.toml');
  }

  /**
   * The AWS detector's anchor, and the reason the short placeholder is safe.
   * The pattern must match the real key id .gitleaks.toml allowlists -- the one
   * piece of scanner ground truth in the repository -- and must not match
   * AKIAEXAMPLE, which is too short to be an access key id.
   */
  public function testKeyPatternMatchesARealKeyIdAndNotThePlaceholder() {
    $real = $this->allowlistedKeyId();
    $this->assertTrue((bool)preg_match(self::KEY_ID_PATTERN, $real),
      'the pattern must match the real access key id .gitleaks.toml allowlists; '
      . 'if it does not, this file is checking a shape the scanner does not key on');

    $this->assertFalse((bool)preg_match(self::KEY_ID_PATTERN,
      self::PLACEHOLDER_ACCESS_KEY_ID),
      'the placeholder is safe because it is too short to match, not by exemption');
  }

  /**
   * Every modelled shape has a positive control, and the report names the file
   * and the line without echoing the value.
   *
   * A pattern that quietly matches nothing is worse than no pattern, so each
   * one is exercised against a sample assembled at run time -- this file stores
   * no scanner-matching literal. Adding a shape to the pattern set without
   * adding its control fails here rather than shipping unexercised.
   */
  public function testEveryModelledShapeIsDetectedAndReportedWithoutItsValue() {
    $patterns = $this->shapePatterns();
    $samples = $this->shapeSamples();

    $this->assertEqual(count($patterns), count($samples),
      'every modelled shape needs a positive control; a pattern with no sample '
      . 'could match nothing at all and this file would still report green');

    foreach ($patterns as $label => $pattern) {
      $this->assertTrue(isset($samples[$label]),
        "the modelled shape $label has no positive control");

      $sample = $samples[$label];
      $planted = 'planted' . DS . $label . '.php';
      $report = $this->keyShapeReport(array(
        $planted => "<?php\n// line two\n\$v = '" . $sample . "';\n"
      ));

      $this->assertContains($planted, $report,
        "the report must name the file carrying the $label shape");
      $this->assertContains('line 3', $report,
        "the report must name the line carrying the $label shape");
      $this->assertContains($label, $report,
        "the report must name the $label shape it matched");
      $this->assertTrue(strpos($report, $sample) === false,
        'the report must not echo the offending value into the CI log');
    }

    // And the real tree is clean, so the controls are the difference.
    $this->assertEqual('', $this->keyShapeReport($this->sources),
      'the controls, not a pre-existing hit, are what turned the report red');
  }

  // ------------------------------------------------------------------
  // Property two: every credential-shaped field holds a placeholder.
  // ------------------------------------------------------------------

  /**
   * Every credential-shaped field under Test/ -- the Dynamo access key id and
   * secret access key on both the array-literal and the class constant form,
   * the LDAP bind passwords, and the admin client's own secret -- must hold a
   * declared placeholder. A realistic-looking value fails here even when it has
   * no modelled shape.
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

    $placeholders = $this->declaredPlaceholders();

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
      'every credential-shaped field under Test/ must hold one of the declared '
      . 'synthetic placeholders, never a realistic value');
  }

  /**
   * The extraction reads a double-quoted value as well as a single-quoted one.
   *
   * This was a real hole: the field patterns required single quotes, so
   * "aws_secret_access_key" => "..." was collected by neither property, and a
   * value with no modelled shape written that way passed the whole file.
   */
  public function testCredentialFieldsAreCollectedWhicheverQuoteStyleIsUsed() {
    // The field name and the samples are assembled rather than written out, so
    // this file does not itself contain a credential-shaped assignment for its
    // own placeholder check to trip over.
    $name = 'aws' . '_secret_access_key';
    $value = 'zzz-quote-style-probe';
    $planted = array(
      'planted' . DS . 'Const.php' =>
        "<?php\nclass P { const " . strtoupper($name) . " = \"" . $value . "\"; }\n",
      'planted' . DS . 'Double.php' =>
        "<?php\n\$a = array(\"" . $name . "\" => \"" . $value . "\");\n",
      'planted' . DS . 'Single.php' =>
        "<?php\n\$a = array('" . $name . "' => '" . $value . "');\n",
      // The JSON form. Without this arm a captured server response could carry
      // an unredacted credential and reach neither the placeholder check nor
      // this control, which is how two such fixtures were nearly committed.
      'planted' . DS . 'response.json' =>
        "{\n  \"" . $name . "\": \"" . $value . "\"\n}\n"
    );

    $found = array();
    foreach ($this->credentialFields($planted) as $field) {
      $this->assertEqual($value, $field['value'],
        'the extraction must capture the whole quoted value, not part of it');
      $found[] = $field['file'];
    }

    $expected = array_keys($planted);
    sort($expected);
    sort($found);
    $this->assertEqual($expected, $found,
      'both quote styles, on the array-literal and the class-constant form, '
      . 'and the JSON-member form must all reach the placeholder check; a '
      . 'style that slips past it is a credential-shaped field nothing in '
      . 'this file examines');
  }

  /**
   * The admin client's own secret is the one credential-shaped field that is
   * not a Dynamo configuration column, and it is seeded from a shared helper
   * rather than from any test case, so an extraction tuned to the cfg fields
   * could miss it and the test above would still pass. Pin it by name.
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
  // The modelled shapes.
  // ------------------------------------------------------------------

  /**
   * Label => pattern for the shapes this guard models, labelled after the
   * gitleaks default rule each one stands in for. The labels reach the failure
   * report, so a hit says which shape it looked like without saying what it was.
   *
   * These are the high-signal shapes, not the whole default ruleset; see the
   * file docblock for what is deliberately out of reach.
   */
  private function shapePatterns() {
    return array(
      'aws-access-token' => self::KEY_ID_PATTERN,
      // Written with -{5} rather than five literal dashes so this line is not
      // itself a PEM header.
      'private-key' => '/-{5}BEGIN[ A-Z0-9_-]{0,100}PRIVATE KEY(?: BLOCK)?-{5}/',
      'github-token' => '/\b(?:gh[pousr]_[0-9A-Za-z]{36,}|github_pat_[0-9A-Za-z_]{22,})\b/',
      'slack-token' => '/\bxox[baprse]-[0-9A-Za-z-]{10,}/',
      'jwt' => '/\beyJ[A-Za-z0-9_=-]{8,}\.eyJ[A-Za-z0-9_=-]{8,}\.[A-Za-z0-9_.+\/=-]*/'
    );
  }

  /**
   * One sample per modelled shape, each assembled here from pieces that match
   * nothing on their own, so the file that checks for scanner-shaped literals
   * never becomes one. The AWS sample takes its prefix and length from the real
   * allowlisted key id rather than inventing them.
   */
  private function shapeSamples() {
    $real = $this->allowlistedKeyId();
    $planted = substr($real, 0, 4) . str_repeat('Z', strlen($real) - 4);
    $this->assertEqual(strlen($real), strlen($planted),
      'the planted key has the length of a real access key id');

    return array(
      'aws-access-token' => $planted,
      'private-key' => str_repeat('-', 5) . 'BEGIN RSA PRIVATE KEY' . str_repeat('-', 5),
      'github-token' => 'gh' . 'p_' . str_repeat('A', 36),
      'slack-token' => 'xo' . 'xb-111111111111-222222222222-' . str_repeat('a', 24),
      'jwt' => 'ey' . 'J0eXAiOiJKV1QifQ.' . 'ey' . 'JzdWIiOiJ0ZXN0In0.'
        . str_repeat('a', 43)
    );
  }

  /** Every placeholder a credential-shaped field is allowed to hold. */
  private function declaredPlaceholders() {
    return array(
      self::PLACEHOLDER_ACCESS_KEY_ID,
      self::PLACEHOLDER_SECRET,
      self::PLACEHOLDER_KEY,
      self::PLACEHOLDER_PASSWORD,
      self::PLACEHOLDER_MARSHALLING_KEY_ID,
      self::PLACEHOLDER_MARSHALLING_SECRET
    );
  }

  // ------------------------------------------------------------------
  // Helpers.
  // ------------------------------------------------------------------

  /**
   * Every modelled-shape hit across $sources (relative path => contents), one
   * per line as "path line N: ... <label> rule ...", or '' when there is none.
   * The value itself is never included.
   */
  private function keyShapeReport($sources) {
    $report = '';

    foreach ($sources as $relative => $contents) {
      foreach ($this->shapePatterns() as $label => $pattern) {
        $hits = array();
        if (!preg_match_all($pattern, $contents, $hits, PREG_OFFSET_CAPTURE)) {
          continue;
        }

        foreach ($hits[0] as $hit) {
          $report .= $relative . ' line ' . $this->lineAt($contents, $hit[1])
            . ': a ' . strlen($hit[0]) . '-character string with the shape the '
            . 'secret scan\'s ' . $label . ' rule keys on' . "\n";
        }
      }
    }

    return $report;
  }

  /**
   * Every credential-shaped field across $sources, as
   * array('file' =>, 'line' =>, 'name' =>, 'value' =>). Both the array-literal
   * form ('aws_secret_access_key' => '...') and the class-constant form
   * (const AWS_SECRET_ACCESS_KEY = '...') are collected, in either quote style;
   * a value that is not a quoted literal (a constant reference, a variable) is
   * not a stored value and is not collected.
   */
  private function credentialFields($sources) {
    $fields = array();

    foreach ($sources as $relative => $contents) {
      // Comments are masked here but NOT for the shape scan above: a field
      // shown as an example in a docblock holds no value, while a
      // scanner-shaped literal reddens the scan wherever it sits, comment
      // included. Masking preserves line numbers so the report stays accurate.
      $code = $this->maskComments($contents);

      // The JSON form is applied only to JSON files. PHP sources legitimately
      // contain JSON-shaped literals inside assertion strings -- a redaction
      // test asserting on '"access_key_id":"[REDACTED]"' is not a credential
      // field -- and collecting those would fail the placeholder check on text
      // that is doing its job.
      $patterns = array(self::FIELD_PATTERN, self::CONST_PATTERN);
      if (substr($relative, -5) === '.json') {
        $patterns[] = self::JSON_PATTERN;
      }

      foreach ($patterns as $pattern) {
        $hits = array();
        if (!preg_match_all($pattern, $code, $hits, PREG_OFFSET_CAPTURE)) {
          continue;
        }

        foreach ($hits['name'] as $index => $name) {
          $fields[] = array(
            'file' => $relative,
            'line' => $this->lineAt($code, $name[1]),
            'name' => $name[0],
            'value' => $hits['value'][$index][0]
          );
        }
      }
    }

    return $fields;
  }

  /**
   * Every file under Test/, relative to the plugin root, walked rather than
   * listed. Nothing is filtered by extension: the secret scan does not care
   * what a file is called.
   */
  private function testTreeFiles() {
    $root = App::pluginPath('Oa4mpClient');
    $base = $root . 'Test';
    $this->assertTrue(is_dir($base), "the Test tree exists at $base");

    $files = array();
    $walk = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($walk as $entry) {
      if (!$entry->isFile()) {
        continue;
      }
      $files[] = substr($entry->getPathname(), strlen($root));
    }

    sort($files);

    return $files;
  }

  /**
   * The scan surface: the whole Test/ tree, minus the paths .gitignore names.
   *
   * An ignored path can never reach the history the scan walks -- Test/.env
   * holds a real dev.cilogon.org secret on purpose -- so scanning it would
   * report a failure the scan itself would never raise. The exclusion is
   * derived from .gitignore rather than listed here, and
   * testTheScanSurfaceIsWalkedOutOfTheTreeRatherThanDeclared checks it drops no
   * source.
   */
  private function scannedFiles() {
    $ignored = $this->gitignoreEntries();

    $files = array();
    foreach ($this->testTreeFiles() as $relative) {
      if ($this->isIgnored($relative, $ignored)) {
        continue;
      }
      $files[] = $relative;
    }

    return $files;
  }

  /** The path entries .gitignore names, normalized to this platform. */
  private function gitignoreEntries() {
    $path = App::pluginPath('Oa4mpClient') . '.gitignore';
    if (!is_readable($path)) {
      return array();
    }

    $entries = array();
    foreach (explode("\n", file_get_contents($path)) as $line) {
      $line = trim($line);
      // Blank lines, comments, and re-inclusions: a re-inclusion can only widen
      // the scan, and widening needs no help from this filter.
      if ($line === '' || $line[0] === '#' || $line[0] === '!') {
        continue;
      }
      $entries[] = str_replace('/', DS, trim($line, '/'));
    }

    return $entries;
  }

  /** True when $relative is named by one of the .gitignore entries. */
  private function isIgnored($relative, $entries) {
    foreach ($entries as $entry) {
      if ($relative === $entry) {
        return true;
      }
      // A directory entry ignores everything beneath it.
      if (strpos($relative, $entry . DS) === 0) {
        return true;
      }
      if (fnmatch($entry, $relative) || fnmatch($entry, basename($relative))) {
        return true;
      }
    }

    return false;
  }

  private function source($relative) {
    $path = App::pluginPath('Oa4mpClient') . $relative;
    $this->assertTrue(is_readable($path), "the scanned file exists at $path");

    return file_get_contents($path);
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
