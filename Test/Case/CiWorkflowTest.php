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
   */
  public function testHermeticGateUsesNoSecrets() {
    $yaml = $this->directives($this->workflow('hermetic-tests.yml'));

    $this->assertTrue(strpos($yaml, 'secrets.') === false,
      'the hermetic gate must not read any repository secret');
    $this->assertTrue(strpos($yaml, 'environment:') === false,
      'the hermetic gate must not attach a secret-bearing environment');
    $this->assertContains('pull_request', $yaml, 'the gate runs on pull requests');
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
}
