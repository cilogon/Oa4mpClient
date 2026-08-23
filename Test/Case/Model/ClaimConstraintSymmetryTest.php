<?php
/**
 * Regression coverage for the two comparator/marshaller asymmetries in
 * Model/Oa4mpClientOa4mpServer.php (U6).
 *
 * The marshaller (oa4mpMarshallCfgQdl) and the sync comparator
 * (isClientDataSynchronized) each decide, independently, which parts of a
 * claim row are real. When those two decisions disagree, the plugin sends the
 * server one thing and then compares against another, and the client reports
 * out of sync on every verify pass with no edit able to repair it.
 *
 * Two fields disagreed:
 *
 *  - Claim constraints. The marshaller emits a constraint only when BOTH
 *    constraint_field and constraint_value are populated (commit 7684cbb);
 *    both comparator normalizations kept a constraint when EITHER was. A
 *    half-populated constraint therefore marshalled to nothing and compared as
 *    one.
 *  - source_model_claim_value_field. The marshaller strips it whenever
 *    empty() says so, which includes the string '0'; both comparator
 *    normalizations read it with a null coalesce, which keeps '0'. The final
 *    array comparison is loose, so '' == null passes and only '0' bites.
 *
 * Both directions are covered here: the asymmetric cases must report in sync,
 * and a genuine constraint difference -- fully populated on both sides, so the
 * drop rule plays no part in it -- must still report out of sync. That
 * negative control is what keeps the fix from being indistinguishable from
 * "stop comparing constraints".
 *
 * The server side is always built by marshalling a claim and running the
 * emitted cfg back through oa4mpUnMarshallContent(), never hand-built, so a
 * test only passes if the real marshall/unmarshall/compare round trip agrees.
 *
 * See docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md
 */

class ClaimConstraintSymmetryTest extends Oa4mpTestCase {

  /**
   * The runner reuses one instance across every method in this file. Nothing
   * here holds instance state; setUp() says so explicitly.
   */
  public function setUp() {
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * The claim constraints the marshaller actually emits for a claim row.
   *
   * @param array $claim The claim row, from Oa4mpClaimRows::claim().
   * @return array The emitted claim_constraints block, or an empty array.
   */
  private function emittedConstraints($claim) {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));
    $mapping = $cfg['tokens']['identity']['qdl']['args']['claim_mappings'][0];

    return $mapping['claim_constraints'] ?? array();
  }

  /**
   * The comparator's verdict for a client whose persisted claim is $pluginClaim
   * and whose server-side cfg was marshalled from $serverClaim.
   *
   * Passing the same claim for both is the ordinary round trip; passing
   * different claims stages a real difference between the two sides.
   *
   * @param array $pluginClaim The persisted claim row.
   * @param array $serverClaim The claim row the server's cfg was built from.
   * @return boolean The comparator's verdict.
   */
  private function verdictFor($pluginClaim, $serverClaim) {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($serverClaim));

    $serverData = $this->server()->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    return $this->server()->isClientDataSynchronized(
      Oa4mpClaimRows::pluginSide($pluginClaim), $serverData);
  }

  // ---------------------------------------------------------------------
  // Defect A: half-populated constraints.
  // ---------------------------------------------------------------------

  /**
   * A constraint with a field but no value is not sent to the server, so the
   * comparator must not count it on the plugin side either.
   */
  public function testConstraintWithEmptyValueReportsInSync() {
    $claim = Oa4mpClaimRows::claim(array(
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'owner', ''),
      ),
    ));

    $this->assertEqual(array(), $this->emittedConstraints($claim),
      'a constraint with an empty value must not be emitted to the server');

    $this->assertTrue($this->verdictFor($claim, $claim),
      'a claim whose only constraint has an empty value marshals to no'
      . ' constraint, so the comparator must see none on either side and'
      . ' report the client in sync');
  }

  /**
   * The mirror case: a constraint with a value but no field.
   */
  public function testConstraintWithEmptyFieldReportsInSync() {
    $claim = Oa4mpClaimRows::claim(array(
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, '', 'false'),
      ),
    ));

    $this->assertEqual(array(), $this->emittedConstraints($claim),
      'a constraint with an empty field must not be emitted to the server');

    $this->assertTrue($this->verdictFor($claim, $claim),
      'a claim whose only constraint has an empty field marshals to no'
      . ' constraint, so the comparator must see none on either side and'
      . ' report the client in sync');
  }

  /**
   * Positive control: a fully populated constraint is emitted and still
   * round-trips in sync, so the two tests above are not passing because
   * constraints stopped being compared.
   */
  public function testFullyPopulatedConstraintReportsInSync() {
    $claim = Oa4mpClaimRows::claim(array(
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'owner', 'false'),
      ),
    ));

    $this->assertEqual(
      array(array('constraint_field' => 'owner', 'constraint_value' => 'false')),
      $this->emittedConstraints($claim),
      'a fully populated constraint must still be emitted to the server');

    $this->assertTrue($this->verdictFor($claim, $claim),
      'a fully populated constraint must still round-trip in sync');
  }

  /**
   * Negative control: a real difference in a constraint value must still
   * report out of sync.
   *
   * Both sides are fully populated on purpose. A half-populated side compared
   * against a full one would exercise the new drop rule instead of drift
   * detection, and would encode the masking the fix introduces as though it
   * were the behavior under test.
   */
  public function testDifferingFullyPopulatedConstraintsReportOutOfSync() {
    $pluginClaim = Oa4mpClaimRows::claim(array(
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'owner', 'false'),
      ),
    ));
    $serverClaim = Oa4mpClaimRows::claim(array(
      'Oa4mpClientClaimConstraint' => array(
        Oa4mpClaimRows::constraint(90, 'owner', 'true'),
      ),
    ));

    $this->assertNotEmpty($this->emittedConstraints($serverClaim),
      'the server side of this comparison must carry a real constraint, or the'
      . ' out-of-sync verdict below would prove nothing');

    $this->assertFalse($this->verdictFor($pluginClaim, $serverClaim),
      'two fully populated constraints that differ in their value must still'
      . ' report the client out of sync');
  }

  // ---------------------------------------------------------------------
  // Defect B: the string-zero value field.
  // ---------------------------------------------------------------------

  /**
   * A source_model_claim_value_field holding the string '0' is stripped by the
   * marshaller (empty('0') is true), so the comparator must strip it too.
   *
   * Before the fix the comparator's null-coalescing read kept the '0' on the
   * plugin side and compared it against the server's absent value; '0' != null
   * even loosely, so the client reported out of sync on every verify pass and
   * could never be repaired by re-sending the same data.
   */
  public function testStringZeroValueFieldReportsInSync() {
    $claim = Oa4mpClaimRows::claim(array('source_model_claim_value_field' => '0'));

    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));
    $mapping = $cfg['tokens']['identity']['qdl']['args']['claim_mappings'][0];
    $this->assertFalse(isset($mapping['source_model_claim_value_field']),
      'a string-zero value field must be absent from the emitted cfg, which is'
      . ' the fact the comparator has to agree with');

    $this->assertTrue($this->verdictFor($claim, $claim),
      'a string-zero value field is stripped from the cfg, so the comparator'
      . ' must strip it on the plugin side too and report the client in sync');
  }

  /**
   * The empty-string sibling. It reported in sync before the fix only because
   * the comparator's final array comparison is loose and '' == null; after the
   * fix it is in sync because both sides normalize to null. Pinned so the two
   * states are not confused for one another later.
   */
  public function testEmptyValueFieldReportsInSync() {
    $claim = Oa4mpClaimRows::claim(array('source_model_claim_value_field' => ''));

    $this->assertTrue($this->verdictFor($claim, $claim),
      'an empty value field is stripped from the cfg and must normalize to'
      . ' null on the plugin side, so the client reports in sync');
  }
}
