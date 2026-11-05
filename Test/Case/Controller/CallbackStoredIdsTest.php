<?php
/**
 * Regression coverage for cilogon/Oa4mpClient#65, callback half: the
 * callbacks tab acted on the callback and client ids the request named, not
 * on the client in the URL.
 *
 * The callback form carries the callback's id and client_id as hidden
 * fields. add() and edit() sent the URL client's callbacks to the OA4MP
 * server and then saved the posted row as it was, so a hand-built post could
 * change another client's callback or attach a callback to another client.
 * edit() and delete() also took the callback id from the URL without
 * checking that it belonged to the client. Either way the other client was
 * left out of sync with its OA4MP copy.
 *
 * Driven through Test/lib/Oa4mpWritePathHarnesses.php with a fake server.
 */

App::uses('ConnectionManager', 'Model');

class CallbackStoredIdsTest extends Oa4mpTestCase {

  private $fx = null;
  private $coId = null;
  private $clientId = null;
  private $otherClientId = null;
  private $callbackId = null;
  private $otherCallbackId = null;

  const OWN = 'https://example.org/own';
  const OTHER = 'https://example.org/other';

  public function setUp() {
    $this->fx = null;
    $this->coId = $this->clientId = $this->otherClientId = null;
    $this->callbackId = $this->otherCallbackId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpcbids');

    $this->coId = $this->fx->co($tag);
    $adminId = $this->fx->adminClient($this->coId, $tag);
    $this->clientId = $this->fx->oidcClient($adminId, 'cbids-' . $tag);
    $this->otherClientId = $this->fx->oidcClient($adminId, 'cbids-other-' . $tag);

    $this->callbackId = $this->fx->insert('cm_oa4mp_client_co_callbacks', array(
      'client_id' => $this->clientId,
      'url' => self::OWN
    ));
    $this->otherCallbackId = $this->fx->insert('cm_oa4mp_client_co_callbacks', array(
      'client_id' => $this->otherClientId,
      'url' => self::OTHER
    ));
  }

  public function tearDown() {
    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if ($this->clientId !== null) {
      $purge['cm_oa4mp_client_co_callbacks'] = 'client_id IN (' . (int)$this->clientId . ', '
        . (int)$this->otherClientId . ')';
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  private function harness($data = array()) {
    return Oa4mpCallbacksControllerHarness::build($this->clientId, $this->coId, $data);
  }

  /** The stored callback URLs of a client, in id order. */
  private function storedUrls($clientId) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $rows = $this->fx->query('SELECT url FROM cm_oa4mp_client_co_callbacks WHERE client_id = '
      . (int)$clientId . ' ORDER BY id');
    $urls = array();
    foreach ($rows as $row) {
      $urls[] = reset($row)['url'];
    }
    return $urls;
  }

  private function callbackRow($id) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $rows = $this->fx->query('SELECT client_id, url FROM cm_oa4mp_client_co_callbacks WHERE id = ' . (int)$id);
    if (empty($rows)) {
      return null;
    }
    $row = reset($rows[0]);
    return array('client_id' => (int)$row['client_id'], 'url' => $row['url']);
  }

  private function serverEdited($harness) {
    return in_array('oa4mpEditClient', $harness->harnessServer->callNames(), true);
  }

  private function logged($harness, $needle) {
    return strpos(implode("\n", $harness->harnessLogged), $needle) !== false;
  }

  // ==========================================================================
  // add()
  // ==========================================================================

  /** A posted id naming another client's callback adds a new callback instead. */
  public function testAddWithAForgedCallbackIdAddsANewCallbackForTheUrlClient() {
    $harness = $this->harness(array('Oa4mpClientCoCallback' => array(
      'id' => (string)$this->otherCallbackId,
      'client_id' => (string)$this->clientId,
      'url' => 'https://example.org/new'
    )));

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(array('client_id' => $this->otherClientId, 'url' => self::OTHER),
      $this->callbackRow($this->otherCallbackId), "the other client's callback is unchanged");
    $this->assertEqual(array(self::OWN, 'https://example.org/new'), $this->storedUrls($this->clientId),
      'the new callback was added to the client in the URL');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.callback.add.flash.success'), 'success'),
      'success was reported');
    $this->assertTrue($this->logged($harness, 'posted callback id'), 'the forged callback id was logged');
  }

  /** A posted client_id naming another client still adds to the URL's client. */
  public function testAddWithAForgedClientIdAddsTheCallbackToTheUrlClient() {
    $harness = $this->harness(array('Oa4mpClientCoCallback' => array(
      'client_id' => (string)$this->otherClientId,
      'url' => 'https://example.org/new'
    )));

    $harness->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(array(self::OTHER), $this->storedUrls($this->otherClientId),
      'no callback was attached to the other client');
    $this->assertEqual(array(self::OWN, 'https://example.org/new'), $this->storedUrls($this->clientId),
      'the new callback was added to the client in the URL');
    $this->assertTrue($this->logged($harness, 'posted client id'), 'the forged client id was logged');
  }

  // ==========================================================================
  // edit()
  // ==========================================================================

  /** A posted id naming another client's callback edits only the route's callback. */
  public function testEditWithForgedCallbackAndClientIdsEditsTheRouteCallbackOnly() {
    $harness = $this->harness(array('Oa4mpClientCoCallback' => array(
      'id' => (string)$this->otherCallbackId,
      'client_id' => (string)$this->otherClientId,
      'url' => 'https://example.org/edited'
    )));

    $harness->harnessInvoke('edit', array($this->callbackId), 'POST');

    $this->assertEqual(array('client_id' => $this->otherClientId, 'url' => self::OTHER),
      $this->callbackRow($this->otherCallbackId), "the other client's callback is unchanged");
    $this->assertEqual(array('client_id' => $this->clientId, 'url' => 'https://example.org/edited'),
      $this->callbackRow($this->callbackId), 'the callback in the route was edited');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.callback.edit.flash.success'), 'success'),
      'success was reported');
  }

  /** Posting an edit of another client's callback is refused before the server call. */
  public function testEditPostOfAnotherClientsCallbackIsRefused() {
    $harness = $this->harness(array('Oa4mpClientCoCallback' => array(
      'id' => (string)$this->otherCallbackId,
      'client_id' => (string)$this->otherClientId,
      'url' => 'https://example.org/edited'
    )));

    $redirect = $harness->harnessInvoke('edit', array($this->otherCallbackId), 'POST');

    $this->assertFalse($this->serverEdited($harness), 'the OA4MP server was not edited');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the refusal redirected to the callbacks index');
    $this->assertEqual(array('client_id' => $this->otherClientId, 'url' => self::OTHER),
      $this->callbackRow($this->otherCallbackId), "the other client's callback is unchanged");
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.er.not_client_callback'), 'error'),
      'the refusal was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /** Opening the edit form for another client's callback is refused too. */
  public function testEditFormOfAnotherClientsCallbackIsRefused() {
    $harness = $this->harness();

    $redirect = $harness->harnessInvoke('edit', array($this->otherCallbackId), 'GET');

    $this->assertEqual('index', $redirect['action'] ?? null, 'the refusal redirected to the callbacks index');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.er.not_client_callback'), 'error'),
      'the refusal was reported');
  }

  // ==========================================================================
  // delete()
  // ==========================================================================

  /** Deleting another client's callback is refused before the server call. */
  public function testDeleteOfAnotherClientsCallbackIsRefused() {
    $harness = $this->harness();

    $redirect = $harness->harnessInvoke('delete', array($this->otherCallbackId), 'POST');

    $this->assertFalse($this->serverEdited($harness), 'the OA4MP server was not edited');
    $this->assertEqual('index', $redirect['action'] ?? null, 'the refusal redirected to the callbacks index');
    $this->assertTrue($this->callbackRow($this->otherCallbackId) !== null, "the other client's callback was not deleted");
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_callback.er.not_client_callback'), 'error'),
      'the refusal was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /** Control: deleting the client's own callback still works. */
  public function testDeleteOfTheClientsOwnCallbackReachesTheServer() {
    $harness = $this->harness();

    $harness->harnessInvoke('delete', array($this->callbackId), 'POST');

    $this->assertTrue($this->serverEdited($harness), 'the OA4MP server was edited');
    $this->assertNull($this->callbackRow($this->callbackId), 'the callback was deleted');
  }
}
