<?php
/**
 * Registry 4 exporter for the Parity check.
 *
 * Turns each fixture under Test/Parity/fixtures/ into the exact request body
 * and cfg this plugin sends the OA4MP server for those client settings, and
 * writes them under Test/Parity/expected/. The Registry 5 port builds the same
 * fixtures with its own code and compares against these files, so the files are
 * the Registry 4 side of the comparison. See Test/Parity/README.md.
 *
 * Run inside the hermetic test container (see Test/Parity/README.md for the
 * full command sequence):
 *
 *   ./Console/cake Oa4mpClient.Oa4mp_parity
 *
 * Nothing here sends a request. The request body is built by
 * oa4mpMarshallContent() and encoded with the same json_encode() call that
 * oa4mpNewClient() and oa4mpEditClient() make before they send; no sender is
 * called. Test/Case/Parity/ParityExporterTest.php checks both that and that
 * the committed expected files still match what this exporter produces.
 */

App::uses('AppShell', 'Console/Command');
App::uses('CakePlugin', 'Core');
App::uses('CakeRequest', 'Network');
App::uses('ClassRegistry', 'Utility');
App::uses('Router', 'Routing');

class Oa4mpParityExporter {

  const FULL_BASE_URL = 'https://registry.example.org';
  const BASE_PATH = '/registry';

  const REQUEST_BODY_FILE = 'request-body.json';
  const CFG_FILE = 'cfg.json';
  const ADDRESSES_FILE = 'addresses.json';
  const FILES = array(self::REQUEST_BODY_FILE, self::CFG_FILE, self::ADDRESSES_FILE);

  protected $server = null;

  protected $columns = null;

  /**
   * @param Oa4mpClientOa4mpServer|null $server The server model to marshal
   *        with. Tests pass a subclass; the shell uses the real model.
   */
  public function __construct($server = null) {
    if ($server === null) {
      $server = ClassRegistry::init('Oa4mpClient.Oa4mpClientOa4mpServer');
    }
    $this->server = $server;
  }

  /** The Test/Parity directory of this plugin. */
  public static function parityDir() {
    return App::pluginPath('Oa4mpClient') . 'Test' . DS . 'Parity';
  }

  public static function fixturesDir() {
    return self::parityDir() . DS . 'fixtures';
  }

  public static function expectedDir() {
    return self::parityDir() . DS . 'expected';
  }

  /**
   * The fixture names in a directory: each *.json file name without its
   * extension, sorted.
   *
   * @param string $dir Directory holding the fixture files.
   * @return array Fixture names.
   */
  public function fixtureNames($dir) {
    $names = array();
    foreach (glob($dir . DS . '*.json') ?: array() as $path) {
      $names[] = basename($path, '.json');
    }
    sort($names);
    return $names;
  }

  /**
   * Read and check one fixture file.
   *
   * @param string $path Path to the fixture file.
   * @return array The decoded fixture.
   * @throws RuntimeException If the file cannot be read, is not JSON, or
   *         carries a key the fixture shape does not have.
   */
  public function readFixture($path) {
    $text = @file_get_contents($path);
    if ($text === false) {
      throw new RuntimeException('Parity fixture cannot be read: ' . $path);
    }

    try {
      $fixture = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new RuntimeException('Parity fixture is not valid JSON: ' . $path
        . ': ' . $e->getMessage());
    }

    $this->checkFixture($fixture, basename($path));

    return $fixture;
  }

  /**
   * Refuse a fixture carrying a key the fixture shape does not have.
   *
   * The marshaller reads the keys it knows and ignores everything else, so a
   * misspelt model or column name in a fixture would be exported as if it
   * were absent and committed as the expected output of a setting nobody
   * made. The shape below names every model key a fixture may carry; the
   * columns of each model are read from Config/Schema/schema.xml. LDAP models
   * are left out on purpose: no Parity fixture uses LDAP.
   *
   * @param mixed  $fixture The decoded fixture.
   * @param string $label   Name used in the error message.
   * @throws RuntimeException Naming the path of the first unknown or
   *         mistyped key.
   */
  public function checkFixture($fixture, $label = 'fixture') {
    if (!is_array($fixture) || array_is_list($fixture)) {
      throw new RuntimeException("Parity fixture $label is not a JSON object");
    }

    foreach (array('description', 'adminClient', 'data') as $required) {
      if (!array_key_exists($required, $fixture)) {
        throw new RuntimeException("Parity fixture $label has no $required key");
      }
    }

    foreach ($fixture as $key => $value) {
      switch ($key) {
        case 'description':
          if (!is_string($value)) {
            throw new RuntimeException("Parity fixture $label: description is not a string");
          }
          break;
        case 'adminClient':
          $this->checkNode($value, $this->adminClientShape(), "$label: adminClient");
          break;
        case 'data':
          $this->checkNode($value, $this->clientShape(), "$label: data");
          break;
        default:
          throw new RuntimeException("Parity fixture $label has an unknown key: $key");
      }
    }
  }

  /**
   * Export one fixture.
   *
   * @param array  $fixture The fixture, as readFixture() returns it.
   * @param string $label   Name used in error messages.
   * @return array File name => exact bytes: the request body always, cfg.json
   *         only when the request body carries a cfg, and addresses.json.
   * @throws RuntimeException If the fixture is refused or the output cannot be
   *         encoded or does not carry the signature.
   */
  public function export($fixture, $label = 'fixture') {
    $this->checkFixture($fixture, $label);

    $content = $this->marshallWithFixedAddresses($fixture);

    // The same call, with the same (default) flags, that oa4mpNewClient() and
    // oa4mpEditClient() make to build the body they send.
    $body = json_encode($content);
    if ($body === false) {
      throw new RuntimeException("Parity fixture $label: the request body cannot be encoded: "
        . json_last_error_msg());
    }

    $files = array();
    $files[self::REQUEST_BODY_FILE] = $body;

    if (array_key_exists('cfg', $content)) {
      $files[self::CFG_FILE] = $this->readableJson($content['cfg']);
    }

    $files[self::ADDRESSES_FILE] = $this->readableJson($this->addresses($content, $label));

    return $files;
  }

  /**
   * Export every fixture in a directory.
   *
   * @param string $dir Directory holding the fixture files.
   * @return array Fixture name => export() result.
   */
  public function exportAll($dir) {
    $all = array();
    foreach ($this->fixtureNames($dir) as $name) {
      $all[$name] = $this->export($this->readFixture($dir . DS . $name . '.json'), $name . '.json');
    }
    return $all;
  }

  /**
   * Write one fixture's exported files into its directory under $dir,
   * removing any file this exporter writes that the export no longer
   * produces (a cfg.json left behind by a fixture that stopped carrying one).
   *
   * @param string $dir   The expected directory.
   * @param string $name  Fixture name.
   * @param array  $files export() result.
   * @throws RuntimeException If a file cannot be written or removed.
   */
  public function write($dir, $name, $files) {
    $target = $dir . DS . $name;
    if (!is_dir($target) && !mkdir($target, 0775, true)) {
      throw new RuntimeException('Cannot create ' . $target);
    }

    foreach (self::FILES as $file) {
      if (!isset($files[$file]) && file_exists($target . DS . $file)
         && !unlink($target . DS . $file)) {
        throw new RuntimeException('Cannot remove ' . $target . DS . $file);
      }
    }

    foreach ($files as $file => $bytes) {
      if (file_put_contents($target . DS . $file, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write ' . $target . DS . $file);
      }
    }
  }

  /**
   * Run the marshaller with Router building addresses from the fixed base.
   *
   * A request carrying the base path is pushed for the duration of the call
   * and popped afterwards, and the full base URL is put back, so nothing else
   * running in the same process sees a different Router.
   */
  protected function marshallWithFixedAddresses($fixture) {
    $previousFullBaseUrl = Router::fullBaseUrl();

    $request = new CakeRequest('/', false);
    $request->base = self::BASE_PATH;
    $request->webroot = self::BASE_PATH . '/';
    $request->here = self::BASE_PATH . '/';

    Router::setRequestInfo($request);
    Router::fullBaseUrl(self::FULL_BASE_URL);

    try {
      return $this->server->oa4mpMarshallContent($fixture['adminClient'], $fixture['data']);
    } finally {
      Router::popRequest();
      Router::fullBaseUrl($previousFullBaseUrl);
    }
  }

  /**
   * The two Registry addresses in what was marshalled.
   *
   * @return array client_index_address (from the comment) and
   *         named_config_address (from the cfg metadata, null when absent).
   * @throws RuntimeException If the comment does not start with the signature.
   */
  protected function addresses($content, $label) {
    $signatureKey = 'pl.oa4mp_client_co_oidc_client.signature';
    $signature = _txt($signatureKey);

    if ($signature === $signatureKey) {
      throw new RuntimeException('The plugin texts are not loaded, so the comment would carry '
        . $signatureKey . ' instead of the signature');
    }

    $prefix = $signature . ': ';
    $comment = $content['comment'] ?? null;
    if (!is_string($comment) || strpos($comment, $prefix) !== 0) {
      throw new RuntimeException("Parity fixture $label: the comment does not start with the signature");
    }

    return array(
      'client_index_address' => substr($comment, strlen($prefix)),
      'named_config_address' => $content['cfg']['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] ?? null,
    );
  }

  protected function readableJson($value) {
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
  }

  /**
   * Check one record against a shape.
   *
   * A shape is array('table' => table name or null, 'one' => key => shape,
   * 'many' => key => shape). A record may carry the table's columns, whose
   * values are scalars or null, and the listed associations: 'one' as an
   * object, 'many' as a list of objects.
   */
  protected function checkNode($node, $shape, $path) {
    if (!is_array($node) || (!empty($node) && array_is_list($node))) {
      throw new RuntimeException("Parity fixture $path is not a JSON object");
    }

    $columns = empty($shape['table']) ? array() : $this->tableColumns($shape['table']);
    $one = $shape['one'] ?? array();
    $many = $shape['many'] ?? array();

    foreach ($node as $key => $value) {
      $at = "$path.$key";

      if (in_array($key, $columns, true)) {
        if (!is_scalar($value) && $value !== null) {
          throw new RuntimeException("Parity fixture $at is a column and must be a scalar or null");
        }
      } elseif (isset($one[$key])) {
        $this->checkNode($value, $one[$key], $at);
      } elseif (isset($many[$key])) {
        if (!is_array($value) || !array_is_list($value)) {
          throw new RuntimeException("Parity fixture $at must be a list");
        }
        foreach ($value as $i => $item) {
          $this->checkNode($item, $many[$key], $at . '[' . $i . ']');
        }
      } else {
        throw new RuntimeException("Parity fixture has an unknown key: $at");
      }
    }
  }

  protected function tableColumns($table) {
    if ($this->columns === null) {
      $path = App::pluginPath('Oa4mpClient') . 'Config' . DS . 'Schema' . DS . 'schema.xml';
      $xml = @simplexml_load_file($path);
      if ($xml === false) {
        throw new RuntimeException('Cannot read ' . $path);
      }
      $this->columns = array();
      foreach ($xml->table as $t) {
        $fields = array();
        foreach ($t->field as $f) {
          $fields[] = (string)$f['name'];
        }
        $this->columns[(string)$t['name']] = $fields;
      }
    }

    if (!isset($this->columns[$table])) {
      throw new RuntimeException('schema.xml declares no table ' . $table);
    }
    return $this->columns[$table];
  }

  /** A named configuration as the admin client contains it, with its scopes. */
  protected function namedConfigShape() {
    return array(
      'table' => 'oa4mp_client_co_named_configs',
      'many' => array(
        'Oa4mpClientCoScope' => array('table' => 'oa4mp_client_co_scopes'),
      ),
    );
  }

  /** The admin client row, with what Oa4mpClientCoOidcClient::current() contains under it. */
  protected function adminClientRowShape() {
    return array(
      'table' => 'oa4mp_client_co_admin_clients',
      'one' => array(
        'DefaultDynamoConfig' => array('table' => 'oa4mp_client_dynamo_configs'),
      ),
      'many' => array(
        'Oa4mpClientCoNamedConfig' => $this->namedConfigShape(),
        'Oa4mpClientCoEmailAddress' => array('table' => 'oa4mp_client_co_email_addresses'),
      ),
    );
  }

  /** The $adminClient argument of oa4mpMarshallContent(). */
  protected function adminClientShape() {
    return array(
      'table' => null,
      'one' => array(
        'Oa4mpClientCoAdminClient' => array('table' => 'oa4mp_client_co_admin_clients'),
        'DefaultDynamoConfig' => array('table' => 'oa4mp_client_dynamo_configs'),
      ),
      'many' => array(
        'Oa4mpClientCoNamedConfig' => $this->namedConfigShape(),
        'Oa4mpClientCoEmailAddress' => array('table' => 'oa4mp_client_co_email_addresses'),
      ),
    );
  }

  /** The $data argument of oa4mpMarshallContent(), without the LDAP models. */
  protected function clientShape() {
    return array(
      'table' => null,
      'one' => array(
        'Oa4mpClientCoOidcClient' => array('table' => 'oa4mp_client_co_oidc_clients'),
        'Oa4mpClientCoAdminClient' => $this->adminClientRowShape(),
        'Oa4mpClientAccessControl' => array('table' => 'oa4mp_client_access_controls'),
        'Oa4mpClientAccessToken' => array('table' => 'oa4mp_client_access_tokens'),
        'Oa4mpClientAuthorization' => array('table' => 'oa4mp_client_authorizations'),
        'Oa4mpClientRefreshToken' => array('table' => 'oa4mp_client_refresh_tokens'),
        'Oa4mpClientCoNamedConfig' => array('table' => 'oa4mp_client_co_named_configs'),
        'Oa4mpClientDynamoConfig' => array('table' => 'oa4mp_client_dynamo_configs'),
      ),
      'many' => array(
        'Oa4mpClientCoEmailAddress' => array('table' => 'oa4mp_client_co_email_addresses'),
        'Oa4mpClientCoScope' => array('table' => 'oa4mp_client_co_scopes'),
        'Oa4mpClientCoCallback' => array('table' => 'oa4mp_client_co_callbacks'),
        'Oa4mpClientClaim' => array(
          'table' => 'oa4mp_client_claims',
          'many' => array(
            'Oa4mpClientClaimConstraint' => array('table' => 'oa4mp_client_claim_constraints'),
          ),
        ),
      ),
    );
  }
}

class Oa4mpParityShell extends AppShell {

  public function getOptionParser() {
    $parser = parent::getOptionParser();
    $parser->description('Write the Parity check\'s expected files from its fixtures.');
    return $parser;
  }

  public function main() {
    if (!CakePlugin::loaded('Oa4mpClient')) {
      CakePlugin::load('Oa4mpClient');
    }

    // The comment carries the signature from Lib/lang.php; see
    // Oa4mpTestShell::main() for why a console shell has to load it itself.
    _bootstrap_plugin_txt();

    $exporter = new Oa4mpParityExporter();
    $fixturesDir = Oa4mpParityExporter::fixturesDir();
    $expectedDir = Oa4mpParityExporter::expectedDir();

    $all = $exporter->exportAll($fixturesDir);
    if (empty($all)) {
      $this->out('<error>No Parity fixtures found in ' . $fixturesDir . '</error>');
      $this->_stop(1);
    }

    foreach ($all as $name => $files) {
      $exporter->write($expectedDir, $name, $files);
      $this->out(sprintf('  %s: %s', $name, implode(', ', array_keys($files))));
    }

    foreach (glob($expectedDir . DS . '*', GLOB_ONLYDIR) ?: array() as $dir) {
      if (!isset($all[basename($dir)])) {
        $this->out('<warning>' . basename($dir) . ' has expected files but no fixture</warning>');
      }
    }

    $this->out(sprintf('Exported %d Parity fixtures to %s', count($all), $expectedDir));
  }
}
