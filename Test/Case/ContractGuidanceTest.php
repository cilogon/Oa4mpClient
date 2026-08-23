<?php
/**
 * The cross-repository obligation must be recorded where a contributor meets
 * it (U8, R14, R15, R16).
 *
 * The plugin's cfg capability contract is coupled to a script in a different
 * repository, and nothing in this repository's CI can see that script. The
 * only things that carry the obligation to a session that never read the plan
 * are AGENTS.md and the pull request template, so both are asserted here the
 * way Test/Case/CiWorkflowTest.php asserts on the workflow YAML: a paragraph
 * deleted in a tidy-up goes red instead of going unnoticed.
 *
 * The last test is the inverse of the others. AGENTS.md is in a PUBLIC
 * repository, so naming the configuration repository, its tier branches, and
 * the QDL path is the point of R14, while saying anything about what that
 * repository holds would publish a targeting hint. That test asserts the
 * guidance describes the coupling and nothing else.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U8.
 */

class ContractGuidanceTest extends Oa4mpTestCase {

  /** The repository the QDL lives in, named by AGENTS.md per R14. */
  const CONFIG_REPO = 'cilogon-service-config-us';

  /** The QDL's path inside that repository, tier-independent. */
  const QDL_PATH =
    'roles/oa4mp-server/files/qdl/COmanageRegistry/default/dynamodb_claims.qdl';

  /** The branches the QDL actually lives on; it is absent from `main`. */
  const TIER_BRANCHES = 'us-east-2-dev,us-east-2-test,us-east-2-prod';

  private function contents($relative) {
    $path = App::pluginPath('Oa4mpClient') . $relative;
    $this->assertTrue(is_readable($path), "$relative exists at $path");
    return file_get_contents($path);
  }

  private function agents() {
    return $this->contents('AGENTS.md');
  }

  private function template() {
    return $this->contents('.github' . DS . 'pull_request_template.md');
  }

  /**
   * AGENTS.md as bullet-sized blocks: a top-level `- ` bullet plus its
   * continuation lines, a heading, or a paragraph run.
   *
   * Blocks rather than the whole file, because the one test that asserts an
   * ABSENCE has to bound where it looks. AGENTS.md legitimately says
   * "credential" about the live test tier a few lines away; a whole-file
   * search would either fail on that or have to be weakened until it proved
   * nothing.
   */
  private function blocks($text) {
    $blocks = array();
    $current = array();
    foreach (explode("\n", $text) as $line) {
      if (preg_match('/^(- |#|\s*$)/', $line)) {
        if (!empty($current)) {
          $blocks[] = implode("\n", $current);
        }
        $current = array();
      }
      $current[] = $line;
    }
    if (!empty($current)) {
      $blocks[] = implode("\n", $current);
    }
    return $blocks;
  }

  /** The blocks of AGENTS.md that talk about the QDL or its repository. */
  private function qdlBlocks() {
    $found = array();
    foreach ($this->blocks($this->agents()) as $block) {
      if (stripos($block, self::CONFIG_REPO) !== false
          || stripos($block, 'dynamodb_claims.qdl') !== false
          || stripos($block, 'us-east-2-') !== false) {
        $found[] = $block;
      }
    }
    return $found;
  }

  /** The `- [ ]` items of the pull request template, one string each. */
  private function checklistItems() {
    $items = array();
    foreach (explode('- [ ]', $this->template()) as $index => $chunk) {
      if ($index === 0) {
        // Everything before the first checkbox is the heading and prose.
        continue;
      }
      $items[] = $chunk;
    }
    return $items;
  }

  /**
   * A session changing claim marshalling has to be able to reach the contract
   * and the check from AGENTS.md alone, without having read the plan.
   */
  public function testAgentsNamesTheContractArtifactAndTheCheck() {
    $agents = $this->agents();

    $this->assertContains('cfg_contract.json', $agents,
      'AGENTS.md names the contract artifact itself');
    $this->assertContains('contract_version', $agents,
      'AGENTS.md names the version a capability change raises');
    $this->assertContains('bin/qdl-conformance.php', $agents,
      'AGENTS.md names the conformance check');
    $this->assertContains('--tier', $agents,
      'AGENTS.md shows the check being run against a NAMED tier, which is the'
      . ' whole point of the check: it does not read whatever is checked out');
    $this->assertContains('--config-repo', $agents,
      'AGENTS.md shows the check being pointed at a checkout');
  }

  /**
   * R11's ordering rule, and the asymmetry that makes it a rule rather than a
   * preference. Deploying the QDL early is a no-op; deploying the plugin early
   * emits keys the tier ignores in silence.
   */
  public function testAgentsStatesTheDeploymentOrderingRule() {
    $agents = $this->agents();

    $this->assertTrue(stripos($agents, 'before any plugin deployment') !== false,
      'AGENTS.md states that the QDL reaches a tier before any plugin'
      . ' deployment that emits a capability introduced with it');
    $this->assertTrue(stripos($agents, 'per subscriber') !== false,
      'AGENTS.md gives the reason the order is fixed: one QDL copy per tier,'
      . ' plugin deployments advancing per subscriber');
    $this->assertTrue(stripos($agents, 'silently ignored') !== false,
      'AGENTS.md says what a capability the tier does not implement costs --'
      . ' a silently ignored cfg key, not an error a deployment would surface');
  }

  /**
   * R14's locating half: repository, the three tier branches, and the path.
   *
   * The branches are asserted individually because the file is absent from
   * that repository's `main`, and a contributor who checks only `main`
   * concludes the QDL does not exist. AGENTS.md has to say so.
   */
  public function testAgentsLocatesTheQdlByRepositoryBranchAndPath() {
    $agents = $this->agents();

    $this->assertContains(self::CONFIG_REPO, $agents,
      'AGENTS.md names the repository the QDL lives in');
    $this->assertContains(self::QDL_PATH, $agents,
      'AGENTS.md gives the path within that repository');

    foreach (explode(',', self::TIER_BRANCHES) as $branch) {
      $this->assertContains($branch, $agents,
        "AGENTS.md names the $branch tier branch; the QDL lives on the tier"
        . ' branches, one copy per tier');
    }

    $this->assertTrue(stripos($agents, 'absent from') !== false,
      'AGENTS.md says the file is absent from that repository\'s main, so a'
      . ' contributor who looks there does not conclude it does not exist');
  }

  /**
   * The contract rule covers one consumer. A session working on the LDAP claim
   * path must not read a green conformance check as covering it.
   */
  public function testAgentsBoundsTheContractRuleToTheDynamoQdl() {
    $bounded = false;
    foreach ($this->qdlBlocks() as $block) {
      if (stripos($block, 'dynamodb_claims.qdl') !== false
          && stripos($block, 'only') !== false
          && stripos($block, 'LDAP') !== false) {
        $bounded = true;
      }
    }

    $this->assertTrue($bounded,
      'AGENTS.md must say, in one place, that the contract rule covers'
      . ' dynamodb_claims.qdl ONLY and that the LDAP consumer is outside it;'
      . ' otherwise a session working on the LDAP path reads the rule as'
      . ' already satisfied on its behalf');
  }

  /**
   * AGENTS.md may name the configuration repository, its branches, and the QDL
   * path -- that is R14. It must not characterize what that repository holds.
   * This repository is public; such a sentence would be a targeting hint, and
   * it is not needed to state the coupling.
   */
  public function testAgentsDoesNotCharacterizeTheConfigRepositoryContents() {
    $blocks = $this->qdlBlocks();
    $this->assertNotEmpty($blocks,
      'AGENTS.md talks about the QDL somewhere, or there is nothing to check');

    $characterizations = array('private', 'credential', 'secret', 'password',
      'sensitive', 'confidential', 'proprietary', 'unsafe', 'restricted');

    foreach ($blocks as $block) {
      foreach ($characterizations as $word) {
        $this->assertTrue(stripos($block, $word) === false,
          "the QDL guidance must not describe the configuration repository as"
          . " \"$word\"; naming the repository, its branches, and the path is"
          . ' what R14 asks for, and anything about its contents is a hint'
          . ' this public repository should not carry. Offending block: '
          . trim($block));
      }
    }
  }

  /**
   * R16 is enforced at review time, because CI cannot reach the configuration
   * repository. The template is the whole enforcement mechanism, so the item
   * has to ask for the verdict and the tier -- not pasted output -- and has to
   * route a contributor without a checkout to someone who has one, rather than
   * inviting a self-attestation.
   */
  public function testPullRequestTemplateRecordsTheConformanceResult() {
    $item = null;
    foreach ($this->checklistItems() as $candidate) {
      if (stripos($candidate, 'qdl-conformance.php') !== false) {
        $item = $candidate;
      }
    }

    $this->assertNotEmpty($item,
      'the pull request template carries a checklist item naming'
      . ' bin/qdl-conformance.php; without it R16 has no artifact at all');
    $this->assertContains('contract_version', $item,
      'the item is conditioned on the pull request raising contract_version');
    $this->assertTrue(stripos($item, 'tier') !== false,
      'the item asks which tier the check ran against; a verdict with no tier'
      . ' names nothing');
    $this->assertTrue(stripos($item, 'verdict') !== false,
      'the item asks for the verdict, which is what R16 records');
    $this->assertTrue(stripos($item, 'not pasted output') !== false,
      'the item asks for the verdict rather than a paste: this repository is'
      . ' public and the check reads a checkout that is not');
    $this->assertTrue(stripos($item, 'maintainer') !== false,
      'a contributor without a checkout is routed to a maintainer who has one'
      . ' rather than attesting to a check they could not run');
  }

  /**
   * The golden matrix is only a contract while a moved value has to explain
   * itself. The Definition of Done reads this item; without it the rule that a
   * re-recorded value is not a fix has no artifact a reviewer sees.
   */
  public function testPullRequestTemplateRequiresAMovedGoldenValueToNameItsChange() {
    $item = null;
    foreach ($this->checklistItems() as $candidate) {
      if (stripos($candidate, 'ClaimCfgContractTest') !== false) {
        $item = $candidate;
      }
    }

    $this->assertNotEmpty($item,
      'the pull request template carries an item about moved golden values in'
      . ' Test/Case/Model/ClaimCfgContractTest.php');
    $this->assertTrue(stripos($item, 'behaviour change it reflects') !== false,
      'the item requires a moved value to name the behaviour change it'
      . ' reflects');
    $this->assertTrue(stripos($item, 're-recorded') !== false,
      'the item says a re-recorded value is not a fix, which is the failure'
      . ' mode the whole golden matrix exists to catch');
  }
}
