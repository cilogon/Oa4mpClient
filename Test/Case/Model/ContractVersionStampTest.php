<?php
/**
 * The capability contract version every marshalled cfg carries
 * (Model/Oa4mpClientOa4mpServer.php::oa4mpMarshallCfgQdl).
 *
 * Every cfg the plugin sends is stamped with the contract version it was built
 * against, at metadata.Oa4mpClient.contract_version. The set of cfgs stored on
 * the OA4MP servers is then a census of which contract versions are actually
 * deployed, which is the only way that question can ever be answered: a cfg
 * written without the stamp never acquires one, so the census cannot be
 * reconstructed after the fact. The stamp therefore ships ahead of anything
 * that reads it.
 *
 * Two properties are load-bearing and neither is obvious from the write itself:
 *
 *  - The named-configuration branch is a SEPARATE return, not a fallthrough,
 *    so it needs its own write. A cfg produced there is the one most likely to
 *    be missed and the one whose version matters most, since its content came
 *    from an operator and not from the plugin.
 *  - On that branch the write happens AFTER array_merge_recursive(). That
 *    function merges rather than overwrites, so a stamp written before the
 *    merge would collide with an operator-authored key of the same name and
 *    leave an array of two values where a scalar version belongs.
 *
 * The stamp sits outside tokens.identity.qdl.args on purpose. The QDL args are
 * the capabilities a tier's QDL has to implement; the version is a fact about
 * the cfg, not a capability, so recording it there would make the version
 * itself something the QDL conformance check demands the QDL understand.
 *
 * Named-configuration JSON is operator-authored and completely unvalidated,
 * which is why the scalar-metadata cases below exist: writing an array offset
 * into a string raises a TypeError under PHP 8, so an unguarded write would
 * turn a malformed stored configuration into a failed save.
 *
 * See docs/plans/2026-08-23-0844-feat-cfg-qdl-contract-plan.md U3 (R6).
 */

class ContractVersionStampTest extends Oa4mpTestCase {

  /**
   * The runner reuses one instance across every method in this file. Nothing
   * here holds instance state: each case builds its data from Oa4mpClaimRows
   * and throws it away.
   */
  public function setUp() {
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * The declared contract version, read straight out of the shipped document.
   *
   * Deliberately not the model's cfgContractVersion(): that accessor is what
   * produces the stamp, so comparing the stamp against it would prove only
   * that the model agrees with itself. Reading the document is an independent
   * derivation, and it is derived rather than written out as a literal because
   * contract_version is expected to advance -- what these cases pin is that
   * the stamp tracks the declaration, not what today's number happens to be.
   *
   * @return mixed The declared contract_version.
   */
  private function contractVersion() {
    $path = App::pluginPath('Oa4mpClient') . 'cfg_contract.json';

    $this->assertTrue(is_readable($path), "the contract document exists at $path");

    $contract = json_decode(file_get_contents($path), true);

    $this->assertTrue(isset($contract['contract_version']),
      'cfg_contract.json declares a contract_version for a cfg to be stamped with');

    return $contract['contract_version'];
  }

  /**
   * A named-configuration client whose stored configuration is $content
   * rather than the fixture's own.
   *
   * The marshaller reads the configuration out of the admin client's list and
   * json_decode()s its config column, so replacing that column is the only
   * place a case has to reach in order to stage operator-authored JSON.
   *
   * @param array $content The stored named configuration, before encoding.
   * @return array Client data in the shape oa4mpMarshallCfgQdl() takes.
   */
  private function namedConfigDataWithContent($content) {
    $data = Oa4mpClaimRows::namedConfigData(Oa4mpClaimRows::claim());

    $data['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'][0]['config']
      = json_encode($content);

    return $data;
  }

  /**
   * The named configuration fixture's content with $metadata put on it, which
   * is what every operator-authored case here varies.
   *
   * @param mixed $metadata The value of the stored metadata key.
   * @return array The stored named configuration, before encoding.
   */
  private function namedConfigContentWithMetadata($metadata) {
    $content = Oa4mpClaimRows::namedConfigContent();
    $content['metadata'] = $metadata;

    return $content;
  }

  /**
   * Marshall a named-configuration client and return the emitted cfg, failing
   * rather than propagating if the marshaller raises.
   *
   * A raise is the interesting failure for the malformed-metadata cases: the
   * unguarded write does not produce a wrong cfg, it produces no cfg at all
   * and takes the save down with it. Catching Throwable rather than Exception
   * because the TypeError it raises is an Error.
   *
   * @param string $case The case name, so a failure is attributable.
   * @param array $content The stored named configuration, before encoding.
   * @return array The emitted cfg.
   */
  private function marshalledNamedConfigCfg($case, $content) {
    try {
      return $this->server()->oa4mpMarshallCfgQdl(
        $this->namedConfigDataWithContent($content));
    } catch (Throwable $e) {
      $this->fail($case . ': marshalling a named configuration must not raise,'
        . ' since a raise here is a client that cannot be saved at all; got '
        . get_class($e) . ': ' . $e->getMessage());
    }
  }

  // ---------------------------------------------------------------------
  // The two marshalling paths.
  // ---------------------------------------------------------------------

  /**
   * A confidential client with no named configuration -- the QDL-shape path --
   * records the contract version, outside the QDL args.
   */
  public function testConfidentialClientCfgRecordsContractVersion() {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(
      Oa4mpClaimRows::data(Oa4mpClaimRows::claim()));

    $this->assertEqual($this->contractVersion(),
      $cfg['metadata']['Oa4mpClient']['contract_version'],
      'a cfg marshalled on the QDL path carries the contract version it was'
      . ' built against');

    // R6's placement requirement, asserted rather than assumed: the version is
    // a fact about the cfg and not a capability, so it must not appear among
    // the args the conformance check reads as capabilities the QDL implements.
    $this->assertFalse(
      isset($cfg['tokens']['identity']['qdl']['args']['contract_version']),
      'the contract version is recorded outside the QDL args the contract'
      . ' enumerates, so it is not itself a capability the QDL must implement');

    // The claim mappings are still there, so the case above is a stamp added
    // to a real cfg and not a stamp on an empty one.
    $this->assertNotEmpty($cfg['tokens']['identity']['qdl']['args']['claim_mappings'],
      'the stamped cfg is the ordinary QDL-shape cfg, claim mappings included');
  }

  /**
   * A client that uses a named configuration -- the early-return path --
   * records the contract version too, alongside the named-configuration URL
   * that branch already writes.
   *
   * This is the case the stamp exists for. The branch returns before the claim
   * loop runs, so a stamp written only at the end of the marshaller would miss
   * every named-configuration client, and those are exactly the cfgs whose
   * content the plugin did not author.
   */
  public function testNamedConfigClientCfgRecordsContractVersion() {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(
      Oa4mpClaimRows::namedConfigData(Oa4mpClaimRows::claim()));

    $this->assertEqual($this->contractVersion(),
      $cfg['metadata']['Oa4mpClient']['contract_version'],
      'a cfg produced by the named-configuration early return carries the'
      . ' contract version as well');

    $this->assertNotEmpty($cfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'],
      'the stamp is added to the metadata block the branch already writes, not'
      . ' in place of it');

    // The branch really did return the stored configuration, so the assertion
    // above is about the named-configuration path and not about a client that
    // quietly fell through to the QDL shape.
    $this->assertEqual('COmanageRegistry/test/named_config.qdl',
      $cfg['tokens']['identity']['qdl']['load'],
      "the emitted cfg is the named configuration's stored content");
  }

  // ---------------------------------------------------------------------
  // Operator-authored JSON. Everything below stages a stored named
  // configuration the plugin did not write and has never validated.
  // ---------------------------------------------------------------------

  /**
   * A stored configuration that already sets contract_version yields the
   * plugin's value, as a scalar.
   *
   * The scalar half is the point. array_merge_recursive() merges two values
   * under one key into an array of both, so a stamp written before the merge
   * would leave contract_version holding array(99, 1) here -- valid JSON, and
   * unreadable as a version by anything that later reads the census.
   */
  public function testStoredContractVersionIsOverwrittenWithAScalar() {
    $case = 'named configuration setting contract_version';

    $cfg = $this->marshalledNamedConfigCfg($case,
      $this->namedConfigContentWithMetadata(
        array('Oa4mpClient' => array('contract_version' => 99))));

    $stamped = $cfg['metadata']['Oa4mpClient']['contract_version'];

    $this->assertFalse(is_array($stamped),
      $case . ': the version must be a scalar, not an array of the operator\'s'
      . ' value and the plugin\'s -- got ' . var_export($stamped, true));

    $this->assertEqual($this->contractVersion(), $stamped,
      $case . ': operator-authored JSON cannot set or alter the contract'
      . ' version; the plugin\'s value wins');
  }

  /**
   * A stored configuration that sets some other key in the plugin's metadata
   * namespace keeps it. The stamp writes one key; it does not clear the block.
   */
  public function testUnrelatedStoredMetadataKeyIsRetained() {
    $case = 'named configuration setting an unrelated metadata key';

    $cfg = $this->marshalledNamedConfigCfg($case,
      $this->namedConfigContentWithMetadata(
        array('Oa4mpClient' => array('operator_note' => 'kept'))));

    $this->assertEqual('kept', $cfg['metadata']['Oa4mpClient']['operator_note'],
      $case . ': a key the operator put in the metadata block survives the stamp');

    $this->assertEqual($this->contractVersion(),
      $cfg['metadata']['Oa4mpClient']['contract_version'],
      $case . ': and the version is recorded beside it');
  }

  /**
   * A stored configuration whose metadata -- at either level -- is a scalar
   * still marshals. The scalar is replaced with the container the stamp needs.
   *
   * Nothing validates a named configuration's JSON on the way in, so
   * "metadata": "anything" is storable today. Writing an array offset into a
   * string raises a TypeError under PHP 8, and the marshaller is not inside a
   * catch, so without the guard this is not a malformed cfg but a client that
   * cannot be saved at all.
   */
  public function testScalarStoredMetadataIsReplacedRatherThanRaising() {
    foreach (array(
      'metadata is a scalar' => 'a string',
      'metadata.Oa4mpClient is a scalar' => array('Oa4mpClient' => 'a string'),
    ) as $case => $metadata) {
      $cfg = $this->marshalledNamedConfigCfg($case,
        $this->namedConfigContentWithMetadata($metadata));

      $this->assertEqual($this->contractVersion(),
        $cfg['metadata']['Oa4mpClient']['contract_version'],
        $case . ': the scalar is replaced with a container and the version is'
        . ' recorded in it');

      // The rest of the stored configuration is untouched: replacing the
      // malformed metadata value is not a licence to discard the cfg.
      $this->assertEqual('COmanageRegistry/test/named_config.qdl',
        $cfg['tokens']['identity']['qdl']['load'],
        $case . ": the named configuration's own content still marshals");
    }
  }

  // ---------------------------------------------------------------------
  // The read-back.
  // ---------------------------------------------------------------------

  /**
   * A stamped cfg fed back through unmarshalling and comparison reports in
   * sync.
   *
   * The stamp is a new top-level key in every cfg the plugin sends, and the
   * plugin reads its own cfgs back on every verify pass. If the unmarshaller
   * or the comparator noticed the key, every client in every deployment would
   * report permanently out of sync the moment the stamp shipped -- a far worse
   * outcome than the missing census it exists to fix. This case says it does
   * not, on the same fixture the contract matrix round-trips.
   */
  public function testStampedCfgRoundTripsInSync() {
    $claim = Oa4mpClaimRows::claim();
    $server = $this->server();

    $cfg = $server->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $this->assertNotEmpty($cfg['metadata']['Oa4mpClient']['contract_version'],
      'the cfg being read back is a stamped one, or this asserts nothing');

    $serverData = $server->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    $this->assertTrue(
      $server->isClientDataSynchronized(Oa4mpClaimRows::pluginSide($claim), $serverData),
      'a client whose cfg carries the contract version reports in sync: the'
      . ' stamp is not a difference the comparator sees');
  }
}
