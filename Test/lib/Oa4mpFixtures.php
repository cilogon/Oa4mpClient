<?php
/**
 * Row-level fixture helper for the Oa4mpClient thin runner.
 *
 * CakePHP 2.x's PHPUnit fixture machinery does not run on this stack (see
 * Test/README.md, U1 findings), so DB-backed tests seed the rows they need
 * directly and drop them again in tearDown. Every insert is recorded so
 * cleanup() can delete in reverse order and satisfy the schema's foreign
 * keys without the test having to track ids itself.
 *
 * The hermetic environment is Postgres (Test/docker/docker-compose.yml), so
 * INSERT ... RETURNING id is used to recover generated ids.
 */

App::uses('ConnectionManager', 'Model');

class Oa4mpFixtures {

  /** @var DboSource */
  protected $db;

  /** @var array List of array('table' => ..., 'id' => ...) in insertion order. */
  protected $rows = array();

  public function __construct() {
    $this->db = ConnectionManager::getDataSource('default');
  }

  /**
   * Insert one row and return its generated id.
   *
   * @param string $table Physical table name (with the cm_ prefix).
   * @param array $fields Column => value. A null value is written as NULL.
   * @return integer
   */
  public function insert($table, $fields) {
    $cols = array();
    $vals = array();
    foreach ($fields as $col => $val) {
      $cols[] = '"' . $col . '"';
      $vals[] = ($val === null) ? 'NULL' : $this->db->value($val);
    }

    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
      . implode(', ', $vals) . ') RETURNING id';

    $id = $this->scalar($sql);
    if ($id === null) {
      throw new Exception("fixture insert into $table returned no id");
    }

    $id = (int)$id;
    $this->rows[] = array('table' => $table, 'id' => $id);
    return $id;
  }

  /** Run arbitrary SQL and return the raw CakePHP result set. */
  public function query($sql) {
    return $this->db->query($sql);
  }

  /**
   * Run a query expected to yield a single value and return it (null if none).
   * Tolerates both result shapes CakePHP 2 produces for aliased and bare columns.
   */
  public function scalar($sql) {
    $result = $this->db->query($sql);
    if (empty($result)) {
      return null;
    }
    $row = array_shift($result);
    while (is_array($row)) {
      $next = array_shift($row);
      if (!is_array($next)) {
        return $next;
      }
      $row = $next;
    }
    return $row;
  }

  /** Convenience: count rows matching a WHERE clause. */
  public function count($table, $where) {
    return (int)$this->scalar('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . $where);
  }

  /**
   * Register a row this helper did not insert (for example one created by the
   * code under test) so cleanup() removes it too.
   */
  public function track($table, $id) {
    $this->rows[] = array('table' => $table, 'id' => (int)$id);
  }

  /**
   * Seed a CO. $tag makes the unique name and is echoed back by the callers'
   * other rows so a leaked fixture is traceable to the test that made it.
   */
  public function co($tag) {
    return $this->insert('cm_cos', array(
      'name' => 'CO ' . $tag,
      'description' => 'hermetic test CO',
      'status' => 'A'
    ));
  }

  /**
   * Seed an OA4MP admin client in $coId. $overrides sets or adds columns, e.g.
   * manage_co_group_id for the delegated management group.
   */
  public function adminClient($coId, $tag, $overrides = array()) {
    return $this->insert('cm_oa4mp_client_co_admin_clients', $overrides + array(
      'co_id' => $coId,
      'serverurl' => 'https://oa4mp.' . $tag . '.example.org/oauth2',
      'name' => 'admin ' . $tag,
      'issuer' => 'https://' . $tag . '.example.org',
      'admin_identifier' => 'admin:' . $tag,
      'secret' => 'not-a-real-secret'
    ));
  }

  /** Seed an OIDC client under $adminId. */
  public function oidcClient($adminId, $name, $overrides = array()) {
    return $this->insert('cm_oa4mp_client_co_oidc_clients', $overrides + array(
      'admin_id' => $adminId,
      'oa4mp_identifier' => 'https://example.org/oidc/client/' . $name,
      'name' => $name,
      'home_url' => 'https://example.org/',
      'proxy_limited' => false,
      'public_client' => false
    ));
  }

  /** A unique, traceable tag for one test's fixture rows. */
  public static function tag($prefix) {
    return $prefix . '-' . getmypid() . '-' . substr(uniqid(), -6);
  }

  /** Quote a value for interpolation into a hand-written WHERE clause. */
  public function value($val) {
    return $this->db->value($val);
  }

  /**
   * Delete every tracked row plus any rows the code under test created in the
   * tables named, newest first, so foreign keys are satisfied.
   *
   * @param array $alsoPurge table => WHERE clause, deleted before tracked rows.
   */
  public function cleanup($alsoPurge = array()) {
    foreach ($alsoPurge as $table => $where) {
      $this->db->query('DELETE FROM ' . $table . ' WHERE ' . $where);
    }

    foreach (array_reverse($this->rows) as $row) {
      $this->db->query('DELETE FROM ' . $row['table'] . ' WHERE id = ' . (int)$row['id']);
    }

    $this->rows = array();
  }
}
