<?php
/**
 * The Claims form's warning about the reserved copy of the DynamoDB key claim
 * (cilogon/Oa4mpClient#67).
 *
 * The DynamoDB claims script keeps the original value of the client's key
 * claim in a claim named cilogon_<key claim>, so the Claims tab refuses that
 * name on save. The form warns first: while the typed name equals the
 * reserved one it shows a message under the field and disables the submit
 * button, and it does nothing for a client with no reserved name. The save
 * refusal stays the real guard; it is covered in
 * Test/Case/Controller/ClaimsWritePathTest.php.
 *
 * The add and edit actions are driven through the claims controller harness
 * for the view variable, and the form is rendered through a bare View with
 * the variables the action set. The runner has no browser, so the script's
 * behavior is checked in source: these tests catch a missing variable, string
 * or wiring, not how a browser runs the script.
 */

App::uses('View', 'View');
App::uses('CakeRequest', 'Network');

class ClaimReservedNameWarningTest extends Oa4mpClaimsControllerTestCase {

  const RESERVED_VAR = 'vv_reserved_claim_name';
  const WARNING_KEY = 'pl.oa4mp_client_claim.reserved_name.warning';

  protected function fixtureTagPrefix() {
    return 'reservedname';
  }

  /**
   * The add form, its re-render after a refused save, and the edit form all
   * carry the reserved name of a client keyed on uid by its own DynamoDB
   * configuration -- not the admin default's cilogon_sub.
   */
  public function testAddAndEditFormsCarryThePerClientReservedName() {
    $this->seedPerClientDynamoConfig();

    $add = $this->harness();
    $this->assertNull($add->harnessInvoke('add', array(), 'GET'), 'the add form rendered');
    $this->assertEqual('cilogon_uid', $add->viewVars[self::RESERVED_VAR] ?? null,
      'the add form is given cilogon_uid');

    $reRender = $this->harness($this->addData('writepath_added'));
    $reRender->harnessServer->editClientReturn = 0;
    $this->assertNull($reRender->harnessInvoke('add', array(), 'POST'),
      'a save the server rejected renders the add form again');
    $this->assertEqual('cilogon_uid', $reRender->viewVars[self::RESERVED_VAR] ?? null,
      'the re-rendered add form is given cilogon_uid');

    $edit = $this->harness();
    $this->assertNull($edit->harnessInvoke('edit', array($this->claimId), 'GET'), 'the edit form rendered');
    $this->assertEqual('cilogon_uid', $edit->viewVars[self::RESERVED_VAR] ?? null,
      'the edit form is given cilogon_uid');
  }

  /**
   * The rendered form of a client keyed on uid carries cilogon_uid and the
   * warning text naming it into its script, and the message element sits
   * under the claim name field.
   */
  public function testRenderedFormCarriesTheReservedNameAndTheWarning() {
    $this->seedPerClientDynamoConfig();

    $harness = $this->harness();
    $harness->harnessInvoke('add', array(), 'GET');
    $html = $this->renderForm($harness->viewVars);

    $this->assertContains('const reservedClaimName = "cilogon_uid";', $html,
      'the script is given the reserved name');

    $warning = _txt(self::WARNING_KEY, array('cilogon_uid'));
    $this->assertFalse(_txt(self::WARNING_KEY) === self::WARNING_KEY,
      'the warning resolves through lang.php');
    $this->assertContains('cilogon_uid', $warning, 'the warning names the reserved claim');
    $this->assertContains('const reservedClaimNameMessage = ' . $this->jsonString($warning) . ';', $html,
      'the script is given the warning text');

    $this->assertTrue((bool)preg_match(
      '#<div id="ClaimNameOverrideMessage"[^>]*></div>\s*'
      . '<div id="ClaimNameReservedMessage" class="oa4mp-override-message" style="display:none;"></div>#',
      $html), 'the reserved-name message sits beside the override message, hidden and styled like it');
  }

  /** A client with no DynamoDB configuration is given no reserved name. */
  public function testFormForAClientWithoutDynamoConfigCarriesNoReservedName() {
    $this->removeDefaultDynamoConfig();

    $harness = $this->harness();
    $this->assertNull($harness->harnessInvoke('add', array(), 'GET'), 'the add form rendered');
    $this->assertTrue(array_key_exists(self::RESERVED_VAR, $harness->viewVars),
      'the action still sets the variable');
    $this->assertNull($harness->viewVars[self::RESERVED_VAR], 'no name is reserved');

    $html = $this->renderForm($harness->viewVars);
    $this->assertContains('const reservedClaimName = null;', $html,
      'the script is given no reserved name');
  }

  /**
   * The script compares the name exactly, untrimmed, on input and change and
   * once on load, does nothing without a reserved name, and on a match shows
   * the message and disables the submit button, otherwise hides the message
   * and enables the button. The message is never faded out.
   */
  public function testScriptDisablesSubmitOnAMatchAndEnablesItOtherwise() {
    $source = $this->formSource();

    $body = $this->jsFunctionBody($source, 'checkReservedClaimName');
    $this->assertNotEmpty($body, 'the script has a checkReservedClaimName() function');

    $this->assertTrue((bool)preg_match('/if\(!reservedClaimName\) \{\s*return;\s*\}/', $body),
      'the check does nothing when no name is reserved');
    $this->assertContains("(value || '') === reservedClaimName", $body,
      'the name is compared exactly, as the save and the DynamoDB claims script compare it');
    $this->assertTrue((bool)preg_match(
      '/=== reservedClaimName\) \{[^}]*\.show\(\);[^}]*\.prop\(\'disabled\', true\);[^}]*\} else \{'
      . '[^}]*\.hide\(\);[^}]*\.prop\(\'disabled\', false\);[^}]*\}/s', $body),
      'a match shows the message and disables submit; anything else hides it and enables submit');
    $this->assertContains("closest('form').find(':submit')", $body,
      'the button disabled is the submit control of the claim form');
    $this->assertFalse(strpos($body, 'fadeOut') !== false || strpos($body, 'delay(') !== false,
      'the message stays visible while the name matches');

    $onload = $this->jsFunctionBody($source, 'js_local_onload');
    $this->assertNotEmpty($onload, 'premise: the script has js_local_onload()');
    $this->assertTrue((bool)preg_match(
      '/\$claimNameField\.on\(\'input change\', function\(\) \{\s*checkReservedClaimName\(\$\(this\)\.val\(\)\);\s*\}\);/',
      $onload), 'the check runs on input and change');
    $this->assertContains('checkReservedClaimName($claimNameField.val());', $onload,
      'the check runs once on load, so an edit form opened on a reserved name warns');
  }

  /** Both strings reach the script through json_encode(), from lang.php. */
  public function testScriptStringsComeFromLangThroughJsonEncode() {
    $source = $this->formSource();

    $this->assertContains('json_encode($vv_reserved_claim_name ?? null, $jsonFlags)', $source,
      'the reserved name is passed through json_encode()');
    $this->assertContains("json_encode(_txt('" . self::WARNING_KEY . "', array(\$vv_reserved_claim_name ?? '')), \$jsonFlags)",
      $source, 'the warning text comes from lang.php through json_encode()');
  }

  // ==========================================================================
  // Helpers (must not begin with "test": the runner would call them)
  // ==========================================================================

  /** A well-formed add() body for a claim with the given name. */
  private function addData($claimName) {
    return array(
      'Oa4mpClientClaim' => array(
        'client_id' => $this->clientId,
        'claim_name' => $claimName,
        'source_model' => 'Identifier',
        'source_model_claim_value_field' => 'identifier',
        'claim_value_selection' => 'first',
        'claim_value_json_format' => 'string'
      ),
      'Oa4mpClientClaimConstraint' => array()
    );
  }

  /** The Claims form template's source. */
  private function formSource() {
    $path = App::pluginPath('Oa4mpClient') . 'View' . DS . 'Oa4mpClientClaims' . DS . 'fields.inc';
    $this->assertTrue(is_readable($path), "the Claims form exists at $path");
    return file_get_contents($path);
  }

  /**
   * Render fields.inc the way the add view includes it, with the action's
   * view variables extracted into its scope.
   */
  private function renderForm($viewVars) {
    $view = new View(null);
    $view->request = new CakeRequest('/', false);
    $view->request->params = array(
      'plugin' => 'oa4mp_client',
      'controller' => 'oa4mp_client_claims',
      'action' => 'add',
      'named' => array('clientid' => $this->clientId)
    );
    $view->request->data = array();
    $view->action = 'add';
    $view->loadHelper('Html');
    $view->loadHelper('Form');

    $path = App::pluginPath('Oa4mpClient') . 'View' . DS . 'Oa4mpClientClaims' . DS . 'fields.inc';
    $this->assertTrue(is_readable($path), "the Claims form exists at $path");

    $vars = $viewVars + array(
      'permissions' => array('add' => true, 'edit' => true, 'view' => true),
      'cur_co' => array('Co' => array('id' => $this->coId))
    );

    $include = function ($__path, $__vars) {
      extract($__vars);
      ob_start();
      print $this->Form->create('Oa4mpClientClaim');
      include $__path;
      print $this->Form->end();
      return ob_get_clean();
    };

    return Closure::bind($include, $view, 'View')($path, $vars);
  }

  /** A string as fields.inc encodes it into the script. */
  private function jsonString($text) {
    return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  }

  /**
   * The body of one JavaScript function in $source, from its signature to
   * its closing brace, or null when it is not found.
   */
  private function jsFunctionBody($source, $name) {
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
      return null;
    }

    $start = strpos($source, '{', $m[0][1]);
    $depth = 0;
    $len = strlen($source);
    for ($i = $start; $i < $len; $i++) {
      if ($source[$i] === '{') {
        $depth++;
      } elseif ($source[$i] === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($source, $m[0][1], $i - $m[0][1] + 1);
        }
      }
    }

    return null;
  }
}
