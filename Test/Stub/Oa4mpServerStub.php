<?php
/**
 * OA4MP server stub for the hermetic tier (U2, KTD3).
 *
 * Most core-logic tests assert the plugin's *marshalled* cfg and database state
 * directly and never contact a server, so they need no stub. This stub is for
 * the few tests that must simulate a server read/response: it returns responses
 * captured from a real OA4MP server (Test/fixtures/oa4mp-responses/) keyed by a
 * scenario name, so a test locks the plugin against the server's actual
 * behavior rather than a hand-authored guess (the trap the public-client
 * cfg-rejection regression must avoid -- see plan R4).
 */

class Oa4mpServerStub {

  /** Return the captured response body for a named scenario, decoded to an array. */
  public static function response($scenario) {
    $path = dirname(dirname(__FILE__)) . DS . 'fixtures' . DS . 'oa4mp-responses'
      . DS . $scenario . '.json';
    if (!is_readable($path)) {
      throw new Exception("No captured OA4MP response for scenario '$scenario' at $path");
    }
    return json_decode(file_get_contents($path), true);
  }
}
