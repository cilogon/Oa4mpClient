<?php
/**
 * Locks the CI wiring the plan treats as a security property (U7, U9, R5, R12).
 *
 * Two invariants are easy to break with a one-line workflow edit and expensive
 * to notice: the merge gate must need no secrets, and the dev.cilogon.org
 * credential must be unreachable from anything that runs a pull request's code.
 * These run in the hermetic tier, so a change to either red-lights the gate.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U7, U9.
 */

class CiWorkflowTest extends Oa4mpTestCase {

  private function workflow($name) {
    $path = App::pluginPath('Oa4mpClient') . '.github' . DS . 'workflows' . DS . $name;
    $this->assertTrue(is_readable($path), "the $name workflow exists at $path");
    return file_get_contents($path);
  }

  /** Strip comment lines so a trigger named only in prose is not a match. */
  private function directives($yaml) {
    $lines = array();
    foreach (explode("\n", $yaml) as $line) {
      if (preg_match('/^\s*#/', $line)) {
        continue;
      }
      $lines[] = preg_replace('/\s+#.*$/', '', $line);
    }
    return implode("\n", $lines);
  }

  /**
   * The merge gate must reference no secret. A fork pull request has none, so a
   * gate that needed one could not gate fork contributions at all.
   *
   * The trigger is asserted with its delimiters, and the escalated triggers are
   * asserted absent: `pull_request_target` and `workflow_run` both run a fork
   * pull request's code with the base repository's secrets in scope, and either
   * one satisfies a bare 'pull_request' substring check. The secret check is on
   * 'secrets' rather than 'secrets.' so the index form, ${{ secrets['NAME'] }},
   * cannot slip past the dot.
   */
  public function testHermeticGateUsesNoSecrets() {
    $yaml = $this->directives($this->workflow('hermetic-tests.yml'));

    $this->assertTrue(strpos($yaml, 'secrets') === false,
      'the hermetic gate must not read any repository secret, in either the '
      . 'secrets.NAME or the secrets[\'NAME\'] form');
    $this->assertTrue(strpos($yaml, 'environment:') === false,
      'the hermetic gate must not attach a secret-bearing environment');

    foreach (array('pull_request_target', 'workflow_run') as $trigger) {
      $this->assertTrue(strpos($yaml, $trigger) === false,
        "the gate must not run untrusted pull-request code via $trigger");
    }

    $this->assertContains("\n  pull_request:\n", $yaml,
      'the gate runs on the pull_request trigger itself, not a variant of it');
    $this->assertContains('permissions:', $yaml,
      'the gate declares an explicit token scope rather than inheriting one');
    $this->assertContains('contents: read', $yaml,
      'the gate holds a read-only token; it never needs to write');
    $this->assertContains('Test/run.sh', $yaml, 'the gate runs the one entry command');
  }

  /**
   * The live tier must never be reachable from a trigger that runs a pull
   * request's code, and must bind its credential to the main-only environment
   * plus a ref guard.
   */
  public function testLiveTierIsOffThePullRequestPath() {
    $yaml = $this->directives($this->workflow('live-server-tests.yml'));

    foreach (array('pull_request', 'pull_request_target', 'workflow_run') as $trigger) {
      $this->assertTrue(strpos($yaml, $trigger) === false,
        "the live tier must never be wired to $trigger");
    }

    $this->assertContains('workflow_dispatch', $yaml, 'it runs on demand');
    $this->assertContains('schedule', $yaml, 'it runs on a schedule');
    $this->assertContains('environment: live-server', $yaml,
      'the credential is bound to the branch-restricted live-server environment');
    $this->assertContains("github.ref == 'refs/heads/main'", $yaml,
      'the job additionally refuses to run off main');
  }

  /**
   * The two tests above lock the two workflows by name. This one locks the
   * directory, so a third workflow added later cannot quietly reintroduce the
   * combination they exist to forbid: a trigger that runs a pull request's code
   * together with a repository secret or a secret-bearing environment.
   *
   * live-server-tests.yml is the one sanctioned holder of the credential and is
   * exempt; testLiveTierIsOffThePullRequestPath is what keeps it honest.
   */
  public function testNoOtherWorkflowMixesPullRequestCodeWithSecrets() {
    $dir = App::pluginPath('Oa4mpClient') . '.github' . DS . 'workflows' . DS;
    $paths = array_merge(glob($dir . '*.yml'), glob($dir . '*.yaml'));

    $this->assertNotEmpty($paths, "workflows are present under $dir");

    foreach ($paths as $path) {
      $name = basename($path);
      if ($name === 'live-server-tests.yml') {
        continue;
      }

      $yaml = $this->directives(file_get_contents($path));
      $runsPullRequestCode = strpos($yaml, 'pull_request') !== false
        || strpos($yaml, 'workflow_run') !== false;
      if (!$runsPullRequestCode) {
        continue;
      }

      foreach (array('secrets.', 'secrets[', 'environment:') as $reach) {
        $this->assertTrue(strpos($yaml, $reach) === false,
          "$name runs pull-request code, so it must not reach a secret via $reach");
      }
    }
  }

  /** The credential file must be gitignored so a real secret cannot be committed. */
  public function testLiveCredentialFileIsGitignored() {
    $path = App::pluginPath('Oa4mpClient') . '.gitignore';
    $this->assertTrue(is_readable($path), 'the repository has a .gitignore');

    $this->assertContains('Test/.env', file_get_contents($path),
      'Test/.env must be ignored; it carries the dev.cilogon.org secret');
  }

  /**
   * The example file documents the variables but must never carry a value for
   * the secret itself.
   */
  public function testEnvExampleCarriesNoSecretValue() {
    $path = App::pluginPath('Oa4mpClient') . 'Test' . DS . '.env.example';
    $this->assertTrue(is_readable($path), 'Test/.env.example exists');

    $contents = file_get_contents($path);
    $this->assertContains('OA4MP_LIVE_ADMIN_SECRET=', $contents,
      'the example documents the secret variable');
    $this->assertTrue((bool)preg_match('/^OA4MP_LIVE_ADMIN_SECRET=\s*$/m', $contents),
      'the example must leave the secret empty');
  }

  /**
   * The secret-scan job is a third-party scanner, so the repository's gitleaks
   * config is part of the gate's wiring. Read it the same way the workflows are
   * read: comments stripped, so a directive that survives only inside a TOML
   * comment is not a match.
   */
  private function gitleaksConfig() {
    $path = App::pluginPath('Oa4mpClient') . '.gitleaks.toml';
    $this->assertTrue(is_readable($path), "the gitleaks config exists at $path");
    return $this->directives(file_get_contents($path));
  }

  /**
   * A gitleaks config with no [extend] block replaces the built-in ruleset
   * rather than adding to it, so the scanner keeps exiting zero while detecting
   * nothing at all. That is the same failure mode as a suite that discovers no
   * tests: a green gate guarding nothing. Losing this one line is silent, which
   * is exactly why it is asserted here.
   */
  public function testSecretScanConfigExtendsTheDefaultRules() {
    $toml = $this->gitleaksConfig();

    $this->assertContains('[extend]', $toml,
      'the config must extend the built-in ruleset, not replace it');
    $this->assertTrue((bool)preg_match('/^\s*useDefault\s*=\s*true\s*$/m', $toml),
      'useDefault = true keeps every built-in rule armed alongside the allowlist');
  }

  /**
   * The allowlist exists for one documented placeholder: the masked AWS key id
   * in cfg_example.json, which predates this suite and sits in history where no
   * edit can remove it.
   *
   * gitleaks 8.x ORs the global allowlist conditions together, so a `paths`
   * entry would exempt every rule across the whole file and a `commits` entry
   * would exempt every rule in those commits -- a genuine credential added to
   * cfg_example.json later would then scan clean. Matching the literal value
   * instead exempts that one string and leaves every other rule live on the
   * same file.
   */
  public function testSecretScanAllowlistIsScopedToALiteralNotAPath() {
    $toml = $this->gitleaksConfig();

    $this->assertContains('[allowlist]', $toml, 'the config declares an allowlist');
    $this->assertTrue((bool)preg_match('/^\s*regexes\s*=/m', $toml),
      'the allowlist exempts literal values, which is the narrowest condition');

    foreach (array('paths', 'commits', 'stopwords') as $blanket) {
      $this->assertTrue(!preg_match('/^\s*' . $blanket . '\s*=/m', $toml),
        "the allowlist must not exempt by $blanket; that would hide a real "
        . 'credential added to the same file or commit');
    }
  }

  /**
   * gitleaks discovers .gitleaks.toml relative to the source directory, which
   * is a bind mount here. Naming the config explicitly means a scan that cannot
   * find it fails loudly instead of falling back to the default ruleset and
   * red-lighting the gate on the known placeholder again.
   */
  public function testSecretScanStepPassesTheRepoConfig() {
    $yaml = $this->directives($this->workflow('hermetic-tests.yml'));

    $this->assertContains('--config=/repo/.gitleaks.toml', $yaml,
      'the scan step points gitleaks at the repository config explicitly');
  }
}
