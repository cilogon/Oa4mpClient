<?php
/**
 * Drift checks for the claim configuration surface (U4).
 *
 * Three independent halves, all of which must derive what they check from the
 * shipping source rather than from a list kept here:
 *
 *  - Half A, row-set coverage. Every enumerated option the claims tab offers
 *    (View/Oa4mpClientClaims/fields.inc) must be pinned by a row the matrix
 *    declares (Oa4mpClaimRows::declaredRows()) or named by an explicit
 *    exemption (Oa4mpClaimRows::declaredExemptions()).
 *
 *  - Half B, comparator whitelist drift. Three sites decide what a claim
 *    field is: oa4mpMarshallCfgQdl() writes the mapping, oa4mpUnMarshallCfgQdlv3()
 *    reads it back, and both claim normalizations in isClientDataSynchronized()
 *    compare it. They used to be three hand-written lists over a marshaller
 *    that copied the whole claim row and stripped a fixed set of keys, so a
 *    column added to the claim table was emitted, dropped on the way back, and
 *    never compared -- the round trip reported "in sync" whatever the new field
 *    held. All three now derive their field list from cfg_contract.json, which
 *    is what makes them incapable of drifting apart rather than merely watched
 *    for it, so this half checks the derivation itself: that every field the
 *    contract declares emittable is reachable by all three, that all three
 *    resolve it out of the same declared capability group, and that a site
 *    which goes back to a list of its own is reported.
 *
 *  - Half C, row-set correspondence. Half A trusts declaredRows() to say what
 *    the matrix pins, and that list is hand-written next to 46 test methods in
 *    ClaimCfgContractTest.php. Nothing used to check the two against each
 *    other, so a row added in one place and not the other either ran unchecked
 *    or credited Half A with coverage nothing runs. This half derives the rows
 *    the contract test actually exercises from its own source -- the
 *    "$row = '...'" literal every row method names itself with -- and diffs
 *    them against declaredRows() in both directions.
 *
 * The load-bearing constraint is that nothing here declares the field set. The
 * emitted set is read out of cfg_contract.json, the document the marshaller
 * itself reads; the three comparator lists are located in
 * Model/Oa4mpClientOa4mpServer.php and resolved through that same document. A
 * hand-maintained copy of either would be updated by the same person who adds
 * the capability, so the check would never fire -- worse than useless, because
 * it would report green.
 *
 * The same reasoning drives how Half A finds the offered options. It does not
 * look for a named widget: it starts from the option lists the view builds
 * ("$options = array();" and the assignments after it) and asks which claim
 * column each one is rendered for, whatever Form helper does the rendering.
 * Scanning for Form->select() and Form->radio() by name used to mean that a
 * column re-rendered through Form->input() -- an idiom this very view already
 * uses for claim_name and for the delimiter -- silently stopped being a column
 * the coverage check knew about. A list this derivation cannot attribute to a
 * claim column is reported, never skipped.
 *
 * That is also why the positive controls below perturb the DERIVATION SOURCE
 * (the schema text, the view text, the contract test's text) and not the test's
 * own inputs: a probe injected downstream of the derivation passes even when
 * the derivation has gone stale, which is precisely the failure this file
 * exists to prevent. The one input a control does hand in directly is an
 * exemption list, because the exemptions are a declaration and not a
 * derivation; the option such a control exempts is still injected into the view
 * text, so what the exemption has to cover is derived like everything else.
 *
 * Source text is read with the comment lines stripped first, following
 * Test/Case/CiWorkflowTest.php, so a field named only in prose is not a match.
 * No database and no credential are needed.
 *
 * See docs/plans/2026-08-22-1554-test-claims-regression-coverage-plan.md U4.
 */

class ClaimCfgDriftTest extends Oa4mpTestCase {

  /** Derivation inputs, comment-stripped. Re-read for every test method. */
  private $schemaSource = null;
  private $modelSource = null;
  private $viewSource = null;
  private $contractSource = null;

  /**
   * The capability contract, read raw. Not comment-stripped: it is JSON, which
   * has no comments, and the declaration itself is the derivation source.
   */
  private $contractDocument = null;

  /** A field name no shipping source uses, for the positive controls. */
  const PROBE = 'zzz_drift_probe';

  /**
   * The capability group that declares what a claim mapping may carry.
   *
   * Named here rather than derived because it is a name and not a set: the
   * model looks the same group up by the same string, and
   * ContractDeclarationTest asserts cfg_contract.json declares it. What is
   * derived is everything the group contains.
   */
  const CLAIM_FIELD_GROUP = 'claim_mapping_fields';

  public function setUp() {
    $this->schemaSource = null;
    $this->modelSource = null;
    $this->viewSource = null;
    $this->contractSource = null;
    $this->contractDocument = null;

    $this->schemaSource = $this->stripComments(
      $this->source('Config' . DS . 'Schema' . DS . 'schema.xml'));
    $this->modelSource = $this->stripComments(
      $this->source('Model' . DS . 'Oa4mpClientOa4mpServer.php'));
    $this->viewSource = $this->stripComments(
      $this->source('View' . DS . 'Oa4mpClientClaims' . DS . 'fields.inc'));
    $this->contractSource = $this->stripComments(
      $this->source('Test' . DS . 'Case' . DS . 'Model' . DS . 'ClaimCfgContractTest.php'));
    $this->contractDocument = $this->source('cfg_contract.json');
  }

  // ------------------------------------------------------------------
  // Half B: comparator whitelist drift.
  // ------------------------------------------------------------------

  /**
   * Every claim field the contract declares emittable must be read back by the
   * QDLv3 unmarshaller and by both claim normalizations in the sync
   * comparator. A field missing from any one of the three is named in the
   * failure.
   */
  public function testEveryEmittedClaimFieldReachesAllThreeComparatorLists() {
    $report = $this->comparatorDriftReport($this->modelSource, $this->contractDocument);

    $this->assertEqual('', $report,
      'a claim field the marshaller emits but a comparator cannot see is'
      . ' round-tripped as "in sync" no matter what it holds; point the'
      . ' unmarshaller and both normalizations in isClientDataSynchronized()'
      . ' at the capability group the marshaller emits from');

    // The reader lists are checked separately above, and they are now provably
    // one list with the writer's: every site resolves its claim fields out of
    // the same declared capability groups, so "do the writer and the
    // comparator agree" has one answer by construction rather than by
    // discipline. Assert the sharing here rather than leave it to be re-read
    // out of the model: four sites happening to agree today is not the
    // property, one source is.
    $sites = $this->claimFieldSites($this->modelSource);

    $this->assertEqual(4, count($sites),
      'the sites are the marshaller, the QDLv3 unmarshaller, and the two claim'
      . ' normalizations in isClientDataSynchronized(); found '
      . implode(', ', array_keys($sites)));

    $groupSets = array();
    foreach ($sites as $label => $site) {
      $this->assertTrue(in_array(self::CLAIM_FIELD_GROUP, $site['groups'], true),
        $label . ' must resolve its claim fields out of the contract\'s '
        . self::CLAIM_FIELD_GROUP . ' group; found '
        . var_export($site['groups'], true));

      $groups = $site['groups'];
      sort($groups);
      $groupSets[] = implode(',', $groups);
    }

    $this->assertEqual(1, count(array_unique($groupSets)),
      'every site must read the same declared capability groups, or the writer'
      . ' and the comparator are once again asking different questions: '
      . var_export($groupSets, true));
  }

  /**
   * The positive control for Half B, injected into the derivation source: a
   * site that stops reading the contract and keeps a list of its own must come
   * back as drift, with the fields it can no longer see named.
   *
   * This is the perturbation that matters now. The old control added a column
   * to schema.xml, because the marshaller emitted every column there was; the
   * marshaller emits what the contract declares now, so the way this seam
   * reopens is a site quietly reverting to a list of its own -- which is
   * exactly how all three sites were written before, and how each of the three
   * shipped drift incidents began.
   */
  public function testComparatorDriftIsDetectedWhenASiteStopsReadingTheContract() {
    $perturbed = $this->modelWithHandWrittenClaimList($this->modelSource,
      'normalizeClaimForComparison');
    $this->assertFalse($perturbed === $this->modelSource,
      'the control must actually alter the model text it perturbs');
    $this->assertFalse(
      strpos($this->functionBody($perturbed, 'normalizeClaimForComparison'),
             self::CLAIM_FIELD_GROUP) !== false,
      'the perturbed normalization no longer reads the contract group');

    $report = $this->comparatorDriftReport($perturbed, $this->contractDocument);

    $this->assertContains('source_model', $report,
      'a field the perturbed site can no longer see must be named');
    $this->assertContains('normalization', $report,
      'the failure must name the site that stopped reading the contract');

    // And the unperturbed tree stays clean, so the control is the difference.
    $this->assertEqual('',
      $this->comparatorDriftReport($this->modelSource, $this->contractDocument),
      'the control, not a pre-existing gap, is what turned the report red');
  }

  /**
   * The second positive control, injected into the other derivation source:
   * the emitted field set really is read out of cfg_contract.json.
   *
   * A field added to the declaration must appear in the emitted set. If it
   * does not, the derivation is reading a copy of the vocabulary kept
   * somewhere else -- in which case the green above is a statement about that
   * copy and about nothing the plugin does.
   */
  public function testTheEmittedFieldSetIsReadOutOfTheContractDocument() {
    $this->assertFalse(
      in_array(self::PROBE, $this->emittedClaimFields($this->contractDocument), true),
      'the probe is a field no contract declares, or the control below cannot'
      . ' tell a derivation from a coincidence');

    $perturbed = $this->contractWithExtraClaimField($this->contractDocument, self::PROBE);
    $this->assertFalse($perturbed === $this->contractDocument,
      'the control must actually alter the contract document it perturbs');

    $this->assertTrue(in_array(self::PROBE, $this->emittedClaimFields($perturbed), true),
      'a claim-mapping field added to the contract is emitted by the'
      . ' marshaller, so the derivation must see it the moment it is declared');

    // A retired entry is NOT emitted, so it is not held against the readers.
    // Retirement is how a capability stops being emitted while staying
    // interpretable in cfgs already stored; a derivation that ignored
    // retired_in would demand read-back of something no longer written.
    $retired = $this->contractWithExtraClaimField($this->contractDocument, self::PROBE, 2);
    $this->assertFalse(in_array(self::PROBE, $this->emittedClaimFields($retired), true),
      'a retired entry stays in the document but is not emitted, so it is not'
      . ' part of the set the readers are checked against');
  }

  /**
   * The third positive control, and the one that keeps the rebuilt derivation
   * honest: a column added to the claim table is still drift-checked now that
   * the emitted set is read out of the contract instead of out of schema.xml.
   *
   * This is the drift the schema-perturbing control used to catch, and it is
   * the reason that control existed: a column added to the claim table was
   * emitted, dropped on the way back, and never compared, so the round trip
   * reported "in sync" whatever the new field held. The path such a column
   * travels now runs through two documents rather than one, so it is walked
   * here end to end:
   *
   *  - added to schema.xml alone it is emitted by nothing, because the
   *    marshaller emits what the contract declares. That mismatch belongs to
   *    ContractDeclarationTest, whose scenario 3 diffs the contract's
   *    column-backed fields against the claim table in schema.xml order; it is
   *    not re-asserted here.
   *  - declared, as adding the column requires, it joins the emitted set --
   *    and every site resolves the same capability group, so all three read it
   *    back without being edited. That is the rebuild working: the seam is
   *    closed by construction rather than watched.
   *  - and the moment one site keeps a list of its own, the NEW COLUMN is what
   *    this half's report names, which is the failure the old control caught.
   *
   * Both perturbations go into the DERIVATION SOURCES -- the schema text and
   * the contract document -- and not into the report's inputs, following the
   * convention this file's header sets out.
   */
  public function testAColumnAddedToTheClaimTableIsStillDriftChecked() {
    $perturbedSchema = $this->schemaWithExtraClaimColumn($this->schemaSource, self::PROBE);
    $this->assertFalse($perturbedSchema === $this->schemaSource,
      'the control must actually alter the schema text it perturbs');
    $this->assertTrue(in_array(self::PROBE, $this->claimColumns($perturbedSchema), true),
      'and the schema derivation must see the new column, or everything below'
      . ' is about a column nothing added');

    // Step one: the schema on its own. The emitted set is the contract's now,
    // so a column no entry declares is written by nothing, and this half stays
    // quiet rather than holding the readers to a field never emitted.
    $this->assertFalse(
      in_array(self::PROBE, $this->emittedClaimFields($this->contractDocument), true),
      'the new column is not emitted while the contract does not declare it');
    $this->assertEqual('',
      $this->comparatorDriftReport($this->modelSource, $this->contractDocument),
      'so nothing in this half is red yet');

    // Step two: declared, as adding the column requires. It is emitted now,
    // and every site resolves the group it was declared in, so all three read
    // it back with no edit of their own.
    $declared = $this->contractWithExtraClaimField($this->contractDocument, self::PROBE);
    $this->assertFalse($declared === $this->contractDocument,
      'the control must actually alter the contract document it perturbs');
    $this->assertTrue(in_array(self::PROBE, $this->emittedClaimFields($declared), true),
      'a declared claim-mapping field is emitted by the marshaller');
    $this->assertEqual('',
      $this->comparatorDriftReport($this->modelSource, $declared),
      'and is read back by all three sites the moment it is declared, because'
      . ' all three resolve the same capability group');

    // Step three: the drift itself. One site back on a list of its own -- the
    // shape all three had the three times this seam shipped open -- and the
    // new column is what the report names.
    $perturbedModel = $this->modelWithHandWrittenClaimList($this->modelSource,
      'normalizeClaimForComparison');
    $this->assertFalse($perturbedModel === $this->modelSource,
      'the control must actually alter the model text it perturbs');

    $report = $this->comparatorDriftReport($perturbedModel, $declared);
    $this->assertContains(self::PROBE, $report,
      'a claim column the marshaller emits and a comparator cannot see is'
      . ' round-tripped as "in sync" whatever it holds; the column has to be'
      . ' named');
    $this->assertContains('normalization', $report,
      'and the site that can no longer see it has to be named with it');

    // The declaration, not the model perturbation on its own, is what put the
    // new column in the report: the same site on the same hand-written list
    // says nothing about a field no contract declares.
    $this->assertFalse(
      strpos($this->comparatorDriftReport($perturbedModel, $this->contractDocument),
             self::PROBE) !== false,
      'a field the contract does not declare is not emitted, so no reader is'
      . ' held to it even when the reader has stopped reading the contract');

    $this->assertEqual('',
      $this->comparatorDriftReport($this->modelSource, $this->contractDocument),
      'and the unperturbed tree stays clean, so the controls are the difference');
  }

  // ------------------------------------------------------------------
  // Half A: row-set coverage.
  // ------------------------------------------------------------------

  /**
   * Every enumerated option the claims tab offers must have a matrix row that
   * pins it, or an explicit exemption naming it.
   *
   * The claim sources have two authorities in the view -- the source_model
   * select and the $sourceModelConfigs behaviour map the tab's JavaScript is
   * built from -- and they must agree, or one of them is offering a source the
   * other cannot handle.
   */
  public function testEveryViewOptionIsCoveredByARowOrAnExemption() {
    $offered = $this->viewOptionSets($this->viewSource, $this->claimColumns($this->schemaSource));
    $selectSources = isset($offered['source_model']) ? $offered['source_model'] : array();
    $configuredSources = $this->arrayTopLevelKeys($this->viewSource, '$sourceModelConfigs = array(');

    $this->assertNotEmpty($configuredSources,
      'the claim-source behaviour map $sourceModelConfigs is where the view'
      . ' declares what each source does; the drift check reads it');

    sort($selectSources);
    sort($configuredSources);
    $this->assertEqual($selectSources, $configuredSources,
      'the source_model select and the $sourceModelConfigs behaviour map are'
      . ' the two authorities for the claim sources the tab offers; a source in'
      . ' one and not the other is offered without behaviour, or configured'
      . ' without being reachable');

    $report = $this->rowCoverageReport($this->viewSource);
    $this->assertEqual('', $report,
      'every option the claims tab offers needs a matrix row in'
      . ' Oa4mpClaimRows::declaredRows() or an explicit exemption in'
      . ' Oa4mpClaimRows::declaredExemptions()');
  }

  /**
   * The positive control for Half A, injected into the derivation source: an
   * option added to the view's own option list must come back uncovered.
   */
  public function testRowCoverageDriftIsDetectedWhenTheViewGainsAnOption() {
    $perturbed = $this->viewWithExtraOption($this->viewSource, self::PROBE);
    $this->assertFalse($perturbed === $this->viewSource,
      'the control must actually alter the view text it perturbs');

    $report = $this->rowCoverageReport($perturbed);
    $this->assertContains(self::PROBE, $report,
      'an option the view offers with no row and no exemption must be named');

    $this->assertEqual('', $this->rowCoverageReport($this->viewSource),
      'the control, not a pre-existing gap, is what turned the report red');
  }

  /**
   * Every option list the claims tab builds must be readable by the derivation
   * and attributable to a claim column.
   *
   * This is the check that keeps Half A from going quietly blind. Coverage is
   * only as wide as the set of columns the derivation still recognizes as
   * enumerated, and that set is not declared here: it is whatever the view
   * builds an option list for. A list this derivation cannot read, or cannot
   * attribute to a column of the claim table, is a set of options no row is
   * ever checked against -- so it is a failure here rather than a quiet
   * subtraction from what Half A covers.
   */
  public function testEveryOptionListTheViewBuildsIsReadByTheDerivation() {
    $this->assertEqual('', $this->optionListReport($this->viewSource),
      'the offered options are derived from the option lists the claims tab'
      . ' builds; a list this derivation cannot read is a column whose options'
      . ' silently stop being covered');
  }

  /**
   * The first positive control for that blindness, injected into the
   * derivation source: an enumerated column re-rendered through a different
   * Form helper must still be derived, and must still be watched.
   *
   * This is the perturbation the old widget-name scan passed while losing the
   * column, so the control asserts what the derivation SEES and not merely
   * that the coverage report is empty: that report is empty both when a column
   * is covered and when it has vanished.
   */
  public function testAnEnumeratedColumnStaysCoveredWhenTheViewChangesWidget() {
    $column = 'claim_value_selection';
    $perturbed = $this->viewWithSelectRenderedByInput($this->viewSource, $column);
    $this->assertFalse($perturbed === $this->viewSource,
      'the control must actually alter the view text it perturbs');
    $this->assertContains('Form->input(\'' . $column . '\'', $perturbed,
      'the control re-renders the column through the Form->input() idiom the'
      . ' view already uses elsewhere');

    $columns = $this->claimColumns($this->schemaSource);
    $sets = $this->viewOptionSets($perturbed, $columns);
    $this->assertNotEmpty(isset($sets[$column]) ? $sets[$column] : array(),
      'a column rendered by Form->input() offers the same options it offered'
      . ' as a select; the derivation must not lose the column along with the'
      . ' widget name');
    $this->assertEqual($this->viewOptionSets($this->viewSource, $columns), $sets,
      'changing which helper renders a column changes no option it offers');

    // Still watched, not merely still derived: a new option on the re-rendered
    // column must come back uncovered.
    $probed = $this->viewWithExtraOption($perturbed, self::PROBE);
    $this->assertContains($column . '=' . self::PROBE, $this->rowCoverageReport($probed),
      'an option added to the re-rendered column must still be reported'
      . ' uncovered; if it is not, the column has gone unwatched');

    $this->assertEqual('', $this->optionListReport($perturbed),
      'a change of widget is not a stale derivation, so nothing is reported');
  }

  /**
   * The second positive control, injected into the same derivation source: an
   * option list the derivation cannot read must be reported, not skipped.
   *
   * Here the column keeps its options but stops handing them over as a list
   * the view builds -- they move inline into the helper's own arguments. No
   * text scan can follow that, so the only honest outcome is to say so.
   */
  public function testAnOptionListTheDerivationCannotReadIsReported() {
    $column = 'claim_value_selection';
    $perturbed = $this->viewWithInlinedOptions($this->viewSource, $column);
    $this->assertFalse($perturbed === $this->viewSource,
      'the control must actually alter the view text it perturbs');

    $report = $this->optionListReport($perturbed);
    $this->assertContains($column, $report,
      'a column rendered with an option list the derivation cannot read must'
      . ' be named');
    $this->assertContains('redone', $report,
      'the failure must send the reader back to the derivation rather than'
      . ' leaving them to guess what went stale');

    $this->assertEqual('', $this->optionListReport($this->viewSource),
      'the control, not a pre-existing gap, is what turned the report red');
  }

  /**
   * The exemption escape hatch Half A's failure message advertises must be one
   * that works.
   *
   * The message tells authors to cover an option with "an explicit exemption
   * in Oa4mpClaimRows::declaredExemptions()", and the reader honours an
   * exemption carrying an 'option' key of the form "column=value". Every
   * exemption declared today is the other kind -- a row-level 'exempt_from'
   * entry -- so nothing exercised that branch, and an advertised hatch nobody
   * has ever walked through is a hatch nobody can trust. This walks through it:
   * the option is injected into the view text, so what the exemption has to
   * cover is derived, not invented.
   */
  public function testAnOptionExemptionCoversTheOptionItNames() {
    $perturbed = $this->viewWithExtraOption($this->viewSource, self::PROBE);
    $option = 'claim_value_selection=' . self::PROBE;

    $this->assertContains($option, $this->rowCoverageReport($perturbed),
      'the perturbed view offers an option no row pins, so it is uncovered'
      . ' before any exemption is applied');

    $exempted = Oa4mpClaimRows::declaredExemptions();
    $exempted[] = array(
      'option' => $option,
      'reason' => 'Control for the option-exemption reader in ClaimCfgDriftTest.',
    );

    $this->assertEqual('', $this->rowCoverageReport($perturbed, $exempted),
      'an exemption naming "column=value" must cover exactly that option;'
      . ' Half A tells authors to reach for this hatch, so it has to work');
  }

  /**
   * An option exemption that names an option the claims tab no longer offers
   * is stale, and stale is loud.
   *
   * Otherwise it sits in the list looking like a reviewed decision about
   * something that is gone, which is how a hand-maintained list rots.
   */
  public function testAnOptionExemptionNamingNoOfferedOptionIsReported() {
    $stale = array(
      array(
        'option' => 'claim_value_selection=' . self::PROBE,
        'reason' => 'Control for the stale-exemption check in ClaimCfgDriftTest.',
      ),
    );

    $report = $this->exemptionReport($this->viewSource, $stale);
    $this->assertContains(self::PROBE, $report,
      'an exemption naming an option the claims tab does not offer must be'
      . ' named as stale');

    $this->assertEqual('', $this->exemptionReport($this->viewSource),
      'the control, not a pre-existing stale exemption, is what turned the'
      . ' report red');
  }

  /**
   * Every declared exemption must be one of the two kinds the reader
   * understands, must carry a reason, and must still name something real.
   *
   * An exemption of neither kind is read by nothing, which makes it a comment
   * that looks like coverage.
   */
  public function testEveryDeclaredExemptionIsWellFormedAndCurrent() {
    $this->assertNotEmpty(Oa4mpClaimRows::declaredExemptions(),
      'the matrix declares its exemptions for this check to read');

    $this->assertEqual('', $this->exemptionReport($this->viewSource),
      'an exemption is either a row exemption (row + exempt_from, naming a row'
      . ' Oa4mpClaimRows::declaredRows() declares) or an option exemption'
      . ' (option, naming an option the claims tab offers); anything else is'
      . ' read by nothing');
  }

  // ------------------------------------------------------------------
  // Half C: the declared row set against the rows the matrix runs.
  // ------------------------------------------------------------------

  /**
   * Oa4mpClaimRows::declaredRows() and the row methods in
   * ClaimCfgContractTest.php must name the same rows.
   *
   * Half A reads declaredRows() to decide whether an offered option is pinned,
   * and that list is hand-written beside 46 test methods that it shadows. Its
   * own docblock used to name this as the one thing the drift check could not
   * detect for itself, which is exactly the shape this file refuses everywhere
   * else: a hand-maintained list nothing checks is worth nothing, because the
   * person who adds the row updates both or neither.
   *
   * So the correspondence is derived, not trusted. Each row method names its
   * own row in a "$row = '...'" literal -- that is how its failures are
   * attributable -- and those literals are read out of the contract test's
   * source and diffed against declaredRows() in both directions.
   */
  public function testDeclaredRowsMatchTheRowsTheContractTestExercises() {
    $this->assertEqual('', $this->rowCorrespondenceReport($this->contractSource),
      'Oa4mpClaimRows::declaredRows() and the row methods in'
      . ' ClaimCfgContractTest.php are two halves of one matrix: a row in the'
      . ' test and not the list runs unchecked by Half A, and a row in the list'
      . ' and not the test credits Half A with coverage nothing runs');
  }

  /**
   * The first positive control for Half C, injected into the derivation
   * source: a row method added to the contract test and not declared must be
   * reported against declaredRows().
   */
  public function testRowCorrespondenceDriftIsDetectedWhenTheContractTestGainsARow() {
    $perturbed = $this->contractWithExtraRowMethod($this->contractSource, self::PROBE);
    $this->assertFalse($perturbed === $this->contractSource,
      'the control must actually alter the contract test text it perturbs');

    $report = $this->rowCorrespondenceReport($perturbed);
    $this->assertContains(self::PROBE, $report,
      'a row the contract test exercises with nothing declaring it must be'
      . ' named');
    $this->assertContains('declaredRows()', $report,
      'the failure must name the side that is missing the row');

    $this->assertEqual('', $this->rowCorrespondenceReport($this->contractSource),
      'the control, not a pre-existing mismatch, is what turned the report red');
  }

  /**
   * The second positive control, injected into the same derivation source: a
   * declared row whose method is gone must be reported against the contract
   * test, so Half A stops crediting coverage nothing runs.
   */
  public function testRowCorrespondenceDriftIsDetectedWhenTheContractTestDropsARow() {
    $dropped = 'cfg_envelope/CoGroupMember';
    $perturbed = $this->contractWithoutRowMethod($this->contractSource, $dropped);
    $this->assertFalse($perturbed === $this->contractSource,
      'the control must actually remove the row method it perturbs');
    $this->assertFalse(strpos($perturbed, '$row = \'' . $dropped . '\';') !== false,
      'the removed method is the one that named that row');

    $report = $this->rowCorrespondenceReport($perturbed);
    $this->assertContains($dropped, $report,
      'a declared row no contract test method exercises must be named');
    $this->assertContains('ClaimCfgContractTest', $report,
      'the failure must name the side that is missing the row');

    $this->assertEqual('', $this->rowCorrespondenceReport($this->contractSource),
      'the control, not a pre-existing mismatch, is what turned the report red');
  }

  /**
   * The third positive control: a contract test method that names no row is
   * reported.
   *
   * The correspondence is only derivable while every row method names its own
   * row; a method that stops doing so is not a row this check can diff, and
   * silently ignoring it would put the hand-maintained gap straight back.
   */
  public function testRowCorrespondenceDriftIsDetectedWhenAContractMethodNamesNoRow() {
    $perturbed = $this->contractWithRowlessMethod($this->contractSource);
    $this->assertFalse($perturbed === $this->contractSource,
      'the control must actually alter the contract test text it perturbs');

    $report = $this->rowCorrespondenceReport($perturbed);
    $this->assertContains('testDriftProbeNamesNoRow', $report,
      'a row method whose row cannot be derived must be named');

    $this->assertEqual('', $this->rowCorrespondenceReport($this->contractSource),
      'the control, not a pre-existing gap, is what turned the report red');
  }

  // ------------------------------------------------------------------
  // Half B derivation.
  // ------------------------------------------------------------------

  /**
   * Fields present in the emitted set and absent from a reader's list, one per
   * line, naming both. Empty string when there is no drift.
   */
  private function comparatorDriftReport($model, $contract) {
    $emitted = $this->emittedClaimFields($contract);
    $this->assertNotEmpty($emitted, 'the marshaller emits at least one claim field');

    $lines = array();
    foreach ($this->claimFieldSites($model, $contract) as $label => $site) {
      if ($site['role'] !== 'reader') {
        continue;
      }

      foreach ($emitted as $field) {
        if (!in_array($field, $site['fields'], true)) {
          $lines[] = $field . ' is emitted by oa4mpMarshallCfgQdl() but never read by ' . $label;
        }
      }
    }

    return implode("\n", $lines);
  }

  /**
   * The claim fields the marshaller sends to the server: every entry the
   * contract's claim-mapping group declares, minus the retired ones and minus
   * the one field the marshaller synthesises rather than reading off a column.
   *
   * The synthesised field is excluded because it is the one entry that does
   * not travel under its own name: the comparator reads a claim's constraints
   * out of the Oa4mpClientClaimConstraint association, not out of a
   * claim_constraints key, so holding the readers to that name would report
   * drift that is not there. The contract marks it column_backed false, which
   * is how this derivation identifies it without naming it.
   */
  private function emittedClaimFields($contract) {
    $fields = array();

    foreach ($this->contractEntries($contract, self::CLAIM_FIELD_GROUP) as $entry) {
      if (!array_key_exists('retired_in', $entry)) {
        $this->fail('a ' . self::CLAIM_FIELD_GROUP . ' entry states no retired_in.'
          . ' A missing retired_in is a malformed entry and never an implied'
          . ' null, so the emitted set cannot be derived from this document.');
      }

      if ($entry['retired_in'] !== null) {
        continue;
      }

      if (isset($entry['column_backed']) && $entry['column_backed'] === false) {
        continue;
      }

      $fields[] = $entry['name'];
    }

    return $fields;
  }

  /** The entries one capability group declares, or a failure saying why not. */
  private function contractEntries($contract, $group) {
    $decoded = json_decode($contract, true);

    if (!is_array($decoded)) {
      $this->fail('cfg_contract.json does not parse (' . json_last_error_msg()
        . '), so nothing here can be derived from it.');
    }

    if (empty($decoded['capabilities'][$group]['entries'])) {
      $this->fail('cfg_contract.json declares no ' . $group . ' entries. Every'
        . ' field list this half checks is resolved out of that group, and the'
        . ' model looks it up by the same name.');
    }

    return $decoded['capabilities'][$group]['entries'];
  }

  /**
   * Every site in the model that decides what a claim field is, keyed by a
   * label a failure can name, each carrying:
   *
   *   role    'writer' for the marshaller, 'reader' for the three that have to
   *           keep up with it
   *   groups  the capability groups the site resolves its names out of
   *   fields  the claim field names the site can actually see, which is those
   *           groups' declared names plus whatever the site still reads by
   *           literal subscript
   *
   * Each site is located by what it does and not by its name: the marshaller
   * by the claim loop it builds mappings in, the unmarshaller by the QDL
   * argument it reads mappings out of, and the two normalizations by the
   * association key they read claims out of. A site renamed is still found; a
   * site that stops doing the thing is a loud failure rather than a quiet
   * subtraction from what this half covers.
   */
  private function claimFieldSites($model, $contract = null) {
    if ($contract === null) {
      $contract = $this->contractDocument;
    }

    $sites = array();

    // The writer.
    $marshaller = $this->functionBody($model, 'oa4mpMarshallCfgQdl');
    $this->assertNotEmpty($marshaller,
      'oa4mpMarshallCfgQdl() is where a claim row becomes an emitted mapping;'
      . ' the emitted field set cannot be checked against it without it');

    $claimLoop = $this->foreachLoop($marshaller, '$data[\'Oa4mpClientClaim\']');
    if (empty($claimLoop)) {
      $this->fail('oa4mpMarshallCfgQdl() no longer loops over'
        . ' $data[\'Oa4mpClientClaim\'] to build the claim mappings, so the'
        . ' writer cannot be located. This derivation is stale; redo it against'
        . ' whatever the marshaller does instead, before trusting this check.');
    }

    // The allowlist is resolved once, ahead of the loop, and consumed inside
    // it, so the whole function body is what says which groups it reads.
    $sites['oa4mpMarshallCfgQdl()'] = $this->claimFieldSite('writer',
      $this->contractGroupsRead($marshaller),
      $this->subscriptKeys($claimLoop['body'], $claimLoop['var']),
      $contract);

    // The QDLv3 read-back.
    $unmarshaller = $this->functionBody($model, 'oa4mpUnMarshallCfgQdlv3');
    $this->assertNotEmpty($unmarshaller,
      'oa4mpUnMarshallCfgQdlv3() is the QDLv3 read-back path; its field list is'
      . ' one of the three the emitted set is checked against');

    if (!preg_match('~\$(\w+)\s*=\s*\$\w+\[\'claim_mappings\'\]~', $unmarshaller, $m)) {
      $this->fail('oa4mpUnMarshallCfgQdlv3() no longer reads the claim mappings'
        . ' out of the claim_mappings QDL argument, so its field list cannot be'
        . ' located; redo this derivation against the new shape.');
    }

    $mappingLoop = $this->foreachLoop($unmarshaller, '$' . $m[1]);
    if (empty($mappingLoop)) {
      $this->fail('oa4mpUnMarshallCfgQdlv3() reads the claim mappings into $'
        . $m[1] . ' but never loops over them; the read-back list is located by'
        . ' that loop. Redo this derivation before trusting this check.');
    }

    $sites['oa4mpUnMarshallCfgQdlv3()'] = $this->claimFieldSite('reader',
      $this->contractGroupsRead($mappingLoop['body']),
      $this->subscriptKeys($mappingLoop['body'], $mappingLoop['var']),
      $contract);

    // The two normalizations. Both hand their claim row to one shared helper
    // on the model, so the derivation follows that call out of each loop; it
    // still keeps the two sides apart, which is what keeps each one checked on
    // its own the day one of them stops delegating.
    $comparator = $this->functionBody($model, 'isClientDataSynchronized');
    $this->assertNotEmpty($comparator,
      'isClientDataSynchronized() is the sync comparator; two of the three'
      . ' field lists the emitted set is checked against live in it');

    preg_match_all('~\$(\w+)\s*=\s*\$\w+\[\'Oa4mpClientClaim\'\]~', $comparator, $m);

    $normalizations = 0;
    foreach ($m[1] as $listVar) {
      $loop = $this->foreachLoop($comparator, '$' . $listVar);
      if (empty($loop)) {
        $this->fail('isClientDataSynchronized() reads claims into $' . $listVar
          . ' but never normalizes them in a foreach loop; the drift check'
          . ' locates each normalization by that loop.');
      }

      $label = 'the ' . $listVar . ' normalization in isClientDataSynchronized()';
      $groups = $this->contractGroupsRead($loop['body']);
      $fields = $this->subscriptKeys($loop['body'], $loop['var']);

      $helper = $this->normalizationHelper($loop['body'], $loop['var']);
      if ($helper !== '') {
        $label .= ', via ' . $helper . '(),';
        $body = $this->helperBody($model, $helper, $loop['var']);
        $groups = array_merge($groups, $this->contractGroupsRead($body['body']));
        $fields = array_merge($fields, $this->subscriptKeys($body['body'], $body['param']));
      }

      if (empty($groups) && empty($fields)) {
        $this->fail('the ' . $listVar . ' normalization in'
          . ' isClientDataSynchronized() neither reads claim fields off $'
          . $loop['var'] . ' itself, nor resolves them out of the capability'
          . ' contract, nor hands the row to a $this->...() helper this'
          . ' derivation can follow. Its whitelist cannot be located; the'
          . ' derivation is stale, so redo it before trusting this check.');
      }

      $normalizations++;
      $sites[$label] = $this->claimFieldSite('reader',
        array_values(array_unique($groups)), $fields, $contract);
    }

    if ($normalizations !== 2) {
      $this->fail('expected the two claim normalizations in'
        . ' isClientDataSynchronized() (plugin side and OA4MP server side), found '
        . $normalizations . '. The derivation is stale; redo it before trusting'
        . ' this check.');
    }

    return $sites;
  }

  /**
   * One site's record: the names its capability groups declare, plus whatever
   * it still reads by literal subscript.
   *
   * Both halves are kept on purpose. A site reading the contract sees every
   * name that group declares, and a site that goes back to reading fields by
   * name is still derived the same way -- which is what lets the control below
   * report drift instead of reporting that the derivation broke.
   */
  private function claimFieldSite($role, $groups, $literalFields, $contract) {
    $fields = $literalFields;

    foreach ($groups as $group) {
      foreach ($this->contractEntries($contract, $group) as $entry) {
        if (isset($entry['name'])) {
          $fields[] = $entry['name'];
        }
      }
    }

    return array(
      'role' => $role,
      'groups' => array_values(array_unique($groups)),
      'fields' => array_values(array_unique($fields)),
    );
  }

  /** The capability groups a stretch of model source resolves names out of. */
  private function contractGroupsRead($source) {
    preg_match_all('~cfgContractNames\s*\(\s*\'([^\']+)\'\s*\)~', $source, $m);

    return array_values(array_unique($m[1]));
  }

  /**
   * The name of the $this->...() helper a normalization loop hands its claim
   * row to, or '' when the loop keeps the normalization inline.
   *
   * The call is identified by the row it is passed, not by its name: a helper
   * renamed is still followed, while $this->log() and any other call that does
   * not take the loop variable is not mistaken for one.
   */
  private function normalizationHelper($loopBody, $loopVar) {
    if (!preg_match_all('~\$this->(\w+)\s*\(~', $loopBody, $m, PREG_OFFSET_CAPTURE)) {
      return '';
    }

    foreach ($m[1] as $i => $call) {
      $args = $this->parenBlock($loopBody, strpos($loopBody, '(', $m[0][$i][1]));
      if (preg_match('~\$' . $loopVar . '\b~', $args)) {
        return $call[0];
      }
    }

    return '';
  }

  /**
   * A shared normalization helper's body and the name of the parameter it
   * takes the claim row in, located by the declaration rather than by the
   * caller's variable name.
   */
  private function helperBody($model, $helper, $loopVar) {
    $at = strpos($model, 'function ' . $helper . '(');
    if ($at === false) {
      $this->fail('the claim normalizations in isClientDataSynchronized() hand'
        . ' their row to ' . $helper . '(), which is not declared in'
        . ' Model/Oa4mpClientOa4mpServer.php. The derivation follows that call'
        . ' to find the whitelist; redo it against wherever the normalization'
        . ' rules live now.');
    }

    if (!preg_match('~\$(\w+)~', $this->parenBlock($model, strpos($model, '(', $at)), $param)) {
      $this->fail($helper . '() takes no parameter, so the claim row it'
        . ' normalizes cannot be named and its whitelist cannot be read. The'
        . ' derivation is stale; redo it.');
    }

    return array(
      'param' => $param[1],
      'body' => $this->functionBody($model, $helper),
    );
  }

  /**
   * Give a capability group one more claim-mapping entry, as a contract raising
   * its version would.
   *
   * @param string $contract The contract document text.
   * @param string $field The entry's name.
   * @param mixed $retiredIn The version it stopped being emitted at, or null.
   * @return string
   */
  private function contractWithExtraClaimField($contract, $field, $retiredIn = null) {
    $entry = '{ "name": "' . $field . '", "introduced_in": 1, "retired_in": '
           . ($retiredIn === null ? 'null' : (int)$retiredIn)
           . ', "secret_bearing": false, "column_backed": true },';

    $group = strpos($contract, '"' . self::CLAIM_FIELD_GROUP . '"');
    if ($group === false) {
      return $contract;
    }

    $entries = strpos($contract, '"entries": [', $group);
    if ($entries === false) {
      return $contract;
    }

    $at = $entries + strlen('"entries": [');

    return substr($contract, 0, $at) . "\n        " . $entry . substr($contract, $at);
  }

  /**
   * Take one site back to a field list of its own, which is how all three
   * readers were written before the contract existed.
   */
  private function modelWithHandWrittenClaimList($model, $function) {
    $body = $this->functionBody($model, $function);
    if ($body === '') {
      return $model;
    }

    $perturbed = str_replace(
      '$this->cfgContractNames(\'' . self::CLAIM_FIELD_GROUP . '\')',
      'array(\'claim_name\')',
      $body);

    return str_replace($body, $perturbed, $model);
  }

  /**
   * Give the claim table one more column, as a schema change adding a claim
   * field would.
   *
   * Every declaration of the table gets it. schema.xml declares the table once
   * per schema version, claimColumns() refuses to guess when the declarations
   * disagree, and a real change adds the column to each of them; a control
   * that touched one would be perturbing the derivation into a failure about
   * the schema rather than about the column.
   *
   * @param string $schema The schema text, comment-stripped.
   * @param string $column The column name to add.
   * @return string
   */
  private function schemaWithExtraClaimColumn($schema, $column) {
    $field = '<field name="' . $column . '" type="C" size="64" />';
    $anchor = '<field name="created" type="T" />';

    return preg_replace_callback(
      '~<table\s+name="oa4mp_client_claims"\s*>.*?</table>~s',
      function ($block) use ($field, $anchor) {
        return str_replace($anchor, $field . "\n        " . $anchor, $block[0]);
      },
      $schema);
  }

  /**
   * The columns Config/Schema/schema.xml declares for the claim table.
   *
   * Half A reads this to know which columns the claims tab may offer options
   * for. Half B no longer derives the emitted set from it -- the contract says
   * what is emitted now -- but ContractDeclarationTest checks the contract's
   * claim-mapping fields against these same columns, so the schema is still
   * where the correspondence is anchored.
   *
   * The table is declared twice with identical field sets; tolerate the repeat,
   * but refuse to guess if the two ever disagree.
   */
  private function claimColumns($schema) {
    $found = preg_match_all('~<table\s+name="oa4mp_client_claims"\s*>(.*?)</table>~s',
      $schema, $tables);
    $this->assertNotEmpty($found,
      'the claim table is declared in schema.xml; the enumerated columns Half A'
      . ' covers are derived from that declaration');

    $sets = array();
    foreach ($tables[1] as $block) {
      preg_match_all('~<field\s+name="([^"]+)"~', $block, $fields);
      $sets[] = $fields[1];
    }

    foreach ($sets as $set) {
      if ($set !== $sets[0]) {
        $this->fail('the cm_oa4mp_client_claims declarations in schema.xml disagree: '
          . implode(',', $sets[0]) . ' vs ' . implode(',', $set));
      }
    }

    $this->assertNotEmpty($sets[0], 'the claim table declares columns');

    return $sets[0];
  }

  // ------------------------------------------------------------------
  // Half A derivation.
  // ------------------------------------------------------------------

  /**
   * Options the claims tab offers that no matrix row pins and no exemption
   * names, one per line. Empty string when the row set covers everything.
   *
   * @param string $view The view source the offered options are derived from.
   * @param array $exemptions The exemption list to honour, or null for the
   *              declared one. The exemptions are a DECLARATION, not a
   *              derivation, so a control may hand one in; the options such a
   *              control has to cover still come out of the view text.
   * @return string
   */
  private function rowCoverageReport($view, $exemptions = null) {
    $offered = $this->offeredOptionSets($view);

    $rows = Oa4mpClaimRows::declaredRows();
    $this->assertNotEmpty($rows, 'the matrix declares its rows for this check to read');

    if ($exemptions === null) {
      $exemptions = Oa4mpClaimRows::declaredExemptions();
    }

    $lines = array();
    foreach ($offered as $column => $values) {
      foreach (array_unique($values) as $value) {
        if ($this->optionIsCovered($column, $value, $rows, $exemptions)) {
          continue;
        }
        $lines[] = $column . '=' . $value . ' is offered by the claims tab but no'
          . ' matrix row pins it and no exemption names it';
      }
    }

    return implode("\n", $lines);
  }

  /**
   * The enumerated options the claims tab offers, by claim column, including
   * the second authority for the claim sources: the behaviour map the tab's
   * JavaScript is built from. The union is covered, so a source added to
   * either place needs a row.
   */
  private function offeredOptionSets($view) {
    $offered = $this->viewOptionSets($view, $this->claimColumns($this->schemaSource));
    $this->assertNotEmpty($offered,
      'the claims tab offers enumerated options; finding none means the'
      . ' derivation no longer recognizes how the view builds them');

    foreach ($this->arrayTopLevelKeys($view, '$sourceModelConfigs = array(') as $source) {
      $offered['source_model'][] = $source;
    }

    return $offered;
  }

  /**
   * True when a matrix row pins the option -- either in the values it declares
   * or as the row's own claim source -- or an exemption names it as
   * "column=value" in an 'option' key.
   *
   * The 'option' branch is the escape hatch Half A's failure message points
   * at; testAnOptionExemptionCoversTheOptionItNames() walks through it, so the
   * advertisement and the code are checked against each other rather than
   * merely written to agree.
   */
  private function optionIsCovered($column, $value, $rows, $exemptions) {
    foreach ($rows as $row) {
      if (isset($row['values'][$column]) && $row['values'][$column] === $value) {
        return true;
      }
      if (isset($row[$column]) && $row[$column] === $value) {
        return true;
      }
    }

    foreach ($exemptions as $exemption) {
      if (isset($exemption['option']) && $exemption['option'] === $column . '=' . $value) {
        return true;
      }
    }

    return false;
  }

  /**
   * Declared exemptions that no reader honours or that name something gone,
   * one per line. Empty string when every exemption is well formed and current.
   *
   * There are exactly two kinds, and the reader above understands exactly
   * those two: a row exemption ('row' plus 'exempt_from', narrowing what one
   * matrix row asserts) and an option exemption ('option' as "column=value",
   * covering an offered option no row pins). An entry of neither kind is read
   * by nothing while looking, in the list, exactly like a reviewed decision.
   */
  private function exemptionReport($view, $exemptions = null) {
    if ($exemptions === null) {
      $exemptions = Oa4mpClaimRows::declaredExemptions();
    }

    $offered = array();
    foreach ($this->offeredOptionSets($view) as $column => $values) {
      foreach (array_unique($values) as $value) {
        $offered[] = $column . '=' . $value;
      }
    }

    $declaredRows = array();
    foreach (Oa4mpClaimRows::declaredRows() as $row) {
      if (isset($row['row'])) {
        $declaredRows[] = $row['row'];
      }
    }

    $lines = array();
    foreach ($exemptions as $index => $exemption) {
      $where = 'the exemption at index ' . $index . ' in'
        . ' Oa4mpClaimRows::declaredExemptions()';

      if (empty($exemption['reason'])) {
        $lines[] = $where . ' carries no reason, so what it exempts was never'
          . ' written down as a reviewed decision';
      }

      if (isset($exemption['option'])) {
        if (!in_array($exemption['option'], $offered, true)) {
          $lines[] = $where . ' exempts the option ' . $exemption['option']
            . ', which the claims tab no longer offers; a stale exemption reads'
            . ' as coverage for something that is gone';
        }
        continue;
      }

      if (isset($exemption['row'])) {
        if (empty($exemption['exempt_from'])) {
          $lines[] = $where . ' names row ' . $exemption['row'] . ' but no'
            . ' exempt_from, so what the row is excused from is not recorded';
        }
        if (!in_array($exemption['row'], $declaredRows, true)) {
          $lines[] = $where . ' names row ' . $exemption['row'] . ', which'
            . ' Oa4mpClaimRows::declaredRows() does not declare';
        }
        continue;
      }

      $lines[] = $where . ' is neither a row exemption (row plus exempt_from)'
        . ' nor an option exemption (option, as "column=value"), so no reader'
        . ' honours it';
    }

    return implode("\n", $lines);
  }

  /**
   * The enumerated options the view offers, by claim column.
   *
   * Built from the option lists the view builds rather than from a list of
   * widget names, so a column keeps its coverage across a change of Form
   * helper. A list this cannot read is left out here and reported by
   * optionListReport(), which is the loud half of the same derivation.
   */
  private function viewOptionSets($view, $columns) {
    $sets = array();

    foreach ($this->viewOptionBlocks($view, $columns) as $block) {
      if ($block['problem'] !== '') {
        continue;
      }
      foreach ($block['values'] as $value) {
        $sets[$block['column']][] = $value;
      }
    }

    return $sets;
  }

  /**
   * Whatever kept the option derivation from reading the view whole, one
   * problem per line. Empty string when every option list was read and every
   * column that is rendered with one was reached.
   *
   * Two directions, because either alone leaves a hole. An option list the
   * derivation cannot attribute to a claim column is a set of options nothing
   * is checked against; and a column rendered with an option list that no
   * derived list accounts for is a column that has dropped out of coverage
   * without dropping out of the view.
   */
  private function optionListReport($view) {
    $columns = $this->claimColumns($this->schemaSource);

    $lines = array();
    $sets = array();
    foreach ($this->viewOptionBlocks($view, $columns) as $block) {
      if ($block['problem'] !== '') {
        $lines[] = $block['problem'];
        continue;
      }
      foreach ($block['values'] as $value) {
        $sets[$block['column']][] = $value;
      }
    }

    foreach ($columns as $column) {
      if (!empty($sets[$column])) {
        continue;
      }
      foreach ($this->columnWidgetCalls($view, $column) as $call) {
        if (!$this->callTakesOptions($call['args'])) {
          continue;
        }
        $lines[] = 'the claims tab renders ' . $column . ' through Form->'
          . $call['method'] . '() with an option list this derivation could not'
          . ' read, so an option added there would go unchecked. This'
          . ' derivation must be redone against how the view now builds that'
          . ' list.';
      }
    }

    return implode("\n", $lines);
  }

  /**
   * Every option list the view builds, one entry per '$options = array();'
   * reset, each as:
   *
   *   column   the claim column the list is rendered for, '' when unattributed
   *   values   the option values assigned before the consuming call
   *   problem  '' when the list was read, else why it could not be
   *
   * The list is located by its own reset rather than by the widget that
   * consumes it, and the consuming call is the first Form helper in the block
   * whose arguments take the list -- whichever helper that is. That is what
   * keeps this from going blind: Form->select(), Form->radio() and
   * Form->input() all consume $options the same way, and no widget name
   * appears below.
   */
  private function viewOptionBlocks($view, $columns) {
    $reset = '$options = array();';

    $starts = array();
    $from = 0;
    while (($at = strpos($view, $reset, $from)) !== false) {
      $starts[] = $at;
      $from = $at + 1;
    }

    $blocks = array();
    foreach ($starts as $i => $start) {
      $end = isset($starts[$i + 1]) ? $starts[$i + 1] : strlen($view);
      $blocks[] = $this->optionBlock(substr($view, $start, $end - $start), $columns);
    }

    return $blocks;
  }

  /** One option list: the claim column it is rendered for, and its values. */
  private function optionBlock($region, $columns) {
    $block = array('column' => '', 'values' => array(), 'problem' => '');

    $call = $this->optionConsumingCall($region);
    if (empty($call)) {
      $block['problem'] = 'the claims tab builds an option list ('
        . $this->excerpt($region) . ') that no Form helper takes, so which'
        . ' column it is offered for cannot be derived. This derivation must be'
        . ' redone against how the view now renders it.';

      return $block;
    }

    if (!preg_match('~^\(\s*\'([^\']+)\'~', $call['args'], $field)) {
      $block['problem'] = 'the Form->' . $call['method'] . '() call that takes'
        . ' the option list (' . $this->excerpt($region) . ') does not name its'
        . ' field as a literal, so which column it is offered for cannot be'
        . ' derived. This derivation must be redone against how the view now'
        . ' renders it.';

      return $block;
    }

    if (!in_array($field[1], $columns, true)) {
      $block['problem'] = 'the claims tab offers an option list for \''
        . $field[1] . '\', which is not a column of the claim table, so no row'
        . ' could pin it and nothing here checks it. Either it is a claim'
        . ' column under another name -- in which case this derivation must be'
        . ' redone -- or the derivation must be taught to pass it over'
        . ' deliberately.';

      return $block;
    }

    preg_match_all('~\$options\[\'([^\']*)\'\]\s*=~',
      substr($region, 0, $call['at']), $values);

    foreach ($values[1] as $value) {
      if ($value === '') {
        continue;
      }
      $block['values'][] = $value;
    }

    if (empty($block['values'])) {
      $block['problem'] = 'the claims tab renders ' . $field[1] . ' through'
        . ' Form->' . $call['method'] . '() from an option list whose values'
        . ' this derivation could not read, so an option added there would go'
        . ' unchecked. This derivation must be redone against how the view now'
        . ' builds that list.';

      return $block;
    }

    $block['column'] = $field[1];

    return $block;
  }

  /**
   * The first Form helper call in the block whose arguments take the option
   * list, as array('method' => ..., 'args' => ..., 'at' => ...), or an empty
   * array when no call in the block does.
   */
  private function optionConsumingCall($region) {
    if (!preg_match_all('~Form->(\w+)\s*\(~', $region, $m, PREG_OFFSET_CAPTURE)) {
      return array();
    }

    foreach ($m[1] as $i => $method) {
      $args = $this->parenBlock($region, strpos($region, '(', $m[0][$i][1]));
      if ($args === '' || !$this->callTakesOptions($args)) {
        continue;
      }

      return array('method' => $method[0], 'args' => $args, 'at' => $m[0][$i][1]);
    }

    return array();
  }

  /** Every Form helper call the view makes for a named field. */
  private function columnWidgetCalls($view, $column) {
    $calls = array();

    $pattern = '~Form->(\w+)\s*\(\s*\'' . preg_quote($column, '~') . '\'~';
    if (!preg_match_all($pattern, $view, $m, PREG_OFFSET_CAPTURE)) {
      return $calls;
    }

    foreach ($m[1] as $i => $method) {
      $calls[] = array(
        'method' => $method[0],
        'args' => $this->parenBlock($view, strpos($view, '(', $m[0][$i][1])),
      );
    }

    return $calls;
  }

  /**
   * True when a Form helper call is handed an enumerated option list, whether
   * as the list the view built or as an 'options' key of its own.
   */
  private function callTakesOptions($args) {
    return strpos($args, '$options') !== false
      || strpos($args, '\'options\'') !== false;
  }

  /** Add an option to the claim_value_selection list the view builds. */
  private function viewWithExtraOption($view, $option) {
    $anchor = '$options[\'all\'] = \'All values found\';';
    $probe = $anchor . "\n          " . '$options[\'' . $option . '\'] = \'Drift probe\';';

    return str_replace($anchor, $probe, $view);
  }

  /**
   * Re-render a column's select through Form->input(), the idiom the view
   * already uses for claim_name and for the delimiter. The option list is
   * untouched: only the helper that consumes it changes.
   */
  private function viewWithSelectRenderedByInput($view, $column) {
    $select = 'Form->select(\'' . $column . '\', $options, $attrs)';
    $input = 'Form->input(\'' . $column . '\', array(\'type\' => \'select\','
      . ' \'options\' => $options) + $attrs)';

    return str_replace($select, $input, $view);
  }

  /**
   * Move a column's options inline into the helper call, so the view builds no
   * list for the derivation to read while still offering every option.
   */
  private function viewWithInlinedOptions($view, $column) {
    $select = 'Form->select(\'' . $column . '\', $options, $attrs)';
    $at = strpos($view, $select);
    if ($at === false) {
      return $view;
    }

    $reset = strrpos(substr($view, 0, $at), '$options = array();');
    if ($reset === false) {
      return $view;
    }

    $region = substr($view, $reset, $at - $reset);
    preg_match_all('~\$options\[\'([^\']*)\'\]\s*=~', $region, $m);

    $inline = array();
    foreach ($m[1] as $value) {
      $inline[] = '\'' . $value . '\' => \'' . $value . '\'';
    }

    // Everything the view used to build the list with, gone.
    $stripped = preg_replace('~\$options(\s*=\s*array\(\);|\[\'[^\']*\'\]\s*=[^;]*;)~',
      '', $region);

    return substr($view, 0, $reset) . $stripped
      . 'Form->input(\'' . $column . '\', array(\'type\' => \'select\','
      . ' \'options\' => array(' . implode(', ', $inline) . ')) + $attrs)'
      . substr($view, $at + strlen($select));
  }

  // ------------------------------------------------------------------
  // Half C derivation.
  // ------------------------------------------------------------------

  /**
   * Rows one side of the matrix names and the other does not, one per line,
   * always saying which side is missing what. Empty string when the declared
   * row set and the rows the contract test runs are the same set.
   */
  private function rowCorrespondenceReport($contract) {
    $exercised = $this->contractRowsByMethod($contract);

    $lines = array();
    $named = array();
    foreach ($exercised as $method => $rows) {
      if (empty($rows)) {
        $lines[] = 'ClaimCfgContractTest::' . $method . '() names no $row'
          . ' literal, so the row it exercises cannot be derived; every row'
          . ' method names its own row, which is what makes its failures'
          . ' attributable and this correspondence derivable';
        continue;
      }
      foreach ($rows as $row) {
        $named[$row] = $method;
      }
    }

    // A literal outside every test method would count as a row nothing runs.
    preg_match_all('~\$row = \'([^\']+)\';~', $contract, $all);
    foreach (array_unique($all[1]) as $row) {
      if (!isset($named[$row])) {
        $lines[] = 'the row literal ' . $row . ' in ClaimCfgContractTest.php is'
          . ' not inside a test method, so which method exercises it cannot be'
          . ' derived';
      }
    }

    $declared = array();
    foreach (Oa4mpClaimRows::declaredRows() as $index => $row) {
      if (empty($row['row'])) {
        $this->fail('the entry at index ' . $index . ' in'
          . ' Oa4mpClaimRows::declaredRows() names no row. The correspondence'
          . ' with ClaimCfgContractTest.php is derived from these names and'
          . ' cannot be derived without them.');
      }
      if (isset($declared[$row['row']])) {
        $lines[] = 'Oa4mpClaimRows::declaredRows() declares row ' . $row['row']
          . ' more than once, so the two halves of the matrix cannot be'
          . ' compared one for one';
      }
      $declared[$row['row']] = true;
    }

    foreach ($named as $row => $method) {
      if (!isset($declared[$row])) {
        $lines[] = 'row ' . $row . ' is exercised by ClaimCfgContractTest::'
          . $method . '() but Oa4mpClaimRows::declaredRows() does not declare'
          . ' it, so Half A credits the options that row pins to nothing';
      }
    }

    foreach (array_keys($declared) as $row) {
      if (!isset($named[$row])) {
        $lines[] = 'row ' . $row . ' is declared by'
          . ' Oa4mpClaimRows::declaredRows() but no ClaimCfgContractTest method'
          . ' names it, so Half A credits coverage that nothing runs';
      }
    }

    return implode("\n", $lines);
  }

  /**
   * The rows each contract test method names, keyed by method name. A method
   * with no row literal is kept, with an empty list, because a row method that
   * stopped naming its row is the drift this half exists to report.
   */
  private function contractRowsByMethod($contract) {
    $found = preg_match_all('~function\s+(test\w+)\s*\(~', $contract, $m,
      PREG_OFFSET_CAPTURE);
    $this->assertNotEmpty($found,
      'ClaimCfgContractTest.php runs one test method per matrix row; the rows'
      . ' the matrix exercises are derived from those methods and cannot be'
      . ' derived without them');

    $methods = array();
    foreach ($m[1] as $i => $name) {
      $body = $this->braceBlock($contract, strpos($contract, '{', $m[0][$i][1]));
      if ($body === '') {
        $this->fail('the body of ClaimCfgContractTest::' . $name[0] . '() does'
          . ' not close, so the row it names cannot be read. Redo this'
          . ' derivation against the file\'s new shape.');
      }

      preg_match_all('~\$row = \'([^\']+)\';~', $body, $rows);
      $methods[$name[0]] = array_values(array_unique($rows[1]));
    }

    return $methods;
  }

  /** Add a contract test method exercising a row nothing declares. */
  private function contractWithExtraRowMethod($contract, $row) {
    return $this->contractWithMethod($contract,
      "  public function testDriftProbeRow() {\n"
      . '    $row = \'' . $row . "';\n"
      . "    \$this->assertNotEmpty(\$row, 'drift probe');\n"
      . "  }\n");
  }

  /** Add a contract test method that names no row at all. */
  private function contractWithRowlessMethod($contract) {
    return $this->contractWithMethod($contract,
      "  public function testDriftProbeNamesNoRow() {\n"
      . "    \$this->assertTrue(true, 'drift probe');\n"
      . "  }\n");
  }

  /** Splice a method in ahead of the class's closing brace. */
  private function contractWithMethod($contract, $method) {
    $at = strrpos($contract, '}');
    if ($at === false) {
      return $contract;
    }

    return substr($contract, 0, $at) . "\n" . $method . substr($contract, $at);
  }

  /** Remove the contract test method that names the given row. */
  private function contractWithoutRowMethod($contract, $row) {
    $at = strpos($contract, '$row = \'' . $row . '\';');
    if ($at === false) {
      return $contract;
    }

    $start = strrpos(substr($contract, 0, $at), 'public function ');
    if ($start === false) {
      return $contract;
    }

    $open = strpos($contract, '{', $start);
    $body = $this->braceBlock($contract, $open);
    if ($body === '') {
      return $contract;
    }

    return substr($contract, 0, $start) . substr($contract, $open + strlen($body));
  }

  // ------------------------------------------------------------------
  // Source-text plumbing.
  // ------------------------------------------------------------------

  /** Read a file from the plugin tree, failing if it is not there. */
  private function source($relative) {
    $path = App::pluginPath('Oa4mpClient') . $relative;
    $this->assertTrue(is_readable($path), "the derivation source exists at $path");

    return file_get_contents($path);
  }

  /**
   * Strip comments so a field or option named only in prose is not a match:
   * XML/HTML comments, leading block comments, and // to end of line (but not
   * the // in a URL scheme).
   */
  private function stripComments($source) {
    $source = preg_replace('~<!--.*?-->~s', '', $source);
    $source = preg_replace('~^[ \t]*/\*.*?\*/~ms', '', $source);
    $source = preg_replace('~(?<!:)//.*$~m', '', $source);

    return $source;
  }

  /** The body of a named function, braces included, or '' if it is gone. */
  private function functionBody($php, $name) {
    $at = strpos($php, 'function ' . $name . '(');
    if ($at === false) {
      return '';
    }

    return $this->braceBlock($php, strpos($php, '{', $at));
  }

  /**
   * The loop variable and body of `foreach(<subject> as $x) { ... }`, or an
   * empty array when there is no such loop.
   */
  private function foreachLoop($source, $subject) {
    $pattern = '~foreach\s*\(\s*' . preg_quote($subject, '~') . '\s+as\s+\$(\w+)\s*\)~';
    if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
      return array();
    }

    $body = $this->braceBlock($source, strpos($source, '{', $m[0][1]));
    if ($body === '') {
      return array();
    }

    return array('var' => $m[1][0], 'body' => $body);
  }

  /** The brace-balanced block starting at $open, or '' if it does not close. */
  private function braceBlock($source, $open) {
    return $this->delimitedBlock($source, $open, '{', '}');
  }

  /** The balanced block starting at $open, or '' if it does not close. */
  private function delimitedBlock($source, $open, $opener, $closer) {
    if ($open === false) {
      return '';
    }

    $depth = 0;
    $len = strlen($source);
    for ($i = $open; $i < $len; $i++) {
      if ($source[$i] === $opener) {
        $depth++;
      } elseif ($source[$i] === $closer) {
        $depth--;
        if ($depth === 0) {
          return substr($source, $open, $i - $open + 1);
        }
      }
    }

    return '';
  }

  /** Every string key read off $<var> in the given source, deduplicated. */
  private function subscriptKeys($source, $var) {
    preg_match_all('~\$' . $var . '\[\'([^\']+)\'\]~', $source, $m);

    return array_values(array_unique($m[1]));
  }

  /**
   * The top-level keys of an array literal, located by its assignment text.
   * Paren depth is tracked so nested keys are not mistaken for top-level ones.
   */
  private function arrayTopLevelKeys($source, $assignment) {
    $at = strpos($source, $assignment);
    if ($at === false) {
      return array();
    }

    $keys = array();
    $depth = 1;
    $len = strlen($source);
    for ($i = $at + strlen($assignment); $i < $len && $depth > 0; $i++) {
      $c = $source[$i];
      if ($c === '(') {
        $depth++;
      } elseif ($c === ')') {
        $depth--;
      } elseif ($c === '\'' && $depth === 1) {
        if (preg_match('~^\'([^\']+)\'\s*=>~', substr($source, $i, 96), $m)) {
          $keys[] = $m[1];
        }
      }
    }

    return $keys;
  }

  /** The paren-balanced argument list starting at $open, or '' if unbalanced. */
  private function parenBlock($source, $open) {
    return $this->delimitedBlock($source, $open, '(', ')');
  }

  /** A short, whitespace-collapsed excerpt, so a failure can point at text. */
  private function excerpt($text) {
    $flat = trim(preg_replace('~\s+~', ' ', $text));

    return (strlen($flat) > 72) ? substr($flat, 0, 72) . '...' : $flat;
  }
}
