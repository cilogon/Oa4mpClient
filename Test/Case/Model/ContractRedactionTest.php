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
 * The LDAP bind credential is in that residue for a third reason, and it is
 * the one this file's legacy-cfg tests are about. A cfg written before QDLv3
 * carries it -- claims.sourceConfig[].ldap.password in the deprecated syntax,
 * tokens.identity.qdl[0].args.bind_password in QDLv2 -- and the plugin no
 * longer emits either shape, so the contract has nothing to declare for it.
 * It still READS those cfgs back, and logged what it read: the QDLv2 and
 * deprecated branches of oa4mpUnMarshallContent() dumped the unmarshalled LDAP
 * configuration through print_r(), which is not JSON-shaped and which
 * redactSecretsInLogText() therefore cannot see into, so an operator reading
 * Registry logs for such a client got its LDAP bind password in cleartext.
 * Masking those two lines was only half the fix: password and bind_password
 * were not in the name list at all, so the cfg dump those branches share --
 * already routed through the redactor -- leaked the same credential too.
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

  /**
   * Any of the private name-list helpers, by name.
   *
   * The vocabulary tests need the residue and the fallback separately: a name
   * added to the residue reaches the fallback only because the fallback merges
   * it, and asserting on the union alone would not say which of the two is
   * carrying it.
   *
   * @param string $method uncontractedSecretFieldNames or fallbackSecretFieldNames.
   * @return array The list that method returns.
   */
  public function secretNamesFrom($method) {
    $names = new ReflectionMethod('Oa4mpClientOa4mpServer', $method);
    $names->setAccessible(true);

    return $names->invoke($this);
  }

  /** The redactor itself, for the substring-safety control. */
  public function redactText($text) {
    $redact = new ReflectionMethod('Oa4mpClientOa4mpServer', 'redactSecretsInLogText');
    $redact->setAccessible(true);

    return $redact->invoke($this, $text);
  }

  /** The one logged line that starts with $prefix, or '' if none. */
  public function lineStartingWith($prefix) {
    foreach ($this->logged as $line) {
      if (strpos($line, $prefix) === 0) {
        return $line;
      }
    }

    return '';
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

  /**
   * The LDAP bind credential a pre-QDLv3 cfg carries, under each of the two
   * names such a cfg spells it with. They differ so a failing assertion says
   * which spelling leaked, and both are declared placeholders from
   * Test/Case/Model/ClaimCfgFixtureHygieneTest.php.
   */
  const LDAP_BIND_PASSWORD = 'not-a-real-password';
  const LDAP_BIND_PASSWORD_ALT = 'not-a-real-key';

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
   * The four response-and-column names with no cfg counterpart are still
   * masked. The residue's other two, the LDAP bind credential's two
   * spellings, have their own coverage in the legacy-cfg section below.
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

  // ------------------------------------------------------------------
  // Scenario 5: a legacy cfg's LDAP bind password never reaches a log.
  // ------------------------------------------------------------------

  /**
   * A server object carrying a cfg in one of the two pre-QDLv3 formats.
   *
   * The LDAP block deliberately declares no search attributes and no
   * ldap_to_claim_mappings, so oa4mpUnMarshallContent()'s claim loop skips it
   * on the `empty($ldapConfig['Oa4mpClientCoSearchAttribute'])` continue. That
   * keeps this hermetic: buildClaimFromLdapMapping() reaches
   * ClassRegistry and a CoProvisioningTarget lookup, which has nothing to do
   * with what is logged and would need a seeded database to reach at all. The
   * log lines under test are emitted before that loop runs.
   *
   * @param array $cfg The cfg object, in whichever legacy format.
   * @return array The decoded server object.
   */
  private function legacyServerObject($cfg) {
    return array(
      'client_id'     => 'cilogon:oa4mp,2012:/client_id/legacycfgprobe',
      'client_name'   => 'oa4mp-legacy-cfg-probe',
      'redirect_uris' => array('https://example.org/callback'),
      'scope'         => array('openid'),
      'cfg'           => $cfg,
    );
  }

  /**
   * A cfg in the QDLv2 format: tokens.identity.qdl is a LIST, which is what
   * makes oa4mpUnMarshallCfgQdlv3() return empty for it -- v3 reads
   * tokens.identity.qdl.args, absent here -- and so sends
   * oa4mpUnMarshallContent() on to the QDLv2 branch.
   *
   * @return array The cfg.
   */
  private function qdlv2Cfg() {
    return array(
      'tokens' => array(
        'identity' => array(
          'qdl' => array(
            array(
              'args' => array(
                'server_fqdn'            => 'ldap.example.org',
                'server_port'            => 636,
                'bind_dn'                => 'uid=oa4mp,ou=system,o=example',
                'bind_password'          => self::LDAP_BIND_PASSWORD,
                'search_base'            => 'ou=people,o=example',
                'search_attribute'       => 'uid',
                'ldap_to_claim_mappings' => array(),
              ),
            ),
          ),
        ),
      ),
    );
  }

  /**
   * A cfg in the deprecated format, which carries no tokens block at all, so
   * both the QDLv3 and the QDLv2 unmarshallers return empty and control falls
   * through to the deprecated branch.
   *
   * @return array The cfg.
   */
  private function deprecatedCfg() {
    return array(
      'claims' => array(
        'sourceConfig' => array(
          array(
            'ldap' => array(
              'authorizationType' => 'simple',
              'enabled'           => true,
              'address'           => 'ldap.example.org',
              'port'              => 636,
              'principal'         => 'uid=oa4mp,ou=system,o=example',
              'password'          => self::LDAP_BIND_PASSWORD,
              'searchBase'        => 'ou=people,o=example',
              'searchName'        => 'uid',
            ),
          ),
        ),
      ),
    );
  }

  /**
   * Reading a QDLv2 client back logs no LDAP bind password.
   *
   * Driven through the shipping method rather than asserted against the
   * source, because two separate things had to be true for the credential to
   * be safe and only the real path exercises both: the branch's own dump had
   * to stop being print_r(), AND bind_password had to enter the redaction
   * vocabulary, without which the cfg dump this branch shares -- already
   * routed through the redactor -- leaked it anyway. Asserting on every line
   * the call emitted, not just the one that was changed, is what makes the
   * second half of that visible.
   */
  public function testAQdlv2ClientsLdapBindPasswordNeverReachesALogLine() {
    $probe = new Oa4mpRedactionProbe();

    $probe->oa4mpUnMarshallContent(
      $this->legacyServerObject($this->qdlv2Cfg()),
      array('Oa4mpClientCoAdminClient' => array('co_id' => 1)));

    $line = $probe->lineStartingWith('Unmarshalled cfg QDL syntax to ');
    $this->assertNotEmpty($line,
      'the QDLv2 branch really was taken; without this the absence assertions'
      . ' below would pass against a call that never unmarshalled anything');

    // The line carried the unmarshalled configuration, so the absence
    // assertions are about masking and not about an empty line.
    $this->assertContains('"binddn":"uid=oa4mp,ou=system,o=example"', $line,
      'the bind DN identifies the configuration and is not a credential');
    $this->assertContains('"password":"[REDACTED]"', $line,
      'the bind credential the QDLv2 cfg carried is replaced by the sentinel');

    // And the cfg dump the branch shares, where the same credential appears
    // under the name the cfg spells it with.
    $cfgLine = $probe->lineStartingWith('Cast JSON cfg from OA4MP server to ');
    $this->assertNotEmpty($cfgLine, 'the cfg dump line was emitted');
    $this->assertContains('"bind_password":"[REDACTED]"', $cfgLine,
      'bind_password is in the redaction vocabulary, so the cfg dump masks it'
      . ' too; masking the branch dump alone would have left this line leaking');

    $this->assertNoLineCarries($probe, self::LDAP_BIND_PASSWORD,
      'no line logged while reading a QDLv2 client back may carry its LDAP'
      . ' bind password');
  }

  /**
   * The same for the deprecated cfg format, whose LDAP block spells the
   * credential ldap.password rather than args.bind_password.
   *
   * Kept as a separate test rather than folded into the one above because it
   * is a separate branch with a separate log statement: the two were converted
   * as a pair once and missed as a pair once, which is exactly the failure a
   * shared assertion would hide.
   */
  public function testADeprecatedCfgClientsLdapPasswordNeverReachesALogLine() {
    $probe = new Oa4mpRedactionProbe();

    $probe->oa4mpUnMarshallContent(
      $this->legacyServerObject($this->deprecatedCfg()),
      array('Oa4mpClientCoAdminClient' => array('co_id' => 1)));

    $line = $probe->lineStartingWith('Unmarshalled deprecated cfg to ');
    $this->assertNotEmpty($line,
      'the deprecated branch really was taken, which requires both the QDLv3'
      . ' and the QDLv2 unmarshallers to have returned empty first');

    $this->assertContains('"binddn":"uid=oa4mp,ou=system,o=example"', $line,
      'the bind DN identifies the configuration and is not a credential');
    $this->assertContains('"password":"[REDACTED]"', $line,
      'the bind credential the deprecated cfg carried is replaced by the sentinel');

    $cfgLine = $probe->lineStartingWith('Cast JSON cfg from OA4MP server to ');
    $this->assertNotEmpty($cfgLine, 'the cfg dump line was emitted');
    $this->assertContains('"password":"[REDACTED]"', $cfgLine,
      'the cfg dump masks the credential under the name the deprecated syntax'
      . ' spells it with');

    $this->assertNoLineCarries($probe, self::LDAP_BIND_PASSWORD,
      'no line logged while reading a deprecated-cfg client back may carry its'
      . ' LDAP bind password');
  }

  /**
   * Both spellings of the LDAP bind credential are in the literal residue, and
   * reach the fallback list through it.
   *
   * The behavioural tests above are the real coverage; this one pins WHERE the
   * names live, because that placement is a claim about the contract. Neither
   * name may be added to cfg_contract.json instead: the contract declares what
   * the plugin EMITS into a cfg, and the plugin stopped emitting an LDAP block
   * before QDLv3. The fallback assertion is not a restatement -- the fallback
   * is the list used when the contract cannot be read at all, which is when a
   * credential is most likely to go out unmasked, and it carries these two
   * only because it merges the residue rather than repeating it.
   */
  public function testBothLdapBindPasswordNamesAreInTheLiteralResidue() {
    $probe = new Oa4mpRedactionProbe();

    $residue = $probe->secretNamesFrom('uncontractedSecretFieldNames');

    foreach (array('password', 'bind_password') as $name) {
      $this->assertTrue(in_array($name, $residue, true),
        "$name has no cfg-contract counterpart -- the plugin no longer emits an"
        . ' LDAP block -- so it belongs in the literal residue, not the contract');
    }

    $fallback = $probe->secretNamesFrom('fallbackSecretFieldNames');

    foreach (array('password', 'bind_password') as $name) {
      $this->assertTrue(in_array($name, $fallback, true),
        "$name must also reach the fallback list, which is what redacts when the"
        . ' contract cannot be read; the fallback merges the residue rather than'
        . ' repeating it, so this holds by construction and breaks if that changes');
    }

    // Control: the cfg-side names are NOT in the residue. Without it, a residue
    // that had quietly become the whole literal list would satisfy the above.
    foreach (array('access_key_id', 'secret_access_key') as $name) {
      $this->assertFalse(in_array($name, $residue, true),
        "$name is a declared cfg capability and must stay derived, not literal");
    }
  }

  /**
   * The two new names do not over-match a neighbouring key.
   *
   * password is a substring context of bind_password, and both are prefixes of
   * plausible non-credential keys. The redactor anchors on the whole JSON key
   * -- the opening quote is part of the pattern -- so neither reaches the
   * other's value and neither touches a longer key that merely starts with it.
   * ServerLogRedactionTest::testAKeyThatMerelyStartsWithASecretKeyIsNotRedacted
   * is the same control for client_secret_expires_at; this is its counterpart
   * for the pair added with the legacy-cfg fix, and it additionally proves the
   * two names are order-independent, which a single-key control cannot.
   */
  public function testTheLdapBindPasswordNamesDoNotOverMatchNeighbouringKeys() {
    $probe = new Oa4mpRedactionProbe();

    $body = '{"password":"' . self::LDAP_BIND_PASSWORD . '"'
      . ',"bind_password":"' . self::LDAP_BIND_PASSWORD_ALT . '"'
      . ',"password_expires_at":0'
      . ',"bind_password_updated_at":"2026-08-23T00:00:00Z"'
      . ',"passwordless":true'
      . ',"binddn":"uid=oa4mp,ou=system,o=example"}';

    $redacted = $probe->redactText($body);

    $this->assertContains('"password":"[REDACTED]"', $redacted,
      'the whole-key match still masks the credential itself');
    $this->assertContains('"bind_password":"[REDACTED]"', $redacted,
      'the longer name is masked in full, not mangled by the shorter one');

    $this->assertContains('"password_expires_at":0', $redacted,
      'a key that merely starts with password is not a credential');
    $this->assertContains('"bind_password_updated_at":"2026-08-23T00:00:00Z"', $redacted,
      'a key that merely starts with bind_password is not a credential');
    $this->assertContains('"passwordless":true', $redacted,
      'a key that merely starts with password, with no separator at all, is'
      . ' still not a credential');
    $this->assertContains('"binddn":"uid=oa4mp,ou=system,o=example"', $redacted,
      'the bind DN is an identifier and must survive');

    // Order-independence: the shorter name is applied first, so a body that
    // presents the longer one first is the case where a mis-anchored pattern
    // would show. Both values must still be gone.
    $reversed = $probe->redactText(
      '{"bind_password":"' . self::LDAP_BIND_PASSWORD_ALT . '"'
      . ',"password":"' . self::LDAP_BIND_PASSWORD . '"}');

    $this->assertEqual('{"bind_password":"[REDACTED]","password":"[REDACTED]"}',
      $reversed,
      'the two names mask independently of the order the keys appear in');
  }

  /**
   * Nothing in oa4mpUnMarshallContent() is logged through print_r().
   *
   * The tests above cover the two branches that leaked. This one covers the
   * next one: the method's five other dump sites were converted to masked JSON
   * in one pass and these two were left behind in the same try block, so the
   * standing rule for this method is that print_r() does not appear in it at
   * all. redactSecretsInLogText() matches a JSON-shaped "key": "value" pair
   * and cannot see into a print_r rendering, which makes a print_r dump here
   * unmaskable by construction rather than merely unmasked.
   *
   * Asserted against the source, the way
   * ServerLogRedactionTest::testNoResponseOrRequestBodyIsLoggedUnredacted is,
   * because it is a claim about every site in the method including ones no
   * fixture reaches.
   */
  public function testNothingInTheUnmarshallPathIsLoggedThroughPrintR() {
    $body = $this->unmarshallContentBody();

    // The extraction really found the method, so the absence assertion below
    // is about the method's content and not about an empty string.
    $this->assertContains('Unmarshalled cfg QDL syntax to ', $body,
      'the extracted body is oa4mpUnMarshallContent(): it carries the QDLv2'
      . ' branch log line');
    $this->assertContains('Unmarshalled deprecated cfg to ', $body,
      'the extracted body carries the deprecated branch log line');

    $this->assertTrue(strpos($body, 'print_r(') === false,
      'oa4mpUnMarshallContent() logs no structure through print_r(): the'
      . ' redactor matches JSON-shaped pairs, so a print_r dump there cannot be'
      . ' masked at all. Render it as redactSecretsInLogText(json_encode(...))');

    $this->assertEqual(2,
      substr_count($body, 'redactSecretsInLogText(json_encode($ldapConfigs))'),
      'both legacy branches render the unmarshalled LDAP configuration as'
      . ' masked JSON; there are two of them and they have been missed as a'
      . ' pair once already');
  }

  /**
   * The source of oa4mpUnMarshallContent(), from its opening brace to the
   * matching close.
   *
   * Brace-matched rather than delimited by the name of whatever method follows
   * it, so inserting a method after it does not silently shrink the scanned
   * region to nothing and turn the gate green.
   *
   * @return string The method body.
   */
  private function unmarshallContentBody() {
    $source = file_get_contents(App::pluginPath('Oa4mpClient')
      . 'Model' . DS . 'Oa4mpClientOa4mpServer.php');
    $this->assertNotEmpty($source, 'the model source must be readable');

    $at = strpos($source, 'function oa4mpUnMarshallContent(');
    $this->assertTrue($at !== false,
      'the model still declares oa4mpUnMarshallContent()');

    $open = strpos($source, '{', $at);
    $this->assertTrue($open !== false, 'the method has an opening brace');

    $depth = 0;
    for ($i = $open; $i < strlen($source); $i++) {
      if ($source[$i] === '{') {
        $depth++;
      } elseif ($source[$i] === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($source, $open, $i - $open + 1);
        }
      }
    }

    $this->fail('oa4mpUnMarshallContent() has no matching closing brace');
  }
}
