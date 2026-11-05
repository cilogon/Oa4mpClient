<?php
/**
 * Applying and removing a Named configuration from a client's Named
 * Configuration page (cilogon/Oa4mpClient#58, cilogon/Oa4mpClient#59).
 *
 * A Named configuration takes the place of the client's own scopes, claims,
 * authorization settings, access token settings, LDAP configuration and
 * DynamoDB configuration. These tests pin that:
 *
 *  - both applying and removing ask for confirmation first, and nothing is
 *    sent or written until the confirmed post; the confirmation says what is
 *    deleted, that Require Active Status and the Authorized User Group stop
 *    being enforced, and, for a client with JWT access tokens, that it stops
 *    getting them;
 *  - the confirmed apply sends the client without those sections and, once
 *    the server accepts, deletes the rows; the confirmed removal sends the
 *    client with new-client defaults and, once accepted, creates them;
 *  - choosing the state the client is already in sends nothing, except
 *    re-saving the applied Named configuration while an access token row is
 *    still held;
 *  - a public client is refused, and a write that fails after the server
 *    accepted is reported as an error, never as success.
 *
 * Driven through Oa4mpNamedConfigsControllerHarness with a fake server that
 * records what it was sent.
 */

App::uses('ConnectionManager', 'Model');

class NamedConfigApplyRemoveTest extends Oa4mpTestCase {

  private $fx = null;
  private $coId = null;
  private $adminId = null;
  private $configA = null;
  private $configB = null;
  private $clientId = null;
  private $publicClientId = null;
  private $accessTokenId = null;

  const CONFIG_A = 'QDL-A';
  const CONFIG_B = 'QDL-B';
  const ADMIN_DYNAMO_TABLE = 'admin-default-table';

  /** The client tables this file's actions write, purged by client id. */
  private $clientTables = array(
    'cm_oa4mp_client_co_scopes',
    'cm_oa4mp_client_claims',
    'cm_oa4mp_client_authorizations',
    'cm_oa4mp_client_co_ldap_configs',
    'cm_oa4mp_client_dynamo_configs',
    'cm_oa4mp_client_access_tokens',
    'cm_oa4mp_client_co_callbacks',
    'cm_oa4mp_client_refresh_tokens'
  );

  public function setUp() {
    $this->fx = null;
    $this->coId = $this->adminId = $this->configA = $this->configB = null;
    $this->clientId = $this->publicClientId = $this->accessTokenId = null;

    $this->fx = new Oa4mpFixtures();
    $tag = Oa4mpFixtures::tag('oa4mpncar');

    $this->coId = $this->fx->co($tag);
    $this->adminId = $this->fx->adminClient($this->coId, $tag);
    $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
      'admin_id' => $this->adminId,
      'client_id' => null,
      'aws_region' => 'us-east-1',
      'aws_access_key_id' => 'AKIAEXAMPLE',
      'aws_secret_access_key' => 'not-a-real-secret',
      'table_name' => self::ADMIN_DYNAMO_TABLE,
      'partition_key' => 'client_id',
      'partition_key_template' => '${client_id}',
      'partition_key_claim_name' => 'sub'
    ));

    $this->configA = $this->seedNamedConfig($this->adminId, self::CONFIG_A);
    $this->configB = $this->seedNamedConfig($this->adminId, self::CONFIG_B);

    $this->clientId = $this->fx->oidcClient($this->adminId, 'ncar-' . $tag);
    $this->seedOwnRows($this->clientId);

    $this->publicClientId = $this->fx->oidcClient($this->adminId, 'ncar-public-' . $tag,
      array('public_client' => true));
  }

  public function tearDown() {
    Oa4mpNamedConfigsControllerHarness::harnessRestoreWrites();

    if ($this->fx === null) {
      return;
    }

    $purge = array();
    if ($this->clientId !== null) {
      $in = (int)$this->clientId . ', ' . (int)$this->publicClientId;
      $purge['cm_oa4mp_client_co_search_attributes'] =
        'ldap_id IN (SELECT id FROM cm_oa4mp_client_co_ldap_configs WHERE client_id IN (' . $in . '))';
      $purge['cm_oa4mp_client_claim_constraints'] =
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE client_id IN (' . $in . '))';
      foreach ($this->clientTables as $table) {
        $purge[$table] = 'client_id IN (' . $in . ')';
      }

      // The Named configurations are removed before the clients, so the
      // clients must stop pointing at them first.
      $this->fx->query('UPDATE cm_oa4mp_client_co_oidc_clients SET named_config_id = NULL WHERE id IN ('
        . $in . ')');
    }

    $this->fx->cleanup($purge);
    $this->fx = null;
  }

  // ==========================================================================
  // Confirmation
  // ==========================================================================

  /**
   * Choosing a Named configuration for a client with JWT access tokens shows
   * the apply confirmation, including that the client stops getting them,
   * and sends and writes nothing.
   */
  public function testApplyWithoutConfirmationShowsTheConfirmationWithTheJwtSentence() {
    $before = $this->rowCounts();

    $harness = $this->invoke($this->clientId, $this->configA, false);

    $this->assertEqual(array('confirm'), $harness->harnessRendered, 'the confirmation page was rendered');
    $this->assertNull($harness->harnessRedirect, 'the page did not redirect');
    $this->assertEmpty($harness->harnessServer->calls, 'nothing was sent to the OA4MP server');
    $this->assertEqual($before, $this->rowCounts(), 'no row was written or deleted');
    $this->assertNull($this->storedNamedConfig(), 'the client still has no Named configuration');

    $text = $harness->viewVars['vv_confirm_text'] ?? array();
    $this->assertTrue(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.apply.text',
      array(self::CONFIG_A)), $text, true), 'it says what the Named configuration replaces and deletes');
    $this->assertTrue(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.apply.jwt'), $text, true),
      'it says the client stops getting JWT access tokens');
    $this->assertEqual((string)$this->configA, $harness->viewVars['vv_selected_config_id'] ?? null,
      'the confirmation carries the choice to post again');
  }

  /**
   * The apply confirmation says Require Active Status and the Authorized
   * User Group stop being enforced (cilogon/Oa4mpClient#59). This client
   * has JWT access tokens off, so the JWT sentence is left out.
   */
  public function testApplyConfirmationSaysTheAuthorizationGatesStopBeingEnforced() {
    $this->fx->query('UPDATE cm_oa4mp_client_access_tokens SET is_jwt = false WHERE id = '
      . (int)$this->accessTokenId);

    $harness = $this->invoke($this->clientId, $this->configA, false);

    $text = $harness->viewVars['vv_confirm_text'] ?? array();
    $this->assertEqual(array('confirm'), $harness->harnessRendered, 'the confirmation page was rendered');
    $this->assertTrue(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.apply.authz'), $text, true),
      'it says Require Active Status and the Authorized User Group stop being enforced');
    $this->assertFalse(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.apply.jwt'), $text, true),
      'no JWT sentence for a client without JWT access tokens');
  }

  /** Switching from one Named configuration to another asks again. */
  public function testSwitchingNamedConfigsShowsTheConfirmation() {
    $this->setStoredNamedConfig($this->configA);
    $this->fx->query('DELETE FROM cm_oa4mp_client_access_tokens WHERE client_id = ' . (int)$this->clientId);

    $harness = $this->invoke($this->clientId, $this->configB, false);

    $this->assertEqual(array('confirm'), $harness->harnessRendered, 'the confirmation page was rendered');
    $this->assertEqual(0, $this->editCalls($harness), 'nothing was sent to the OA4MP server');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the stored Named configuration is unchanged');
    $this->assertTrue(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.apply.text', array(self::CONFIG_B)),
      $harness->viewVars['vv_confirm_text'] ?? array(), true), 'the apply confirmation names the new choice');
  }

  /** Choosing no Named configuration shows the removal confirmation and sends nothing. */
  public function testRemovalWithoutConfirmationShowsTheRemovalConfirmation() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->invoke($this->clientId, 'none', false);

    $this->assertEqual(array('confirm'), $harness->harnessRendered, 'the confirmation page was rendered');
    $this->assertEqual(0, $this->editCalls($harness), 'nothing was sent to the OA4MP server');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is still applied');
    $this->assertTrue(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.remove.text', array(self::CONFIG_A)),
      $harness->viewVars['vv_confirm_text'] ?? array(), true),
      'it says what stops and what the client starts over with');
    $this->assertEqual('none', $harness->viewVars['vv_selected_config_id'] ?? null,
      'the confirmation carries the choice to post again');
  }

  /**
   * The confirmation page posts the choice back with the confirmed flag, and
   * Cancel is a link back to the Named Configuration page, not a post.
   */
  public function testConfirmationPagePostsTheChoiceBackAndCancelIsALink() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS . 'Oa4mpClientCoNamedConfigs' . DS . 'confirm.ctp';
    $this->assertTrue(is_readable($path), 'the confirmation view exists');
    $source = (string)file_get_contents($path);

    $this->assertContains("Form->hidden('selected_config_id'", $source, 'it posts the choice back');
    $this->assertContains("Form->hidden('confirmed'", $source, 'it posts the confirmed flag');
    $this->assertContains('$vv_confirm_text', $source, 'it shows the confirmation text');
    $this->assertContains("Html->link(_txt('op.cancel')", $source, 'Cancel is a link, not a post');
    $this->assertContains("'action' => 'manage'", $source, 'to the Named Configuration page');
  }

  // ==========================================================================
  // Confirmed apply
  // ==========================================================================

  /**
   * The confirmed apply sends the Named configuration with none of the
   * client's own replaced sections; once accepted, those rows are gone and
   * the Named configuration is stored. Callbacks and the refresh token stay.
   */
  public function testConfirmedApplySendsTheStrippedClientAndDeletesTheReplacedRows() {
    $harness = $this->invoke($this->clientId, $this->configA, true);

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited once');
    $sent = $harness->harnessServer->lastEditData();
    $this->assertEqual((string)$this->configA, (string)($sent['Oa4mpClientCoOidcClient']['named_config_id'] ?? ''),
      'the server was sent the Named configuration');
    $this->assertEqual((int)$this->configA, (int)($sent['Oa4mpClientCoNamedConfig']['id'] ?? 0),
      'with the Named configuration row the cfg metadata links to');
    $this->assertNoOwnSections($sent);
    $this->assertNotEmpty($sent['Oa4mpClientCoCallback'] ?? array(), 'the callbacks are still sent');
    $this->assertEqual(3600, (int)($sent['Oa4mpClientRefreshToken']['token_lifetime'] ?? 0),
      'the refresh token setting is still sent');

    $this->assertEqual(array(
      'scopes' => 0, 'claims' => 0, 'constraints' => 0, 'authorizations' => 0,
      'ldap' => 0, 'search attributes' => 0, 'dynamo' => 0, 'access tokens' => 0,
      'callbacks' => 1, 'refresh tokens' => 1
    ), $this->rowCounts(), 'only the rows a Named configuration does not replace remain');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is stored');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'),
      'success'), 'success was reported');
    $this->assertEqual('manage', $harness->harnessRedirect['action'] ?? null, 'back to the Named Configuration page');
  }

  /** A refused or out-of-sync apply deletes nothing and stores nothing. */
  public function testConfirmedApplyRefusedByTheServerChangesNothing() {
    $before = $this->rowCounts();

    foreach (array(0, 2) as $verdict) {
      $harness = $this->harness($this->clientId, $this->configA, true);
      $harness->harnessServer->editClientReturn = $verdict;
      $harness->harnessInvoke('manage', array(), 'POST');

      $this->assertEqual(1, $this->editCalls($harness), "verdict $verdict: the server was edited once");
      $this->assertEqual($before, $this->rowCounts(), "verdict $verdict: nothing was deleted");
      $this->assertNull($this->storedNamedConfig(), "verdict $verdict: no Named configuration was stored");
      $this->assertFalse($harness->harnessFlashedAnySuccess(), "verdict $verdict: no success was reported");
    }
  }

  // ==========================================================================
  // Confirmed removal
  // ==========================================================================

  /**
   * Removing the Named configuration from an older client that still holds
   * claims sends the new-client defaults and no claims; afterwards the old
   * rows are gone and the defaults exist.
   */
  public function testConfirmedRemovalResetsAnOlderClientToNewClientDefaults() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->invoke($this->clientId, 'none', true);

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited once');
    $sent = $harness->harnessServer->lastEditData();
    $this->assertTrue(array_key_exists('named_config_id', $sent['Oa4mpClientCoOidcClient'] ?? array())
      && $sent['Oa4mpClientCoOidcClient']['named_config_id'] === null, 'the server was sent no Named configuration');
    $this->assertEqual(array('openid'), $this->scopeValues($sent['Oa4mpClientCoScope'] ?? array()),
      'the openid scope only');
    $this->assertTrue(!empty($sent['Oa4mpClientAuthorization']['require_active']), 'Require Active Status on');
    $this->assertEmpty($sent['Oa4mpClientClaim'] ?? array(), 'no claims');
    $this->assertEmpty($sent['Oa4mpClientCoLdapConfig'] ?? array(), 'no LDAP configuration');
    $this->assertTrue(empty($sent['Oa4mpClientAccessToken']['is_jwt']), 'no JWT access tokens');
    $this->assertEqual(self::ADMIN_DYNAMO_TABLE, $sent['Oa4mpClientDynamoConfig']['table_name'] ?? null,
      'the admin client default DynamoDB configuration');

    $counts = $this->rowCounts();
    $this->assertEqual(0, $counts['claims'], 'the old claims are gone');
    $this->assertEqual(0, $counts['ldap'], 'the old LDAP configuration is gone');
    $this->assertEqual(0, $counts['access tokens'], 'the access token row is gone');
    $this->assertEqual(array('openid'), $this->storedScopes(), 'the openid scope was created');
    $this->assertEqual(1, $this->count('cm_oa4mp_client_authorizations',
      'client_id = ' . (int)$this->clientId . ' AND require_active = true'), 'Require Active Status was created');
    $this->assertEqual(1, $this->count('cm_oa4mp_client_dynamo_configs',
      'client_id = ' . (int)$this->clientId . " AND table_name = '" . self::ADMIN_DYNAMO_TABLE . "'"),
      'the client got its own copy of the admin client default DynamoDB configuration');
    $this->assertEqual(1, $counts['dynamo'], 'and no other DynamoDB row');
    $this->assertNull($this->storedNamedConfig(), 'the Named configuration was removed');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'),
      'success'), 'success was reported');
  }

  /**
   * A removal is not sent when the leftover rows could not all be deleted
   * first: once the Named configuration is cleared nothing would delete them,
   * and the reset would bring them back into force.
   */
  public function testConfirmedRemovalIsNotSentWhenTheLeftoverDeleteFails() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->harness($this->clientId, 'none', true);
    $harness->harnessFailWrites('delete', $this->clientModelPath('Oa4mpClientClaim'));
    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(0, $this->editCalls($harness), 'nothing was sent to the OA4MP server');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is still applied');
    $this->assertEqual(2, $this->rowCounts()['claims'], 'premise: the claims could not be deleted');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.remove_blocked'), 'error'),
      'the removal was reported as not done');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  // ==========================================================================
  // Nothing to change
  // ==========================================================================

  /** Choosing none for a client with no Named configuration sends and changes nothing. */
  public function testChoosingNoneWithoutANamedConfigChangesNothing() {
    $before = $this->rowCounts();

    $harness = $this->invoke($this->clientId, 'none', false);

    $this->assertEmpty($harness->harnessServer->calls, 'nothing was sent to the OA4MP server');
    $this->assertEmpty($harness->harnessRendered, 'no confirmation was shown');
    $this->assertEqual($before, $this->rowCounts(), 'the client\'s claims and other rows are untouched');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.flash.unchanged'),
      'information'), 'the user is told nothing changed');
    $this->assertEqual('manage', $harness->harnessRedirect['action'] ?? null, 'back to the Named Configuration page');
  }

  /** Re-saving the applied Named configuration with no access token row sends nothing. */
  public function testResavingTheAppliedNamedConfigWithoutAnAccessTokenSendsNothing() {
    $this->setStoredNamedConfig($this->configA);
    $this->fx->query('DELETE FROM cm_oa4mp_client_access_tokens WHERE client_id = ' . (int)$this->clientId);

    $harness = $this->invoke($this->clientId, $this->configA, true);

    $this->assertEqual(0, $this->editCalls($harness), 'nothing was sent to the OA4MP server');
    $this->assertEmpty($harness->harnessRendered, 'no confirmation was shown');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.flash.unchanged'),
      'information'), 'the user is told nothing changed');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is still applied');
  }

  /**
   * An older client still holding an access token row, which is still sent,
   * re-saves its Named configuration through the confirmation, and the
   * confirmed apply deletes the row.
   */
  public function testResavingTheAppliedNamedConfigWithAnAccessTokenRowConfirmsThenDeletesIt() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->invoke($this->clientId, $this->configA, false);

    $this->assertEqual(array('confirm'), $harness->harnessRendered, 'the confirmation page was rendered');
    $this->assertEqual(0, $this->editCalls($harness), 'nothing was sent before confirming');
    $this->assertTrue(in_array(_txt('pl.oa4mp_client_co_named_config.confirm.apply.jwt'),
      $harness->viewVars['vv_confirm_text'] ?? array(), true), 'the JWT access token warning is shown');

    $harness = $this->invoke($this->clientId, $this->configA, true);

    $this->assertEqual(1, $this->editCalls($harness), 'the confirmed apply was sent');
    $this->assertNoOwnSections($harness->harnessServer->lastEditData());
    $this->assertEqual(0, $this->rowCounts()['access tokens'], 'the access token row was deleted');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is still applied');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.manage.flash.success'),
      'success'), 'success was reported');
  }

  // ==========================================================================
  // Refused
  // ==========================================================================

  /** A public client cannot use a Named configuration, confirmed or not. */
  public function testPublicClientIsRefused() {
    foreach (array(false, true) as $confirmed) {
      $harness = $this->invoke($this->publicClientId, $this->configA, $confirmed);
      $label = $confirmed ? 'confirmed' : 'unconfirmed';

      $this->assertEmpty($harness->harnessServer->calls, "$label: nothing was sent to the OA4MP server");
      $this->assertEmpty($harness->harnessRendered, "$label: no confirmation was shown");
      $this->assertNull($this->storedNamedConfig($this->publicClientId), "$label: nothing was stored");
      $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.public'), 'error'),
        "$label: the refusal was reported");
      $redirect = $harness->harnessRedirect;
      $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'] ?? null,
        "$label: sent to the client's edit page");
      $this->assertEqual('edit', $redirect['action'] ?? null, "$label: the edit action");
      $this->assertEqual($this->publicClientId, $redirect[0] ?? null, "$label: of the same client");
    }
  }

  /**
   * Opening the Named Configuration page of a public client is refused too:
   * the tab is not offered to it, and the form would list the admin client's
   * Named configurations.
   */
  public function testPublicClientManagePageIsRefused() {
    $harness = Oa4mpNamedConfigsControllerHarness::build($this->publicClientId, $this->coId);
    $harness->harnessInvoke('manage', array(), 'GET');

    $this->assertEmpty($harness->harnessServer->calls, 'nothing was sent to the OA4MP server');
    $this->assertEmpty($harness->harnessRendered, 'nothing was rendered');
    $this->assertFalse(array_key_exists('vv_available_configs', $harness->viewVars),
      'the Named configurations were not listed');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.public'), 'error'),
      'the refusal was reported');
    $redirect = $harness->harnessRedirect;
    $this->assertEqual('oa4mp_client_co_oidc_clients', $redirect['controller'] ?? null,
      "sent to the client's edit page");
    $this->assertEqual('edit', $redirect['action'] ?? null, 'the edit action');
    $this->assertEqual($this->publicClientId, $redirect[0] ?? null, 'of the same client');
  }

  /** A confirmed post is checked again: another admin client's Named configuration is refused. */
  public function testConfirmedPostNamingAnotherAdminClientsNamedConfigIsRefused() {
    $otherAdminId = $this->fx->adminClient($this->coId, Oa4mpFixtures::tag('oa4mpncar-other'));
    $foreign = $this->seedNamedConfig($otherAdminId, 'foreign');

    $harness = $this->invoke($this->clientId, (string)$foreign, true);

    $this->assertEqual(0, $this->editCalls($harness), 'nothing was sent to the OA4MP server');
    $this->assertEmpty($harness->harnessRendered, 'no confirmation was shown');
    $this->assertNull($this->storedNamedConfig(), 'nothing was stored');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.selection'), 'error'),
      'the selection error was reported');
  }

  // ==========================================================================
  // Registry writes failing after the server accepted
  // ==========================================================================

  /** A claim delete that fails after the server accepted the apply is reported. */
  public function testClaimDeleteFailingAfterAnAcceptedApplyIsReported() {
    $harness = $this->harness($this->clientId, $this->configA, true);
    $harness->harnessFailWrites('delete', $this->clientModelPath('Oa4mpClientClaim'));
    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited once, with no undo');
    $this->assertEqual(2, $this->rowCounts()['claims'], 'premise: the claims could not be deleted');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is stored');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.cleanup.apply'), 'error'),
      'the incomplete cleanup was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /**
   * An access token delete that fails after the apply leaves the row, which
   * is still sent; the error tells the user to re-save the Named
   * configuration, which runs the apply again.
   */
  public function testAccessTokenDeleteFailingAfterAnAcceptedApplyAsksForAResave() {
    $harness = $this->harness($this->clientId, $this->configA, true);
    $harness->harnessFailWrites('delete', $this->clientModelPath('Oa4mpClientAccessToken'));
    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->rowCounts()['access tokens'], 'premise: the access token row is still there');
    $this->assertTrue($harness->harnessFlashed(
      _txt('pl.oa4mp_client_co_named_config.er.cleanup.access_token', array(self::CONFIG_A)), 'error'),
      'the user is told to re-save the Named configuration');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /** Creating the defaults failing after the server accepted the removal is reported. */
  public function testDefaultsFailingAfterAnAcceptedRemovalIsReported() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->harness($this->clientId, 'none', true);
    $harness->harnessFailWrites('save', $this->clientModelPath('Oa4mpClientCoScope'));
    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited once, with no undo');
    $this->assertEqual(array(), $this->storedScopes(), 'premise: the openid scope could not be created');
    $this->assertNull($this->storedNamedConfig(), 'the Named configuration was removed');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.cleanup.remove'), 'error'),
      'the incomplete cleanup was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /**
   * An access token delete that fails after the server accepted the removal
   * stops there: the Named configuration stays stored, so a later apply or
   * removal still deletes the row, and no new-client settings are created.
   */
  public function testAccessTokenDeleteFailingAfterAnAcceptedRemovalKeepsTheNamedConfig() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->harness($this->clientId, 'none', true);
    $harness->harnessFailWrites('delete', $this->clientModelPath('Oa4mpClientAccessToken'));
    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited once, with no undo');
    $this->assertEqual(1, $this->rowCounts()['access tokens'], 'premise: the access token row is still there');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'the Named configuration is still stored');
    $this->assertEqual(array(), $this->storedScopes(), 'no new-client scope was created');
    $this->assertEqual(0, $this->rowCounts()['authorizations'], 'no new-client authorization settings were created');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.edit.save'), 'error'),
      'the failed write was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  /**
   * Clearing the stored Named configuration failing after the server
   * accepted the removal is reported, and no new-client settings are created.
   */
  public function testClearingTheNamedConfigFailingAfterAnAcceptedRemovalIsReported() {
    $this->setStoredNamedConfig($this->configA);

    $harness = $this->harness($this->clientId, 'none', true);
    $harness->harnessFailWrites('saveField',
      array('Oa4mpClientCoNamedConfig', 'Oa4mpClientCoAdminClient', 'Oa4mpClientCoOidcClient'));
    $harness->harnessInvoke('manage', array(), 'POST');

    $this->assertEqual(1, $this->editCalls($harness), 'the server was edited once, with no undo');
    $this->assertEqual($this->configA, $this->storedNamedConfig(), 'premise: the Named configuration is still stored');
    $this->assertEqual(0, $this->rowCounts()['access tokens'], 'the access token row was deleted first');
    $this->assertEqual(array(), $this->storedScopes(), 'no new-client scope was created');
    $this->assertEqual(0, $this->rowCounts()['authorizations'], 'no new-client authorization settings were created');
    $this->assertTrue($harness->harnessFlashed(_txt('pl.oa4mp_client_co_named_config.er.edit.save'), 'error'),
      'the failed write was reported');
    $this->assertFalse($harness->harnessFlashedAnySuccess(), 'no success was reported');
  }

  // ==========================================================================
  // Helpers
  // ==========================================================================

  private function harness($clientId, $selection, $confirmed) {
    $data = array('Oa4mpClientCoNamedConfig' => array('selected_config_id' => (string)$selection));
    if ($confirmed) {
      $data['Oa4mpClientCoNamedConfig']['confirmed'] = '1';
    }

    return Oa4mpNamedConfigsControllerHarness::build($clientId, $this->coId, $data);
  }

  private function invoke($clientId, $selection, $confirmed) {
    $harness = $this->harness($clientId, $selection, $confirmed);
    $harness->harnessInvoke('manage', array(), 'POST');

    return $harness;
  }

  /** The property chain from the controller to one of the client model's associations. */
  private function clientModelPath($association) {
    return array('Oa4mpClientCoNamedConfig', 'Oa4mpClientCoAdminClient', 'Oa4mpClientCoOidcClient', $association);
  }

  /** The sent client carries none of the sections a Named configuration replaces. */
  private function assertNoOwnSections($sent) {
    $this->assertTrue($sent !== null, 'the server was sent the client');
    $this->assertEmpty($sent['Oa4mpClientCoScope'] ?? array(), 'no scopes of the client\'s own');
    $this->assertEmpty($sent['Oa4mpClientClaim'] ?? array(), 'no claims');
    $this->assertEmpty($sent['Oa4mpClientCoLdapConfig'] ?? array(), 'no LDAP configuration');
    $this->assertTrue(empty($sent['Oa4mpClientAuthorization']['id'])
      && empty($sent['Oa4mpClientAuthorization']['require_active']), 'no authorization settings');
    $this->assertTrue(empty($sent['Oa4mpClientAccessToken']['id'])
      && empty($sent['Oa4mpClientAccessToken']['is_jwt']), 'no access token setting');
    $this->assertTrue(empty($sent['Oa4mpClientDynamoConfig']['id'])
      && empty($sent['Oa4mpClientDynamoConfig']['aws_region']), 'no DynamoDB configuration');
  }

  private function editCalls($harness) {
    return count(array_keys($harness->harnessServer->callNames(), 'oa4mpEditClient', true));
  }

  private function seedNamedConfig($adminId, $name) {
    return $this->fx->insert('cm_oa4mp_client_co_named_configs', array(
      'admin_id' => $adminId,
      'config_name' => $name,
      'description' => 'hermetic test named configuration',
      'config' => '{}'
    ));
  }

  private function setStoredNamedConfig($namedConfigId) {
    $this->fx->query('UPDATE cm_oa4mp_client_co_oidc_clients SET named_config_id = ' . (int)$namedConfigId
      . ' WHERE id = ' . (int)$this->clientId);
  }

  private function storedNamedConfig($clientId = null) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $value = $this->fx->scalar('SELECT named_config_id FROM cm_oa4mp_client_co_oidc_clients WHERE id = '
      . (int)($clientId ?? $this->clientId));
    return ($value === null) ? null : (int)$value;
  }

  /**
   * Every row kind a client can hold: two scopes, two claims with a
   * constraint each, Require Active Status, an LDAP configuration with two
   * search attributes, two DynamoDB rows (as some older clients hold), JWT
   * access tokens, a callback and a refresh token.
   */
  private function seedOwnRows($clientId) {
    foreach (array('openid', 'profile') as $scope) {
      $this->fx->insert('cm_oa4mp_client_co_scopes', array('client_id' => $clientId, 'scope' => $scope));
    }

    foreach (array('eppn', 'email') as $name) {
      $claimId = $this->fx->insert('cm_oa4mp_client_claims', array(
        'client_id' => $clientId,
        'claim_name' => $name,
        'source_model' => 'Identifier',
        'source_model_claim_value_field' => 'identifier',
        'claim_value_json_format' => 'string'
      ));
      $this->fx->insert('cm_oa4mp_client_claim_constraints', array(
        'claim_id' => $claimId,
        'constraint_field' => 'type',
        'constraint_value' => 'eppn'
      ));
    }

    $this->fx->insert('cm_oa4mp_client_authorizations', array(
      'client_id' => $clientId,
      'require_active' => true
    ));

    $ldapId = $this->fx->insert('cm_oa4mp_client_co_ldap_configs', array(
      'client_id' => $clientId,
      'enabled' => true,
      'authorization_type' => 'simple',
      'serverurl' => 'ldaps://ldap.example.org',
      'binddn' => 'cn=test,dc=example,dc=org',
      'password' => 'not-a-real-password',
      'basedn' => 'dc=example,dc=org',
      'search_name' => 'uid'
    ));
    foreach (array('mail', 'uid') as $name) {
      $this->fx->insert('cm_oa4mp_client_co_search_attributes', array(
        'ldap_id' => $ldapId,
        'name' => $name,
        'return_as_list' => false,
        'return_name' => $name,
        'claim_id' => null
      ));
    }

    foreach (array('first-table', 'second-table') as $table) {
      $this->fx->insert('cm_oa4mp_client_dynamo_configs', array(
        'client_id' => $clientId,
        'aws_region' => 'us-east-1',
        'aws_access_key_id' => 'AKIAEXAMPLE',
        'aws_secret_access_key' => 'not-a-real-key',
        'table_name' => $table,
        'partition_key' => 'client_id',
        'partition_key_template' => '${client_id}',
        'partition_key_claim_name' => 'sub'
      ));
    }

    $this->accessTokenId = $this->fx->insert('cm_oa4mp_client_access_tokens', array(
      'client_id' => $clientId,
      'is_jwt' => true
    ));
    $this->fx->insert('cm_oa4mp_client_co_callbacks', array(
      'client_id' => $clientId,
      'url' => 'https://example.org/cb'
    ));
    $this->fx->insert('cm_oa4mp_client_refresh_tokens', array(
      'client_id' => $clientId,
      'token_lifetime' => 3600
    ));
  }

  private function count($table, $where) {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    return (int)$this->fx->count($table, $where);
  }

  /** How many rows of each kind the confidential client holds. */
  private function rowCounts() {
    $c = 'client_id = ' . (int)$this->clientId;

    return array(
      'scopes' => $this->count('cm_oa4mp_client_co_scopes', $c),
      'claims' => $this->count('cm_oa4mp_client_claims', $c),
      'constraints' => $this->count('cm_oa4mp_client_claim_constraints',
        'claim_id IN (SELECT id FROM cm_oa4mp_client_claims WHERE ' . $c . ')'),
      'authorizations' => $this->count('cm_oa4mp_client_authorizations', $c),
      'ldap' => $this->count('cm_oa4mp_client_co_ldap_configs', $c),
      'search attributes' => $this->count('cm_oa4mp_client_co_search_attributes',
        'ldap_id IN (SELECT id FROM cm_oa4mp_client_co_ldap_configs WHERE ' . $c . ')'),
      'dynamo' => $this->count('cm_oa4mp_client_dynamo_configs', $c),
      'access tokens' => $this->count('cm_oa4mp_client_access_tokens', $c),
      'callbacks' => $this->count('cm_oa4mp_client_co_callbacks', $c),
      'refresh tokens' => $this->count('cm_oa4mp_client_refresh_tokens', $c)
    );
  }

  private function scopeValues($rows) {
    $values = array();
    foreach ($rows as $row) {
      $values[] = $row['scope'];
    }
    sort($values);
    return $values;
  }

  private function storedScopes() {
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $rows = $this->fx->query('SELECT scope FROM cm_oa4mp_client_co_scopes WHERE client_id = '
      . (int)$this->clientId . ' ORDER BY scope');
    $values = array();
    foreach ((array)$rows as $row) {
      $values[] = reset($row)['scope'] ?? null;
    }
    return $values;
  }
}
