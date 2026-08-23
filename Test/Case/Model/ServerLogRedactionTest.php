<?php
/**
 * Regression tests for credential redaction in the OA4MP server model's
 * logging (Model/Oa4mpClientOa4mpServer.php).
 *
 * The model logs the request body it sends and a full dump of the
 * HttpSocketResponse it gets back. Both carry credentials: a create response
 * carries the new client's client_secret, and a client with a DynamoDB
 * configuration carries AWS keys in the cfg it sends and reads back. The
 * live-server tier writes those logs into a GitHub Actions log on a public
 * repository, and the workflow's own secret masking does not cover them
 * because the values come from the server, not from `secrets.*`.
 *
 * The first real live-server run (2026-08-23) printed three client secrets in
 * full, which is the defect these tests lock.
 *
 * See docs/plans/2026-08-19-0342-test-plugin-test-suite-plan.md U9 (R11, R12).
 */

class ServerLogRedactionTest extends Oa4mpTestCase {

  private function server() {
    return $this->model('Oa4mpClient.Oa4mpClientOa4mpServer');
  }

  /**
   * The redactor is private: it is an internal logging concern, not API. Drive
   * it through reflection rather than widening its visibility for the tests
   * (the same approach ClaimsControllerHarnessTest takes for _oa4mpServer()).
   */
  private function redact($text) {
    $method = new ReflectionMethod('Oa4mpClientOa4mpServer', 'redactSecretsInLogText');
    $method->setAccessible(true);

    return $method->invoke($this->server(), $text);
  }

  /**
   * A create response in the shape dev.cilogon.org actually returns one.
   *
   * Every credential value here is a short synthetic token, deliberately:
   * the secret-scan job in the hermetic gate scans the full history, so a
   * realistic-looking value committed once is found forever. Keep them short
   * and obviously fake -- what these tests need is a distinguishable string,
   * not a plausible credential.
   */

  private function createResponseBody() {
    return '{"client_id":"cilogon:oa4mp,2012:/client_id/267d3c291b982f51bcac74766962785"'
      . ',"client_secret":"SECRET-A"'
      . ',"client_id_issued_at":1787484004,"client_secret_expires_at":0'
      . ',"scope":"openid"}';
  }

  public function testClientSecretIsRedactedFromAResponseBody() {
    $redacted = $this->redact($this->createResponseBody());

    $this->assertFalse(strpos($redacted, 'SECRET-A') !== false,
      'the client secret must not survive redaction');
    $this->assertContains('"client_secret":"[REDACTED]"', $redacted,
      'the client_secret value must be replaced by the sentinel');
  }

  /**
   * Redaction must not damage the rest of the log line: the identifiers and
   * timestamps around the secret are what make the log worth keeping.
   */
  public function testNonSecretFieldsSurviveRedaction() {
    $redacted = $this->redact($this->createResponseBody());

    $this->assertContains('"client_id":"cilogon:oa4mp,2012:/client_id/267d3c291b982f51bcac74766962785"',
      $redacted, 'the client identifier must be preserved');
    $this->assertContains('"client_id_issued_at":1787484004', $redacted,
      'a non-secret numeric field must be preserved');
    $this->assertContains('"scope":"openid"', $redacted,
      'the scope must be preserved');
  }

  /**
   * client_secret_expires_at contains the substring client_secret. Redaction
   * keys off the whole JSON key, so the expiry must be left alone.
   */
  public function testAKeyThatMerelyStartsWithASecretKeyIsNotRedacted() {
    $redacted = $this->redact($this->createResponseBody());

    $this->assertContains('"client_secret_expires_at":0', $redacted,
      'client_secret_expires_at is not a credential and must not be touched');
  }

  /**
   * A DynamoDB cfg carries AWS credentials under the module's own key names
   * (access_key_id / secret_access_key), not the plugin's column names, and it
   * travels in the request body of every create and edit for such a client.
   */
  public function testDynamoCredentialsAreRedactedUnderBothKeyNames() {
    $body = '{"cfg":{"dynamo":{"access_key_id":"KEY-A"'
      . ',"secret_access_key":"SECRET-B","table_name":"clients"}}'
      . ',"aws_access_key_id":"KEY-B"'
      . ',"aws_secret_access_key":"SECRET-C"}';

    $redacted = $this->redact($body);

    foreach (array('KEY-A', 'SECRET-B', 'KEY-B', 'SECRET-C') as $secret) {
      $this->assertFalse(strpos($redacted, $secret) !== false,
        "$secret must not survive redaction");
    }

    $this->assertContains('"table_name":"clients"', $redacted,
      'a non-secret cfg field must be preserved');
  }

  /** The token that authorizes managing a client is a credential too. */
  public function testRegistrationAccessTokenIsRedacted() {
    $redacted = $this->redact('{"registration_access_token":"TOKEN-A"}');

    $this->assertContains('"registration_access_token":"[REDACTED]"', $redacted,
      'the registration access token must be replaced by the sentinel');
  }

  /**
   * A backslash-escaped quote inside a JSON string must not end the match
   * early, which would leave the tail of the secret in the log.
   */
  public function testAnEscapedQuoteInsideTheValueDoesNotEndRedactionEarly() {
    $redacted = $this->redact('{"client_secret":"abc\\"def","scope":"openid"}');

    $this->assertFalse(strpos($redacted, 'def') !== false,
      'the whole secret must be redacted, including past an escaped quote');
    $this->assertContains('"scope":"openid"', $redacted,
      'the field after the secret must still be intact');
  }

  /**
   * The redactor only helps where it is called. A response dump logged
   * directly would put a live client secret into a public CI log, so no such
   * call site may exist: every response and request-body log in the model must
   * route through redactSecretsInLogText().
   *
   * Asserted against the source because the alternative is an HTTP round trip
   * to a real server, which the hermetic tier must never make.
   */
  public function testNoResponseOrRequestBodyIsLoggedUnredacted() {
    $source = file_get_contents(App::pluginPath('Oa4mpClient')
      . 'Model' . DS . 'Oa4mpClientOa4mpServer.php');

    $this->assertNotEmpty($source, 'the model source must be readable');

    foreach (array('print_r($response, true)', "print_r(\$request['body'], true)") as $dump) {
      $offset = 0;
      while (($at = strpos($source, $dump, $offset)) !== false) {
        $line = substr($source, strrpos(substr($source, 0, $at), "\n") + 1,
          $at - strrpos(substr($source, 0, $at), "\n") - 1);
        $this->assertContains('redactSecretsInLogText', $line,
          "a log of $dump must be wrapped in redactSecretsInLogText()");
        $offset = $at + strlen($dump);
      }
    }
  }
}
