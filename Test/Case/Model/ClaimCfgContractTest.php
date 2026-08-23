<?php
/**
 * Golden cfg contract matrix for claim marshalling
 * (Model/Oa4mpClientOa4mpServer.php::oa4mpMarshallCfgQdl).
 *
 * One test method per matrix row. The runner reports pass or fail per test*
 * method and its fail() helper throws, so a loop over rows inside one method
 * would stop at the first drifted row and report a single failure; per-method
 * rows give per-row attribution, and every failure message names its row.
 *
 * Each row asserts the exact claim mapping the plugin emits against a stored
 * expected value written out in full, and (except where noted) feeds that
 * emitted cfg back through unmarshalling and sync verification and asserts the
 * client reports in sync.
 *
 * Golden values are captured at oa4mpMarshallCfgQdl(), not at the outer
 * oa4mpMarshallContent(): the outer marshaller embeds an absolute Router::url
 * on every call, which nothing pins in the test environment, so an expected
 * value captured there would be environment-dependent.
 *
 * A changed expected value is a behavior change. Resolve it by reviewing the
 * change, not by re-recording the value.
 *
 * Rows build their data with Test/lib/Oa4mpClaimRows.php, which also declares
 * the row set machine-readably for the enumeration drift check.
 *
 * The configuration-shape rows near the end of the file are the exception to
 * both of those: they pin which cfg a client resolves to, not what the QDL
 * shape contains, so they carry no stored expected claim mapping and no round
 * trip. Two of them run at oa4mpMarshallContent(), because that is where the
 * public-client suppression lives.
 *
 * Already locked elsewhere; deliberately not re-asserted here:
 *  - A confidential client with no claim sources emits no cfg --
 *    CfgMarshallingTest::testMarshalledContentUsesSecretAuthForConfidentialClient.
 *  - A constraint with a populated field and an empty value is dropped --
 *    CfgMarshallingTest::testEmptyConstraintValueIsNotSerialized.
 *  - The per-client / admin-default Dynamo configuration resolution --
 *    CfgMarshallingTest::testResolveDynamoConfigFallsBackWhenPerClientIsPhantomNull
 *    and testResolveDynamoConfigUsesPerClientWhenRealValuesPresent.
 *  - A multi-claim QDLv3 cfg and a legacy QDLv2 cfg round-tripping in sync,
 *    and their negative controls --
 *    SyncVerificationTest::testUnmarshalledQdlv3ClaimsReportInSync,
 *    testUnmarshalledQdlv3ConstraintDifferenceReportsOutOfSync,
 *    testUnmarshalledLegacyCfgClaimsReportInSync and
 *    testUnmarshalledLegacyCfgMissingMappingReportsOutOfSync.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U1
 * (R1, R2, R3, R4, R5, R13, R19) and U2 (R2, R4).
 */

class ClaimCfgContractTest extends Oa4mpTestCase {

  /**
   * The runner reuses one instance across every method in this file, so any
   * instance state would leak between rows. This case holds none: every row
   * builds its data from Oa4mpClaimRows and throws it away. setUp() is here to
   * make that explicit rather than accidental.
   */
  public function setUp() {
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * Marshall one claim and assert the emitted claim mapping matches the row's
   * stored expected value exactly, including key order and scalar type.
   *
   * @param string $row Row name, so a multi-row regression is attributable.
   * @param array $claim The claim row, from Oa4mpClaimRows::claim().
   * @param array $expected The single expected claim mapping.
   * @return array The full emitted cfg, for rows that assert more.
   */
  private function assertRowEmits($row, $claim, $expected) {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $this->assertEqual(array($expected), $cfg['tokens']['identity']['qdl']['args']['claim_mappings'],
      'row ' . $row . ': the emitted claim mapping must match the stored expected value');

    return $cfg;
  }

  /**
   * Feed the row's emitted cfg back through unmarshalling and sync
   * verification and assert the comparator's verdict.
   *
   * @param string $row Row name.
   * @param array $claim The same claim row that was marshalled.
   * @param boolean $expected The verdict the emitted cfg implies.
   * @param string $why Why that verdict follows from the emitted cfg.
   */
  private function assertRowVerdict($row, $claim, $expected, $why) {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $serverData = $this->server()->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    $verdict = $this->server()->isClientDataSynchronized(
      Oa4mpClaimRows::pluginSide($claim), $serverData);

    $this->assertEqual($expected, $verdict, 'row ' . $row . ': ' . $why);
  }

  /** Shorthand for the common case: the row round-trips in sync. */
  private function assertRowRoundTrips($row, $claim) {
    $this->assertRowVerdict($row, $claim, true,
      'the emitted cfg, unmarshalled and compared against the persisted claim'
      . ' row, must report in sync');
  }

  // ---------------------------------------------------------------------
  // Emptiness axis. Every claim column that reaches the cfg, three ways:
  // populated, empty, and set to a string zero.
  //
  // All three layers now agree about a string zero. The marshaller strips it
  // (empty('0') is true), the unmarshaller skips it for the same reason, and
  // the comparator applies the same emptiness test to
  // source_model_claim_value_field, so the column normalizes to null on both
  // sides. It did not always: the comparator used to read that column with a
  // null coalesce, kept the '0' on the plugin side, and compared it unequal to
  // the server's absent value, which reported the client out of sync on every
  // verify pass. Each string-zero row therefore asserts the comparator's
  // verdict, not merely that the field is absent from the cfg -- see
  // Test/Case/Model/ClaimConstraintSymmetryTest.php for the regression lock.
  //
  // The populated rows each override a single column to a value distinct from
  // the baseline, so each row pins its own column. The marshaller copies the
  // claim row verbatim, so a combination that the claims form would not offer
  // (a CoPersonRole source with a CoGroupMember value field, say) still
  // exercises the pass-through correctly; the form-coherent combinations are
  // covered by the claim-source rows further down.
  // ---------------------------------------------------------------------

  public function testClaimNamePopulated() {
    $row = 'claim_name/populated';
    $claim = Oa4mpClaimRows::claim(array('claim_name' => 'eduperson_entitlement'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'eduperson_entitlement',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimNameEmpty() {
    $row = 'claim_name/empty';
    $claim = Oa4mpClaimRows::claim(array('claim_name' => ''));

    // No claim_name key at all: the server is sent a nameless mapping. That is
    // the marshaller's current behavior, characterized here rather than
    // endorsed. This row is exempt from the round-trip assertion (see
    // Oa4mpClaimRows::declaredExemptions): both the unmarshaller and the
    // comparator index claim_name unconditionally, so the missing key produces
    // an undefined-key warning rather than a verdict.
    $this->assertRowEmits($row, $claim, array(
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));
  }

  public function testClaimNameStringZero() {
    $row = 'claim_name/string_zero';
    $claim = Oa4mpClaimRows::claim(array('claim_name' => '0'));

    // A claim literally named "0" is stripped exactly like an empty one.
    $cfg = $this->assertRowEmits($row, $claim, array(
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    // The structural fact this row stands in for, since the comparator cannot
    // reach a verdict on a nameless mapping.
    $mapping = $cfg['tokens']['identity']['qdl']['args']['claim_mappings'][0];
    $this->assertFalse(isset($mapping['claim_name']),
      'row ' . $row . ': a string-zero claim_name must leave the mapping nameless,'
      . ' which is what makes it unreadable to the unmarshaller and the comparator');
  }

  public function testSourceModelPopulated() {
    $row = 'source_model/populated';
    $claim = Oa4mpClaimRows::claim(array('source_model' => 'CoPersonRole'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoPersonRole',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceModelEmpty() {
    $row = 'source_model/empty';
    $claim = Oa4mpClaimRows::claim(array('source_model' => ''));

    // Exempt from the round trip for the same reason as claim_name/empty.
    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));
  }

  public function testSourceModelStringZero() {
    $row = 'source_model/string_zero';
    $claim = Oa4mpClaimRows::claim(array('source_model' => '0'));

    $cfg = $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $mapping = $cfg['tokens']['identity']['qdl']['args']['claim_mappings'][0];
    $this->assertFalse(isset($mapping['source_model']),
      'row ' . $row . ': a string-zero source_model must leave the mapping with no'
      . ' source, which is what makes it unreadable to the unmarshaller and the'
      . ' comparator');
  }

  public function testSourceModelClaimValueFieldPopulated() {
    $row = 'source_model_claim_value_field/populated';
    $claim = Oa4mpClaimRows::claim(array('source_model_claim_value_field' => 'all'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceModelClaimValueFieldEmpty() {
    $row = 'source_model_claim_value_field/empty';
    $claim = Oa4mpClaimRows::claim(array('source_model_claim_value_field' => ''));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    // The comparator applies the marshaller's emptiness test to this column,
    // so the empty string normalizes to null on the plugin side exactly as the
    // server's absent value does. This row reported in sync before that fix
    // too, but only because the final array comparison is loose and
    // '' == null; the string-zero row below is where the difference showed.
    $this->assertRowVerdict($row, $claim, true,
      'an empty value field is absent from the cfg and normalizes to null on'
      . ' both sides of the comparison');
  }

  public function testSourceModelClaimValueFieldStringZero() {
    $row = 'source_model_claim_value_field/string_zero';
    $claim = Oa4mpClaimRows::claim(array('source_model_claim_value_field' => '0'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    // This column used to be the one place the three layers disagreed: the
    // marshaller and the unmarshaller both dropped the '0' on an emptiness
    // test while the comparator's null-coalescing read kept it, so the client
    // reported out of sync on every verify pass and could never be brought
    // back into sync by re-sending the same data. The comparator now applies
    // the same emptiness test, so the column normalizes to null on both sides.
    $this->assertRowVerdict($row, $claim, true,
      'a string-zero value field is stripped from the cfg and the comparator'
      . ' applies the same emptiness test, so the client reports in sync');
  }

  public function testClaimValueSelectionPopulated() {
    $row = 'claim_value_selection/populated';
    $claim = Oa4mpClaimRows::claim(array('claim_value_selection' => 'first'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimValueSelectionEmpty() {
    $row = 'claim_value_selection/empty';
    $claim = Oa4mpClaimRows::claim(array('claim_value_selection' => ''));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimValueSelectionStringZero() {
    $row = 'claim_value_selection/string_zero';
    $claim = Oa4mpClaimRows::claim(array('claim_value_selection' => '0'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    // The comparator normalizes this column with an emptiness test on both
    // sides, so it agrees with the cfg: nothing was sent, nothing is compared.
    $this->assertRowVerdict($row, $claim, true,
      'a string-zero selection is stripped from the cfg and normalized to null'
      . ' on both sides of the comparator, so the client reports in sync');
  }

  public function testClaimValueJsonFormatPopulated() {
    $row = 'claim_value_json_format/populated';
    $claim = Oa4mpClaimRows::claim(array('claim_value_json_format' => 'number'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimValueJsonFormatEmpty() {
    $row = 'claim_value_json_format/empty';
    $claim = Oa4mpClaimRows::claim(array('claim_value_json_format' => ''));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimValueJsonFormatStringZero() {
    $row = 'claim_value_json_format/string_zero';
    $claim = Oa4mpClaimRows::claim(array('claim_value_json_format' => '0'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowVerdict($row, $claim, true,
      'a string-zero json format is stripped from the cfg and normalized to null'
      . ' on both sides of the comparator, so the client reports in sync');
  }

  public function testClaimMultipleValueSerializationPopulated() {
    $row = 'claim_multiple_value_serialization/populated';
    $claim = Oa4mpClaimRows::claim(array('claim_multiple_value_serialization' => 'json_array'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimMultipleValueSerializationEmpty() {
    $row = 'claim_multiple_value_serialization/empty';
    $claim = Oa4mpClaimRows::claim(array('claim_multiple_value_serialization' => ''));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimMultipleValueSerializationStringZero() {
    $row = 'claim_multiple_value_serialization/string_zero';
    $claim = Oa4mpClaimRows::claim(array('claim_multiple_value_serialization' => '0'));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_value_string_serialization_delimiter' => ';',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowVerdict($row, $claim, true,
      'a string-zero serialization mode is stripped from the cfg and normalized'
      . ' to null on both sides of the comparator, so the client reports in sync');
  }

  public function testClaimValueStringSerializationDelimiterPopulated() {
    $row = 'claim_value_string_serialization_delimiter/populated';
    $claim = Oa4mpClaimRows::claim(array('claim_value_string_serialization_delimiter' => ','));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ',',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimValueStringSerializationDelimiterEmpty() {
    $row = 'claim_value_string_serialization_delimiter/empty';
    $claim = Oa4mpClaimRows::claim(array('claim_value_string_serialization_delimiter' => ''));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testClaimValueStringSerializationDelimiterStringZero() {
    $row = 'claim_value_string_serialization_delimiter/string_zero';
    $claim = Oa4mpClaimRows::claim(array('claim_value_string_serialization_delimiter' => '0'));

    // A literal "0" is a plausible delimiter and an implausible one to intend,
    // but either way the marshaller cannot send it: empty('0') is true.
    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowVerdict($row, $claim, true,
      'a string-zero delimiter is stripped from the cfg and normalized to null'
      . ' on both sides of the comparator, so the client reports in sync');
  }

  // ---------------------------------------------------------------------
  // Claim source x constraint count. The claims form exposes exactly two
  // constraint slots and gates how many each source shows, so this cross
  // covers all eight sources at their form-offered constraint count.
  // ---------------------------------------------------------------------

  public function testSourceEmailAddressWithTwoConstraints() {
    $row = 'source/EmailAddress/constraints:2';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
        Oa4mpClaimRows::constraint(91, 'verified', 'true'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'official'),
        array('constraint_field' => 'verified', 'constraint_value' => 'true'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceNameWithTwoConstraints() {
    $row = 'source/Name/constraints:2';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'name',
      'source_model' => 'Name',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
        Oa4mpClaimRows::constraint(91, 'primary', 'true'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'name',
      'source_model' => 'Name',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'official'),
        array('constraint_field' => 'primary', 'constraint_value' => 'true'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceCoGroupMemberWithOneConstraint() {
    $row = 'source/CoGroupMember/constraints:1';
    $claim = Oa4mpClaimRows::claim();

    $this->assertRowEmits($row, $claim, array(
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
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceIdentifierWithOneConstraint() {
    $row = 'source/Identifier/constraints:1';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'vo_person_id',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'orcid'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'vo_person_id',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'orcid'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceCoPersonRoleWithOneConstraint() {
    $row = 'source/CoPersonRole/constraints:1';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'affiliation',
      'source_model' => 'CoPersonRole',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'status', 'active'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'affiliation',
      'source_model' => 'CoPersonRole',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'status', 'constraint_value' => 'active'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceSshKeyWithOneConstraint() {
    $row = 'source/SshKey/constraints:1';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'ssh_keys',
      'source_model' => 'SshKey',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'all'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'ssh_keys',
      'source_model' => 'SshKey',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'all'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceCoTAndCAgreementWithNoConstraints() {
    $row = 'source/CoTAndCAgreement/constraints:0';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'tandc',
      'source_model' => 'CoTAndCAgreement',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(),
    ));

    // No claim_constraints key at all -- not an empty one. The exact-match
    // assertion is what proves the key is absent rather than present-and-empty.
    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'tandc',
      'source_model' => 'CoTAndCAgreement',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceUnixClusterAccountWithNoConstraints() {
    $row = 'source/UnixClusterAccount/constraints:0';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'unix_username',
      'source_model' => 'UnixClusterAccount',
      'source_model_claim_value_field' => 'username',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'unix_username',
      'source_model' => 'UnixClusterAccount',
      'source_model_claim_value_field' => 'username',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  // ---------------------------------------------------------------------
  // Claim source x value format. The claims form shows the JSON-format
  // selector only for Identifier and UnixClusterAccount; the string cells for
  // the other sources are the constraint-count rows above. The EmailAddress
  // row below is the interesting cell: the form hides the selector, but a
  // stored value still reaches the cfg, because the marshaller does not gate
  // on the source.
  // ---------------------------------------------------------------------

  public function testSourceIdentifierWithNumberFormat() {
    $row = 'source/Identifier/format:number';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'uid_number',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'uid'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'uid_number',
      'source_model' => 'Identifier',
      'source_model_claim_value_field' => 'identifier',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'uid'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceUnixClusterAccountWithNumberFormat() {
    $row = 'source/UnixClusterAccount/format:number';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'unix_uid',
      'source_model' => 'UnixClusterAccount',
      'source_model_claim_value_field' => 'uid',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'unix_uid',
      'source_model' => 'UnixClusterAccount',
      'source_model_claim_value_field' => 'uid',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceEmailAddressWithNumberFormat() {
    $row = 'source/EmailAddress/format:number';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
      ),
    ));

    // The form hides the format selector for EmailAddress, so this combination
    // cannot be produced through the UI today. The marshaller emits it anyway;
    // the row exists so a later decision to gate the format on the source
    // shows up here rather than only on a real server.
    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'number',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'official'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  // ---------------------------------------------------------------------
  // Claim source x value selection. The form forces 'all' for CoGroupMember
  // and CoTAndCAgreement and leaves the rest free.
  // ---------------------------------------------------------------------

  public function testSourceCoGroupMemberWithForcedAllSelection() {
    $row = 'source/CoGroupMember/selection:all';
    $claim = Oa4mpClaimRows::claim(array('claim_value_selection' => 'all'));

    $this->assertRowEmits($row, $claim, array(
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
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceCoTAndCAgreementWithForcedAllSelection() {
    $row = 'source/CoTAndCAgreement/selection:all';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'tandc',
      'source_model' => 'CoTAndCAgreement',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'tandc',
      'source_model' => 'CoTAndCAgreement',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceEmailAddressWithFirstSelection() {
    $row = 'source/EmailAddress/selection:first';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'official'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceEmailAddressWithAllSelection() {
    $row = 'source/EmailAddress/selection:all';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'official'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  // ---------------------------------------------------------------------
  // Claim source x serialization mode. The form allows a delimited string for
  // every source except CoTAndCAgreement; the marshaller does not enforce that,
  // which is what the first row below records.
  // ---------------------------------------------------------------------

  public function testSourceCoTAndCAgreementWithDelimitedStringSerialization() {
    $row = 'source/CoTAndCAgreement/serialization:delimited_string';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'tandc',
      'source_model' => 'CoTAndCAgreement',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => '|',
      'Oa4mpClientClaimConstraint' => array(),
    ));

    // The claims form marks this source as not allowing a delimited string.
    // The marshaller has no such rule and emits it, so a stored row from an
    // earlier form version, or one written outside the form, still reaches the
    // server this way. Characterized, not endorsed.
    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'tandc',
      'source_model' => 'CoTAndCAgreement',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => '|',
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceEmailAddressWithDelimitedStringSerialization() {
    $row = 'source/EmailAddress/serialization:delimited_string';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ',',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'delimited_string',
      'claim_value_string_serialization_delimiter' => ',',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'official'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testSourceSshKeyWithJsonArraySerialization() {
    $row = 'source/SshKey/serialization:json_array';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'ssh_keys',
      'source_model' => 'SshKey',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'all'),
      ),
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'ssh_keys',
      'source_model' => 'SshKey',
      'source_model_claim_value_field' => 'all',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'type', 'constraint_value' => 'all'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  // ---------------------------------------------------------------------
  // Serialization mode x delimiter field. Crossed precisely because the
  // marshaller does NOT couple them: it emits whatever delimiter is stored,
  // including alongside a serialization mode that does not use one. The
  // delimited_string half of the cross is the delimiter rows in the emptiness
  // axis above, which run on the delimited_string baseline.
  // ---------------------------------------------------------------------

  public function testJsonArraySerializationWithPopulatedDelimiter() {
    $row = 'serialization:json_array/delimiter:populated';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => ',',
    ));

    // Both fields are emitted. A delimiter alongside json_array is inert on the
    // server, but it is sent, and it participates in the sync comparison -- so
    // clearing the mode without clearing the delimiter leaves a value behind
    // that both sides must keep agreeing on.
    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => ',',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  public function testJsonArraySerializationWithEmptyDelimiter() {
    $row = 'serialization:json_array/delimiter:empty';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
    ));

    $this->assertRowEmits($row, $claim, array(
      'claim_name' => 'is_member_of',
      'source_model' => 'CoGroupMember',
      'source_model_claim_value_field' => 'member',
      'claim_value_selection' => 'all',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_constraints' => array(
        array('constraint_field' => 'owner', 'constraint_value' => 'false'),
      ),
    ));

    $this->assertRowRoundTrips($row, $claim);
  }

  // ---------------------------------------------------------------------
  // Round trip and cfg envelope.
  // ---------------------------------------------------------------------

  /**
   * A confidential client whose claim carries two fully populated constraints,
   * marshalled and fed back through unmarshalling and sync verification,
   * reports in sync -- constraints included. The negative control lives in
   * SyncVerificationTest::testUnmarshalledQdlv3ConstraintDifferenceReportsOutOfSync,
   * which changes one constraint value and asserts out of sync; without a
   * negative control somewhere, an unmarshaller that dropped constraints
   * entirely would satisfy this row.
   */
  public function testTwoConstraintRowRoundTripsInSync() {
    $row = 'round_trip/EmailAddress/two_constraints';
    $claim = Oa4mpClaimRows::claim(array(
      'claim_name' => 'email',
      'source_model' => 'EmailAddress',
      'source_model_claim_value_field' => 'mail',
      'claim_value_selection' => 'first',
      'claim_value_json_format' => 'string',
      'claim_multiple_value_serialization' => 'json_array',
      'claim_value_string_serialization_delimiter' => '',
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'type', 'official'),
        Oa4mpClaimRows::constraint(91, 'verified', 'true'),
      ),
    ));

    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $this->assertEqual(2, count($cfg['tokens']['identity']['qdl']['args']['claim_mappings'][0]['claim_constraints']),
      'row ' . $row . ': both fully populated constraints must be emitted');

    $this->assertRowRoundTrips($row, $claim);
  }

  /**
   * The cfg envelope every other row's claim mapping sits inside: the QDL
   * script to load, the execution phases, and the resolved Dynamo arguments.
   * Asserted once, in full, so the matrix is not blind to a change outside the
   * claim_mappings block.
   */
  public function testCfgEnvelopeForBaselineRow() {
    $row = 'cfg_envelope/CoGroupMember';
    $claim = Oa4mpClaimRows::claim();

    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $this->assertEqual(array(
      'tokens' => array(
        'identity' => array(
          'type' => 'identity',
          'qdl' => array(
            'load' => 'COmanageRegistry/test/dynamodb_claims.qdl',
            'xmd' => array(
              'exec_phase' => array('post_auth', 'post_refresh', 'post_token', 'post_user_info'),
            ),
            'args' => array(
              'dynamo_module_config' => array(
                'region' => 'us-east-2',
                'access_key_id' => 'AKIAEXAMPLE',
                'secret_access_key' => 'not-a-real-secret',
                'table_name' => 'registry',
                'partition_key' => 'sub',
              ),
              'partition_key_template' => '${sub}',
              'partition_key_claim_name' => 'sub',
              'claim_mappings' => array(
                array(
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
                ),
              ),
            ),
          ),
        ),
      ),
    ), $cfg, 'row ' . $row . ': the whole emitted cfg must match the stored expected value');
  }

  // ---------------------------------------------------------------------
  // Configuration shape. Every row above assumes the client resolves to the
  // QDL shape; these three pin that resolution itself. A client carrying
  // claims lands in one of three places -- no cfg at all, the stored named
  // configuration, or the QDL shape -- and which one is decided before any
  // claim is read.
  //
  // None of the three carries a stored expected claim mapping, and all three
  // are exempt from the round-trip assertion (see
  // Oa4mpClaimRows::declaredExemptions).
  // ---------------------------------------------------------------------

  /**
   * A public client carrying claims is sent no cfg at all.
   *
   * The behavior itself is already locked by
   * CfgMarshallingTest::testMarshalledContentHasNoCfgForPublicClient, which
   * covers the bug it came from (OA4MP rejects a cfg on a public client) and
   * carries its own confidential positive control. This row extends that into
   * the matrix rather than duplicating it: it runs on the matrix's own client
   * and claim fixture, so a claim shape that the matrix adds later is carried
   * through the public-client branch too, and it reads as the first cell of
   * the configuration-shape dimension rather than as an isolated bug test.
   *
   * Asserted at oa4mpMarshallContent() because that is where the suppression
   * lives; oa4mpMarshallCfgQdl() does not know whether the client is public.
   */
  public function testPublicClientResolvesToNoCfg() {
    $row = 'cfg_shape/public_client';
    $claim = Oa4mpClaimRows::claim();

    $data = Oa4mpClaimRows::data($claim, array('public_client' => true));

    $content = $this->server()->oa4mpMarshallContent(
      Oa4mpClaimRows::adminClientContext(), $data);

    $this->assertEqual('none', $content['token_endpoint_auth_method'],
      'row ' . $row . ': a public client uses token_endpoint_auth_method none');

    $this->assertFalse(isset($content['cfg']),
      'row ' . $row . ': a public client must carry no cfg, claims or not --'
      . ' OA4MP rejects a custom configuration on a public client');
  }

  /**
   * A client that uses a named configuration is sent that configuration's
   * stored content, and no claim mappings at all.
   *
   * DEFERRED DEFECT -- this row characterizes current behavior and does not
   * endorse it. The claims tab is ungated for named-configuration clients, so
   * a user can create claims that are silently inert: the named-configuration
   * branch of oa4mpMarshallCfgQdl() returns before the claim loop runs, so
   * those claims are never sent to the server. Clearing the named
   * configuration later activates every previously inert claim at once,
   * releasing identity attributes to a relying party with no review at the
   * moment they go live.
   *
   * This is recorded as open question Q1 in
   * docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md. The
   * row locks current behavior so it cannot drift unnoticed; it is expected to
   * CHANGE when Q1 is resolved, not to block that resolution.
   *
   * The metadata key is excluded from the comparison: the named-configuration
   * branch builds it with Router::url(..., true), so it is environment-
   * dependent even here at the QDL marshaller, which is the same reason the
   * matrix captures its golden values at oa4mpMarshallCfgQdl() rather than at
   * oa4mpMarshallContent(). Its presence is asserted; its value is not.
   */
  public function testNamedConfigClientResolvesToNamedConfigAndDropsClaims() {
    $row = 'cfg_shape/named_config';
    $claim = Oa4mpClaimRows::claim();

    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::namedConfigData($claim));

    // The environment-dependent key, asserted present and then removed so the
    // rest of the cfg can be compared exactly.
    $this->assertNotEmpty($cfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'],
      'row ' . $row . ': the branch must stamp the named configuration URL into'
      . ' the metadata block');
    unset($cfg['metadata']);

    // The structural fact the deferred defect turns on: the client carries a
    // claim, and the emitted cfg has nowhere for it. Asserted on its own so a
    // failure names the defect rather than only reporting a shape mismatch.
    $this->assertFalse(isset($cfg['tokens']['identity']['qdl']['args']['claim_mappings']),
      'row ' . $row . ': the named-configuration branch must emit no claim'
      . ' mappings -- the client carries a claim and it is silently dropped'
      . ' (deferred defect, Q1)');

    // Everything else is the stored named configuration, merged verbatim.
    $this->assertEqual(Oa4mpClaimRows::namedConfigContent(), $cfg,
      'row ' . $row . ': apart from the metadata URL, the emitted cfg must be'
      . " the named configuration's stored content, merged unchanged");
  }

  /**
   * The positive control for the two rows above: a confidential client with no
   * named configuration resolves to the QDL shape, and its claim reaches the
   * claim mappings.
   *
   * Asserted at oa4mpMarshallContent(), the same entry point as the public
   * row, so the two differ only in the public_client flag. Without this, the
   * public row would pass for the wrong reason if the cfg stopped being
   * attached for everyone, and the named-configuration row would pass for the
   * wrong reason if the claim loop stopped emitting mappings on every path.
   *
   * Only the QDL-shape markers are asserted here, not a stored expected value:
   * the full golden cfg is testCfgEnvelopeForBaselineRow, captured at
   * oa4mpMarshallCfgQdl() where no absolute URL is embedded.
   */
  public function testConfidentialClientWithNoNamedConfigResolvesToQdlShape() {
    $row = 'cfg_shape/confidential_no_named_config';
    $claim = Oa4mpClaimRows::claim();

    $content = $this->server()->oa4mpMarshallContent(
      Oa4mpClaimRows::adminClientContext(), Oa4mpClaimRows::data($claim));

    $this->assertEqual('client_secret_basic', $content['token_endpoint_auth_method'],
      'row ' . $row . ': a confidential client uses token_endpoint_auth_method'
      . ' client_secret_basic');

    $this->assertTrue(isset($content['cfg']),
      'row ' . $row . ': a confidential client carrying claims must carry a cfg');

    $this->assertFalse(isset($content['cfg']['metadata']),
      'row ' . $row . ': a client with no named configuration must carry no'
      . ' named-configuration metadata');

    $this->assertEqual('COmanageRegistry/test/dynamodb_claims.qdl',
      $content['cfg']['tokens']['identity']['qdl']['load'],
      'row ' . $row . ": the QDL shape must load the admin client's claims script");

    $this->assertEqual(array(
      array(
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
      ),
    ), $content['cfg']['tokens']['identity']['qdl']['args']['claim_mappings'],
      'row ' . $row . ': the claim must reach the claim mappings');
  }
}
