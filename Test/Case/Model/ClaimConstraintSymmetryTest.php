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
 * The one exception is the server side of the drop rule. Nothing that arrives
 * through marshall/unmarshall can carry a half-populated constraint -- the
 * marshaller strips it on the way out -- so the two tests that exercise the
 * OA4MP-side loop put the constraint on the unmarshalled data directly, and
 * say so where they do it.
 *
 * See docs/solutions/logic-errors/oa4mp-comparator-marshaller-asymmetry-2026-08-22.md
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');

/**
 * The comparator with its log calls captured instead of written.
 *
 * Dropping a half-populated constraint removes it from the comparison, so the
 * log line is the only evidence that state ever existed -- which makes the
 * line, and what it does and does not carry, part of the behavior under test.
 * Overriding log() keeps that assertion off the global CakeLog configuration:
 * no engine is added, nothing is left behind for a later test, and the
 * captured lines belong to this comparison and no other.
 *
 * Local to this file on purpose: it exists for the two tests below, not as
 * shared infrastructure.
 */
class Oa4mpConstraintDropLogSpy extends Oa4mpClientOa4mpServer {

  /** @var array Every message log() was handed, in order. */
  public $logged = array();

  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->logged[] = (string)$msg;

    return true;
  }

  /** Just the lines reporting a dropped constraint. */
  public function droppedConstraintLines() {
    $lines = array();
    foreach ($this->logged as $line) {
      if (strpos($line, 'dropping half-populated constraint') !== false) {
        $lines[] = $line;
      }
    }

    return $lines;
  }
}

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

  /**
   * The OA4MP server side as the real round trip produces it, with
   * $constraints then put on its claim directly.
   *
   * Direct injection is not a shortcut here, it is the only route: the
   * marshaller strips a half-populated constraint on the way out, so anything
   * that reaches the server-side normalization through marshall/unmarshall
   * arrives already filtered. Everything else about the server side is still
   * the product of the real round trip.
   *
   * @param array $claim The claim row both sides are built from.
   * @param array $constraints The constraint rows to put on the server side.
   * @return array The OA4MP server representation, ready to compare.
   */
  private function serverSideWithConstraints($claim, $constraints) {
    $cfg = $this->server()->oa4mpMarshallCfgQdl(Oa4mpClaimRows::data($claim));

    $serverData = $this->server()->oa4mpUnMarshallContent(
      Oa4mpClaimRows::serverObject($cfg), Oa4mpClaimRows::adminClientContext());

    $serverData['Oa4mpClientClaim'][0]['Oa4mpClientClaimConstraint'] = $constraints;

    return $serverData;
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
  // Defect A, the OA4MP server's side of it.
  // ---------------------------------------------------------------------

  /**
   * A half-populated constraint on the OA4MP server's own copy is dropped
   * there too, so the client still reports in sync.
   *
   * Every test above reaches the server side through marshall/unmarshall,
   * which strips a half-populated constraint before the comparator can see
   * one -- so they all exercise the plugin-side loop and none of them the
   * server-side one. This test is the server-side half: the constraint is put
   * on the unmarshalled data directly, which is the only way that state
   * reaches the OA4MP-side normalization at all.
   *
   * It is not a hypothetical shape. The plugin can no longer send one, but the
   * server's stored cfg is not written only by this plugin, and a cfg edited
   * anywhere else can hold one.
   */
  public function testHalfPopulatedConstraintOnTheServerSideIsDroppedFromTheComparison() {
    $claim = Oa4mpClaimRows::claim(array('Oa4mpClientClaimConstraint' => array()));

    $this->assertEqual(array(), $this->emittedConstraints($claim),
      'the plugin side of this comparison carries no constraint, so the only'
      . ' constraint in play is the one put on the server side below');

    $serverData = $this->serverSideWithConstraints($claim, array(
      array('constraint_field' => 'owner', 'constraint_value' => '')
    ));

    $spy = new Oa4mpConstraintDropLogSpy();
    $verdict = $spy->isClientDataSynchronized(Oa4mpClaimRows::pluginSide($claim), $serverData);

    $this->assertTrue($verdict,
      'a half-populated constraint counts for as little on the OA4MP side as'
      . ' on the plugin side: the marshaller would never have emitted one, so'
      . ' keeping it here compares one constraint against the nothing that was'
      . ' actually sent and reports a client out of sync that no edit can'
      . ' repair');

    $lines = $spy->droppedConstraintLines();
    $this->assertEqual(1, count($lines),
      'dropping the constraint takes it out of the comparison, so this log'
      . ' line is the only trace that state leaves anywhere');
    $this->assertContains('oa4mp-side', $lines[0],
      'the line says which side the constraint was dropped from, which is the'
      . ' whole difference between this case and the plugin-side one');
  }

  /**
   * What that log line may carry: the client and the constraint's field name,
   * never the constraint value.
   *
   * Both loops' comments promise this and nothing checked it. The claim rows
   * this model logs elsewhere can carry the DynamoDB credentials, so "log the
   * row" is a habit worth keeping a test against.
   *
   * Two half-populated constraints, one of each shape: the field-only one has
   * a field name to report, and the value-only one has a value that must not
   * be reported.
   */
  public function testServerSideDropLogNamesTheClientAndFieldButNeverTheValue() {
    $claim = Oa4mpClaimRows::claim(array('Oa4mpClientClaimConstraint' => array()));

    $value = 'zzz-constraint-value-that-must-not-be-logged';
    $serverData = $this->serverSideWithConstraints($claim, array(
      array('constraint_field' => 'owner', 'constraint_value' => ''),
      array('constraint_field' => '', 'constraint_value' => $value)
    ));

    $spy = new Oa4mpConstraintDropLogSpy();
    $spy->isClientDataSynchronized(Oa4mpClaimRows::pluginSide($claim), $serverData);

    $lines = $spy->droppedConstraintLines();
    $this->assertEqual(2, count($lines),
      'both half-populated constraints were dropped, so both were reported');

    $report = implode("\n", $lines);
    $this->assertContains(Oa4mpClaimRows::CLIENT_IDENTIFIER, $report,
      'the log names the client the constraint was dropped for; without it the'
      . ' line cannot be acted on');
    $this->assertContains('owner', $report,
      'and names the constraint field, which is what identifies the row');

    $this->assertTrue(strpos($report, $value) === false,
      'but never the constraint value: a dropped-constraint line is written on'
      . ' data the plugin did not author, and this model logs rows that can'
      . ' carry the DynamoDB credentials');
    $this->assertTrue(strpos(implode("\n", $spy->logged), $value) === false,
      'and nothing else the comparison logged echoed it either');
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
