<?php
/**
 * The marshaller emits only what the capability contract declares (U2).
 *
 * oa4mpMarshallCfgQdl() used to build a claim mapping by copying the claim row
 * whole and unsetting the handful of keys it knew the names of. Everything
 * else went to the OA4MP server, so a column added to cm_oa4mp_client_claims
 * -- the ordinary shape of a claims-tab feature -- was on the wire the day it
 * was added, with no QDL on any tier prepared to read it, and nothing said so.
 *
 * The writer is closed now rather than merely watched: the mapping is built
 * from cfg_contract.json's declaration, and a value the contract does not
 * cover is WITHHELD rather than emitted. Withholding is not silent. Every pass
 * over the claim loop logs one line naming the contract version and how many
 * values it withheld -- zero when it withheld none -- so the absence of that
 * line means the code did not run, which is a distinct third state from "it
 * ran and withheld nothing". The line names fields and never values: the rows
 * this model marshals reach logs that the live-server tier writes to a public
 * GitHub Actions log.
 *
 * Three properties are asserted together here, because closing the writer is
 * only safe if all three hold at once:
 *
 *  - Nothing undeclared is emitted, and withholding is signalled.
 *  - Nothing DECLARED changed. A claim marshalled before this change and
 *    untouched since produces the same cfg, key order included, and still
 *    reports in sync. The 46 golden mappings in ClaimCfgContractTest are the
 *    wider version of that; the baseline row is repeated here so this file
 *    fails on its own terms rather than only in company.
 *  - The write side and the compare side apply one set of rules. This
 *    repository has shipped writer-versus-comparator drift three times, most
 *    recently over a string '0' -- empty('0') is true and '0' ?? null is not
 *    -- so the string-zero and empty-string cases are driven through
 *    marshall, unmarshall and compare from one fixture rather than asserted
 *    on either side alone.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U2 (R4, R5, R7)
 * and docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md.
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');

/**
 * The marshaller with its log calls captured instead of written.
 *
 * The withheld-value signal IS the behavior under test: what it counts, what
 * it names, and that it is emitted on every pass. Overriding log() keeps those
 * assertions off the global CakeLog configuration -- no engine is added and
 * nothing is left behind for a later test -- and it is the only seam this
 * suite has, which is why the model's log() is not final and its contract
 * reader is protected. Test/Case/Model/ClaimConstraintSymmetryTest.php
 * establishes the pattern.
 *
 * Local to this file on purpose: it exists for the tests below, not as shared
 * infrastructure.
 */
class Oa4mpContractSignalSpy extends Oa4mpClientOa4mpServer {

  /** @var array Every message log() was handed, in order. */
  public $logged = array();

  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->logged[] = (string)$msg;

    return true;
  }

  /** Just the per-pass withheld-value signal lines. */
  public function signalLines() {
    $lines = array();
    foreach ($this->logged as $line) {
      if (strpos($line, Oa4mpClientOa4mpServer::CFG_WITHHELD_SIGNAL) === 0) {
        $lines[] = $line;
      }
    }

    return $lines;
  }
}

/**
 * The same spy with the contract reader pointed wherever a test says.
 *
 * There is no mocking framework here, so a subclass overriding the protected
 * path accessor is how a test reaches an unreadable or malformed contract
 * without touching the shipping document. The reader caches per path, so an
 * instance that has read one contract is never handed another's.
 */
class Oa4mpContractPathProbe extends Oa4mpContractSignalSpy {

  /** @var string Where this instance reads its contract from. */
  public $contractPath = '';

  protected function cfgContractPath() {
    return $this->contractPath;
  }
}

class ContractAllowlistTest extends Oa4mpTestCase {

  /** A claim column no shipping schema declares, for the withholding probes. */
  const UNDECLARED_COLUMN = 'zzz_undeclared_column';

  /** A source_model value the contract does not declare. */
  const UNDECLARED_SOURCE_MODEL = 'ZzzUndeclaredSource';

  /** The baseline claim's emitted mapping, as it stood before U2 closed the
      writer. Repeated from ClaimCfgContractTest on purpose: it is the value
      this file's own "nothing declared changed" claim rests on. */
  private $baselineMapping = array(
    'claim_name' => 'is_member_of',
    'source_model' => 'CoGroupMember',
    'source_model_claim_value_field' => 'member',
    'claim_value_selection' => 'all',
    'claim_value_json_format' => 'string',
    'claim_multiple_value_serialization' => 'delimited_string',
    'claim_value_string_serialization_delimiter' => ';',
    'claim_constraints' => array(
      array('constraint_field' => 'owner', 'constraint_value' => 'false'),
    ),
  );

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

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /** The claim mappings a claim marshals to, through the log-capturing spy. */
  private function marshalledMappings($spy, $claim) {
    $cfg = $spy->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    return $cfg['tokens']['identity']['qdl']['args']['claim_mappings'];
  }

  /**
   * The one signal line a marshalling pass emits, failing if there is not
   * exactly one. "Exactly one" is half the property: a line per claim, or a
   * line only when something was withheld, would each make the count
   * unreadable.
   */
  private function soleSignalLine($spy) {
    $lines = $spy->signalLines();

    $this->assertEqual(1, count($lines),
      'a marshalling pass emits exactly one withheld-value signal line, so its'
      . ' absence means the claim loop did not run; got '
      . var_export($lines, true));

    return $lines[0];
  }

  /**
   * Marshall one claim, unmarshall the emitted cfg, and compare it against the
   * persisted row it was built from. One fixture through both sides: the point
   * of the round trip is that the write rules and the compare rules are the
   * same rules, which asserting on either side alone cannot show.
   */
  private function roundTripVerdict($claim) {
    $server = $this->server();

    $cfg = $server->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $serverData = $server->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    return $server->isClientDataSynchronized(Oa4mpClaimRows::pluginSide($claim), $serverData);
  }

  /** Write a fixture contract document and return its path. */
  private function fixtureContract($contents) {
    $path = sys_get_temp_dir() . DS . 'oa4mp_contract_fixture_'
          . getmypid() . '_' . count($this->tempContracts) . '.json';
    file_put_contents($path, $contents);
    $this->tempContracts[] = $path;

    return $path;
  }

  // ------------------------------------------------------------------
  // Scenario 1: a declared claim marshals to what it always did.
  // ------------------------------------------------------------------

  /**
   * A claim carrying only declared fields produces the cfg it produced before
   * the writer was closed, key order included, and the key order is the
   * contract's declaration order.
   *
   * The order assertion is not decoration. The old loop inherited its key
   * order from whatever order Containable read the row in; the contract states
   * it once, and a mapping that agreed on content but not on order would still
   * move all 46 golden values.
   */
  public function testDeclaredClaimMarshalsToTheCfgItAlwaysProduced() {
    $spy = new Oa4mpContractSignalSpy();
    $mappings = $this->marshalledMappings($spy, Oa4mpClaimRows::claim());

    $this->assertEqual(array($this->baselineMapping), $mappings,
      'a claim carrying only declared fields must marshal to exactly the cfg it'
      . ' marshalled to before the contract closed the writer');

    $declared = $this->server()->cfgContractNames('claim_mapping_fields');
    $this->assertEqual($declared, array_keys($mappings[0]),
      'the emitted key order is the contract\'s declaration order; the suite\'s'
      . ' assertEqual is key-order sensitive, so a mapping ordered any other'
      . ' way moves every stored golden value');
  }

  // ------------------------------------------------------------------
  // Scenario 2: an undeclared field is withheld and named.
  // ------------------------------------------------------------------

  /**
   * A claim row carrying a column the contract does not declare -- the shape
   * of every claims-tab feature that adds one -- puts nothing new in the cfg,
   * and the signal names the field it withheld.
   */
  public function testAnUndeclaredClaimColumnIsWithheldAndNamed() {
    $spy = new Oa4mpContractSignalSpy();
    $claim = Oa4mpClaimRows::claim(array(self::UNDECLARED_COLUMN => 'a value'));

    $mappings = $this->marshalledMappings($spy, $claim);

    $this->assertEqual(array($this->baselineMapping), $mappings,
      'a column the contract does not declare must not reach the OA4MP server,'
      . ' and must not disturb the fields that do');

    $line = $this->soleSignalLine($spy);
    $this->assertContains('1 values withheld', $line,
      'the signal counts the values it withheld');
    $this->assertContains(self::UNDECLARED_COLUMN, $line,
      'the signal names the field it withheld, or the value is dropped as'
      . ' silently as the denylist dropped it');

    // An undeclared VALUE under a declared key is withheld the same way, and
    // named by its field. Without this the check would only cover new columns.
    $valueSpy = new Oa4mpContractSignalSpy();
    $valueMappings = $this->marshalledMappings($valueSpy,
      Oa4mpClaimRows::claim(array('source_model' => self::UNDECLARED_SOURCE_MODEL)));

    $this->assertFalse(isset($valueMappings[0]['source_model']),
      'a source_model value the contract does not declare must not be emitted:'
      . ' the QDL conformance check never asked whether any tier handles it');

    $valueLine = $this->soleSignalLine($valueSpy);
    $this->assertContains('1 values withheld', $valueLine,
      'withholding an undeclared value counts exactly like withholding an'
      . ' undeclared key');
    $this->assertContains('source_model', $valueLine,
      'the signal names the field whose value was withheld');
  }

  // ------------------------------------------------------------------
  // Scenario 3: the signal names fields, never values.
  // ------------------------------------------------------------------

  /**
   * The signal names the withheld field and never what it held.
   *
   * Seeded with a credential-shaped value on purpose: the rows this model
   * marshals can carry the DynamoDB keys, and the live-server tier writes this
   * model's logs into a public GitHub Actions log. The value is
   * Oa4mpClaimRows' existing synthetic placeholder rather than a new literal,
   * so this test adds nothing to the history the secret scan walks.
   */
  public function testTheSignalNamesTheFieldAndNeverItsValue() {
    $secret = Oa4mpClaimRows::AWS_SECRET_ACCESS_KEY;
    $this->assertNotEmpty($secret,
      'the probe needs a non-empty value, or "the value is absent from the log"'
      . ' would be true for the wrong reason');

    $spy = new Oa4mpContractSignalSpy();
    $this->marshalledMappings($spy,
      Oa4mpClaimRows::claim(array(self::UNDECLARED_COLUMN => $secret)));

    $line = $this->soleSignalLine($spy);
    $this->assertContains(self::UNDECLARED_COLUMN, $line,
      'the signal still names the field');

    $this->assertFalse(strpos($line, $secret) !== false,
      'the signal must never carry the withheld VALUE: this is why it is not'
      . ' the print_r residue log the extra-keys capture uses');

    // Not merely absent from the signal line: absent from everything this pass
    // logged. A second line carrying the value would leak it just as well.
    foreach ($spy->logged as $logged) {
      $this->assertFalse(strpos($logged, $secret) !== false,
        'no line the marshalling pass logs may carry a withheld value: '
        . var_export($logged, true));
    }
  }

  // ------------------------------------------------------------------
  // Scenario 4: the line is emitted even when nothing is withheld.
  // ------------------------------------------------------------------

  /**
   * Every row the declared matrix exercises withholds nothing, and each still
   * emits the per-pass line with a count of zero.
   *
   * Both halves matter. The zero count is what a CI gate keys on: a pull
   * request whose marshalling withholds anything says so in this line, and a
   * line that appeared only on withholding would leave "the marshaller never
   * ran" indistinguishable from "it withheld nothing". Sweeping the whole
   * declared row set rather than one claim is what makes the zero a statement
   * about the plugin's real vocabulary instead of about one fixture.
   */
  public function testEveryDeclaredRowWithholdsNothingAndStillSignals() {
    $rows = Oa4mpClaimRows::declaredRows();
    $this->assertNotEmpty($rows,
      'the matrix declares its rows for this sweep to read');

    foreach ($rows as $row) {
      $overrides = $row['values'];
      $overrides['source_model'] = $row['source_model'];

      $spy = new Oa4mpContractSignalSpy();
      $this->marshalledMappings($spy, Oa4mpClaimRows::claim($overrides));

      $line = $this->soleSignalLine($spy);

      $this->assertContains('0 values withheld', $line,
        'row ' . $row['row'] . ': a row the matrix declares is built entirely'
        . ' from declared vocabulary, so marshalling it must withhold nothing;'
        . ' got ' . var_export($line, true));
      $this->assertContains('version', $line,
        'row ' . $row['row'] . ': the signal names the contract version the'
        . ' pass was built against, which is what a stamped cfg is compared to');
    }
  }

  // ------------------------------------------------------------------
  // Scenarios 5 and 6: the emptiness boundary, both sides at once.
  // ------------------------------------------------------------------

  /**
   * A claim whose declared field holds the string '0' round-trips in sync.
   *
   * empty('0') is true, so the marshaller omits the field; a comparator
   * reading it with ?? would keep the '0' on the plugin side and compare it
   * against the server's absent value, reporting out of sync on every verify
   * pass with no edit able to repair it. That is the most recent live incident
   * in this code, and closing the writer moved both sides at once, so it is
   * re-asserted here rather than trusted to stay fixed.
   */
  public function testStringZeroFieldRoundTripsInSync() {
    $claim = Oa4mpClaimRows::claim(array(
      'claim_value_string_serialization_delimiter' => '0'));

    $spy = new Oa4mpContractSignalSpy();
    $mappings = $this->marshalledMappings($spy, $claim);

    $this->assertFalse(isset($mappings[0]['claim_value_string_serialization_delimiter']),
      'a string-zero value is empty by the marshaller\'s rule and is omitted');
    $this->assertContains('0 values withheld', $this->soleSignalLine($spy),
      'omitting an empty value is the contract\'s own rule, not withholding: a'
      . ' declared field need not appear in every mapping');

    $this->assertTrue($this->roundTripVerdict($claim),
      'a claim whose declared field holds a string zero must report in sync:'
      . ' the field was never sent, so the comparator must not count it');
  }

  /**
   * The same boundary from the other side: an empty-string field round-trips
   * in sync. This is the case that passes even when '0' does not, which is why
   * both are here.
   */
  public function testEmptyStringFieldRoundTripsInSync() {
    $claim = Oa4mpClaimRows::claim(array(
      'claim_value_string_serialization_delimiter' => ''));

    $spy = new Oa4mpContractSignalSpy();
    $mappings = $this->marshalledMappings($spy, $claim);

    $this->assertFalse(isset($mappings[0]['claim_value_string_serialization_delimiter']),
      'an empty value is omitted from the emitted mapping');
    $this->assertContains('0 values withheld', $this->soleSignalLine($spy),
      'an empty declared field is omitted, not withheld');

    $this->assertTrue($this->roundTripVerdict($claim),
      'a claim whose declared field is empty must report in sync');
  }

  // ------------------------------------------------------------------
  // Scenario 7: an unusable contract raises.
  // ------------------------------------------------------------------

  /**
   * A contract that cannot be read, or cannot be parsed, raises rather than
   * resolving to an empty allowlist.
   *
   * An empty allowlist is the worst available failure: it marshals every claim
   * to an empty mapping, sends that to the OA4MP server, and reports success.
   * A `?? array()` anywhere in the reader would produce exactly that, silently,
   * so both failure modes are exercised through the protected path accessor.
   */
  public function testAnUnusableContractRaisesRatherThanMarshallingAnEmptyAllowlist() {
    $data = Oa4mpClaimRows::data(Oa4mpClaimRows::claim());

    $missing = new Oa4mpContractPathProbe();
    $missing->contractPath = sys_get_temp_dir() . DS . 'oa4mp_contract_absent_'
                           . getmypid() . '.json';
    $this->assertFalse(is_file($missing->contractPath),
      'the unreadable-contract probe points at a path that does not exist');

    $raised = '';
    try {
      $missing->oa4mpMarshallCfgQdl($data);
    } catch (Throwable $e) {
      $raised = $e->getMessage();
    }
    $this->assertNotEmpty($raised,
      'marshalling against an unreadable contract must raise; resolving it to'
      . ' an empty allowlist would send the server an empty mapping and call'
      . ' that success');
    $this->assertContains($missing->contractPath, $raised,
      'the failure names the document it could not read, since the reader is'
      . ' redirectable and the default path is not always the one in play');

    $malformed = new Oa4mpContractPathProbe();
    $malformed->contractPath = $this->fixtureContract('{ "capabilities": not json');

    $raised = '';
    try {
      $malformed->oa4mpMarshallCfgQdl($data);
    } catch (Throwable $e) {
      $raised = $e->getMessage();
    }
    $this->assertNotEmpty($raised,
      'marshalling against an unparseable contract must raise for the same'
      . ' reason an unreadable one does');

    // A document that parses but declares nothing is the same hazard wearing a
    // different hat, and json_decode() reports no error on it at all.
    $empty = new Oa4mpContractPathProbe();
    $empty->contractPath = $this->fixtureContract('{"contract_version": 1, "capabilities": {}}');

    $raised = '';
    try {
      $empty->oa4mpMarshallCfgQdl($data);
    } catch (Throwable $e) {
      $raised = $e->getMessage();
    }
    $this->assertNotEmpty($raised,
      'a contract that parses but declares no capability must raise: an empty'
      . ' declaration reads to an allowlist as "emit nothing"');

    // The control: the same probe pointed at the shipping document marshals
    // normally, so the three failures above are the fixtures and not the probe.
    $real = new Oa4mpContractPathProbe();
    $real->contractPath = App::pluginPath('Oa4mpClient') . 'cfg_contract.json';
    $cfg = $real->oa4mpMarshallCfgQdl($data);

    $this->assertEqual(array($this->baselineMapping),
      $cfg['tokens']['identity']['qdl']['args']['claim_mappings'],
      'pointed at the shipping contract, the probe marshals exactly what the'
      . ' model does; the failures above belong to the fixtures');
  }

  // ------------------------------------------------------------------
  // Scenario 8: one fixture through marshall, unmarshall and compare.
  // ------------------------------------------------------------------

  /**
   * A marshalled claim fed back through unmarshalling and comparison reports
   * in sync, with its constraints intact.
   *
   * Driven from one fixture through both sides deliberately. The three drift
   * incidents this file's header cites were all cases where each side was
   * separately plausible and the pair was wrong; a test that built the server
   * side by hand would have passed through every one of them.
   */
  public function testMarshalledClaimUnmarshallsAndComparesInSync() {
    $claim = Oa4mpClaimRows::claim(array(
      'source_model' => 'EmailAddress',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
        Oa4mpClaimRows::constraint(91, 'verified', 'true'),
      ),
    ));

    $server = $this->server();
    $cfg = $server->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));
    $mapping = $cfg['tokens']['identity']['qdl']['args']['claim_mappings'][0];

    $this->assertEqual(2, count($mapping['claim_constraints']),
      'both fully populated constraints must be emitted, or the round trip'
      . ' below would agree about nothing');

    $serverData = $server->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    $this->assertNotEmpty($serverData['Oa4mpClientClaim'],
      'the unmarshaller must publish the claim under the association key the'
      . ' comparator reads');
    $this->assertEqual(2,
      count($serverData['Oa4mpClientClaim'][0]['Oa4mpClientClaimConstraint']),
      'the constraints must survive the read-back, or the comparison silently'
      . ' stops covering them');

    $this->assertTrue(
      $server->isClientDataSynchronized(Oa4mpClaimRows::pluginSide($claim), $serverData),
      'a claim marshalled by the contract-driven writer and read back by the'
      . ' contract-driven reader must report in sync');

    // The negative control: a real difference must still be seen, so the
    // verdict above is not the comparator having stopped looking.
    $different = Oa4mpClaimRows::claim(array(
      'source_model' => 'EmailAddress',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
        Oa4mpClaimRows::constraint(91, 'verified', 'false'),
      ),
    ));

    $this->assertFalse(
      $server->isClientDataSynchronized(Oa4mpClaimRows::pluginSide($different), $serverData),
      'a genuinely different constraint value must still report out of sync');
  }

  // ------------------------------------------------------------------
  // Scenario 9: a declared field that is empty is still omitted.
  // ------------------------------------------------------------------

  /**
   * A declared field whose value is empty is omitted from the mapping, exactly
   * as the denylist omitted it.
   *
   * The contract fixes WHICH fields may appear and in what order, not that
   * they all appear. A writer that emitted every declared key -- the obvious
   * reading of "build the mapping from the declaration" -- would put empty
   * keys on the wire, move all 46 stored golden mappings, and break the
   * comparator's emptiness rule at the same time.
   */
  public function testADeclaredButEmptyFieldIsStillOmitted() {
    $spy = new Oa4mpContractSignalSpy();
    $mappings = $this->marshalledMappings($spy, Oa4mpClaimRows::claim(array(
      'claim_value_selection' => '',
      'claim_value_json_format' => '',
    )));

    $expected = $this->baselineMapping;
    unset($expected['claim_value_selection']);
    unset($expected['claim_value_json_format']);

    $this->assertEqual(array($expected), $mappings,
      'the emitted mapping must be exactly what the denylist produced: the two'
      . ' empty fields absent, every other field present, in contract order');

    $declared = $this->server()->cfgContractNames('claim_mapping_fields');
    $this->assertTrue(in_array('claim_value_selection', $declared, true),
      'the omitted field really is declared, or this test proves nothing about'
      . ' declared-but-empty fields');

    // A claim with no constraints omits the synthesised list for the same
    // reason, which is the one declared field that is never a column.
    $bare = new Oa4mpContractSignalSpy();
    $bareMappings = $this->marshalledMappings($bare,
      Oa4mpClaimRows::claim(array('Oa4mpClientClaimConstraint' => array())));

    $this->assertFalse(isset($bareMappings[0]['claim_constraints']),
      'a claim with no constraints emits no claim_constraints key, the same'
      . ' emptiness rule applied to the one synthesised field');
  }

  // ------------------------------------------------------------------
  // Scenario 10: the QDL args block and the DynamoDB module configuration
  // are the contract's vocabulary too.
  // ------------------------------------------------------------------

  /**
   * A client that populates every optional section emits nothing under
   * tokens.identity.qdl.args, and nothing inside dynamo_module_config, that
   * the contract does not declare.
   *
   * The contract has declared qdl_args (eight names) and
   * dynamo_module_config_keys (five) since version 1, and for that whole time
   * neither group had a reader: those keys were literal assignments in
   * oa4mpMarshallCfgQdl(). The declaration and the emission agreed only
   * because one person kept them agreeing, which is precisely the arrangement
   * the contract exists to replace -- a ninth arg would have reached every
   * tier with no withheld line and a conformance run that still said PASS.
   *
   * The authorization section is populated on purpose. Its four args are the
   * only CONDITIONAL entries in the group, emitted only when the row carries
   * the value each names, so a subset check run against a client without them
   * would leave four of the eight declared names untested.
   */
  public function testEmittedQdlArgsAndModuleConfigKeysAreAllContractDeclared() {
    $server = $this->server();
    $declaredArgs = $server->cfgContractNames('qdl_args');
    $declaredModuleKeys = $server->cfgContractNames('dynamo_module_config_keys');

    $cfg = $server->oa4mpMarshallCfgQdl($this->authorizedData());
    $args = $cfg['tokens']['identity']['qdl']['args'];

    $undeclaredArgs = array_values(array_diff(array_keys($args), $declaredArgs));
    $this->assertEqual(array(), $undeclaredArgs,
      'every key under tokens.identity.qdl.args must be a name the contract'
      . ' declares in qdl_args; got '
      . var_export(array_keys($args), true));

    $moduleKeys = array_keys($args['dynamo_module_config']);
    $undeclaredModuleKeys = array_values(array_diff($moduleKeys, $declaredModuleKeys));
    $this->assertEqual(array(), $undeclaredModuleKeys,
      'every key inside dynamo_module_config must be a name the contract'
      . ' declares in dynamo_module_config_keys; got '
      . var_export($moduleKeys, true));

    // The four conditional args really were exercised. Without this the subset
    // check above would pass just as well on a client that emitted none of
    // them, and the conditional half of the group would be untested.
    foreach (array('require_active_status',
                   'authorization_group_id',
                   'authorization_group_redirect_url',
                   'require_active_redirect_url') as $conditional) {
      $this->assertTrue(isset($args[$conditional]),
        'the probe client populates the optional authorization section, so '
        . $conditional . ' must be emitted; a subset check that never sees it'
        . ' says nothing about the conditional half of qdl_args');
    }

    // And the conditionality is still conditionality: the same client with no
    // authorization section emits none of the four.
    $bare = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data(Oa4mpClaimRows::claim()));
    $bareArgs = $bare['tokens']['identity']['qdl']['args'];

    foreach (array('require_active_status',
                   'authorization_group_id',
                   'authorization_group_redirect_url',
                   'require_active_redirect_url') as $conditional) {
      $this->assertFalse(array_key_exists($conditional, $bareArgs),
        'a client with no authorization row must emit no ' . $conditional
        . ': routing the block through the contract must not turn four'
        . ' conditional args into four unconditional ones');
    }
  }

  /**
   * The declaration is really READ, for every group -- and the way to show
   * that is to take a name out of it and watch the value stop being emitted.
   *
   * A subset assertion alone cannot distinguish "the marshaller consults
   * qdl_args" from "the marshaller assigns eight literals that happen to match
   * qdl_args". This drives the marshaller against a fixture contract missing
   * one entry per group, through the same redirectable path accessor the
   * unusable-contract scenario uses, and asserts both halves of the
   * withholding rule: the value does not reach the cfg, and the signal names
   * the key it withheld.
   *
   * The entries chosen are one unconditional top-level arg, one conditional
   * one, the synthesised claim_mappings, and one module key -- the four
   * distinct ways a name gets into the args block.
   */
  public function testAnUndeclaredQdlArgOrModuleConfigKeyIsWithheldAndNamed() {
    $cases = array(
      array('group' => 'qdl_args', 'name' => 'partition_key_template'),
      array('group' => 'qdl_args', 'name' => 'require_active_status'),
      array('group' => 'qdl_args', 'name' => 'claim_mappings'),
      array('group' => 'dynamo_module_config_keys', 'name' => 'partition_key'),
    );

    foreach ($cases as $case) {
      $probe = new Oa4mpContractPathProbe();
      $probe->contractPath = $this->fixtureContract(
        $this->contractTextWithout($case['group'], $case['name']));

      $cfg = $probe->oa4mpMarshallCfgQdl($this->authorizedData());
      $args = $cfg['tokens']['identity']['qdl']['args'];

      $emitted = ($case['group'] === 'dynamo_module_config_keys')
                 ? array_keys($args['dynamo_module_config'])
                 : array_keys($args);

      $this->assertFalse(in_array($case['name'], $emitted, true),
        $case['group'] . '/' . $case['name'] . ': a name the contract does not'
        . ' declare must not be emitted, or the group has no reader and the'
        . ' declaration is decoration; got ' . var_export($emitted, true));

      $line = $this->soleSignalLine($probe);
      $this->assertContains('1 values withheld', $line,
        $case['group'] . '/' . $case['name'] . ': withholding an args key'
        . ' counts exactly like withholding a claim column');
      $this->assertContains($case['name'], $line,
        $case['group'] . '/' . $case['name'] . ': the signal names the key it'
        . ' withheld, or the value is dropped as silently as it was assigned');
    }
  }

  /**
   * A client carrying the optional authorization section, so the four
   * conditional qdl_args entries are actually emitted.
   *
   * The values are the shapes Oa4mpClientAuthorization persists: a boolean
   * flag, a CO group id, and two redirect URLs. Nothing here is
   * credential-shaped.
   */
  private function authorizedData() {
    $data = Oa4mpClaimRows::data(Oa4mpClaimRows::claim());

    $data['Oa4mpClientAuthorization'] = array(
      'id' => 55,
      'require_active' => true,
      'authz_co_group_id' => 12,
      'authz_group_redirect_url' => 'https://example.org/not-a-member',
      'require_active_redirect_url' => 'https://example.org/not-active',
    );

    return $data;
  }

  /**
   * The shipping contract with one entry removed, as JSON text.
   *
   * Derived from the shipping document rather than written out here, so the
   * fixture stays a real contract as the real one grows: only the named entry
   * differs, and every other group, the version and the secret_bearing flags
   * are whatever the plugin actually ships.
   *
   * @param string $group The capability group to remove an entry from.
   * @param string $name The entry name to remove.
   * @return string The fixture contract document.
   */
  private function contractTextWithout($group, $name) {
    $contract = json_decode(
      file_get_contents(App::pluginPath('Oa4mpClient') . 'cfg_contract.json'), true);

    $this->assertTrue(isset($contract['capabilities'][$group]['entries']),
      'the shipping contract declares a ' . $group . ' group for the fixture'
      . ' to remove an entry from');

    $kept = array();
    $removed = 0;

    foreach ($contract['capabilities'][$group]['entries'] as $entry) {
      if ($entry['name'] === $name) {
        $removed++;
        continue;
      }

      $kept[] = $entry;
    }

    $this->assertEqual(1, $removed,
      'the fixture removed exactly one ' . $group . ' entry named ' . $name
      . '; removing none would make the probe a copy of the shipping contract');

    $contract['capabilities'][$group]['entries'] = $kept;

    return json_encode($contract);
  }
}
