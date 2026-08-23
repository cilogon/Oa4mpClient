<?php
/**
 * What Model/Oa4mpClientOa4mpServer.php logs, and what it concludes, when
 * unmarshalling the OA4MP server's representation of a client fails.
 *
 * Two defects, one path.
 *
 * The first is a credential leak. oa4mpUnMarshallCfgQdlv3() now reads the
 * fields a claim mapping may carry out of cfg_contract.json, and
 * cfgContract() raises by design when that document is unreadable or is not a
 * usable contract. That raise lands in oa4mpUnMarshallContent()'s
 * catch (Exception), whose first act was to log the whole server object with
 * print_r(). That object carries the client's client_secret, its
 * registration_access_token, and the cfg's AWS access_key_id and
 * secret_access_key. redactSecretsInLogText() matches a JSON-shaped
 * "key": "value" pair, so it cannot see into print_r output and masked none of
 * them -- and these logs are not private: the live-server tier writes them to
 * a GitHub Actions log on a PUBLIC repository, where the workflow's own secret
 * masking does not apply because the values come from the server rather than
 * from `secrets.*`. The same defect sat on the two extras-blob dumps, which
 * mattered more than it looks: client_secret was absent from the $knownKeys
 * list, so an RFC 7592 client-read response carrying it put the client's own
 * credential into the extras blob -- logged in the clear, persisted to
 * oa4mp_server_extra, and logged again on every edit that merged it back.
 *
 * The second is a misleading failure. That same raise reached
 * oa4mpVerifyClient()'s catch, which logged only getMessage() and left the
 * synchronized verdict at its initial false. oa4mpEditClient() then returned
 * 2, which every controller maps to "This client has been modified outside of
 * the Registry. Please email help@cilogon.org for assistance." A deployment
 * missing a file therefore presented as client tampering and sent support
 * after the wrong problem entirely. Both halves of the fix are asserted here:
 * the catch names the exception's class, file and line (the standing rule from
 * docs/solutions/logic-errors/oa4mp-cfg-unmarshall-swallowed-typeerror-2026-05-12.md),
 * and the failed comparison reports itself as an internal error rather than as
 * a mismatch nobody actually observed.
 *
 * The comparison is driven through compareToServerObject() rather than through
 * oa4mpVerifyClient(), because the latter constructs an HttpSocket and reaches
 * a real server; that method was split out of it so this tier could reach the
 * catch at all. The edit verdict is driven through oa4mpEditClient() itself,
 * whose two failure branches both return before any socket is constructed.
 *
 * Every credential-shaped value below is a declared placeholder from
 * Test/Case/Model/ClaimCfgFixtureHygieneTest.php, and the two that are not
 * credential-shaped fields are short synthetic tokens no scanner rule matches.
 * They differ from one another so a failing assertion says which one leaked.
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');

/**
 * The model with its log calls captured, its contract reader pointed wherever
 * a test says, and the two seams these tests need widened.
 *
 * There is no mocking framework here. log() is not final and cfgContractPath()
 * is protected precisely so a subclass can be the seam;
 * Test/Case/Model/ClaimConstraintSymmetryTest.php established the log capture
 * and Test/Case/Model/ContractRedactionTest.php the path override.
 *
 * Local to this file on purpose: it exists for the tests below, not as shared
 * infrastructure.
 */
class Oa4mpUnmarshallFailureProbe extends Oa4mpClientOa4mpServer {

  /** @var array Every message log() was handed, in order. */
  public $logged = array();

  /** @var string Where this instance reads its contract from; '' = the real one. */
  public $contractPath = '';

  /**
   * @var mixed What the stubbed oa4mpVerifyClient() hands back, or null to
   *            leave the real one in place. Set it from a comparison the real
   *            compareToServerObject() produced, never from a hand-built
   *            array, so the edit verdict is tested against the shape the
   *            model actually returns.
   */
  public $verifyReturn = null;

  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->logged[] = (string)$msg;

    return true;
  }

  protected function cfgContractPath() {
    return $this->contractPath !== '' ? $this->contractPath : parent::cfgContractPath();
  }

  /**
   * Stand in for the HTTP round trip oa4mpEditClient() opens with, and only
   * for it. With $verifyReturn unset the real method runs, so nothing here can
   * quietly neutralize a test that forgot to set it.
   */
  public function oa4mpVerifyClient($adminClient, $curClient, $returnExtras = false) {
    if($this->verifyReturn === null) {
      return parent::oa4mpVerifyClient($adminClient, $curClient, $returnExtras);
    }

    return $this->verifyReturn;
  }

  /** compareToServerObject() is protected; these tests need it by name. */
  public function compareWith($adminClient, $curClient, $oa4mpObject) {
    return $this->compareToServerObject($adminClient, $curClient, $oa4mpObject);
  }

  /** Every logged line that carries $needle. */
  public function linesCarrying($needle) {
    $lines = array();
    foreach ($this->logged as $line) {
      if (strpos($line, $needle) !== false) {
        $lines[] = $line;
      }
    }

    return $lines;
  }

  /** The first logged line beginning with $prefix, or '' if none. */
  public function lineStartingWith($prefix) {
    foreach ($this->logged as $line) {
      if (strpos($line, $prefix) === 0) {
        return $line;
      }
    }

    return '';
  }
}

class UnmarshallFailureDiagnosticsTest extends Oa4mpTestCase {

  const CLIENT_ID = 'cilogon:oa4mp,2012:/client_id/267d3c291b982f51bcac74766962785';

  /**
   * The four credentials the OA4MP server's representation of a client
   * carries. All four are declared placeholders or short synthetic tokens, and
   * all four differ, so an assertion that finds one in a log line names it.
   */
  const CLIENT_SECRET = 'not-a-real-secret';
  const REGISTRATION_TOKEN = 'REGTOK-P1';
  const CFG_KEY_ID = 'AKIAEXAMPLE';
  const CFG_SECRET = 'not-a-real-key';

  /**
   * The runner reuses one instance across every method in this file. Nothing
   * here holds instance state -- every probe is built inside the method that
   * uses it and the broken-contract path names a file that is never created --
   * and setUp() says so explicitly.
   */
  public function setUp() {
  }

  // ------------------------------------------------------------------
  // Helpers.
  // ------------------------------------------------------------------

  private function adminClient() {
    return array('Oa4mpClientCoAdminClient' => array('co_id' => 1));
  }

  /**
   * A GET response for a confidential client, in the shape dev.cilogon.org
   * returns one, carrying all four credentials and a QDLv3 cfg.
   *
   * The claim_mappings entry is what makes this fixture load-bearing: reading
   * it back is what sends oa4mpUnMarshallCfgQdlv3() to cfgContractNames(), and
   * therefore to the contract document a test can break. Without a mapping the
   * unmarshaller never consults the contract and the failure under test cannot
   * be provoked.
   *
   * at_lifetime is here for the same reason: it is not modelled by the plugin,
   * so it lands in the extras blob and makes the capture log line exist.
   *
   * @return array The decoded server object.
   */
  private function serverObject() {
    return array(
      'client_id' => self::CLIENT_ID,
      'client_name' => 'oa4mp-unmarshall-failure-probe',
      'client_secret' => self::CLIENT_SECRET,
      'registration_access_token' => self::REGISTRATION_TOKEN,
      'redirect_uris' => array('https://example.org/callback'),
      'scope' => array('openid'),
      'at_lifetime' => 0,
      'cfg' => array(
        'tokens' => array(
          'identity' => array(
            'qdl' => array(
              'args' => array(
                'partition_key_template' => 'sub',
                'partition_key_claim_name' => 'sub',
                'dynamo_module_config' => array(
                  'region' => 'us-east-2',
                  'access_key_id' => self::CFG_KEY_ID,
                  'secret_access_key' => self::CFG_SECRET,
                  'table_name' => 'registry',
                  'partition_key' => 'id',
                ),
                'claim_mappings' => array(
                  array(
                    'claim_name' => 'is_member_of',
                    'source_model' => 'CoGroupMember',
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  /**
   * The persisted side of the comparison.
   *
   * It differs from the server object in name, which is the second field
   * isClientDataSynchronized() checks, so the comparator returns at that
   * difference. That is deliberate: nothing in this file is about the
   * comparison itself, and carrying the setup needed to reach further into it
   * would say nothing about the failure and return paths that are.
   *
   * @return array The current client.
   */
  private function currentClient() {
    return array(
      'Oa4mpClientCoOidcClient' => array(
        'name' => 'a-name-the-server-object-does-not-carry',
        'oa4mp_identifier' => self::CLIENT_ID,
      ),
    );
  }

  /**
   * A probe pointed at a contract path that names no file, which is the
   * deployment failure under test: the plugin's cfg_contract.json missing or
   * unreadable on the server it is installed on.
   *
   * @return Oa4mpUnmarshallFailureProbe
   */
  private function probeWithNoContract() {
    $probe = new Oa4mpUnmarshallFailureProbe();
    $probe->contractPath = sys_get_temp_dir() . DS . 'oa4mp_unmarshall_no_contract_'
                         . getmypid() . '.json';

    $this->assertFalse(is_file($probe->contractPath),
      'the fixture path names no file, which is the condition under test');

    return $probe;
  }

  /** Assert no logged line anywhere carries $value. */
  private function assertNoLineCarries($probe, $value, $why) {
    foreach ($probe->logged as $line) {
      $this->assertFalse(strpos($line, $value) !== false,
        "$value reached a log line ($why): " . $line);
    }
  }

  // ------------------------------------------------------------------
  // The leak.
  // ------------------------------------------------------------------

  /**
   * The server object logged on the failure path is masked.
   *
   * This is the test that goes red against the print_r() rendering: all four
   * values survive it, because the redactor matches JSON-shaped pairs and
   * print_r output is not JSON. The surviving non-secret cfg values are the
   * control on the control -- without them an absence assertion would pass
   * just as well against a line that never carried the object at all.
   */
  public function testTheServerObjectLoggedOnAFailedUnmarshallIsMasked() {
    $probe = $this->probeWithNoContract();

    $probe->compareWith($this->adminClient(), $this->currentClient(),
      $this->serverObject());

    $line = $probe->lineStartingWith('oa4mpObject: ');
    $this->assertNotEmpty($line,
      'the failure path logs the server object it could not unmarshall');

    $this->assertContains('"client_name":"oa4mp-unmarshall-failure-probe"', $line,
      'the logged line really did carry the server object, so the absence'
      . ' assertions below are about masking and not about an empty line');
    $this->assertContains('"table_name":"registry"', $line,
      'a non-secret cfg value is not masked');

    foreach (array('client_secret', 'registration_access_token',
                   'access_key_id', 'secret_access_key') as $field) {
      $this->assertContains('"' . $field . '":"[REDACTED]"', $line,
        "$field is a credential and must be replaced by the sentinel");
    }

    foreach (array(self::CLIENT_SECRET, self::REGISTRATION_TOKEN,
                   self::CFG_KEY_ID, self::CFG_SECRET) as $value) {
      $this->assertNoLineCarries($probe, $value,
        'no credential from the server object may reach any log line; the'
        . ' live-server tier writes these logs to a public GitHub Actions log');
    }
  }

  /**
   * The extras blob is masked in both of its log lines: the one written when
   * it is captured from a server response, and the one written when it is
   * merged back into the content of a later edit.
   *
   * Driven with a readable contract, because this is the ordinary path rather
   * than the failure path -- the leak here was never conditional on anything
   * going wrong.
   */
  public function testTheExtrasBlobIsMaskedInBothOfItsLogLines() {
    $probe = new Oa4mpUnmarshallFailureProbe();

    // A server response carrying a credential under a key the plugin does not
    // model. Whatever the server returns outside $knownKeys lands in the blob,
    // so the blob is exactly where an unmodelled credential arrives.
    $object = $this->serverObject();
    $object['aws_secret_access_key'] = self::CFG_SECRET;

    $unmarshalled = $probe->oa4mpUnMarshallContent($object, $this->adminClient());

    $captured = $probe->lineStartingWith('Captured extra keys from OA4MP server: ');
    $this->assertNotEmpty($captured, 'the capture line was emitted');
    $this->assertContains('"at_lifetime":0', $captured,
      'the line really did carry the extras blob');
    $this->assertContains('"aws_secret_access_key":"[REDACTED]"', $captured,
      'a credential in the extras blob is masked on capture');

    // And the merge line, which the marshaller writes on every subsequent edit
    // of a client whose stored blob carries one.
    $probe->logged = array();
    $probe->oa4mpMarshallContent($this->adminClient(), array(
      'Oa4mpClientCoOidcClient' => array(
        'name' => 'oa4mp-unmarshall-failure-probe',
        'home_url' => 'https://example.org/',
        'public_client' => false,
        'oa4mp_identifier' => self::CLIENT_ID,
        'oa4mp_server_extra' =>
          $unmarshalled['Oa4mpClientCoOidcClient']['oa4mp_server_extra'],
      ),
      'Oa4mpClientCoCallback' => array(array('url' => 'https://example.org/callback')),
      'Oa4mpClientCoScope' => array(array('scope' => 'openid')),
    ));

    $merged = $probe->lineStartingWith('Merged extra keys into content for OA4MP server: ');
    $this->assertNotEmpty($merged, 'the merge line was emitted');
    $this->assertContains('"at_lifetime":0', $merged,
      'the line really did carry the extras blob');
    $this->assertContains('"aws_secret_access_key":"[REDACTED]"', $merged,
      'a credential in the extras blob is masked again on every edit');

    $this->assertNoLineCarries($probe, self::CFG_SECRET,
      'the extras blob is logged twice per edit cycle and must leak on neither');
  }

  /**
   * The client's own credential is never captured as an extra in the first
   * place.
   *
   * Masking the log lines is not enough on its own: an uncaptured key is not
   * persisted to oa4mp_server_extra and not echoed back to the server on the
   * next edit either. An RFC 7592 client-read response carries client_secret,
   * so before it was named in $knownKeys the plugin was storing the client's
   * secret in a blob meant for configuration it cannot model.
   *
   * at_lifetime is the positive control: it keeps this from passing simply
   * because nothing is captured any more.
   */
  public function testTheClientSecretIsNeverCapturedAsAnExtra() {
    $probe = new Oa4mpUnmarshallFailureProbe();

    $unmarshalled = $probe->oa4mpUnMarshallContent($this->serverObject(),
      $this->adminClient());
    $extra = $unmarshalled['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] ?? null;

    $this->assertNotEmpty($extra, 'the response must still yield extra keys');
    $extras = json_decode($extra, true);

    $this->assertTrue(array_key_exists('at_lifetime', $extras),
      'at_lifetime is not modelled by the plugin and must still be captured');
    $this->assertFalse(array_key_exists('client_secret', $extras),
      'client_secret is the client\'s own credential and must never be stored in'
      . ' the unmodelled-keys blob, nor sent back to the server from it');
    $this->assertNoLineCarries($probe, self::CLIENT_SECRET,
      'an uncaptured client_secret reaches no log line at all');
  }

  // ------------------------------------------------------------------
  // The misleading failure.
  // ------------------------------------------------------------------

  /**
   * The catch names the exception, where it was raised, and what it said.
   *
   * A catch that logs getMessage() alone hands the next operator an
   * interpretation with no evidence behind it. That is the failure recorded in
   * docs/solutions/logic-errors/oa4mp-cfg-unmarshall-swallowed-typeerror-2026-05-12.md,
   * whose standing rule this asserts: never swallow $e without its class, file
   * and line.
   */
  public function testTheFailedComparisonNamesTheExceptionClassFileAndLine() {
    $probe = $this->probeWithNoContract();

    $probe->compareWith($this->adminClient(), $this->currentClient(),
      $this->serverObject());

    $lines = $probe->linesCarrying('Caught exception during unmarshall');
    $this->assertEqual(1, count($lines),
      'the failed comparison logs exactly one diagnostic line');
    $line = $lines[0];

    $this->assertContains('LogicException', $line,
      'the line names the exception class, so the operator knows what fired');
    $this->assertContains('Model' . DS . 'Oa4mpClientOa4mpServer.php', $line,
      'the line names the file the exception was raised in');
    $this->assertTrue((bool)preg_match('/Oa4mpClientOa4mpServer\.php:[0-9]+/', $line),
      'the line names the line number, so the raise can be found without a search: '
      . $line);
    $this->assertContains('capability contract cannot be read', $line,
      'the line still carries the message, which is what actually says the'
      . ' contract document is missing');
  }

  /**
   * A comparison that did not run reports itself as an internal error, not as
   * a mismatch.
   *
   * Nothing was compared, so nothing was found to differ. Reporting false here
   * without saying why is what turned a missing deployment file into a
   * tampering verdict.
   */
  public function testAComparisonThatDidNotRunReportsAnInternalError() {
    $probe = $this->probeWithNoContract();

    $comparison = $probe->compareWith($this->adminClient(), $this->currentClient(),
      $this->serverObject());

    $this->assertTrue($comparison['error'],
      'the comparison did not run, and says so');
    $this->assertFalse($comparison['synchronized'],
      'a comparison that did not run is not evidence the client is in sync either');

    // The control: the same probe on a readable contract compares for real and
    // reports no error. Without it every assertion above would pass against a
    // method that reported an error unconditionally.
    $clean = new Oa4mpUnmarshallFailureProbe();
    $healthy = $clean->compareWith($this->adminClient(), $this->currentClient(),
      $this->serverObject());

    $this->assertFalse($healthy['error'],
      'a readable contract lets the comparison run, so no internal error is reported');
    $this->assertNotEmpty($healthy['oa4mp_server_extra'],
      'and the comparison really did run: it captured the extras blob');
  }

  /**
   * The operator-facing outcome of an internal failure is the generic edit
   * error, never the tampering message.
   *
   * oa4mpEditClient() returns 0 for "the edit did not happen" and 2 for "this
   * client has been modified outside of the Registry". Every controller maps 2
   * to pl.oa4mp_client_co_oidc_client.er.bad_client, which tells the operator
   * to email help@cilogon.org about a tampered client. A missing
   * cfg_contract.json must not produce that.
   *
   * The verdict is fed from what the real compareToServerObject() returned,
   * not from a hand-built array, so this cannot pass against a shape the model
   * does not actually produce. Both branches asserted here return before
   * oa4mpEditClient() constructs its HttpSocket, which is what lets the
   * hermetic tier drive them.
   */
  public function testAnInternalFailureIsNotReportedAsClientTampering() {
    $broken = $this->probeWithNoContract();
    $comparison = $broken->compareWith($this->adminClient(), $this->currentClient(),
      $this->serverObject());

    $probe = new Oa4mpUnmarshallFailureProbe();
    $probe->verifyReturn = $comparison;

    $this->assertEqual(0, $probe->oa4mpEditClient($this->adminClient(),
      $this->currentClient(), $this->currentClient()),
      'a comparison that could not run is reported as an edit error, not as the'
      . ' out-of-sync verdict controllers render as "modified outside of the'
      . ' Registry"');

    // The control, and the reason this is a distinction rather than a
    // downgrade: a genuine mismatch still returns 2 and still reaches the
    // tampering message, which is the only thing that message is true of.
    $mismatch = new Oa4mpUnmarshallFailureProbe();
    $mismatch->verifyReturn = array(
      'synchronized' => false,
      'oa4mp_server_extra' => null,
      'error' => false
    );

    $this->assertEqual(2, $mismatch->oa4mpEditClient($this->adminClient(),
      $this->currentClient(), $this->currentClient()),
      'a comparison that ran and found a difference still reports the client'
      . ' out of sync');
  }
}
