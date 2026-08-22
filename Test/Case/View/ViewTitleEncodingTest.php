<?php
/**
 * Regression tests for the view-title double-encoding bug
 * (docs/solutions/ui-bugs/oa4mp-view-title-double-html-encoding-2026-08-03.md, #9).
 *
 * COmanage escapes title_for_layout exactly once, at render time, in the core
 * pageTitleAndButtons element. Controllers that pre-encoded the OIDC client
 * name with FILTER_SANITIZE_SPECIAL_CHARS before handing it to _txt() made the
 * element escape it a second time, so a client named "scott's" rendered as the
 * literal &#39;. The fix removed the controller-side pre-encode at all 11
 * sites.
 *
 * Two things are locked here: that the element really is a single escape point
 * (rendered, not asserted from memory), and that no plugin controller
 * reintroduces a pre-encode on a title_for_layout value. The second is a source
 * check because the thin runner cannot render a full plugin edit page; the
 * learning's own "Test gap" note asks for exactly this invariant.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U6 (R3, R5).
 */

App::uses('View', 'View');

class ViewTitleEncodingTest extends Oa4mpTestCase {

  /** The name from the original report. */
  private $clientName = "scott's confidential client";

  /** Render the core element that owns title escaping. */
  private function renderTitle($title) {
    $view = new View(null);
    return $view->element('pageTitleAndButtons', array(
      'title' => $title,
      'topLinks' => array()
    ));
  }

  /**
   * Passing the raw name through (what the controllers do now) yields a single
   * &#39; in the HTML source, which a browser renders as an apostrophe.
   */
  public function testRawNameIsEscapedExactlyOnce() {
    $html = $this->renderTitle(sprintf('Edit Claim for %s', $this->clientName));

    $this->assertContains('&#39;', $html, 'the element escapes the apostrophe');
    $this->assertTrue(strpos($html, '&#38;') === false,
      'a singly-escaped title must not carry an escaped ampersand');
  }

  /**
   * The failure mode, rendered: pre-encoding in the controller makes the
   * element escape the ampersand as well, producing the literal &#39; on screen.
   * This is what the bug looked like, and it is why the source check below
   * matters.
   */
  public function testPreEncodedNameIsDoubleEncodedByTheElement() {
    $preEncoded = filter_var($this->clientName, FILTER_SANITIZE_SPECIAL_CHARS);
    $html = $this->renderTitle(sprintf('Edit Claim for %s', $preEncoded));

    // FILTER_SANITIZE_SPECIAL_CHARS encodes & as &#38;, so the doubly-escaped
    // bytes are &#38;#39; -- the learning describes this as &amp;#39;, which is
    // the same defect with a different entity form. Either way the browser
    // shows the literal &#39; instead of an apostrophe.
    $this->assertContains('&#38;#39;', $html,
      'pre-encoding in the controller double-encodes at render');
  }

  /**
   * No plugin controller may pre-encode a value it hands to title_for_layout.
   * The scan is statement-scoped rather than line-scoped: the original
   * single-line grep missed every two-line _txt(...) call and under-counted the
   * blast radius by more than half.
   */
  public function testNoControllerPreEncodesTitleForLayout() {
    $offenders = array();

    foreach ($this->controllerFiles() as $file) {
      foreach ($this->titleStatements(file_get_contents($file)) as $statement) {
        if (strpos($statement, 'FILTER_SANITIZE_SPECIAL_CHARS') !== false) {
          $offenders[] = basename($file) . ': ' . preg_replace('/\s+/', ' ', $statement);
        }
      }
    }

    $this->assertEqual(array(), $offenders,
      'title_for_layout must receive the raw value; the element escapes it');
  }

  /** Sanity check on the scan itself: it must catch a pre-encoded statement. */
  public function testTitleScanDetectsAPreEncodedStatement() {
    $source = '<?php $this->set("title_for_layout", _txt("pl.x", array(' . "\n"
      . 'filter_var($client["name"], FILTER_SANITIZE_SPECIAL_CHARS))));';

    $statements = $this->titleStatements($source);
    $this->assertEqual(1, count($statements), 'one title statement is found');
    $this->assertContains('FILTER_SANITIZE_SPECIAL_CHARS', $statements[0],
      'the scan must see across the line break');
  }

  private function controllerFiles() {
    return glob(App::pluginPath('Oa4mpClient') . 'Controller' . DS . '*.php') ?: array();
  }

  /**
   * Return each title_for_layout statement in $source, from the occurrence to
   * the terminating semicolon, so a call wrapped across lines is seen whole.
   */
  private function titleStatements($source) {
    $statements = array();
    $offset = 0;

    while (($pos = strpos($source, 'title_for_layout', $offset)) !== false) {
      $end = strpos($source, ';', $pos);
      $statements[] = substr($source, $pos, ($end === false ? strlen($source) : $end) - $pos);
      $offset = $pos + 1;
    }

    return $statements;
  }
}
