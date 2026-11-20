<?php
/**
 * The Registry 4 exporter for the Parity check
 * (Console/Command/Oa4mpParityShell.php).
 *
 * The committed files under Test/Parity/expected/ are what the Registry 5 port
 * is compared against, so they have to be exactly what this plugin sends for
 * each fixture under Test/Parity/fixtures/. These tests hold the exporter and
 * the committed files to that:
 *
 *  - the export of a fixture is the same bytes every time it runs;
 *  - every fixture's export equals its committed expected files, so a change
 *    to what the marshaller sends turns this red until the expected files are
 *    regenerated and the change shows in the diff;
 *  - a public client exports no cfg;
 *  - a fixture carrying a key the fixture shape does not have is refused
 *    rather than exported as if the key were absent;
 *  - the exporter builds no request to the OA4MP server.
 *
 * The expected values here come from the committed files and the fixtures,
 * never from the exporter at assertion time. No database and no credential
 * are needed.
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');
App::uses('Router', 'Routing');
App::uses('CakeRequest', 'Network');

require_once App::pluginPath('Oa4mpClient') . 'Console' . DS . 'Command' . DS . 'Oa4mpParityShell.php';

/** Raised instead of building a request to the OA4MP server. */
class Oa4mpParityRequestAttempted extends Exception {}

/**
 * The server model with both request-building seams closed. Every sender --
 * oa4mpNewClient(), oa4mpEditClient() through oa4mpVerifyClient(),
 * oa4mpVerifyClient() and oa4mpDeleteClient() -- builds its request through
 * oa4mpBuildRequest() before it sends (Test/Case/Model/ServerRequestQueryTest.php
 * holds each of them to that), and oa4mpBuildRequest() starts from
 * oa4mpInitializeRequest(). Recording and raising here therefore catches any
 * request the exporter would make, before HttpSocket::request() is reached.
 */
class Oa4mpParityNoRequestServer extends Oa4mpClientOa4mpServer {

  /** @var array Request kinds asked for, in order. */
  public $requested = array();

  protected function oa4mpBuildRequest($kind, $adminClient, $clientIdentifier = null) {
    $this->requested[] = $kind;
    throw new Oa4mpParityRequestAttempted('request built: ' . $kind);
  }

  public function oa4mpInitializeRequest($adminClient) {
    $this->requested[] = 'initialize';
    throw new Oa4mpParityRequestAttempted('request initialized');
  }
}

class ParityExporterTest extends Oa4mpTestCase {

  private function exporter($server = null) {
    return new Oa4mpParityExporter($server);
  }

  private function fixturesDir() {
    return Oa4mpParityExporter::fixturesDir();
  }

  private function expectedDir() {
    return Oa4mpParityExporter::expectedDir();
  }

  /** The fixture names, read from the directory rather than from the exporter. */
  private function fixtureNames() {
    $names = array();
    foreach (glob($this->fixturesDir() . DS . '*.json') ?: array() as $path) {
      $names[] = basename($path, '.json');
    }
    sort($names);
    return $names;
  }

  private function fixture($name) {
    $text = file_get_contents($this->fixturesDir() . DS . $name . '.json');
    $fixture = json_decode($text, true);
    $this->assertTrue(is_array($fixture), "fixture $name must decode");
    return $fixture;
  }

  /** The committed expected files of one fixture: file name => bytes. */
  private function committedFiles($name) {
    $files = array();
    foreach (glob($this->expectedDir() . DS . $name . DS . '*') ?: array() as $path) {
      $files[basename($path)] = file_get_contents($path);
    }
    ksort($files);
    return $files;
  }

  /**
   * The set is not empty, and every fixture exports identical bytes on two
   * runs, each through its own exporter.
   */
  public function testEveryFixtureExportsTheSameBytesOnTwoRuns() {
    $names = $this->fixtureNames();
    $this->assertTrue(count($names) > 0, 'Test/Parity/fixtures/ holds no fixture');

    foreach ($names as $name) {
      $fixture = $this->fixture($name);
      $first = $this->exporter()->export($fixture, $name);
      $second = $this->exporter()->export($fixture, $name);

      $this->assertEqual($first, $second, "fixture $name exported different bytes on a second run");
    }
  }

  /**
   * Every fixture exports exactly its committed expected files: the same file
   * names and the same bytes. A difference means what the plugin sends has
   * changed; regenerate the expected files (Test/Parity/README.md) and let the
   * change show in the diff.
   */
  public function testEveryFixtureMatchesItsCommittedExpectedFiles() {
    foreach ($this->fixtureNames() as $name) {
      $exported = $this->exporter()->export($this->fixture($name), $name);
      ksort($exported);
      $committed = $this->committedFiles($name);

      $this->assertEqual(array_keys($committed), array_keys($exported),
        "fixture $name: the committed expected files are not the files the export writes");

      foreach ($committed as $file => $bytes) {
        $this->assertTrue($bytes === $exported[$file],
          "fixture $name: $file differs from what the plugin now sends; see Test/Parity/README.md");
      }
    }
  }

  /**
   * Fixtures and expected directories match one to one, so neither a fixture
   * without expected files nor expected files left behind by a removed fixture
   * reaches the Registry 5 side.
   */
  public function testFixturesAndExpectedDirectoriesMatchOneToOne() {
    $expected = array();
    foreach (glob($this->expectedDir() . DS . '*', GLOB_ONLYDIR) ?: array() as $dir) {
      $expected[] = basename($dir);
    }
    sort($expected);

    $this->assertEqual($this->fixtureNames(), $expected,
      'every fixture needs an expected directory and every expected directory a fixture');
  }

  /**
   * The public client fixture exports no cfg, and its request body carries
   * none. The control flips only public_client on the same fixture, which
   * carries a claim, and must export a cfg -- so the first half fails if the
   * cfg stopped being sent to everyone rather than to public clients.
   */
  public function testThePublicClientFixtureExportsNoCfg() {
    $fixture = $this->fixture('public-client');
    $this->assertTrue($fixture['data']['Oa4mpClientCoOidcClient']['public_client'] === true,
      'the public-client fixture must describe a public client');

    $files = $this->exporter()->export($fixture, 'public-client');
    $this->assertFalse(isset($files[Oa4mpParityExporter::CFG_FILE]),
      'a public client must export no cfg.json');
    $body = json_decode($files[Oa4mpParityExporter::REQUEST_BODY_FILE], true);
    $this->assertFalse(array_key_exists('cfg', $body), 'a public client request body must carry no cfg');
    $this->assertEqual('none', $body['token_endpoint_auth_method']);

    $confidential = $fixture;
    $confidential['data']['Oa4mpClientCoOidcClient']['public_client'] = false;
    $files = $this->exporter()->export($confidential, 'public-client control');
    $this->assertTrue(isset($files[Oa4mpParityExporter::CFG_FILE]),
      'the same settings on a confidential client must export a cfg');
  }

  /**
   * A key the fixture shape does not have fails the export and is named, at
   * every depth the marshaller reads: the fixture itself, a model of the
   * client, a column of a client row, a claim column, a constraint column, a
   * Named configuration's scope, and an LDAP model, which no fixture may use.
   */
  public function testAFixtureWithAnUnknownKeyFailsTheExport() {
    $base = $this->fixture('claims-email-address');
    $named = $this->fixture('named-config');

    $cases = array();

    $f = $base;
    $f['comment'] = 'not a fixture key';
    $cases['comment'] = $f;

    $f = $base;
    $f['data']['Oa4mpClientClaims'] = $f['data']['Oa4mpClientClaim'];
    $cases['data.Oa4mpClientClaims'] = $f;

    $f = $base;
    $f['data']['Oa4mpClientCoOidcClient']['publicclient'] = true;
    $cases['data.Oa4mpClientCoOidcClient.publicclient'] = $f;

    $f = $base;
    $f['data']['Oa4mpClientClaim'][0]['claim_value_json_formats'] = 'number';
    $cases['data.Oa4mpClientClaim[0].claim_value_json_formats'] = $f;

    $f = $base;
    $f['data']['Oa4mpClientClaim'][1]['Oa4mpClientClaimConstraint'][0]['constraint_type'] = 'official';
    $cases['data.Oa4mpClientClaim[1].Oa4mpClientClaimConstraint[0].constraint_type'] = $f;

    $f = $named;
    $f['adminClient']['Oa4mpClientCoNamedConfig'][0]['Oa4mpClientCoScope'][0]['scopes'] = 'email';
    $cases['adminClient.Oa4mpClientCoNamedConfig[0].Oa4mpClientCoScope[0].scopes'] = $f;

    $f = $base;
    $f['data']['Oa4mpClientCoLdapConfig'] = array();
    $cases['data.Oa4mpClientCoLdapConfig'] = $f;

    foreach ($cases as $key => $fixture) {
      $refused = null;
      try {
        $this->exporter()->export($fixture, 'unknown-key');
      } catch (RuntimeException $e) {
        $refused = $e->getMessage();
      }

      $this->assertTrue($refused !== null, "a fixture carrying $key must fail the export");
      $leaf = substr($key, strrpos('.' . $key, '.'));
      $this->assertContains($leaf, $refused, "the refusal must name the unknown key $key");
    }

    // The unchanged fixtures export, so the refusals above are the keys.
    $this->assertNotEmpty($this->exporter()->export($base, 'claims-email-address'));
    $this->assertNotEmpty($this->exporter()->export($named, 'named-config'));
  }

  /**
   * Exporting every fixture builds no request to the OA4MP server, and the
   * output is the same as through the ordinary model. The control shows the
   * probe stops a real sender, so an empty record means none was reached.
   */
  public function testTheExporterMakesNoHttpRequest() {
    $server = new Oa4mpParityNoRequestServer();
    $probed = $this->exporter($server);
    $plain = $this->exporter();

    foreach ($this->fixtureNames() as $name) {
      $fixture = $this->fixture($name);
      $this->assertEqual($plain->export($fixture, $name), $probed->export($fixture, $name),
        "fixture $name: the probe must not change the export");
    }

    $this->assertEqual(array(), $server->requested, 'the exporter built a request to the OA4MP server');

    $fixture = $this->fixture('confidential-minimal');
    $stopped = false;
    try {
      $server->oa4mpNewClient($fixture['adminClient'], $fixture['data']);
    } catch (Oa4mpParityRequestAttempted $e) {
      $stopped = true;
    }
    $this->assertTrue($stopped, 'the probe must stop a sender before it reaches the server');
    $this->assertEqual(array('create'), $server->requested);
  }

  /**
   * The exporter fixes the base the two Registry addresses are built from
   * and puts the Router back afterwards, so a test running after it in the
   * same process builds the addresses it would have built anyway.
   */
  public function testTheExporterLeavesTheRouterAsItFoundIt() {
    $route = array(
      'plugin' => 'oa4mp_client',
      'controller' => 'oa4mp_client_co_oidc_clients',
      'action' => 'index',
      'co' => 2
    );

    // Start from a request and base URL of this test's own, unlike the
    // exporter's, so a request or base URL the exporter leaves behind shows
    // even when an earlier export in this process already changed the Router.
    $previousFullBaseUrl = Router::fullBaseUrl();
    $request = new CakeRequest('/', false);
    $request->base = '/router-probe';
    $request->webroot = '/router-probe/';
    $request->here = '/router-probe/';
    Router::setRequestInfo($request);
    Router::fullBaseUrl('https://router-probe.example.net');

    try {
      $before = Router::url($route, true);
      $files = $this->exporter()->export($this->fixture('named-config'), 'named-config');
      $after = Router::url($route, true);
    } finally {
      Router::popRequest();
      Router::fullBaseUrl($previousFullBaseUrl);
    }

    $this->assertTrue(strpos($before, 'https://router-probe.example.net/router-probe/') === 0,
      'the probe request must be the one the Router builds from');
    $this->assertEqual($before, $after,
      'the Router must build the same address after an export as before it');

    $addresses = json_decode($files[Oa4mpParityExporter::ADDRESSES_FILE], true);
    $this->assertTrue(strpos($addresses['client_index_address'], Oa4mpParityExporter::FULL_BASE_URL
      . Oa4mpParityExporter::BASE_PATH . '/') === 0,
      'the client index address must be built from the fixed base');
    $this->assertTrue(strpos($addresses['named_config_address'], Oa4mpParityExporter::FULL_BASE_URL
      . Oa4mpParityExporter::BASE_PATH . '/') === 0,
      'the Named configuration address must be built from the fixed base');
  }
}
