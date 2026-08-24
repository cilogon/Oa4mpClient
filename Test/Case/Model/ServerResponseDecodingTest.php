<?php
/**
 * How oa4mpVerifyClient() decodes the OA4MP server's response.
 *
 * The server may answer in ISO-8859-1. json_decode() accepts only UTF-8 and
 * returns null on anything else, so a Latin-1 body carrying a non-ASCII byte --
 * an accented character in a client name or comment -- decodes to null unless
 * it is transcoded first. The plugin has had a branch for that since
 * 2026-01-13, and it did nothing: an unconditional
 *
 *     $oa4mpObject = json_decode($response->body(), true);
 *
 * sat directly below the branch and overwrote the transcoded value on every
 * call, so the ISO-8859-1 arm was dead code. On the common ASCII path both
 * arms compute the same thing, which is why it never looked wrong.
 *
 * What it cost: the null flowed into compareToServerObject(), which
 * unmarshalled it. That either raised -- reported since 2026-08-24 as a check
 * that could not complete -- or compared as a difference and told the user
 * their client had been modified outside the Registry. Neither was true; the
 * server had simply answered in Latin-1.
 *
 * The decode is driven through decodeServerResponse() rather than through
 * oa4mpVerifyClient(), because that method constructs an HttpSocket and reaches
 * a real server. That seam is the point: the defect survived seven months
 * precisely because no hermetic test could observe which branch a response
 * selects. Same reason compareToServerObject() was split out before it.
 *
 * See docs/solutions/logic-errors/oa4mp-verify-response-encoding-discarded-2026-08-24.md.
 */

App::uses('Oa4mpClientOa4mpServer', 'Oa4mpClient.Model');

/**
 * Stand-in for HttpSocketResponse. The decode reads exactly two things off a
 * response -- one header and the body -- so this supplies exactly those.
 *
 * Local to this file: it exists for the tests below, not as shared
 * infrastructure.
 */
class Oa4mpFakeServerResponse {

  /** @var string Raw body bytes, in whatever encoding the test is exercising. */
  public $bodyBytes = '';

  /** @var mixed Content-Type value, or false for a response carrying none. */
  public $contentType = false;

  public function __construct($bodyBytes, $contentType = false) {
    $this->bodyBytes = $bodyBytes;
    $this->contentType = $contentType;
  }

  public function getHeader($name) {
    return $name === 'Content-Type' ? $this->contentType : false;
  }

  public function body() {
    return $this->bodyBytes;
  }
}

/** The model with decodeServerResponse() reachable by name. */
class Oa4mpResponseDecodingProbe extends Oa4mpClientOa4mpServer {

  public function decodeWith($response) {
    return $this->decodeServerResponse($response);
  }
}

class ServerResponseDecodingTest extends Oa4mpTestCase {

  /**
   * Nothing here holds instance state; every probe and response is built
   * inside the method that uses it.
   */
  public function setUp() {
  }

  /**
   * The name the tests below round-trip. Written as an escape rather than a
   * literal so this file stays ASCII and the byte under test is unambiguous:
   * U+00E9, LATIN SMALL LETTER E WITH ACUTE.
   */
  private function accentedName() {
    return "Andr\u{00E9} Test Client";
  }

  /**
   * That name's JSON document, encoded as UTF-8.
   *
   * JSON_UNESCAPED_UNICODE is load-bearing, not a style choice. Without it
   * json_encode() escapes the accent to the ASCII sequence é, the body
   * carries no non-ASCII byte at all, and the Latin-1 test below passes
   * against the very defect it exists to catch -- which is exactly what it did
   * on the first attempt.
   */
  private function utf8Body() {
    return json_encode(array('client_id' => 'cilogon:/client_id/abc',
                             'client_name' => $this->accentedName()),
                       JSON_UNESCAPED_UNICODE);
  }

  /** The same document, transcoded to ISO-8859-1 the way the server sends it. */
  private function latin1Body() {
    return mb_convert_encoding($this->utf8Body(), 'ISO-8859-1', 'UTF-8');
  }

  // ------------------------------------------------------------------
  // The regression.
  // ------------------------------------------------------------------

  /**
   * A Latin-1 response carrying a non-ASCII byte decodes to the client it
   * describes.
   *
   * This is the assertion the discarded branch failed: before the fix the
   * transcoded value was overwritten by a raw re-decode, and the raw bytes are
   * not valid UTF-8, so the result was null.
   */
  public function testALatin1ResponseDecodesRatherThanCollapsingToNull() {
    $probe = new Oa4mpResponseDecodingProbe();
    $response = new Oa4mpFakeServerResponse($this->latin1Body(),
      'application/json; charset=ISO-8859-1');

    // Guard the fixture itself. If the body ever stops carrying a byte above
    // 0x7F, every assertion below still passes while testing nothing.
    $this->assertTrue(preg_match('/[\x80-\xFF]/', $this->latin1Body()) === 1,
      'the fixture body actually carries a non-ASCII byte, without which this'
      . ' test cannot fail against the defect');

    $decoded = $probe->decodeWith($response);

    $this->assertTrue($decoded !== null,
      'a Latin-1 response is transcoded before decoding, so it does not'
      . ' collapse to null and reach the comparison as a phantom difference');
    $this->assertEqual($this->accentedName(), $decoded['client_name'],
      'and the accented character survives the transcode intact');
  }

  /**
   * The control, and the reason the bug was invisible: on a body with no
   * non-ASCII byte both arms agree, so an ASCII client never showed the defect
   * however it was declared.
   */
  public function testAnAsciiBodyDecodesTheSameUnderEitherDeclaredEncoding() {
    $probe = new Oa4mpResponseDecodingProbe();
    $ascii = json_encode(array('client_id' => 'cilogon:/client_id/abc',
                               'client_name' => 'Plain Test Client'));

    $asLatin1 = $probe->decodeWith(
      new Oa4mpFakeServerResponse($ascii, 'application/json; charset=ISO-8859-1'));
    $asUtf8 = $probe->decodeWith(
      new Oa4mpFakeServerResponse($ascii, 'application/json; charset=UTF-8'));

    $this->assertEqual($asLatin1, $asUtf8,
      'both arms agree on an ASCII body, which is why a dead branch here went'
      . ' unnoticed for seven months');
    $this->assertEqual('Plain Test Client', $asUtf8['client_name'],
      'and the body decoded at all');
  }

  // ------------------------------------------------------------------
  // The ordinary paths, so the fix cannot regress them.
  // ------------------------------------------------------------------

  /** A UTF-8 response decodes, accented characters included. */
  public function testAUtf8ResponseDecodes() {
    $probe = new Oa4mpResponseDecodingProbe();

    $decoded = $probe->decodeWith(
      new Oa4mpFakeServerResponse($this->utf8Body(), 'application/json; charset=UTF-8'));

    $this->assertEqual($this->accentedName(), $decoded['client_name'],
      'a UTF-8 response is decoded as-is');
  }

  /**
   * A response declaring no Content-Type at all is treated as UTF-8.
   *
   * getHeader() returns false there, so this also covers the cast: without it
   * str_contains() would be handed a boolean.
   */
  public function testAResponseWithNoContentTypeIsTreatedAsUtf8() {
    $probe = new Oa4mpResponseDecodingProbe();

    $decoded = $probe->decodeWith(new Oa4mpFakeServerResponse($this->utf8Body()));

    $this->assertEqual($this->accentedName(), $decoded['client_name'],
      'a response with no declared encoding decodes as UTF-8');
  }

  /**
   * A body that is not JSON decodes to null rather than raising.
   *
   * The caller relies on this: compareToServerObject() is what turns an
   * unusable representation into a reported internal error, and it can only do
   * that if the decode returns instead of throwing.
   */
  public function testAnUndecodableBodyReturnsNullWithoutRaising() {
    $probe = new Oa4mpResponseDecodingProbe();

    $decoded = $probe->decodeWith(
      new Oa4mpFakeServerResponse('<html>502 Bad Gateway</html>', 'text/html'));

    $this->assertTrue($decoded === null,
      'an undecodable body returns null rather than raising, which is what'
      . ' lets the comparison report it');
  }
}
