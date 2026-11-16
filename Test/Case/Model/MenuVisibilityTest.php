<?php
/**
 * Tests who gets the main-menu "OIDC Clients" link.
 *
 * Platform and CO admins can always open the OIDC Clients page and always see
 * it under Configuration. The main-menu link used to appear only for members
 * of a delegated management group or an OIDC client's authorization group, so
 * an admin saw it only when they happened to belong to one of those groups
 * (cilogon/Oa4mpClient#78). Platform admins now get the link in every CO, and
 * CO admins get it in their CO when the CO has an OIDC admin client.
 * Permissions are unchanged.
 *
 * The thin runner has no controller session, so the tests call menusFor()
 * with the CO person id and username cmPluginMenus() would read from it.
 *
 * See docs/plans/2026-11-16-0705-fix-oidc-clients-menu-for-admins-plan.md.
 */

class MenuVisibilityTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $tag;

  /** CO the tests run in, with an admin client unless a test removes it. */
  private $coId;

  private $adminId;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $this->tag = Oa4mpFixtures::tag('oa4mpmenu');

    $this->coId = $this->fx->co($this->tag);
    $this->adminId = $this->fx->adminClient($this->coId, $this->tag);
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }
    $this->fx->cleanup();
    $this->fx = null;
  }

  /**
   * Stand-ins for the cmp and coconfig entries cmPluginMenus() passes in.
   * menusFor() must hand them back unchanged.
   */
  private function staticMenus() {
    return array(
      'cmp' => array('cmp label' => array('controller' => 'cmp_stand_in')),
      'coconfig' => array('coconfig label' => array('controller' => 'coconfig_stand_in'))
    );
  }

  private function menus($coPersonId, $username = null) {
    return $this->model('Oa4mpClient.Oa4mpClient')
      ->menusFor($this->staticMenus(), $coPersonId, $username);
  }

  private function hasMainLink($menus) {
    return !empty($menus['comain']);
  }

  /** The CO's own admins group (no COU), as COmanage creates it. */
  private function adminsGroup($coId) {
    return $this->fx->group($coId, 'CO:admins', array('group_type' => 'A'));
  }

  /** A login identifier for $coPersonId. */
  private function loginIdentifier($coPersonId, $identifier) {
    return $this->fx->insert('cm_identifiers', array(
      'identifier' => $identifier,
      'type' => 'eppn',
      'login' => true,
      'status' => 'A',
      'co_person_id' => $coPersonId,
      'deleted' => false,
      'identifier_id' => null
    ));
  }

  /**
   * The COmanage CO, reused when the database already has one and created
   * (and cleaned up) otherwise.
   */
  private function comanageCoId() {
    $existing = $this->fx->scalar(
      "SELECT id FROM cm_cos WHERE name = '" . DEF_COMANAGE_CO_NAME . "' AND status = 'A'");

    if (!empty($existing)) {
      return (int)$existing;
    }

    return $this->fx->insert('cm_cos', array(
      'name' => DEF_COMANAGE_CO_NAME,
      'description' => 'hermetic test COmanage CO',
      'status' => 'A'
    ));
  }

  /**
   * A platform admin: a member of the COmanage CO's admins group, with a
   * login identifier. Returns array(COmanage CO person id, username).
   */
  private function platformAdmin() {
    $comanageCoId = $this->comanageCoId();
    $personId = $this->fx->person($comanageCoId);
    $groupId = $this->adminsGroup($comanageCoId);
    $this->fx->member($groupId, $personId);

    $username = 'platform-admin-' . $this->tag . '@example.org';
    $this->loginIdentifier($personId, $username);

    return array($personId, $username);
  }

  public function testCoAdminGetsMainLinkWithoutManagementGroup() {
    $personId = $this->fx->person($this->coId);
    $this->fx->member($this->adminsGroup($this->coId), $personId);

    $this->assertTrue($this->hasMainLink($this->menus($personId)),
      'a CO admin of a CO with an admin client gets the main-menu link');
  }

  public function testCoAdminWithoutAdminClientGetsNoMainLink() {
    $coId = $this->fx->co($this->tag . '-noclient');
    $personId = $this->fx->person($coId);
    $this->fx->member($this->adminsGroup($coId), $personId);

    $this->assertFalse($this->hasMainLink($this->menus($personId)),
      'a CO admin of a CO with no admin client gets no main-menu link');
  }

  public function testPlatformAdminGetsMainLinkFromComanageCo() {
    list($personId, $username) = $this->platformAdmin();

    $this->assertTrue($this->hasMainLink($this->menus($personId, $username)),
      'a platform admin whose session person is in the COmanage CO gets the link');
  }

  public function testPlatformAdminWhoIsOrdinaryMemberGetsMainLink() {
    list(, $username) = $this->platformAdmin();
    $memberId = $this->fx->person($this->coId);

    $this->assertTrue($this->hasMainLink($this->menus($memberId, $username)),
      'a platform admin who is an ordinary member of the session CO gets the link');
  }

  public function testCouAdminGetsNoMainLink() {
    $couId = $this->fx->insert('cm_cous', array(
      'co_id' => $this->coId,
      'name' => 'COU ' . $this->tag,
      'description' => 'hermetic test COU',
      'deleted' => false,
      'cou_id' => null
    ));
    $personId = $this->fx->person($this->coId);
    $couAdmins = $this->fx->group($this->coId, 'CO:COU:' . $this->tag . ':admins',
      array('group_type' => 'A', 'cou_id' => $couId));
    $this->fx->member($couAdmins, $personId);

    $this->assertFalse($this->hasMainLink($this->menus($personId)),
      'a COU admin in no other qualifying group gets no main-menu link');
  }

  public function testExpiredOrDeletedAdminMembershipGetsNoMainLink() {
    $groupId = $this->adminsGroup($this->coId);

    $expiredId = $this->fx->person($this->coId);
    $this->fx->member($groupId, $expiredId, array('valid_through' => '2000-01-01 00:00:00'));
    $this->assertFalse($this->hasMainLink($this->menus($expiredId)),
      'an expired CO admins membership gives no link');

    $deletedId = $this->fx->person($this->coId);
    $this->fx->member($groupId, $deletedId, array('deleted' => true));
    $this->assertFalse($this->hasMainLink($this->menus($deletedId)),
      'a deleted CO admins membership gives no link');
  }

  public function testDelegatedManagerStillGetsMainLink() {
    $groupId = $this->fx->group($this->coId, 'managers ' . $this->tag);
    // A second admin client created after the group, so cleanup removes it first.
    $this->fx->adminClient($this->coId, $this->tag . '-managed',
      array('manage_co_group_id' => $groupId));
    $personId = $this->fx->person($this->coId);
    $this->fx->member($groupId, $personId);

    $this->assertTrue($this->hasMainLink($this->menus($personId)),
      'a member of the delegated management group gets the link');
  }

  public function testAuthorizationGroupEditorStillGetsMainLink() {
    $groupId = $this->fx->group($this->coId, 'editors ' . $this->tag);
    $clientId = $this->fx->oidcClient($this->adminId, 'menu-' . $this->tag);
    $this->fx->insert('cm_oa4mp_client_access_controls', array(
      'client_id' => $clientId,
      'co_group_id' => $groupId
    ));
    $personId = $this->fx->person($this->coId);
    $this->fx->member($groupId, $personId);

    $this->assertTrue($this->hasMainLink($this->menus($personId)),
      'a member of an OIDC client authorization group gets the link');
  }

  public function testOrdinaryMemberGetsNoMainLink() {
    $personId = $this->fx->person($this->coId);

    $this->assertFalse($this->hasMainLink($this->menus($personId, 'nobody-' . $this->tag)),
      'an ordinary member in no qualifying group gets no link');
  }

  public function testNoSessionPersonReturnsBaseMenusOnly() {
    $menus = $this->menus(null, null);

    $this->assertFalse($this->hasMainLink($menus), 'no person means no main-menu link');
    $this->assertEqual(array('cmp', 'coconfig'), array_keys($menus),
      'only the cmp and coconfig menus are returned');
  }

  public function testUnknownOrEmptyUsernameIsNotPlatformAdmin() {
    $personId = $this->fx->person($this->coId);

    foreach (array('no-such-login-' . $this->tag, '', null) as $username) {
      $this->assertFalse($this->hasMainLink($this->menus($personId, $username)),
        'username ' . var_export($username, true) . ' raises nothing and gives no link');
    }
  }

  public function testCmpAndCoconfigEntriesUnchanged() {
    $base = $this->staticMenus();
    $personId = $this->fx->person($this->coId);
    $this->fx->member($this->adminsGroup($this->coId), $personId);
    $menus = $this->menus($personId);

    $this->assertEqual($base['cmp'], $menus['cmp'], 'the cmp menu is unchanged');
    $this->assertEqual($base['coconfig'], $menus['coconfig'], 'the coconfig menu is unchanged');
  }
}
