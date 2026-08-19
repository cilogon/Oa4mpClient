<?php
/**
 * Regression tests for claim mapping and search-attribute migration
 * (Model/Oa4mpClientCoSearchAttribute.php). Covers documented bugs:
 *  - oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18
 *    ("All Types" empty-string sentinel must normalize to the literal 'all').
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U5 (R2, R3, R5).
 */

class ClaimMigrationTest extends Oa4mpTestCase {

  private function searchAttr() {
    return $this->model('Oa4mpClient.Oa4mpClientCoSearchAttribute');
  }

  /**
   * Bug: LdapProvisioner persists the "All Types" UI choice as an empty string,
   * but OA4MP's QDL expects the literal sentinel 'all'. Copying the empty string
   * verbatim produced empty-string 'type' constraints, validation failures, and
   * silent count drift. The bare-literal branch must normalize empty/null to
   * 'all'.
   */
  public function testEmptyTypeNormalizesToAll() {
    $m = $this->searchAttr();
    $this->assertEqual('all', $m->computeVoPersonApplicationUidConstraint(1, '', false),
      'empty-string type (LdapProvisioner "All Types") must normalize to all');
    $this->assertEqual('all', $m->computeVoPersonApplicationUidConstraint(1, null, false),
      'null type must normalize to all');
  }

  /**
   * A real type value passes through unchanged in the bare-literal branch.
   */
  public function testRealTypeIsPreserved() {
    $m = $this->searchAttr();
    $this->assertEqual('eppn', $m->computeVoPersonApplicationUidConstraint(1, 'eppn', false),
      'a real type value must be preserved unchanged');
  }
}
