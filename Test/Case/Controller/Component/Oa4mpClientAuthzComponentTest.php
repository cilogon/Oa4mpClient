<?php
/**
 * Tests for the three-role authorization model in
 * Controller/Component/Oa4mpClientAuthzComponent.php::permissionSet().
 *
 * The three roles are: CO/platform admin (full rights), manager (a member of an
 * admin client's delegated management group), and editor (a member of the CO
 * group named by a specific client's Oa4mpClientAccessControl). The subtle part
 * -- and what this locks -- is the hand-off between manager and editor: a
 * manager's per-client rights end as soon as that client has an authorization
 * group they are not in.
 *
 * Group membership is resolved through the real Registry RoleComponent against
 * real cm_co_group_members rows, so the tests exercise the actual chain rather
 * than a mocked membership answer.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U3 (R2).
 */

App::uses('Component', 'Controller');
App::uses('ComponentCollection', 'Controller');
App::uses('Oa4mpClientAuthzComponent', 'Oa4mpClient.Controller/Component');

class Oa4mpClientAuthzComponentTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $coId;
  private $adminId;
  private $manageGroupId;
  private $editorGroupId;
  private $openClientId;    // no authorization group
  private $lockedClientId;  // has an authorization group
  private $managerId;
  private $editorId;
  private $strangerId;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpauthz');
    $this->coId = $this->fx->co($tag);

    $this->managerId = $this->person();
    $this->editorId = $this->person();
    $this->strangerId = $this->person();

    $this->manageGroupId = $this->group('manage-' . $tag);
    $this->editorGroupId = $this->group('editors-' . $tag);

    $this->member($this->manageGroupId, $this->managerId);
    $this->member($this->editorGroupId, $this->editorId);

    $this->adminId = $this->fx->adminClient($this->coId, $tag,
      array('manage_co_group_id' => $this->manageGroupId));

    $this->openClientId = $this->client('open-' . $tag);
    $this->lockedClientId = $this->client('locked-' . $tag);

    $this->fx->insert('cm_oa4mp_client_access_controls', array(
      'client_id' => $this->lockedClientId,
      'co_group_id' => $this->editorGroupId
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }
    $this->fx->cleanup();
    $this->fx = null;
  }

  private function person() {
    return $this->fx->insert('cm_co_people', array(
      'co_id' => $this->coId,
      'status' => 'A',
      'deleted' => false,
      'co_person_id' => null
    ));
  }

  private function group($name) {
    return $this->fx->insert('cm_co_groups', array(
      'co_id' => $this->coId,
      'name' => $name,
      'description' => 'hermetic authz test group',
      'open' => false,
      'status' => 'A',
      'group_type' => 'S',
      'auto' => false,
      'nesting_mode_all' => false,
      'deleted' => false,
      'co_group_id' => null
    ));
  }

  private function member($groupId, $coPersonId) {
    return $this->fx->insert('cm_co_group_members', array(
      'co_group_id' => $groupId,
      'co_person_id' => $coPersonId,
      'member' => true,
      'owner' => false,
      'deleted' => false,
      'co_group_member_id' => null
    ));
  }

  private function client($name) {
    return $this->fx->oidcClient($this->adminId, $name);
  }

  /**
   * Compute a permission set. A fresh component (and so a fresh RoleComponent
   * membership cache) is built per call so one scenario cannot answer for
   * another.
   */
  private function permissions($coPersonId, $clientId, $roles = array()) {
    $roles = $roles + array('cmadmin' => false, 'coadmin' => false);
    $params = array('pass' => ($clientId === null ? array() : array($clientId)), 'named' => array());

    $component = new Oa4mpClientAuthzComponent(new ComponentCollection());
    return $component->permissionSet($this->coId, $coPersonId, $roles, $params);
  }

  /**
   * A CO admin holds every capability regardless of client or group state,
   * including on a client that has an authorization group they are not in.
   */
  public function testCoAdminHasEveryCapability() {
    $p = $this->permissions($this->strangerId, $this->lockedClientId, array('coadmin' => true));

    foreach (array('add', 'delegate', 'edit', 'edit_scopes', 'delete', 'manage', 'index') as $capability) {
      $this->assertTrue($p[$capability], "a CO admin must have $capability");
    }
  }

  /** A platform admin likewise holds every capability. */
  public function testPlatformAdminHasEveryCapability() {
    $p = $this->permissions($this->strangerId, $this->lockedClientId, array('cmadmin' => true));

    foreach (array('add', 'delegate', 'edit', 'edit_scopes', 'delete', 'manage', 'index') as $capability) {
      $this->assertTrue($p[$capability], "a platform admin must have $capability");
    }
  }

  /**
   * A manager (delegated management group member) can create clients and fully
   * manage a client that has no authorization group, but may not delegate --
   * configuring the management group stays with the CO admins.
   */
  public function testManagerCanAddAndManageClientWithoutAuthorizationGroup() {
    $p = $this->permissions($this->managerId, $this->openClientId);

    $this->assertTrue($p['add'], 'a manager can add clients');
    $this->assertFalse($p['delegate'], 'a manager cannot delegate');
    $this->assertTrue($p['edit'], 'a manager can edit an unrestricted client');
    $this->assertTrue($p['edit_scopes'], 'a manager can edit scopes on an unrestricted client');
    $this->assertTrue($p['delete'], 'a manager can delete an unrestricted client');
    $this->assertTrue($p['manage'], 'a manager can manage an unrestricted client');
    $this->assertTrue($p['index'], 'a manager can see the index');
  }

  /**
   * The manager/editor hand-off: once a client has an authorization group, a
   * manager who is not in that group loses edit, delete, and manage on it --
   * while keeping add, which is not client-scoped.
   */
  public function testManagerLosesClientRightsOnceAuthorizationGroupExists() {
    $p = $this->permissions($this->managerId, $this->lockedClientId);

    $this->assertTrue($p['add'], 'add is not client-scoped, so it survives');
    $this->assertFalse($p['edit'], 'a manager outside the authorization group cannot edit');
    $this->assertFalse($p['edit_scopes'], 'a manager outside the authorization group cannot edit scopes');
    $this->assertFalse($p['delete'], 'a manager outside the authorization group cannot delete');
    $this->assertFalse($p['manage'], 'a manager outside the authorization group cannot manage');
    $this->assertTrue($p['index'], 'the manager still sees the index');
  }

  /**
   * An editor holds edit/manage rights on the client whose authorization group
   * they belong to, but cannot add clients or delegate.
   */
  public function testEditorHasRightsOnTheirClientOnly() {
    $p = $this->permissions($this->editorId, $this->lockedClientId);

    $this->assertTrue($p['edit'], 'an editor can edit their client');
    $this->assertTrue($p['edit_scopes'], 'an editor can edit scopes on their client');
    $this->assertTrue($p['delete'], 'an editor can delete their client');
    $this->assertTrue($p['manage'], 'an editor can manage their client');
    $this->assertFalse($p['add'], 'an editor cannot add clients');
    $this->assertFalse($p['delegate'], 'an editor cannot delegate');
    $this->assertTrue($p['index'], 'an editor of any client can see the index');

    // The same editor has no rights on a different client that has no
    // authorization group -- their rights come from the group, not the CO.
    $other = $this->permissions($this->editorId, $this->openClientId);
    $this->assertFalse($other['edit'], 'an editor has no rights on an unrelated client');
    $this->assertFalse($other['manage'], 'an editor has no rights on an unrelated client');
  }

  /**
   * index is the list-visibility gate: true for a manager and for an editor of
   * any client, false for a CO member who is neither.
   */
  public function testIndexIsFalseForUnrelatedUser() {
    $p = $this->permissions($this->strangerId, null);

    $this->assertFalse($p['index'], 'an unrelated user cannot see the index');
    $this->assertFalse($p['add'], 'an unrelated user cannot add clients');
    $this->assertFalse($p['edit'], 'an unrelated user cannot edit');
    $this->assertFalse($p['manage'], 'an unrelated user cannot manage');
  }

  /**
   * With no COMANAGE_REGISTRY_OA4MP_ADMIN_USERS configured, the OA4MP-admin
   * flag is off for everyone.
   */
  public function testOa4mpAdminIsFalseWithoutConfiguredAdminUsers() {
    if (getenv('COMANAGE_REGISTRY_OA4MP_ADMIN_USERS')) {
      // The environment configures OA4MP admins, so the flag is not ours to
      // assert; skip rather than lock an environment-dependent answer.
      return;
    }

    $p = $this->permissions($this->strangerId, $this->openClientId);
    $this->assertFalse($p['oa4mp_admin'], 'no configured admin users means no OA4MP admin');
  }
}
