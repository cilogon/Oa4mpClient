<?php
/**
 * The cfg-side redaction names are derived from the capability contract (U4).
 *
 * Before this, Model/Oa4mpClientOa4mpServer.php's secretFieldNames() was one
 * literal list spanning three vocabularies, and two of its entries --
 * access_key_id and secret_access_key -- were the cfg spelling of credentials
 * the capability contract already declares. A second list beside the contract
 * is a list that can disagree with it: a credential-carrying capability could
 * be declared and shipped without ever being added here, and nothing would
 * say so. The cfg-side half is read out of cfg_contract.json's secret_bearing
 * flag now, so declaring such a capability IS adding it to the redaction list.
 *
 * The residue stays literal on purpose and is asserted here as such: the
 * plugin's own aws_* column names and the client_secret and
 * registration_access_token the OA4MP server returns in a RESPONSE never
 * appear in a cfg, and the contract declares only what the plugin emits into
 * one. The union is deliberate, not a half-finished derivation.
 *
 * Two failure behaviors are load-bearing and are asserted separately, because
 * conflating them is how this kind of gate disarms itself:
 *
 *  - Deriving no cfg-side name RAISES. The contract declares two secret
 *    -bearing keys, so an empty derivation is a defect; without the raise it
 *    would be indistinguishable from a contract that genuinely carries no
 *    credentials. That is the failure shape of
 *    docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md,
 *    where a scanner with no rules loaded printed exactly what a clean
 *    repository prints.
 *  - The redaction helpers never propagate that raise. They run while about to
 *    log a body carrying credentials, so on an unreadable contract they fall
 *    back to the full literal list, log the read failure, and still redact.
 *    An exception there would abort a client save or leave the text
 *    unredacted, reintroducing the leak 7.0.0-rc6 fixed.
 *
 * Test/Case/Model/ServerLogRedactionTest.php is the neighbouring coverage and
 * is not duplicated here: it characterizes the redactor's own text handling
 * (whole-key matching, escaped quotes, non-secret fields preserved) and locks,
 * against the model source, that every request-body and response log routes
 * through redactSecretsInLogText(). This file asserts where the NAMES come
 * from, and does it against emitted log lines rather than against the derived
 * array, so a name that is derived but never reaches the masking loop fails.
 *
 * Every credential-shaped value here is a short synthetic token, deliberately:
 * the secret-scan job walks full history, so a value committed once can never
 * be edited out and widening .gitleaks.toml is the documented trap. Each is
 * under ten characters, which is below the shortest value any gitleaks
 * built-in rule matches, and none carries an AWS key-id prefix.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U4 (R3).
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');

/**
 * The model with its log calls captured and its contract reader pointed
 * wherever a test says.
 *
 * There is no mocking framework here, which is why the model's log() is not
 * final and its cfgContractPath() is protected: a subclass is the only seam.
 * Test/Case/Model/ClaimConstraintSymmetryTest.php established the log capture
 * and Test/Case/Model/ContractAllowlistTest.php the path override; this file
 * needs both at once.
 *
 * Local to this file on purpose: it exists for the tests below, not as shared
 * infrastructure.
 */
class Oa4mpRedactionProbe extends Oa4mpClientOa4mpServer {

  /** @var array Every message log() was handed, in order. */
  public $logged = array();

  /** @var string Where this instance reads its contract from; '' = the real one. */
  public $contractPath = '';

  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->logged[] = (string)$msg;

    return true;
  }

  protected function cfgContractPath() {
    return $this->contractPath !== '' ? $this->contractPath : parent::cfgContractPath();
  }

  /**
   * Emit the request-body log line oa4mpNewClient() and oa4mpEditClient()
   * emit, without their HttpSocket.
   *
   * Statement for statement what those two call sites run --
   * log("Request body is " . redactSecretsInLogText(print_r($body, true))) --
   * and the reason it is repeated here rather than driven through them is that
   * both reach a socket immediately afterwards, which the hermetic tier must
   * never do. That the shipping call sites really are wrapped this way is
   * ServerLogRedactionTest::testNoResponseOrRequestBodyIsLoggedUnredacted(),
   * which asserts it against the model source; this method supplies the other
   * half, which is that the wrapper masks a real marshalled body.
   *
   * The redactor is private -- an internal logging concern, not API -- so it
   * is reached by reflection rather than by widening its visibility for the
   * tests, the way ServerLogRedactionTest already does.
   *
   * @param string $body The request body, as json_encode() produced it.
   */
  public function logRequestBody($body) {
    $redact = new ReflectionMethod('Oa4mpClientOa4mpServer', 'redactSecretsInLogText');
    $redact->setAccessible(true);

    $this->log("Request body is " . $redact->invoke($this, print_r($body, true)));
  }

  /** secretFieldNames() is private; the raise-on-empty test needs it by name. */
  public function derivedSecretFieldNames() {
    $names = new ReflectionMethod('Oa4mpClientOa4mpServer', 'secretFieldNames');
    $names->setAccessible(true);

    return $names->invoke($this);
  }

  /** The one request-body line, or '' if none was emitted. */
  public function requestBodyLine() {
    foreach ($this->logged as $line) {
      if (strpos($line, 'Request body is ') === 0) {
        return $line;
      }
    }

    return '';
  }
}

class ContractRedactionTest extends Oa4mpTestCase {

  /**
   * Synthetic credentials for the cfg-side names the contract declares. Both
   * are declared synthetic placeholders or short non-credential tokens, so no
   * gitleaks built-in rule can match them and ClaimCfgFixtureHygieneTest's
   * placeholder gate accepts them. They differ from each other so a test can
   * tell which name leaked.
   */
  const CFG_KEY_ID = 'KEY-U4';
  const CFG_SECRET = 'not-a-real-secret';

  /** The same, for the four names that have no cfg counterpart. */
  const COLUMN_KEY_ID = 'COLKEY-U4';
  const COLUMN_SECRET = 'not-a-real-password';
  const CLIENT_SECRET = 'not-a-real-key';
  const REGISTRATION_TOKEN = 'REGTOK-U4';

  /** @var array Fixture contract documents written by a test, to remove. */
  private $tempContracts = array();

  public function setUp() {
    $this->tempContracts = array();
  }

  public function tearDown() {
    foreach ($this->tempContracts as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    $this->tempContracts = array();
  }

  // ------------------------------------------------------------------
  // Helpers.
  // ------------------------------------------------------------------

  /**
   * A real request body for a client whose DynamoDB credentials are the
   * fixture values above.
   *
   * Marshalled by the model, not hand-built: the cfg-side names under test are
   * the ones oa4mpMarshallCfgQdl() writes into dynamo_module_config, so a
   * hand-built body could agree with the redactor while disagreeing with the
   * marshaller.
   *
   * @param Oa4mpRedactionProbe $probe The probe to marshall with.
   * @return string The body, as oa4mpNewClient() json_encode()s it.
   */
  private function marshalledBody($probe) {
    $data = Oa4mpClaimRows::data(Oa4mpClaimRows::claim());
    $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig']['aws_access_key_id']
      = self::CFG_KEY_ID;
    $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig']['aws_secret_access_key']
      = self::CFG_SECRET;

    $content = $probe->oa4mpMarshallContent(Oa4mpClaimRows::adminClientContext(), $data);

    return json_encode($content);
  }

  /** Assert no logged line anywhere carries $value. */
  private function assertNoLineCarries($probe, $value, $why) {
    foreach ($probe->logged as $line) {
      $this->assertFalse(strpos($line, $value) !== false,
        "$value reached a log line ($why): " . $line);
    }
  }

  /**
   * Write a fixture contract document and return its path. Built by decoding
   * the shipping contract and changing one thing, so the fixture stays a
   * usable contract in every respect the test is not about.
   *
   * @param callable $mutate Handed the decoded contract by reference.
   * @return string Path to the fixture.
   */
  private function fixtureContract($mutate) {
    $contract = json_decode(
      file_get_contents(App::pluginPath('Oa4mpClient') . 'cfg_contract.json'), true);

    $this->assertNotEmpty($contract, 'the shipping contract decodes');

    $mutate($contract);

    $path = sys_get_temp_dir() . DS . 'oa4mp_redaction_contract_'
          . getmypid() . '_' . count($this->tempContracts) . '.json';
    file_put_contents($path, json_encode($contract));
    $this->tempContracts[] = $path;

    return $path;
  }

  // ------------------------------------------------------------------
  // Scenario 1: the contract-derived names mask a real marshalled cfg.
  // ------------------------------------------------------------------

  /**
   * The positive control. A client whose DynamoDB credentials are the fixture
   * values marshals to a body that carries them under the two names the
   * contract flags secret_bearing, and neither value survives into the log
   * line the model emits for that body.
   *
   * Asserted on the emitted line rather than on the derived name list because
   * the derived list is only half the property: a name that is derived and
   * then never reaches the masking loop would satisfy an array assertion and
   * still leak. The surviving non-secret cfg values are the control on the
   * control -- without them, an absence assertion would pass just as well
   * against a line that never carried the cfg at all.
   *
   * This is the test that goes red when secret_bearing is removed from either
   * contract entry: the name stops being derived, the value stops being
   * masked, and it appears in the line.
   */
  public function testContractDeclaredCfgCredentialsNeverReachALogLine() {
    $probe = new Oa4mpRedactionProbe();

    $probe->logRequestBody($this->marshalledBody($probe));
    $line = $probe->requestBodyLine();

    $this->assertNotEmpty($line, 'the probe emitted the request-body log line');

    // The line really did carry the cfg. Both are contract capabilities that
    // are NOT secret-bearing, so they must come through untouched.
    $this->assertContains('"region":"us-east-2"', $line,
      'the logged body carries the marshalled DynamoDB module config, so the'
      . ' absence assertions below are about redaction and not about an empty line');
    $this->assertContains('"table_name":"registry"', $line,
      'a non-secret contract capability is not masked');

    $this->assertContains('"access_key_id":"[REDACTED]"', $line,
      'access_key_id is declared secret_bearing, so its value is masked');
    $this->assertContains('"secret_access_key":"[REDACTED]"', $line,
      'secret_access_key is declared secret_bearing, so its value is masked');

    $this->assertNoLineCarries($probe, self::CFG_KEY_ID,
      'the cfg access_key_id value must not survive redaction');
    $this->assertNoLineCarries($probe, self::CFG_SECRET,
      'the cfg secret_access_key value must not survive redaction');
  }

  // ------------------------------------------------------------------
  // Scenario 2: the literal residue still redacts.
  // ------------------------------------------------------------------

  /**
   * The four names with no cfg counterpart are still masked.
   *
   * They are the reason the list is a union rather than a derivation: two are
   * the plugin's own column names and two are credentials the OA4MP server
   * returns in a response, so none of them can be declared in a contract that
   * says what the plugin emits into a cfg. Deriving the cfg half must not cost
   * the other half.
   */
  public function testLiteralSecretNamesWithNoCfgCounterpartStillRedact() {
    $probe = new Oa4mpRedactionProbe();

    $body = '{"aws_access_key_id":"' . self::COLUMN_KEY_ID . '"'
      . ',"aws_secret_access_key":"' . self::COLUMN_SECRET . '"'
      . ',"client_secret":"' . self::CLIENT_SECRET . '"'
      . ',"registration_access_token":"' . self::REGISTRATION_TOKEN . '"'
      . ',"client_id":"cilogon:oa4mp,2012:/client_id/abc"}';

    $probe->logRequestBody($body);
    $line = $probe->requestBodyLine();

    $this->assertNotEmpty($line, 'the probe emitted the request-body log line');

    foreach (array('aws_access_key_id', 'aws_secret_access_key',
                   'client_secret', 'registration_access_token') as $field) {
      $this->assertContains('"' . $field . '":"[REDACTED]"', $line,
        "$field has no cfg counterpart and must still be masked from the literal list");
    }

    foreach (array(self::COLUMN_KEY_ID, self::COLUMN_SECRET,
                   self::CLIENT_SECRET, self::REGISTRATION_TOKEN) as $value) {
      $this->assertNoLineCarries($probe, $value,
        'a literal secret name must still mask its value');
    }

    $this->assertContains('"client_id":"cilogon:oa4mp,2012:/client_id/abc"', $line,
      'the client identifier is not a credential and must be preserved');
  }

  // ------------------------------------------------------------------
  // Scenario 3: an empty derivation raises rather than passing quietly.
  // ------------------------------------------------------------------

  /**
   * A contract that flags nothing secret_bearing makes secretFieldNames()
   * raise, rather than returning the literal residue as though all were well.
   *
   * The shipping contract declares two secret-bearing keys, so deriving none
   * means the reader or the document is broken. Returning the literals in that
   * state would leave a disarmed derivation looking exactly like a healthy
   * one, which is the trap the gitleaks solution document records: a scanner
   * with no rules loaded prints what a clean repository prints.
   */
  public function testAContractDeclaringNoSecretBearingCapabilityRaises() {
    $probe = new Oa4mpRedactionProbe();
    $probe->contractPath = $this->fixtureContract(function (&$contract) {
      foreach ($contract['capabilities'] as $group => $declaration) {
        foreach ($declaration['entries'] as $i => $entry) {
          $contract['capabilities'][$group]['entries'][$i]['secret_bearing'] = false;
        }
      }
    });

    $raised = '';
    $returned = null;
    try {
      $returned = $probe->derivedSecretFieldNames();
    } catch (Exception $e) {
      $raised = $e->getMessage();
    }

    $this->assertNull($returned,
      'secretFieldNames() must not return a list at all when the contract yields no'
      . ' cfg-side secret name; returning the literal residue would make a broken'
      . ' derivation indistinguishable from a healthy one');
    $this->assertNotEmpty($raised,
      'an empty cfg-side derivation raises');
    $this->assertContains('secret-bearing', $raised,
      'the raise names what was missing, so the defect is readable from the message');

    // Control: the same probe pointed at the shipping contract derives names.
    // Without this the assertions above would pass against a reader that
    // raised on every contract, fixture or not.
    $probe->contractPath = '';
    $this->assertContains('access_key_id', implode(',', $probe->derivedSecretFieldNames()),
      'the shipping contract still yields the cfg-side names');
  }

  // ------------------------------------------------------------------
  // Scenario 4: an unreadable contract still masks a logged cfg.
  // ------------------------------------------------------------------

  /**
   * With the contract unreadable, the credential values in a logged cfg are
   * still masked, and the read failure is logged rather than swallowed.
   *
   * The redaction helpers run while about to log a body carrying credentials.
   * Propagating the raise from scenario 3 there would abort a client save or
   * leave the body unredacted, so they fall back to the full literal list --
   * cfg-side names included -- which is the redaction this plugin performed
   * before the contract existed.
   */
  public function testAnUnreadableContractStillMasksALoggedCfg() {
    $readable = new Oa4mpRedactionProbe();
    $body = $this->marshalledBody($readable);

    $probe = new Oa4mpRedactionProbe();
    $probe->contractPath = sys_get_temp_dir() . DS . 'oa4mp_contract_absent_'
                         . getmypid() . '.json';

    $this->assertFalse(is_file($probe->contractPath),
      'the fixture path names no file, which is the condition under test');

    $probe->logRequestBody($body);
    $line = $probe->requestBodyLine();

    $this->assertNotEmpty($line,
      'the log line was emitted: an unreadable contract must not stop the logging');
    $this->assertContains('"access_key_id":"[REDACTED]"', $line,
      'the cfg access_key_id is masked from the fallback list');
    $this->assertContains('"secret_access_key":"[REDACTED]"', $line,
      'the cfg secret_access_key is masked from the fallback list');

    $this->assertNoLineCarries($probe, self::CFG_KEY_ID,
      'an unreadable contract must not leak the cfg access_key_id value');
    $this->assertNoLineCarries($probe, self::CFG_SECRET,
      'an unreadable contract must not leak the cfg secret_access_key value');

    $reported = false;
    foreach ($probe->logged as $logged) {
      if (strpos($logged, 'falling back to the literal list') !== false) {
        $reported = true;
      }
    }
    $this->assertTrue($reported,
      'the contract read failure is logged, so a deployment whose contract has gone'
      . ' missing says so instead of degrading in silence');
  }
}
