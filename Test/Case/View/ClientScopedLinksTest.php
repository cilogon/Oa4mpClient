<?php
/**
 * Regression test for the broken Callback column link on the callbacks index.
 *
 * The callbacks and claims controllers are client-scoped: parseCOID() and the
 * actions read the OIDC client from the `clientid` named parameter, and a
 * request without it fails. The callbacks index linked each callback URL to
 * its edit (or view) page without `clientid`, so the link in the Callback
 * column failed while the Edit button beside it, which passed `clientid`,
 * worked.
 *
 * The thin runner cannot render a full plugin index page, so every link or
 * URL these views build to their own controller is checked in source: each
 * must carry `clientid`.
 */

class ClientScopedLinksTest extends Oa4mpTestCase {

  /** Client-scoped index views and the controller their links target. */
  private $views = array(
    'Oa4mpClientCoCallbacks/index.ctp' => 'oa4mp_client_co_callbacks',
    'Oa4mpClientClaims/index.ctp' => 'oa4mp_client_claims',
  );

  /**
   * The argument list of every $this->Html->link( or $this->Html->url( call
   * in $source, from its opening parenthesis to the matching close.
   */
  private function linkCalls($source) {
    $calls = array();
    $offset = 0;

    while (preg_match('/->Html->(link|url)\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
      $start = $m[0][1] + strlen($m[0][0]);
      $depth = 1;
      $i = $start;
      $len = strlen($source);

      while ($i < $len && $depth > 0) {
        if ($source[$i] === '(') {
          $depth++;
        } elseif ($source[$i] === ')') {
          $depth--;
        }
        $i++;
      }

      $calls[] = substr($source, $start, $i - $start - 1);
      $offset = $i;
    }

    return $calls;
  }

  /**
   * Every link or URL a client-scoped index view builds to its own
   * controller carries the clientid named parameter.
   */
  public function testClientScopedIndexLinksCarryClientId() {
    foreach ($this->views as $view => $controller) {
      $path = App::pluginPath('Oa4mpClient') . 'View' . DS . str_replace('/', DS, $view);
      $this->assertTrue(is_readable($path), "the view exists at $path");

      $own = 0;
      foreach ($this->linkCalls(file_get_contents($path)) as $call) {
        if (strpos($call, "'controller' => '" . $controller . "'") === false) {
          continue;
        }
        $own++;
        $this->assertContains("'clientid' =>", $call,
          "$view links to $controller without clientid: " . preg_replace('/\s+/', ' ', $call));
      }

      $this->assertTrue($own > 0, "premise: $view links to $controller at least once");
    }
  }
}
