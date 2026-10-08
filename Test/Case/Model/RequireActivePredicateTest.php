<?php
/**
 * Tests that the Authorization tab shows Require Active Status only when it
 * was actually saved.
 *
 * The tab used to render the checkbox ticked whenever require_active was not
 * set, which is every client with no authorization row (read by Containable as
 * an all-null array) and every row saved with NULL. Those clients did not
 * require Active Status on the OA4MP server while the tab said they did. The
 * tab now asks Oa4mpClientOa4mpServer::requiresActiveStatus(), the same
 * question the marshaller asks before sending require_active_status, so the
 * two cannot disagree.
 *
 * The thin runner cannot render the full Authorization page, so the template's
 * binding to the controller's value is checked in its source.
 *
 * See docs/plans/2026-09-28-1104-feat-require-active-status-default-plan.md
 * U3 (R4, R5, AE2, AE3, KTD5).
 */

class RequireActivePredicateTest extends Oa4mpTestCase {

  /** @var Oa4mpFixtures */
  private $fx;

  private $adminId;

  public function setUp() {
    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mprequireactive');

    $coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($coId, $tag);
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }
    $this->fx->cleanup();
    $this->fx = null;
  }

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * A client as the Authorization tab reads it (current()), with an
   * authorization row whose require_active is $requireActive, or no row at
   * all when $requireActive is 'none'.
   */
  private function clientWith($requireActive) {
    $clientId = $this->fx->oidcClient($this->adminId, 'require-active-' . uniqid());

    if ($requireActive !== 'none') {
      $this->fx->insert('cm_oa4mp_client_authorizations', array(
        'client_id' => $clientId,
        'require_active' => $requireActive
      ));
    }

    return $this->model('Oa4mpClient.Oa4mpClientCoOidcClient')->current($clientId);
  }

  /** Whether the marshaller sends require_active_status for $client. */
  private function sends($client) {
    $content = $this->server()->oa4mpMarshallContent(
      array('Oa4mpClientCoAdminClient' => array('co_id' => 1)), $client);

    return isset($content['cfg']['tokens']['identity']['qdl']['args']['require_active_status']);
  }

  /** Covers AE2: a client with no authorization row shows the box unticked. */
  public function testNoAuthorizationRowIsNotRequired() {
    $client = $this->clientWith('none');

    $this->assertFalse($this->server()->requiresActiveStatus($client),
      'a client with no authorization row does not require Active Status');
    $this->assertFalse($this->sends($client), 'and nothing is sent');
  }

  /** Covers AE3: a row saved with require_active off shows the box unticked. */
  public function testSavedOffIsNotRequired() {
    $client = $this->clientWith(false);

    $this->assertFalse($this->server()->requiresActiveStatus($client),
      'a row saved off does not require Active Status');
    $this->assertFalse($this->sends($client), 'and nothing is sent');
  }

  /** A row saved with require_active NULL shows the box unticked. */
  public function testSavedNullIsNotRequired() {
    $client = $this->clientWith(null);

    $this->assertFalse($this->server()->requiresActiveStatus($client),
      'a row saved with NULL does not require Active Status');
    $this->assertFalse($this->sends($client), 'and nothing is sent');
  }

  /** A row saved with require_active on shows the box ticked. */
  public function testSavedOnIsRequired() {
    $client = $this->clientWith(true);

    $this->assertTrue($this->server()->requiresActiveStatus($client),
      'a row saved on requires Active Status');
    $this->assertTrue($this->sends($client), 'and require_active_status is sent');
  }

  /** The posted strings the tab submits read the same way. */
  public function testPostedValuesReadAsSaved() {
    $this->assertTrue($this->server()->requiresActiveStatus(
      array('Oa4mpClientAuthorization' => array('require_active' => '1'))), "'1' is on");
    $this->assertFalse($this->server()->requiresActiveStatus(
      array('Oa4mpClientAuthorization' => array('require_active' => '0'))), "'0' is off");
  }

  /**
   * The Authorization template ticks the box from the controller's value and
   * no longer defaults it to ticked.
   */
  public function testAuthorizationTemplateUsesTheSavedValue() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientAuthorizations' . DS . 'manage.ctp';
    $this->assertTrue(is_readable($path), "the Authorization template exists at $path");
    $source = file_get_contents($path);

    $this->assertTrue(strpos($source, "'checked' => \$vv_require_active") !== false,
      'the checkbox is ticked from the controller\'s vv_require_active');
    $this->assertTrue(strpos($source, ": true;") === false,
      'the checkbox no longer defaults to ticked');

    $controller = App::pluginPath('Oa4mpClient') . 'Controller' . DS
      . 'Oa4mpClientAuthorizationsController.php';
    $this->assertContains("set('vv_require_active', \$oa4mpServer->requiresActiveStatus(",
      file_get_contents($controller),
      'the controller sets vv_require_active from the shared predicate');
  }
}
