<?php
/**
 * The no-record redirect URL on the Authorization tab.
 *
 * The thin runner cannot render the tab, so the template is checked in
 * source, as RequireActivePredicateTest does for the checkbox. The field
 * differs on purpose from the two existing redirect URLs: those are hidden,
 * and their values cleared, when their gate is off. This one stays visible,
 * and its help text carries the "only when a gate is on" rule instead.
 *
 * See docs/plans/2026-10-12-0712-feat-no-record-redirect-plan.md U4 (R1, R12,
 * KTD6).
 */

class AuthorizationNoRecordFieldTest extends Oa4mpTestCase {

  private function template() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS
      . 'Oa4mpClientAuthorizations' . DS . 'manage.ctp';
    $this->assertTrue(is_readable($path), "the Authorization template exists at $path");
    return file_get_contents($path);
  }

  /** The tab renders an input, label, and description for the column. */
  public function testTheTabRendersTheNoRecordField() {
    $source = $this->template();

    $this->assertContains("Form->input('no_record_redirect_url'", $source,
      'the tab renders an input for no_record_redirect_url');
    $this->assertContains("_txt('pl.oa4mp_client_authorization.fd.no_record_redirect_url')", $source,
      'the field is labelled from lang.php');
    $this->assertContains("_txt('pl.oa4mp_client_authorization.fd.no_record_redirect_url.desc')", $source,
      'the field carries its description from lang.php');
  }

  /**
   * The template's script never hides or clears the field: neither its
   * container nor its input is named anywhere in the script block.
   */
  public function testTheScriptNeverHidesOrClearsTheField() {
    $source = $this->template();

    $this->assertTrue(preg_match('#<script[^>]*>(.*?)</script>#s', $source, $m) === 1,
      'premise: the template has a script block');
    $script = $m[1];

    $this->assertTrue(stripos($script, 'NoRecordRedirectUrl') === false,
      'the script must not touch the no-record input');
    $this->assertTrue(stripos($script, 'no_record_redirect_url') === false,
      'the script must not touch the no-record field container');
  }

  /**
   * The help text names the gate condition and that members logging in with
   * an unlinked identity are caught too.
   */
  public function testTheHelpTextNamesTheGateConditionAndUnlinkedIdentities() {
    $label = _txt('pl.oa4mp_client_authorization.fd.no_record_redirect_url');
    $desc = _txt('pl.oa4mp_client_authorization.fd.no_record_redirect_url.desc');

    $this->assertFalse($label === 'pl.oa4mp_client_authorization.fd.no_record_redirect_url',
      'the label resolves through lang.php');
    $this->assertContains('Authorized User Group or Require Active Status', $desc,
      'the description says the URL applies only when a gate is on');
    $this->assertContains('not linked to their', $desc,
      'the description names members logging in with an unlinked identity');
  }
}
