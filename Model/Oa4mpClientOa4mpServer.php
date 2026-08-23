<?php
/**
 * COmanage Registry Oa4mp Client Plugin OA4MP Server Model
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugin
 * @since         COmanage Registry v4.2.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("HttpSocket", "Network/Http");

class Oa4mpClientOa4mpServer extends AppModel {
  public $useTable = false;

  /**
   * Decoded cfg capability contracts, keyed by the path each was read from.
   *
   * The document is small and every claim mapping consults it, so it is read
   * once per path rather than once per claim. Keying on the path rather than
   * on a bare flag keeps a subclass that points cfgContractPath() somewhere
   * else from being handed the default document's cache entry.
   *
   * @var array
   */
  private $cfgContractCache = array();

  /**
   * The prefix every withheld-value signal line carries, so the line can be
   * recognized without matching the whole sentence.
   */
  const CFG_WITHHELD_SIGNAL = 'Oa4mpClientClaim: cfg capability contract';

  /**
   * The one claim-mapping field the marshaller synthesises rather than reading
   * off a claim column: the mapping's claim_constraints list, built from the
   * claim's Oa4mpClientClaimConstraint rows. The contract declares it last and
   * marks it column_backed false; see its claim_mapping_fields note.
   */
  const CFG_CONSTRAINTS_FIELD = 'claim_constraints';

  /**
   * Path to the cfg capability contract document.
   *
   * protected, not private, on purpose. The suite has no mocking framework, so
   * a test that needs the reader pointed at a fixture -- an unreadable or a
   * malformed contract -- subclasses this model and overrides this one method.
   * Test/Case/Model/ClaimConstraintSymmetryTest.php overrides log() the same
   * way and for the same reason.
   *
   * @return string Absolute path to cfg_contract.json.
   * @since COmanage Registry 4.5.1
   */

  protected function cfgContractPath() {
    return App::pluginPath('Oa4mpClient') . 'cfg_contract.json';
  }

  /**
   * The decoded cfg capability contract.
   *
   * Every enforcement site that asks what this plugin may put in a cfg comes
   * through here, so an unreadable or unparseable document raises rather than
   * resolving to an empty array. An allowlist reads an empty contract as "emit
   * nothing" and a redaction list reads it as "redact nothing"; both are
   * silent, and either is worse than a stopped edit.
   *
   * @return array The decoded contract.
   * @throws RuntimeException If the document cannot be read or is not a usable
   *                          contract.
   * @since COmanage Registry 4.5.1
   */

  protected function cfgContract() {
    $path = $this->cfgContractPath();

    if(array_key_exists($path, $this->cfgContractCache)) {
      return $this->cfgContractCache[$path];
    }

    $text = is_readable($path) ? file_get_contents($path) : false;

    if($text === false) {
      throw new RuntimeException("Oa4mpClient cfg capability contract cannot be read at " . $path);
    }

    $contract = json_decode($text, true);

    if(!is_array($contract)
       || !isset($contract['contract_version'])
       || empty($contract['capabilities'])) {
      throw new RuntimeException("Oa4mpClient cfg capability contract at " . $path
                                 . " is not a usable contract document: "
                                 . json_last_error_msg());
    }

    $this->cfgContractCache[$path] = $contract;

    return $contract;
  }

  /**
   * The version of the capability contract a cfg marshalled now is built to.
   *
   * @return mixed The declared contract_version.
   * @since COmanage Registry 4.5.1
   */

  public function cfgContractVersion() {
    $contract = $this->cfgContract();

    return $contract['contract_version'];
  }

  /**
   * The names one capability group declares, in declaration order, excluding
   * any entry that has been retired.
   *
   * Declaration order is load-bearing for claim_mapping_fields: it is the
   * order a marshalled mapping carries, and the suite's assertEqual is key
   * -order sensitive. Retired entries stay in the document so an older cfg
   * remains interpretable, but they are not emitted, so they are not returned.
   *
   * @param string $group Capability group name, e.g. 'claim_mapping_fields'.
   * @return array Declared names, in contract order.
   * @throws RuntimeException If the group is absent or carries a malformed entry.
   * @since COmanage Registry 4.5.1
   */

  public function cfgContractNames($group) {
    $contract = $this->cfgContract();

    if(!isset($contract['capabilities'][$group]['entries'])) {
      throw new RuntimeException("Oa4mpClient cfg capability contract declares no "
                                 . $group . " group, which the plugin looks up by name");
    }

    $names = array();

    foreach($contract['capabilities'][$group]['entries'] as $entry) {
      // A missing retired_in is a malformed entry and never an implied null;
      // see the contract's own entry_fields note. Defaulting it here would
      // emit a capability nothing declared was still live.
      if(!isset($entry['name']) || !array_key_exists('retired_in', $entry)) {
        throw new RuntimeException("Oa4mpClient cfg capability contract carries a malformed "
                                   . $group . " entry: every entry names itself and states"
                                   . " retired_in");
      }

      if($entry['retired_in'] !== null) {
        continue;
      }

      $names[] = $entry['name'];
    }

    return $names;
  }

  /**
   * The capability group enumerating the values $field may carry, or '' when
   * the contract enumerates no values for it.
   *
   * Derived from the contract rather than from a map kept here: an enumerated
   * -value group is named for the field it constrains, so source_model is
   * constrained by source_model_values and constraint_field by
   * constraint_field_values. A further such group added to the contract is
   * therefore enforced the day it is declared, with no edit in this file.
   *
   * @param string $field A declared field name.
   * @return string The value group's name, or ''.
   * @since COmanage Registry 4.5.1
   */

  private function cfgContractValueGroup($field) {
    $contract = $this->cfgContract();
    $group = $field . '_values';

    return isset($contract['capabilities'][$group]['entries']) ? $group : '';
  }

  /**
   * Whether the contract permits $field to carry $value.
   *
   * Called from the marshaller and from normalizeClaimForComparison(), which
   * is the whole point of it being one function: a value the marshaller
   * withholds never reaches the server, so a comparator that kept it would
   * compare the plugin's value against the nothing that was actually sent and
   * report the client out of sync on every verify pass, with no edit able to
   * repair it. That failure has shipped from this file three times.
   *
   * Free-text fields (a claim_name, a constraint_value) are permitted whatever
   * they carry: the contract enumerates names for them, not values.
   *
   * @param string $field A declared field name.
   * @param mixed $value The value the row carries.
   * @return boolean
   * @since COmanage Registry 4.5.1
   */

  private function cfgContractPermitsValue($field, $value) {
    $group = $this->cfgContractValueGroup($field);

    if($group === '') {
      return true;
    }

    return in_array($value, $this->cfgContractNames($group), true);
  }

  /**
   * Ensure a cfg carries an array-valued metadata.Oa4mpClient container.
   *
   * A named configuration's stored JSON is operator-authored and entirely
   * unvalidated: nothing on the way in requires metadata to be an object, or
   * requires metadata.Oa4mpClient to be one. Writing an array offset into a
   * string raises a TypeError under PHP 8, so a configuration carrying
   * "metadata": "anything" would take down the save rather than being saved.
   * A non-array value at either level is therefore replaced with an empty
   * array, which is the only reading that lets the client still be sent.
   *
   * @param array $cfg The cfg being marshalled.
   * @return array The same cfg with metadata.Oa4mpClient guaranteed an array.
   * @since COmanage Registry 4.5.1
   */

  private function normalizeCfgMetadataContainer($cfg) {
    if(!isset($cfg['metadata']) || !is_array($cfg['metadata'])) {
      $cfg['metadata'] = array();
    }

    if(!isset($cfg['metadata']['Oa4mpClient']) || !is_array($cfg['metadata']['Oa4mpClient'])) {
      $cfg['metadata']['Oa4mpClient'] = array();
    }

    return $cfg;
  }

  /**
   * Record the capability contract version this cfg was marshalled against.
   *
   * Every cfg the plugin sends carries the version, so the set of cfgs stored
   * on the OA4MP servers is a census of which contract versions are actually
   * deployed. That census cannot be reconstructed after the fact -- a cfg
   * written without the stamp never acquires one -- which is why the stamp
   * ships ahead of anything that reads it.
   *
   * It sits at metadata.Oa4mpClient.contract_version, not under
   * tokens.identity.qdl.args: the QDL args are the capabilities a tier's QDL
   * has to implement, and the version is a fact about the cfg rather than a
   * capability the QDL must understand. The namespace already exists and
   * already round-trips -- the named-configuration branch writes
   * metadata.Oa4mpClient.Oa4mpClientCoNamedConfig into it -- and cfg_schema.json
   * permits it through its top-level additionalProperties.
   *
   * The write is unconditional and overwrites. Operator-authored JSON that
   * happens to set the same key does not get a say, and because this runs
   * AFTER the named configuration has been merged, it cannot collide with that
   * merge: array_merge_recursive() merges rather than overwrites, so a stamp
   * written before the merge would leave an array of two values behind where a
   * scalar version belongs.
   *
   * @param array $cfg The cfg being marshalled.
   * @return array The same cfg carrying the contract version.
   * @since COmanage Registry 4.5.1
   */

  private function stampCfgContractVersion($cfg) {
    $cfg = $this->normalizeCfgMetadataContainer($cfg);

    $cfg['metadata']['Oa4mpClient']['contract_version'] = $this->cfgContractVersion();

    return $cfg;
  }

  /**
   * Keys a persisted claim or constraint row carries that are never candidates
   * for a cfg: the surrogate keys, the foreign keys, the timestamps, and the
   * contained constraint association.
   *
   * This is not the denylist the claim loop used to run on. That list decided
   * what was emitted, so a column added to either table reached the OA4MP
   * server the day it was added; the contract decides that now. This list only
   * decides which withheld keys are worth a signal, and every name on it is
   * structural -- present on every row, carrying nothing a cfg could express.
   * A new column is on none of them, so it is withheld AND reported.
   *
   * @return array Key names that never carry a capability.
   * @since COmanage Registry 4.5.1
   */

  private function cfgNonEmittedRowKeys() {
    return array(
      'id',
      'client_id',
      'claim_id',
      'created',
      'modified',
      'Oa4mpClientClaimConstraint',
    );
  }

  /**
   * The values one row contributes to a cfg, in the order the contract
   * declares them.
   *
   * Three rules, in this order, and all three are also the comparator's:
   *
   *  - A field the contract does not declare is never copied. That is the
   *    allowlist: adding a column to cm_oa4mp_client_claims or to
   *    cm_oa4mp_client_claim_constraints does not by itself put anything on
   *    the wire, and neither does adding a key to the QDL args block or to
   *    the DynamoDB module configuration the marshaller builds by hand.
   *  - An empty value is no value, and is omitted. Unchanged from the whole
   *    -row copy this replaced, and deliberately still empty() -- empty('0')
   *    is true, so a string-zero value is omitted here exactly as it was
   *    before, and normalizeClaimForComparison() asks the identical question.
   *    A mapping therefore need not carry every declared field; the contract
   *    fixes which fields may appear and in what order, not that they all do.
   *    $dropEmpty turns this rule off for the row shapes it does not describe;
   *    see below.
   *  - An enumerated field may only carry a value the contract declares.
   *
   * A value withheld by the last rule, and any value under a key the contract
   * does not declare that the first two rules would otherwise have emitted,
   * appends its FIELD NAME to $withheld. Never its value: this model's rows
   * can carry DynamoDB credentials, and the signal built from $withheld is
   * logged.
   *
   * $dropEmpty exists because "an empty value is no value" is a rule about a
   * CLAIM row, not about every row a cfg is built from. A claim mapping omits
   * an empty field and the comparator omits it on both sides, so the two
   * agree. The QDL args block and the DynamoDB module configuration are
   * different: the marshaller has always emitted partition_key_template,
   * partition_key_claim_name and all five module keys unconditionally, null
   * values included, and the unmarshaller reads two of them back without an
   * isset() guard. Dropping an empty one here would change what an existing
   * client sends. Those callers pass false, and express optionality by
   * leaving the key off the row they hand in rather than by handing in an
   * empty value -- which is exactly how the four conditional authorization
   * args keep their conditionality.
   *
   * @param array $row One row -- a claim, a constraint, the QDL args block, or
   *                   the DynamoDB module configuration.
   * @param array $declared The field names the contract declares, in order.
   * @param array &$withheld Collects the name of every field whose value was
   *                         withheld. Names only, never values.
   * @param array $synthesised Values the marshaller supplies rather than
   *                           reading off the row, keyed by declared field name.
   * @param boolean $dropEmpty Whether an empty value is omitted. True for the
   *                           claim and constraint rows; false for the rows
   *                           whose keys are emitted unconditionally.
   * @return array The values to emit, in contract order.
   * @since COmanage Registry 4.5.1
   */

  private function marshallDeclaredRow($row, $declared, &$withheld, $synthesised = array(),
                                       $dropEmpty = true) {
    $marshalled = array();

    foreach($declared as $field) {
      if(array_key_exists($field, $synthesised)) {
        $value = $synthesised[$field];
      } elseif(array_key_exists($field, $row)) {
        $value = $row[$field];
      } else {
        continue;
      }

      if($dropEmpty && empty($value)) {
        continue;
      }

      if(!$this->cfgContractPermitsValue($field, $value)) {
        $withheld[] = $field;
        continue;
      }

      $marshalled[$field] = $value;
    }

    // Whatever the row carries beyond the declaration, tested with the same
    // emptiness rule the copy above uses, so the two sides of "would this have
    // been emitted" ask one question. On a claim row a key holding nothing was
    // never going to reach the server, and reporting it withheld would make
    // the signal noise; on a row whose keys are emitted unconditionally it
    // WOULD have reached the server, so it is reported however empty it is.
    foreach($row as $key => $value) {
      if(in_array($key, $declared, true)
         || in_array($key, $this->cfgNonEmittedRowKeys(), true)
         || ($dropEmpty && empty($value))) {
        continue;
      }

      $withheld[] = $key;
    }

    return $marshalled;
  }

  /**
   * Return a copy of $row with known secret-bearing field values replaced
   * by a redaction sentinel.
   *
   * Used by isClientDataSynchronized() and any future caller that logs a
   * row that may contain a credential field. Centralizes the list of
   * field names treated as secret so adding a new secret-bearing field
   * to a logged structure only requires updating this list.
   *
   * Null-safe: a missing key stays missing; an explicit null value is
   * left as null (no need to redact what is already empty).
   *
   * @param array $row An associative array, e.g. an Oa4mpClientDynamoConfig row.
   * @return array A copy of $row with secret-field values replaced by '[REDACTED]'.
   */

  private function redactSecrets($row) {
    foreach($this->loggingSecretFieldNames() as $field) {
      if(isset($row[$field])) {
        $row[$field] = '[REDACTED]';
      }
    }

    return $row;
  }

  /**
   * Names whose values are credentials and must never reach a log.
   *
   * The single enforcement point for both redaction helpers: redactSecrets()
   * matches these as array keys, redactSecretsInLogText() matches them as JSON
   * keys.
   *
   * The list is a union of two halves that are maintained differently, and the
   * split is the point:
   *
   *  - The cfg-side half is DERIVED from cfg_contract.json's secret_bearing
   *    flag, not written here. A credential-carrying capability therefore
   *    cannot be declared without being redacted; declaring it is the whole
   *    edit. See cfgContractSecretNames().
   *  - The other half is literal, because those names never appear in a cfg:
   *    the plugin's own column names and the credentials the OA4MP server
   *    returns in a response. The contract declares only what the plugin emits
   *    INTO a cfg, so widening it to cover them would misstate what it is.
   *    See uncontractedSecretFieldNames().
   *
   * An empty cfg-side derivation raises rather than quietly leaving the union
   * as the literals alone. The contract declares two secret-bearing keys, so
   * deriving none means the reader or the document is broken -- and without
   * the raise, that disarmed state looks exactly like a healthy one, which is
   * the failure shape documented in
   * docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md.
   *
   * The raise never reaches a caller: both redaction helpers go through
   * loggingSecretFieldNames(), which catches it, logs it, and falls back. In
   * production the defect therefore surfaces as that logged line rather than
   * as an aborted client save or an unredacted log.
   *
   * @return array Field and JSON key names treated as secret.
   * @throws RuntimeException If the contract cannot be read, or declares no
   *                          secret-bearing capability at all.
   */

  private function secretFieldNames() {
    $cfgNames = $this->cfgContractSecretNames();

    if(empty($cfgNames)) {
      throw new RuntimeException("Oa4mpClient cfg capability contract at "
                                 . $this->cfgContractPath() . " declares no secret-bearing"
                                 . " capability, so the cfg-side half of the log-redaction"
                                 . " list derived from it is empty. The contract declares the"
                                 . " two AWS credential keys; deriving none is a defect, not a"
                                 . " cfg vocabulary that carries no credentials");
    }

    return array_values(array_unique(array_merge($cfgNames,
                                                 $this->uncontractedSecretFieldNames())));
  }

  /**
   * The cfg-side secret names, read out of the capability contract.
   *
   * Every capability group is scanned, not just the DynamoDB one: what makes a
   * name belong here is the secret_bearing flag, so a credential declared in
   * some future group is redacted the day it is declared, with no edit in this
   * file. A missing flag is a malformed entry and never an implied false; the
   * contract's own entry_fields note says so, and defaulting it here would
   * silently drop a credential out of the redaction list.
   *
   * Unlike cfgContractNames(), a RETIRED entry is kept. The plugin no longer
   * emits it, but a cfg written before the retirement is still stored on an
   * OA4MP server and is still logged when it is read back, so its credential
   * still has to be masked. Redaction wants every name a cfg may CARRY; the
   * allowlist wants only the names the plugin may WRITE.
   *
   * @return array Secret-bearing names the contract declares, deduplicated.
   * @throws RuntimeException If the contract cannot be read, or carries an
   *                          entry with no secret_bearing flag.
   * @since COmanage Registry 4.5.1
   */

  private function cfgContractSecretNames() {
    $contract = $this->cfgContract();

    $names = array();

    foreach($contract['capabilities'] as $group => $declaration) {
      if(empty($declaration['entries'])) {
        continue;
      }

      foreach($declaration['entries'] as $entry) {
        if(!isset($entry['name']) || !array_key_exists('secret_bearing', $entry)) {
          throw new RuntimeException("Oa4mpClient cfg capability contract carries a malformed "
                                     . $group . " entry: every entry names itself and states"
                                     . " secret_bearing, which must not default to false");
        }

        if($entry['secret_bearing'] !== true) {
          continue;
        }

        $names[] = $entry['name'];
      }
    }

    return array_values(array_unique($names));
  }

  /**
   * The secret names that have no cfg counterpart, and so are not derivable
   * from the capability contract.
   *
   * Deliberately literal, and deliberately small. Neither vocabulary here ever
   * appears inside a cfg: the first is how the plugin's own table names these
   * columns, the second is what the OA4MP server puts in a RESPONSE. The
   * contract declares what the plugin emits into a cfg and nothing else, so
   * neither belongs in it.
   *
   * @return array Secret names with no cfg counterpart.
   * @since COmanage Registry 4.5.1
   */

  private function uncontractedSecretFieldNames() {
    return array(
      // Oa4mpClientDynamoConfig column names, as the plugin persists them.
      // The cfg spells the same two credentials access_key_id and
      // secret_access_key; those come from the contract.
      'aws_access_key_id',
      'aws_secret_access_key',
      // Credentials the OA4MP server issues and returns in a response body:
      // the client's own secret, and the token that authorizes managing the
      // client at the registration endpoint.
      'client_secret',
      'registration_access_token',
    );
  }

  /**
   * The redaction list as the logging helpers must have it: never raising.
   *
   * secretFieldNames() raises on a contract it cannot read or trust, which is
   * right for a defect detector and wrong here. Both callers are about to log
   * a body that carries credentials, so an exception would either abort a
   * client save or leave the text unredacted -- reintroducing the leak fixed
   * in 7.0.0-rc6. On any failure the full literal list is used instead,
   * cfg-side names included, and the failure is logged rather than swallowed.
   *
   * The two cfg-side names are repeated in fallbackSecretFieldNames() rather
   * than derived, on purpose: the fallback exists precisely for the case where
   * the derivation is unavailable.
   *
   * @return array Field and JSON key names to mask.
   * @since COmanage Registry 4.5.1
   */

  private function loggingSecretFieldNames() {
    try {
      return $this->secretFieldNames();
    } catch(Throwable $e) {
      // Throwable, not Exception: a malformed document can fail in ways that
      // are Errors rather than Exceptions, and nothing about a broken contract
      // may stop this line from being masked.
      $this->log("Oa4mpClient could not derive the cfg-side log-redaction names from the"
                 . " capability contract; falling back to the literal list so this line is"
                 . " still redacted: " . $e->getMessage());

      return $this->fallbackSecretFieldNames();
    }
  }

  /**
   * The redaction list used when the contract cannot be consulted.
   *
   * The cfg-side names are literal here and only here. This list is what
   * secretFieldNames() returned before the contract existed, so an unreadable
   * contract degrades to the redaction the plugin has always performed.
   *
   * @return array Every secret name, literal.
   * @since COmanage Registry 4.5.1
   */

  private function fallbackSecretFieldNames() {
    return array_merge(
      array(
        // The two credentials as a cfg spells them; normally derived from
        // cfg_contract.json's secret_bearing flag.
        'access_key_id',
        'secret_access_key',
      ),
      $this->uncontractedSecretFieldNames()
    );
  }

  /**
   * Return $text with the value of every secret-bearing JSON key masked.
   *
   * The request bodies and HttpSocketResponse dumps this model logs carry
   * credentials in JSON: a create response carries the new client's
   * client_secret, and any client with a DynamoDB configuration carries AWS
   * keys in the cfg it sends and reads back. Those logs are not private -- the
   * live-server tier writes them to a GitHub Actions log on a public
   * repository, where masking of the workflow's own secrets does not apply
   * because these values come from the server rather than from `secrets.*`.
   *
   * Redacting the rendered text rather than the structure is deliberate: an
   * HttpSocketResponse dump repeats the body inside its [raw] HTTP exchange,
   * so masking the parsed body alone would leave the same secret in the same
   * log line.
   *
   * @param string $text Text about to be logged.
   * @return string The text with secret values replaced by '[REDACTED]'.
   */

  private function redactSecretsInLogText($text) {
    foreach($this->loggingSecretFieldNames() as $field) {
      // Match "field": "value" and replace only the value, leaving the
      // surrounding JSON intact. The value pattern tolerates escaped
      // characters so a backslash inside a secret cannot end the match early.
      $pattern = '/("' . preg_quote($field, '/') . '"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/';
      $text = preg_replace($pattern, '${1}[REDACTED]${2}', $text);
    }

    return $text;
  }

  /**
   * Resolve the effective DynamoDB configuration for a client. Prefers the
   * per-client Oa4mpClientDynamoConfig and falls back to the admin client's
   * DefaultDynamoConfig when the client has no per-client row.
   *
   * Oa4mpClientDynamoConfig is a hasOne association: when a client has no
   * per-client row, CakePHP's Containable returns it as an array of null-valued
   * fields (not an empty array), so a bare !empty($data['Oa4mpClientDynamoConfig'])
   * check is fooled into selecting that phantom config and never reaches the
   * fallback. aws_region is required+notBlank on any real persisted row, so its
   * presence reliably distinguishes a real per-client config from the phantom.
   *
   * Both the marshalling path (oa4mpMarshallCfgQdl) and the sync-comparison path
   * (isClientDataSynchronized) must resolve the config identically, otherwise a
   * client without a per-client row is sent the default values but compared
   * against the phantom nulls, producing a spurious out-of-sync result.
   *
   * @param array $data Client data including Oa4mpClientDynamoConfig and
   *                    Oa4mpClientCoAdminClient.DefaultDynamoConfig.
   * @return array The effective Dynamo configuration, or an empty array if none.
   * @since COmanage Registry 4.4.2
   */

  function resolveDynamoConfig($data) {
    if(!empty($data['Oa4mpClientDynamoConfig']['aws_region'])) {
      return $data['Oa4mpClientDynamoConfig'];
    }
    return $data['Oa4mpClientCoAdminClient']['DefaultDynamoConfig'] ?? array();
  }

  /**
   * Reduce one claim row to the normalized shape isClientDataSynchronized()
   * compares.
   *
   * Both sides of that comparison -- the plugin's claim rows and the OA4MP
   * server's -- are normalized by this one function. That is the point of it:
   * the rules below only mean anything if both sides apply exactly the same
   * ones, and two hand-maintained copies of them were free to drift apart.
   *
   * The rules are the marshaller's, because the marshaller is what actually
   * went over the wire:
   *
   *  - An empty value is no value. oa4mpMarshallCfgQdl() strips any empty
   *    value from the emitted mapping, and empty('0') is true, so a
   *    string-zero value field is never sent to the server. Reading such a
   *    field here with ?? kept the '0' on the plugin side and compared it
   *    against the server's absent value; '0' does not equal null even
   *    loosely, so the client reported out of sync on every verify pass and
   *    could not be repaired by re-sending the same data.
   *
   *  - A constraint counts only when BOTH of its fields are populated, which
   *    is the rule oa4mpMarshallCfgQdl() applies when it emits them. Keeping a
   *    half-populated constraint here compared one constraint against the
   *    nothing that was actually sent, so the client reported out of sync
   *    permanently.
   *
   * @param array  $claim            One claim row, optionally carrying its
   *                                 Oa4mpClientClaimConstraint rows.
   * @param mixed  $clientIdentifier oa4mp_identifier of the client being
   *                                 compared, named in the dropped-constraint
   *                                 log below.
   * @param string $side             Which representation this claim came from,
   *                                 'plugin' or 'oa4mp', also for that log.
   * @return array The normalized claim.
   * @since COmanage Registry 4.4.2
   */

  private function normalizeClaimForComparison($claim, $clientIdentifier, $side) {
    $normalized = array();

    // The fields the cfg capability contract declares a claim mapping may
    // carry, minus the constraint list, which is normalized separately below.
    // Reading the same declaration the marshaller writes from is the whole
    // point: this file has shipped writer-versus-comparator drift three times,
    // every one of them a hand-copied field list that quietly stopped matching
    // what actually went over the wire.
    foreach($this->cfgContractNames('claim_mapping_fields') as $field) {
      if($field === self::CFG_CONSTRAINTS_FIELD) {
        continue;
      }

      // empty(), never ??, and then the contract's own value rule -- both are
      // marshallDeclaredRow()'s, in that order. empty('0') is true while
      // '0' ?? null is not, and a string '0' diverging across this exact
      // boundary is the most recent live incident in this code.
      $value = !empty($claim[$field]) ? $claim[$field] : null;

      $normalized[$field] = ($value !== null && $this->cfgContractPermitsValue($field, $value))
                          ? $value
                          : null;
    }

    $constraints = array();
    if(!empty($claim['Oa4mpClientClaimConstraint'])) {
      foreach($claim['Oa4mpClientClaimConstraint'] as $constraint) {
        if(!empty($constraint['constraint_field']) && !empty($constraint['constraint_value'])) {
          if(!$this->cfgContractPermitsValue('constraint_field', $constraint['constraint_field'])) {
            // The marshaller withholds a constraint_field value the contract
            // does not declare, which leaves that constraint half-populated
            // and so unemitted. Both sides have to reach the same conclusion,
            // or the client reports out of sync with nothing able to fix it.
            // The field name only, as below: never the value.
            $this->log("Oa4mpClientClaim: dropping constraint with an undeclared"
                       . " constraint_field from the " . $side . "-side comparison"
                       . " (client=" . var_export($clientIdentifier, true)
                       . ", constraint_field=" . var_export($constraint['constraint_field'], true) . ")");
            continue;
          }

          // Built from the contract for the same reason the mapping above is:
          // a constraint field added to the declaration is emitted by the
          // marshaller, so it has to be compared here or the round trip
          // reports in sync whatever that field holds.
          $normalizedConstraint = array();
          foreach($this->cfgContractNames('claim_constraint_fields') as $field) {
            $normalizedConstraint[$field] = !empty($constraint[$field]) ? $constraint[$field] : null;
          }

          $constraints[] = $normalizedConstraint;
        } elseif(!empty($constraint['constraint_field']) || !empty($constraint['constraint_value'])) {
          // Dropping the constraint takes it out of the comparison, so this is
          // the only place that state stays visible. Log the client and the
          // constraint's field name only -- never the constraint value and
          // never a row-shaped payload, which in this model can carry the
          // DynamoDB credentials.
          $this->log("Oa4mpClientClaim: dropping half-populated constraint from"
                     . " the " . $side . "-side comparison"
                     . " (client=" . var_export($clientIdentifier, true)
                     . ", constraint_field=" . var_export($constraint['constraint_field'] ?? null, true) . ")");
        }
      }
    }

    // Sort constraints for consistent comparison.
    usort($constraints, function($a, $b) {
      $fieldCmp = strcmp($a['constraint_field'] ?? '', $b['constraint_field'] ?? '');
      if($fieldCmp !== 0) {
        return $fieldCmp;
      }
      return strcmp($a['constraint_value'] ?? '', $b['constraint_value'] ?? '');
    });
    $normalized['constraints'] = $constraints;

    return $normalized;
  }

  /**
   * Determine if our representation of the client and the Oa4mp server
   * representation of the client is synchronized, in order to detect
   * if the client has been changed outside of this plugin.
   *
   * @param array $curData The current client data.
   * @param array $oa4mpServerData The Oa4mp server representation of the client.
   * @return boolean True if the client data is synchronized, false otherwise.
   * @since COmanage Registry 3.1.1
   */

  function isClientDataSynchronized($curData, $oa4mpServerData) {
    // Compare basic client details.
    $curClient = $curData['Oa4mpClientCoOidcClient'];
    $oa4mpClient = $oa4mpServerData['Oa4mpClientCoOidcClient'];

    if($curClient['oa4mp_identifier'] !== $oa4mpClient['oa4mp_identifier']) {
      $this->log("Oa4mpClientCoOidcClient oa4mp_identifier is out of sync"
                 . " (plugin=" . var_export($curClient['oa4mp_identifier'], true)
                 . ", oa4mp=" . var_export($oa4mpClient['oa4mp_identifier'], true) . ")");
      return false;
    }

    if($curClient['name'] !== $oa4mpClient['name']) {
      $this->log("Oa4mpClientCoOidcClient name is out of sync"
                 . " (plugin=" . var_export($curClient['name'], true)
                 . ", oa4mp=" . var_export($oa4mpClient['name'], true) . ")");
      return false;
    }

    if($curClient['proxy_limited'] != $oa4mpClient['proxy_limited']) {
      $this->log("Oa4mpClientCoOidcClient proxy_limited is out of sync"
                 . " (plugin=" . var_export($curClient['proxy_limited'], true)
                 . ", oa4mp=" . var_export($oa4mpClient['proxy_limited'], true) . ")");
      return false;
    }

    if($curClient['public_client'] != $oa4mpClient['public_client']) {
      $this->log("Oa4mpClientCoOidcClient public_client is out of sync"
                 . " (plugin=" . var_export($curClient['public_client'], true)
                 . ", oa4mp=" . var_export($oa4mpClient['public_client'], true) . ")");
      return false;
    }

    // Compare refresh token lifetime.
    //
    // The state where the OA4MP server has a refresh token lifetime of exactly
    // zero and our representation does not have a value is considered to be
    // synchronized.

    // Both sides are optional. A caller may omit the section entirely (the
    // live-server tier does), and the unmarshaller only sets token_lifetime
    // when the server returned rt_lifetime. Reading through the missing key
    // raised "Undefined array key" and "array offset on null" warnings on
    // every live-tier comparison; resolving to null first keeps the
    // null-vs-zero rule below reading exactly as it did.
    $curRefreshToken = $curData['Oa4mpClientRefreshToken'] ?? array();
    $oa4mpRefreshToken = $oa4mpServerData['Oa4mpClientRefreshToken'] ?? array();

    $curTokenLifetime = $curRefreshToken['token_lifetime'] ?? null;
    $oa4mpTokenLifetime = $oa4mpRefreshToken['token_lifetime'] ?? null;

    if($curTokenLifetime != $oa4mpTokenLifetime) {
      if(!(is_null($curTokenLifetime) && ($oa4mpTokenLifetime === 0))) {
        $this->log("Oa4mpClientRefreshToken token_lifetime is out of sync"
                   . " (plugin=" . var_export($curTokenLifetime, true)
                   . ", oa4mp=" . var_export($oa4mpTokenLifetime, true) . ")");
        return false;
      }
    }

    // Compare email addresses.
    $curEmails = array();
    $oa4mpEmails = array();

    foreach(($curData['Oa4mpClientCoEmailAddress'] ?? array()) as $key => $e) {
      $curEmails[] = $e['mail'];
    }

    foreach(($oa4mpServerData['Oa4mpClientCoEmailAddress'] ?? array()) as $key => $e) {
      $oa4mpEmails[] = $e['mail'];
    }

    sort($curEmails);
    sort($oa4mpEmails);

    if($curEmails != $oa4mpEmails) {
      $this->log("Oa4mpClientCoEmailAddress emails are out of sync"
                 . " (plugin=" . count($curEmails)
                 . ", oa4mp=" . count($oa4mpEmails) . ")");
      $this->log("curEmails: " . print_r($curEmails, true));
      $this->log("oa4mpEmails: " . print_r($oa4mpEmails, true));
      return false;
    }

    // Compare callbacks.
    $curCallbacks = array();
    $oa4mpCallbacks = array();

    foreach($curData['Oa4mpClientCoCallback'] as $key => $cb) {
      $curCallbacks[] = $cb['url'];
    }

    foreach($oa4mpServerData['Oa4mpClientCoCallback'] as $key => $cb) {
      $oa4mpCallbacks[] = $cb['url'];
    }

    sort($curCallbacks);
    sort($oa4mpCallbacks);

    if($curCallbacks != $oa4mpCallbacks) {
      $this->log("Oa4mpClientCoCallback callbacks are out of sync"
                 . " (plugin=" . count($curCallbacks)
                 . ", oa4mp=" . count($oa4mpCallbacks) . ")");
      $this->log("curCallbacks: " . print_r($curCallbacks, true));
      $this->log("oa4mpCallbacks: " . print_r($oa4mpCallbacks, true));
      return false;
    }

    // Does this client used a named configuration?
    if(!empty($curData['Oa4mpClientCoOidcClient']['named_config_id'])) {
      $usesNamedConfig = true;
    } else {
      $usesNamedConfig = false;
    }

    // Compare scopes.
    $curScopes = array();
    $oa4mpScopes = array();

    if($usesNamedConfig) {
      // Compare the scopes sent by the OA4MP server to the scopes
      // specified as part of the named configuration.
      $usedNamedConfigId = $curData['Oa4mpClientCoOidcClient']['named_config_id'];
      foreach($curData['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] as $config) {
        if($config['id'] == $usedNamedConfigId) {
          foreach($config['Oa4mpClientCoScope'] as $s) {
            if(in_array($s['scope'], Oa4mpClientScopeEnum::$allScopesArray)) {
              $curScopes[] = $s['scope'];
            }
          }
          break;
        }
      }
    } else {
      // Compare the scopes sent by the OA4MP server to the scopes
      // linked to this OIDC client instance.
      foreach($curData['Oa4mpClientCoScope'] as $key => $s) {
        $curScopes[] = $s['scope'];
      }
    }

    foreach($oa4mpServerData['Oa4mpClientCoScope'] as $key => $s) {
      $oa4mpScopes[] = $s['scope'];
    }

    sort($curScopes);
    sort($oa4mpScopes);

    if($curScopes != $oa4mpScopes) {
      $this->log("Oa4mpClientCoScope scopes are out of sync"
                 . " (plugin=" . count($curScopes)
                 . ", oa4mp=" . count($oa4mpScopes) . ")");
      $this->log("curScopes: " . print_r($curScopes, true));
      $this->log("oa4mpScopes: " . print_r($oa4mpScopes, true));
      return false;
    }

    // Compare the comment.
    if(empty($oa4mpClient['comment'])) {
      $this->log("The OA4MP server representation of the client does not include a comment");
      return false;
    }

    if(!str_starts_with($oa4mpClient['comment'], _txt('pl.oa4mp_client_co_oidc_client.signature'))) {
      $this->log("The OA4MP server respresentation of the client has comment");
      $this->log($oa4mpClient['comment']);
      $this->log("but the comment should start with");
      $this->log(_txt('pl.oa4mp_client_co_oidc_client.signature'));
      return false;
    }

    // If this client uses a named configuration than return true here,
    // else continue with more detailed comparison.
    if($usesNamedConfig) {
      return true;
    }

    // Compare access token configuration. Optional on both sides for the same
    // reason as the refresh token above: an omitted section is "no access
    // token configuration", which is what the three tests below already
    // treat an empty array as.
    $curAccessToken = $curData['Oa4mpClientAccessToken'] ?? array();
    $oa4mpAccessToken = $oa4mpServerData['Oa4mpClientAccessToken'] ?? array();

    if($curAccessToken && ($curAccessToken['is_jwt'] ?? null) && !$oa4mpAccessToken) {
      $this->log("Oa4mpClientAccessToken plugin has access token configuration but Oa4mp server does not");
      $this->log("curAccessToken: " . print_r($curAccessToken, true));
      return false;
    }

    if(!$curAccessToken && $oa4mpAccessToken) {
      $this->log("Oa4mpClientAccessToken Oa4mp server has access token configuration but plugin does not");
      $this->log("oa4mpAccessToken: " . print_r($oa4mpAccessToken, true));
      return false;
    }

    if($curAccessToken && $oa4mpAccessToken) {
      if(($curAccessToken['is_jwt'] ?? null) != ($oa4mpAccessToken['is_jwt'] ?? null)) {
        $this->log("Oa4mpClientAccessToken is_jwt is out of sync"
                   . " (plugin=" . var_export($curAccessToken['is_jwt'] ?? null, true)
                   . ", oa4mp=" . var_export($oa4mpAccessToken['is_jwt'] ?? null, true) . ")");
        return false;
      }
    }

    // Compare client authorization configuration.
    if(!empty($curData['Oa4mpClientAuthorization']['id']) && 
        empty($oa4mpServerData['Oa4mpClientAuthorization']) &&
        ($curData['Oa4mpClientAuthorization']['require_active'] == true)) {
      $this->log("Oa4mpClientAuthorization plugin has authorization configuration but Oa4mp server does not");
      $this->log("curAuthorization: " . print_r($curData['Oa4mpClientAuthorization'], true));
      return false;
    }

    if(empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      $this->log("Oa4mpClientAuthorization Oa4mp server has authorization configuration but plugin does not");
      $this->log("oa4mpAuthorization: " . print_r($oa4mpServerData['Oa4mpClientAuthorization'], true));
      return false;
    }

    if(!empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      if($curData['Oa4mpClientAuthorization']['require_active'] != ($oa4mpServerData['Oa4mpClientAuthorization']['require_active'] ?? null)) {
        $this->log("Oa4mpClientAuthorization require_active is out of sync"
                   . " (plugin=" . var_export($curData['Oa4mpClientAuthorization']['require_active'], true)
                   . ", oa4mp=" . var_export($oa4mpServerData['Oa4mpClientAuthorization']['require_active'] ?? null, true) . ")");
        return false;
      }
    }

    if(!empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      if($curData['Oa4mpClientAuthorization']['authz_co_group_id'] != ($oa4mpServerData['Oa4mpClientAuthorization']['authz_co_group_id'] ?? null)) {
        $this->log("Oa4mpClientAuthorization authz_co_group_id is out of sync"
                   . " (plugin=" . var_export($curData['Oa4mpClientAuthorization']['authz_co_group_id'], true)
                   . ", oa4mp=" . var_export($oa4mpServerData['Oa4mpClientAuthorization']['authz_co_group_id'] ?? null, true) . ")");
        return false;
      }
    }

    if(!empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      if($curData['Oa4mpClientAuthorization']['authz_group_redirect_url'] != ($oa4mpServerData['Oa4mpClientAuthorization']['authz_group_redirect_url'] ?? null)) {
        $this->log("Oa4mpClientAuthorization authz_group_redirect_url is out of sync");
        $this->log("  plugin: " . var_export($curData['Oa4mpClientAuthorization']['authz_group_redirect_url'], true));
        $this->log("  oa4mp:  " . var_export($oa4mpServerData['Oa4mpClientAuthorization']['authz_group_redirect_url'] ?? null, true));
        return false;
      }
    }
    
    if(!empty($curData['Oa4mpClientAuthorization']['id']) && !empty($oa4mpServerData['Oa4mpClientAuthorization'])) {
      if($curData['Oa4mpClientAuthorization']['require_active_redirect_url'] != ($oa4mpServerData['Oa4mpClientAuthorization']['require_active_redirect_url'] ?? null)) {
        $this->log("Oa4mpClientAuthorization require_active_redirect_url is out of sync");
        $this->log("  plugin: " . var_export($curData['Oa4mpClientAuthorization']['require_active_redirect_url'], true));
        $this->log("  oa4mp:  " . var_export($oa4mpServerData['Oa4mpClientAuthorization']['require_active_redirect_url'] ?? null, true));
        return false;
      }
    }

    // Compare DynamoDB configurations. Resolve the plugin-side config the same
    // way marshalling does (resolveDynamoConfig) so a client without a per-client
    // row is compared against the DefaultDynamoConfig values that were actually
    // sent to the server, rather than the phantom all-null hasOne array.
    $curDynamo = $this->resolveDynamoConfig($curData);
    if(!empty($curDynamo) && !empty($oa4mpServerData['Oa4mpClientDynamoConfig'])) {
      if($curDynamo['aws_region'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['aws_region']) {
        $this->log("Oa4mpClientDynamoConfig aws_region is out of sync"
                   . " (plugin=" . var_export($curDynamo['aws_region'], true)
                   . ", oa4mp=" . var_export($oa4mpServerData['Oa4mpClientDynamoConfig']['aws_region'], true) . ")");
        return false;
      }
      if($curDynamo['aws_access_key_id'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['aws_access_key_id']) {
        // aws_access_key_id is a secret credential. Route both sides through
        // redactSecrets() so the masking list stays the single enforcement
        // point — any field name added to redactSecrets is masked here.
        $curMasked = $this->redactSecrets(array(
          'aws_access_key_id' => $curDynamo['aws_access_key_id'],
        ));
        $oa4mpMasked = $this->redactSecrets(array(
          'aws_access_key_id' => $oa4mpServerData['Oa4mpClientDynamoConfig']['aws_access_key_id'],
        ));
        $this->log("Oa4mpClientDynamoConfig aws_access_key_id is out of sync"
                   . " (plugin=" . $curMasked['aws_access_key_id']
                   . ", oa4mp=" . $oa4mpMasked['aws_access_key_id'] . ")");
        return false;
      }
      if($curDynamo['table_name'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['table_name']) {
        $this->log("Oa4mpClientDynamoConfig table_name is out of sync"
                   . " (plugin=" . var_export($curDynamo['table_name'], true)
                   . ", oa4mp=" . var_export($oa4mpServerData['Oa4mpClientDynamoConfig']['table_name'], true) . ")");
        return false;
      }
      if($curDynamo['partition_key'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['partition_key']) {
        $this->log("Oa4mpClientDynamoConfig partition_key is out of sync"
                   . " (plugin=" . var_export($curDynamo['partition_key'], true)
                   . ", oa4mp=" . var_export($oa4mpServerData['Oa4mpClientDynamoConfig']['partition_key'], true) . ")");
        return false;
      }
      if($curDynamo['partition_key_template'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['partition_key_template']) {
        $this->log("Oa4mpClientDynamoConfig partition_key_template is out of sync");
        $this->log("  plugin: " . var_export($curDynamo['partition_key_template'], true));
        $this->log("  oa4mp:  " . var_export($oa4mpServerData['Oa4mpClientDynamoConfig']['partition_key_template'], true));
        return false;
      }
      if($curDynamo['partition_key_claim_name'] != $oa4mpServerData['Oa4mpClientDynamoConfig']['partition_key_claim_name']) {
        $this->log("Oa4mpClientDynamoConfig partition_key_claim_name is out of sync"
                   . " (plugin=" . var_export($curDynamo['partition_key_claim_name'], true)
                   . ", oa4mp=" . var_export($oa4mpServerData['Oa4mpClientDynamoConfig']['partition_key_claim_name'], true) . ")");
        return false;
      }

      // sort_key and sort_key_template are deliberately NOT compared.
      //
      // cfg_contract.json's qdl_args group omits both names, so the plugin
      // never writes either into a cfg -- and the marshaller never has. They
      // are nonetheless editable columns: View/Oa4mpClientCoAdminClients/
      // fields.inc offers both on the admin client's default DynamoDB
      // configuration, and Oa4mpClientCoOidcClientsController copies that row
      // into a new client's Oa4mpClientDynamoConfig. Comparing them therefore
      // put the plugin's populated value up against the OA4MP server's
      // permanent null: an operator who filled either field got a client that
      // reported out of sync on every verify pass and that no edit could
      // repair, because the repair the comparison implies -- send the value --
      // is one the contract says is never sent.
      //
      // This is the fourth appearance of the writer-versus-comparator
      // asymmetry this file has shipped. The rule the rest of this function
      // now follows is that a name the contract does not declare is compared
      // by nothing, so the removal is the fix; adding the names to the
      // contract and starting to emit them would be a capability change, which
      // no tier's QDL has been prepared for.
    }

    // Compare claim mappings.
    $curClaims = $curData['Oa4mpClientClaim'] ?? array();
    $oa4mpClaims = $oa4mpServerData['Oa4mpClientClaim'] ?? array();

    // If one side has claims and the other doesn't, they are out of sync.
    if(empty($curClaims) && !empty($oa4mpClaims)) {
      $this->log("Oa4mpClientClaim: OA4MP server has claims but plugin does not");
      $this->log("oa4mpClaims: " . print_r($oa4mpClaims, true));
      return false;
    }

    if(!empty($curClaims) && empty($oa4mpClaims)) {
      $this->log("Oa4mpClientClaim: Plugin has claims but OA4MP server does not");
      $this->log("curClaims: " . print_r($curClaims, true));
      return false;
    }

    // If both sides have claims, compare them.
    if(!empty($curClaims) && !empty($oa4mpClaims)) {
      // Compare the number of claims.
      if(count($curClaims) != count($oa4mpClaims)) {
        $this->log("Oa4mpClientClaim: Number of claims is out of sync"
                   . " (plugin=" . count($curClaims)
                   . ", oa4mp=" . count($oa4mpClaims) . ")");
        $this->log("curClaims: " . print_r($curClaims, true));
        $this->log("oa4mpClaims: " . print_r($oa4mpClaims, true));
        return false;
      }

      // Identifier for the dropped-constraint log below. A half-populated
      // constraint is filtered out of the comparison, so the only trace it
      // leaves is that log line; naming the client is what makes it useful.
      $clientIdentifier = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'] ?? null;

      // Build a normalized array of claims from curData for comparison.
      $curClaimsNormalized = array();
      foreach($curClaims as $claim) {
        $curClaimsNormalized[] = $this->normalizeClaimForComparison($claim, $clientIdentifier, 'plugin');
      }

      // Build a normalized array of claims from oa4mpServerData for comparison.
      // The same helper as above, deliberately: the two sides have to apply the
      // marshaller's rules identically or the comparison is between different
      // questions.
      $oa4mpClaimsNormalized = array();
      foreach($oa4mpClaims as $claim) {
        $oa4mpClaimsNormalized[] = $this->normalizeClaimForComparison($claim, $clientIdentifier, 'oa4mp');
      }

      // Sort both arrays by claim_name for consistent comparison.
      usort($curClaimsNormalized, function($a, $b) {
        return strcmp($a['claim_name'], $b['claim_name']);
      });
      usort($oa4mpClaimsNormalized, function($a, $b) {
        return strcmp($a['claim_name'], $b['claim_name']);
      });

      // Compare the normalized claim arrays.
      if($curClaimsNormalized != $oa4mpClaimsNormalized) {
        $this->log("Oa4mpClientClaim: Claims are out of sync");
        $this->log("curClaimsNormalized: " . print_r($curClaimsNormalized, true));
        $this->log("oa4mpClaimsNormalized: " . print_r($oa4mpClaimsNormalized, true));
        return false;
      }
    }

    return true;
  }

  /**
   * Delete an existing OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * 
   */
  function oa4mpDeleteClient($adminClient, $oidcClient) {
    $ret = false;

    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);
    $request['method'] = 'DELETE';

    $client_id = $oidcClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request['uri']['query'] = array('client_id' => $client_id);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . print_r(null, true));

    $response = $http->request($request);

    $this->log("Response is " . $this->redactSecretsInLogText(print_r($response, true)));

    if($response->code == 204) {
      $ret = true;
    }

    return $ret;
  }

  /**
   * Edit an existing OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @return 1 if edit is successful, 0 if not, and 2 if detect client
   *         modified outside of this plugin
   */

  function oa4mpEditClient($adminClient, $curData, $data) {
    $ret = 0;

    // Check that the current client data is synchronized with the
    // server and capture any extra keys from the OA4MP server response.
    $verifyResult = $this->oa4mpVerifyClient($adminClient, $curData, true);

    // An internal failure is not a tampering verdict. When the comparison did
    // not happen at all -- an unreadable cfg capability contract, a cfg the
    // unmarshaller cannot read -- nothing was found to differ, so report the
    // generic edit error rather than 2. Controllers map 2 to "This client has
    // been modified outside of the Registry. Please email help@cilogon.org for
    // assistance.", which would send the operator and support after client
    // tampering when the real cause is a broken deployment of this plugin.
    if(!empty($verifyResult['error'])) {
      return 0;
    }

    if(!$verifyResult['synchronized']) {
      return 2;
    }

    // Update the data with any extra keys from the OA4MP server so they
    // are included when marshalling the content for the edit request.
    if(!empty($verifyResult['oa4mp_server_extra'])) {
      $data['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = $verifyResult['oa4mp_server_extra'];
    }

    // The current data before edit and the current Oa4mp server respresentation
    // of the client agree so marshall the edited data and submit to
    // the Oa4mp server.
    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);
    $request['method'] = 'PUT';
    $client_id = $curData['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request['uri']['query'] = array('client_id' => $client_id);

    $body = $this->oa4mpMarshallContent($adminClient, $data);

    $request['body'] = json_encode($body);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . $this->redactSecretsInLogText(print_r($request['body'], true)));

    $response = $http->request($request);

    $this->log("Response is " . $this->redactSecretsInLogText(print_r($response, true)));

    if($response->code == 200) {
      $ret = 1;
    }

    return $ret;
  }

  /**
   * Initialize request for HttpSocket instance for oa4mp server invocation.
   *
   * @since COmanage Registry 2.0.1
   * @return Array array to be used with HttpSocket request() method.
   */
  function oa4mpInitializeRequest($adminClient) {
    $request = array();
    $request['method'] = 'GET';

    $parsedUrl = parse_url($adminClient['Oa4mpClientCoAdminClient']['serverurl']);
    $request['uri']['scheme'] = $parsedUrl['scheme'];
    $request['uri']['host']   = $parsedUrl['host'];
    $request['uri']['path']   = $parsedUrl['path'];

    $request['header']['Content-Type'] = 'application/json; charset=UTF-8';

    $aclientId = $adminClient['Oa4mpClientCoAdminClient']['admin_identifier'];
    $aclientSecret = $adminClient['Oa4mpClientCoAdminClient']['secret'];
    $bearerToken = base64_encode($aclientId . ":" . $aclientSecret);

    $request['header']['Authorization'] = "Bearer $bearerToken";

    return $request;
  }

  /**
   * Marshall Oa4mpClientCoLdapConfig object for oa4mp server using deprecated syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $data Posted client data after validation
   * @return array cfg object to be sent to oa4mp server
   */
  function oa4mpMarshallCfgDeprecated($data) {
    $cfg = array();

    $cfg['config'] = _txt('pl.oa4mp_client_co_oidc_client.signature');
    $cfg['claims'] = array();
    $cfg['claims']['sourceConfig'] = array();

    $ldap = array();

    // Concatenate the LDAP config server URL, the bind DN, and the
    // base DN and then SHA1 hash it to compute a name for the LDAP
    // configuration to be used with the Oa4mp server.
    $id = $data['Oa4mpClientCoLdapConfig'][0]['serverurl'];
    $id = $id . $data['Oa4mpClientCoLdapConfig'][0]['binddn'];
    $id = $id . $data['Oa4mpClientCoLdapConfig'][0]['basedn'];
    $id = sha1($id);

    $ldap['id'] = $id;
    
    if($data['Oa4mpClientCoLdapConfig'][0]['enabled']) {
      $ldap['enabled'] = 'true';
    } else {
      $ldap['enabled'] = 'false';
    }
    $ldap['authorizationType'] = $data['Oa4mpClientCoLdapConfig'][0]['authorization_type'];

    $parsedUrl = parse_url($data['Oa4mpClientCoLdapConfig'][0]['serverurl']);
    $ldap['address'] = $parsedUrl['host'];
    if(!empty($parsedUrl['port'])) {
      $ldap['port'] = $parsedUrl['port'];
    } 
    else if($parsedUrl['scheme'] == 'ldaps') {
      $ldap['port'] = 636;
    } else {
      $ldap['port'] = 389;
    }

    $ldap['principal'] = $data['Oa4mpClientCoLdapConfig'][0]['binddn'];
    $ldap['password'] = $data['Oa4mpClientCoLdapConfig'][0]['password'];
    $ldap['searchBase'] = $data['Oa4mpClientCoLdapConfig'][0]['basedn'];
    $ldap['searchName'] = $data['Oa4mpClientCoLdapConfig'][0]['search_name'];

    $ldap['searchAttributes'] = array();
    foreach($data['Oa4mpClientCoLdapConfig'][0]['Oa4mpClientCoSearchAttribute'] as $sa) {
      $a = array();
      $a['name'] = $sa['name'];
      $a['returnName'] = $sa['return_name'];
      if($sa['return_as_list']) {
        $a['returnAsList'] = 'true';
      } else {
        $a['returnAsList'] = 'false';
      }

      $ldap['searchAttributes'][] = $a;
    }

    $cfg['claims']['sourceConfig'][] = array('ldap' => $ldap);

    $preProcessing = array();
    $preProcessing['$if'] = array('$true');
    $preProcessing['$then'] = array(array('$set_claim_source' => array('LDAP', $id)));

    $cfg['claims']['preProcessing'] = array();
    $cfg['claims']['preProcessing'][] = $preProcessing;

    return $cfg;
  }

  /**
   * Marshall Oa4mpClientCoLdapConfig object for oa4mp server using QDL syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $data Posted client data after validation
   * @return array cfg object to be sent to oa4mp server
   */
  function oa4mpMarshallCfgQdl($data) {
    // Construct the OA4MP cfg object.
    $cfg = array();

    // Access token configuration. Note that access token configuration is
    // orthogonal to using a named configuration. That is, a client can
    // use a named configuration and still have an access token configuration.
    if(!empty($data['Oa4mpClientAccessToken']) && $data['Oa4mpClientAccessToken']['is_jwt']) {
      $cfg['tokens']['access']['type'] = 'access';
    }

    // The names the contract declares for tokens.identity.qdl.args, in
    // declaration order. Every one of the three places this function writes
    // into that block -- the authorization args below, the DynamoDB args after
    // the named-configuration branch, and the claim mappings at the end --
    // goes through it, so a ninth arg is emitted only once it is declared,
    // and is withheld and named in the signal until it is. Before this, the
    // contract declared these eight names and nothing read the declaration:
    // they were literal assignments, and a new one reached every tier with no
    // signal and a conformance run that still passed.
    $declaredQdlArgs = $this->cfgContractNames('qdl_args');

    // Field names whose values this pass withheld. Names only, never values.
    // Accumulated across every block below and reported once, at the end.
    $withheldFields = array();

    // Client authorization configuration. Note that client authorization configuration is
    // orthogonal to using a named configuration. That is, a client can
    // use a named configuration and still have a client authorization configuration.
    //
    // Each of the four args is CONDITIONAL: it is emitted only when the
    // authorization row carries the value it names. The conditionality lives
    // in whether the key is put on the row below, never in an empty value
    // reaching marshallDeclaredRow(), which is why that call passes
    // $dropEmpty false and still emits exactly the keys it always did.
    $authzArgs = array();

    if(!empty($data['Oa4mpClientAuthorization']) && $data['Oa4mpClientAuthorization']['require_active']) {
      $authzArgs['require_active_status'] = $data['Oa4mpClientAuthorization']['require_active'];
    }

    if(!empty($data['Oa4mpClientAuthorization']) && !empty($data['Oa4mpClientAuthorization']['authz_co_group_id'])) {
      $authzArgs['authorization_group_id'] = $data['Oa4mpClientAuthorization']['authz_co_group_id'];
    }

    if(!empty($data['Oa4mpClientAuthorization']) && !empty($data['Oa4mpClientAuthorization']['authz_group_redirect_url'])) {
      $authzArgs['authorization_group_redirect_url'] = $data['Oa4mpClientAuthorization']['authz_group_redirect_url'];
    }

    if(!empty($data['Oa4mpClientAuthorization']) && !empty($data['Oa4mpClientAuthorization']['require_active_redirect_url'])) {
      $authzArgs['require_active_redirect_url'] = $data['Oa4mpClientAuthorization']['require_active_redirect_url'];
    }

    $authzArgs = $this->marshallDeclaredRow($authzArgs, $declaredQdlArgs, $withheldFields,
                                            array(), false);

    // Only when something survived. A client with no authorization row has
    // never carried an args key at all, and the named-configuration branch
    // below array_merge_recursive()s this cfg, so creating an empty args array
    // here would put one into every named-configuration cfg.
    if(!empty($authzArgs)) {
      $cfg['tokens']['identity']['qdl']['args'] = $authzArgs;
    }

    // If using a named configuration then just add the cfg for that
    // named configuration and then return the cfg.
    if(!empty($data['Oa4mpClientCoOidcClient']['named_config_id'])) {
      foreach($data['Oa4mpClientCoAdminClient']['Oa4mpClientCoNamedConfig'] as $config) {
        if($config['id'] == $data['Oa4mpClientCoOidcClient']['named_config_id']) {
          $jsonString = $config['config'];
          $namedCfg = json_decode($jsonString, true);
          break;
        }
      }

      // The stored JSON is operator-authored and unvalidated, so the metadata
      // container it may or may not carry is made an array before anything
      // writes into it. Without this a configuration carrying a scalar
      // metadata value raises a TypeError on the very next line.
      $namedCfg = $this->normalizeCfgMetadataContainer($namedCfg);

      // Add metadata with URL to the named configuration if not already present.
      if($namedCfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] ?? true) {

        $routingArray = array();
        $routingArray['plugin'] = 'oa4mp_client';
        $routingArray['controller'] = 'oa4mp_client_co_named_configs';
        $routingArray['action'] = 'edit';
        $routingArray[] = $data['Oa4mpClientCoNamedConfig']['id'];

        $namedCfg['metadata']['Oa4mpClient']['Oa4mpClientCoNamedConfig'] = Router::url($routingArray, true);
      }

      $cfg = array_merge_recursive($cfg, $namedCfg);

      // After the merge, never before it: see stampCfgContractVersion().
      $cfg = $this->stampCfgContractVersion($cfg);

      return $cfg;
    }

    // Older admin clients may not have the QDL path set so use the configured
    // default, or a hard-coded default as a last resort.
    if(!empty($data['Oa4mpClientCoAdminClient']['qdl_claim_source'])) {
      $qdlClaimSourcePath = $data['Oa4mpClientCoAdminClient']['qdl_claim_source'];
    } elseif(!empty(getenv('COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT'))) {
      $qdlClaimSourcePath = getenv('COMANAGE_REGISTRY_OA4MP_QDL_CLAIM_DEFAULT');
    } else{
      $qdlClaimSourcePath = 'COmanageRegistry/default/dynamodb_claims.qdl';
    }

    // Identity token configuration.
    $cfg['tokens']['identity']['type'] = 'identity';

    $qdl = $cfg['tokens']['identity']['qdl'] ?? array();

    // Configure the QDL script file to load.
    $qdl['load'] = $qdlClaimSourcePath;

    // Configure the execution phases.
    $qdl['xmd'] = array();
    $qdl['xmd']['exec_phase'] = array();
    $qdl['xmd']['exec_phase'][] = 'post_auth';
    $qdl['xmd']['exec_phase'][] = 'post_refresh';
    $qdl['xmd']['exec_phase'][] = 'post_token';
    $qdl['xmd']['exec_phase'][] = 'post_user_info';

    // Configure the arguments to pass to the QDL script.
    $qdl['args'] = $qdl['args'] ?? array();

    // Resolve the per-client Oa4mpClientDynamoConfig, falling back to the admin
    // client's DefaultDynamoConfig. See resolveDynamoConfig() for why this cannot
    // be a bare !empty() check on the hasOne association. The sync-comparison path
    // (isClientDataSynchronized) resolves the config the same way so the values
    // sent here always match the values compared on a subsequent edit.
    $dynamoConfig = $this->resolveDynamoConfig($data);

    // Add the Dynamo module configuration.
    //
    // Built as a row and routed through the contract's
    // dynamo_module_config_keys group rather than assigned key by key. The
    // five keys are emitted unconditionally, null values included -- that is
    // what every stored cfg carries and what the unmarshaller reads back
    // without a guard -- so $dropEmpty is false and the plugin's own reads of
    // $dynamoConfig are left exactly as they were. What changes is that a
    // sixth key added here is withheld and named unless the contract declares
    // it, instead of reaching every tier silently.
    $dynamoModuleConfigRow = array();
    $dynamoModuleConfigRow['region'] = $dynamoConfig['aws_region'];
    $dynamoModuleConfigRow['access_key_id'] = $dynamoConfig['aws_access_key_id'];
    $dynamoModuleConfigRow['secret_access_key'] = $dynamoConfig['aws_secret_access_key'];
    $dynamoModuleConfigRow['table_name'] = $dynamoConfig['table_name'];
    $dynamoModuleConfigRow['partition_key'] = $dynamoConfig['partition_key'];

    $dynamoModuleConfig = $this->marshallDeclaredRow($dynamoModuleConfigRow,
                                                     $this->cfgContractNames('dynamo_module_config_keys'),
                                                     $withheldFields,
                                                     array(),
                                                     false);

    // The module configuration and the partition key pattern and claim name,
    // through the same declaration. The contract declares them in exactly this
    // order, after the four authorization args and before claim_mappings, so
    // the emitted key order is the order this block has always produced.
    $qdlArgsRow = array();
    $qdlArgsRow['dynamo_module_config'] = $dynamoModuleConfig;
    $qdlArgsRow['partition_key_template'] = $dynamoConfig['partition_key_template'];
    $qdlArgsRow['partition_key_claim_name'] = $dynamoConfig['partition_key_claim_name'];

    $qdl['args'] = array_merge($qdl['args'],
                               $this->marshallDeclaredRow($qdlArgsRow,
                                                          $declaredQdlArgs,
                                                          $withheldFields,
                                                          array(),
                                                          false));

    // Add the claims configurations.
    //
    // Every key a claim mapping may carry, every key a constraint may carry,
    // and every value an enumerated one of those may hold, is declared in
    // cfg_contract.json. Nothing else reaches the OA4MP server. Building each
    // mapping from that declaration is the point: this loop used to copy the
    // claim row whole and unset the handful of keys it knew the names of, so a
    // column added to cm_oa4mp_client_claims was on the wire the day it was
    // added, with no QDL on any tier prepared to read it.
    //
    // The declaration also fixes the ORDER, which is the seven claim columns in
    // Config/Schema/schema.xml order with the synthesised claim_constraints
    // list last -- the order a mapping has always carried, now stated once
    // rather than inherited from the order Containable happened to read.
    $declaredClaimFields = $this->cfgContractNames('claim_mapping_fields');
    $declaredConstraintFields = $this->cfgContractNames('claim_constraint_fields');

    $claimMappings = array();
    foreach($data['Oa4mpClientClaim'] as $claim) {
      // Add the claim constraints. Built before the mapping so the mapping can
      // carry them in the position the contract declares them in.
      $claimConstraints = array();

      // The same emptiness test normalizeClaimForComparison() applies to this
      // association: both sides have to ask one question about whether a claim
      // has constraints at all.
      if(!empty($claim['Oa4mpClientClaimConstraint'])) {
        foreach($claim['Oa4mpClientClaimConstraint'] as $constraint) {
          $constraintMapping = $this->marshallDeclaredRow($constraint,
                                                          $declaredConstraintFields,
                                                          $withheldFields);

          // Only emit a constraint when BOTH fields are populated. A constraint
          // with only a field or only a value is meaningless to the OA4MP
          // server's QDL. Defense-in-depth: Oa4mpClientClaimConstraint validates
          // both fields as notBlank, so persisted rows shouldn't reach here
          // half-populated, but the guard keeps malformed constraints from being
          // serialized to the server even if validation is ever bypassed (raw
          // SQL inserts, future code). A constraint whose constraint_field value
          // the contract does not declare arrives here already missing that
          // field, so this same guard drops it.
          if(!empty($constraintMapping['constraint_field'])
             && !empty($constraintMapping['constraint_value'])) {
            $claimConstraints[] = $constraintMapping;
          }
        }
      }

      $claimMappings[] = $this->marshallDeclaredRow(
        $claim,
        $declaredClaimFields,
        $withheldFields,
        array(self::CFG_CONSTRAINTS_FIELD => $claimConstraints));
    }

    // The claim mappings through the same declaration as everything else in
    // the args block. claim_mappings is a declared qdl_args entry and the
    // contract declares it LAST, so routing it here rather than assigning it
    // puts it in the position it has always occupied while making it as
    // undeclarable-by-accident as its seven neighbours. $dropEmpty is false
    // for the same reason it is false above: a client with no claims has
    // always sent an empty claim_mappings list, not an absent key.
    $qdl['args'] = array_merge($qdl['args'],
                               $this->marshallDeclaredRow(
                                 array('claim_mappings' => $claimMappings),
                                 $declaredQdlArgs,
                                 $withheldFields,
                                 array(),
                                 false));

    // One line per marshalling pass, always. A line emitted only when
    // something was withheld would make "nothing was withheld" and "this code
    // never ran" the same observation; emitting it every pass makes silence
    // the distinct third state, which is what a CI gate needs in order to fail
    // on the signal rather than on its absence. The count is of VALUES, and
    // the names appended after it are the distinct FIELDS those values sat
    // under, so two claims withholding the same column read as two values and
    // one name. Names are appended only when the count is non-zero, and the
    // values themselves never are.
    //
    // It sits here, after every block that writes into the args, rather than
    // after the claim loop: the args block and the DynamoDB module
    // configuration are marshalled through the contract too, so a value
    // withheld from either has to reach this count. Exactly one line per pass
    // is the property the CI gate reads, so there is one call, not one per
    // block.
    $withheldNames = array_values(array_unique($withheldFields));
    sort($withheldNames);

    $this->log(self::CFG_WITHHELD_SIGNAL . " version "
               . var_export($this->cfgContractVersion(), true) . ": "
               . count($withheldFields) . " values withheld from the cfg"
               . (empty($withheldNames) ? "" : ": " . implode(', ', $withheldNames)));

    $cfg['tokens']['identity']['qdl'] = $qdl;

    $cfg = $this->stampCfgContractVersion($cfg);

    return $cfg;
  }

  /**
   * Marshall Oa4mpClientCoOidcClient object for oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @param array $data Posted client data after validation
   * @return array Content to be sent to Oa4mp server after JSON encoding
   */
  function oa4mpMarshallContent($adminClient, $data) {
    $content = array();

    // Default is a non-public client.
    $content['token_endpoint_auth_method'] = 'client_secret_basic';

    if(!empty($data['Oa4mpClientCoOidcClient']['public_client'])) {
      if($data['Oa4mpClientCoOidcClient']['public_client']) {
        $content['token_endpoint_auth_method'] = 'none';
      }
    }

    $content['grant_types'] = array();
    $content['grant_types'][] = 'authorization_code';
    $content['response_types'] = 'code';
    $content['client_name'] = $data['Oa4mpClientCoOidcClient']['name'];
    $content['client_uri']  = $data['Oa4mpClientCoOidcClient']['home_url'];

    // Client metadata per RFC 7591.
    // https://tools.ietf.org/html/rfc7591#section-2
    if(!empty($data['Oa4mpClientCoCallback'])) {
      $content['redirect_uris'] = array();
      foreach($data['Oa4mpClientCoCallback'] as $cb) {
        $content['redirect_uris'][] = $cb['url'];
      }
    }

    if(!empty($data['Oa4mpClientRefreshToken']['token_lifetime'])) {
      if(is_numeric($data['Oa4mpClientRefreshToken']['token_lifetime'])) {
        $content['grant_types'][] = 'refresh_token';
        $content['rt_lifetime'] = $data['Oa4mpClientRefreshToken']['token_lifetime'];
      }
    }

    // Determine if the client uses a named configuration.
    if(!empty($data['Oa4mpClientCoOidcClient']['named_config_id'])) {
      $usesNamedConfig = true;
    } else {
      $usesNamedConfig = false;
    }

    $scopeString = "";
    $strictScopes = true;

    if($usesNamedConfig) {
      // If this client uses a named configuration then create the scope
      // string from the named configuration.
      $usedNamedConfigId = $data['Oa4mpClientCoOidcClient']['named_config_id'];

      foreach($adminClient['Oa4mpClientCoNamedConfig'] as $config) {
        if($usedNamedConfigId == $config['id']) {
          foreach($config['Oa4mpClientCoScope'] as $s) {
            if(!in_array($s['scope'], Oa4mpClientScopeEnum::$allScopesArray)) {
              $strictScopes = false;
            } else {
              $scopeString = $scopeString . " " . $s['scope'];
            }
          }
          break;
        }
      }
    } else {
      // If this client does not used a named configuration then create
      // the scope string from the scopes associated with this client.
      if(!empty($data['Oa4mpClientCoScope'])) {
        $scopeString = "";

        foreach($data['Oa4mpClientCoScope'] as $s) {
          $scopeString = $scopeString . " " . $s['scope'];
        }
      }
    }

    if(!empty($scopeString)) {
      $scopeString = trim($scopeString);
      $content['scope'] = $scopeString;
    }

    // Today OA4MP only supports a single contact though we send
    // it in a JSON list.
    if(!empty($data['Oa4mpClientCoEmailAddress'][0])) {
      $content['contacts'] = array();
      $content['contacts'][] = $data['Oa4mpClientCoEmailAddress'][0]['mail'];
    }

    // Include a comment that begins with a constant static string
    // appended with a URL to the index view for the clients since we
    // do not yet know that ID for the new client.
    $indexRoutingArray = array();
    $indexRoutingArray['plugin'] = 'oa4mp_client';
    $indexRoutingArray['controller'] = 'oa4mp_client_co_oidc_clients';
    $indexRoutingArray['action'] = 'index';
    $indexRoutingArray['co'] = $adminClient['Oa4mpClientCoAdminClient']['co_id'];

    $indexUrl = Router::url($indexRoutingArray, true);

    $content['comment'] = _txt('pl.oa4mp_client_co_oidc_client.signature') . ': ' . $indexUrl;

    // OA4MP rejects custom configurations (cfg) on public clients with
    // "custom configurations not permitted in public clients", so only
    // marshall and attach a cfg for confidential (non-public) clients.
    $isPublicClient = !empty($data['Oa4mpClientCoOidcClient']['public_client'])
                      && $data['Oa4mpClientCoOidcClient']['public_client'];

    if(!$isPublicClient &&
       (!empty($data['Oa4mpClientCoLdapConfig']) ||
        !empty($data['Oa4mpClientCoOidcClient']['named_config_id']) ||
        !empty($data['Oa4mpClientAccessToken']) ||
        !empty($data['Oa4mpClientAuthorization']) ||
        !empty($data['Oa4mpClientClaim']))) {
      $cfg = $this->oa4mpMarshallCfgQdl($data);
      if(!empty($cfg)) {
        $content['cfg'] = $cfg;
      }
    }

    // Merge any extra keys that were stored from a previous OA4MP server
    // response. These are keys that are not represented in the plugin's
    // data model but need to be sent back to the OA4MP server so that
    // those configuration details are not lost.
    if(!empty($data['Oa4mpClientCoOidcClient']['oa4mp_server_extra'])) {
      $extraKeys = json_decode($data['Oa4mpClientCoOidcClient']['oa4mp_server_extra'], true);
      if(!empty($extraKeys) && is_array($extraKeys)) {
        // Merge extra keys but do not overwrite any keys that were already set.
        foreach($extraKeys as $key => $value) {
          if(!array_key_exists($key, $content)) {
            $content[$key] = $value;
          }
        }
        // Masked JSON, not print_r, for the same reason the capture site is:
        // print_r output is not JSON-shaped and the redactor cannot see into it.
        $this->log("Merged extra keys into content for OA4MP server: "
                   . $this->redactSecretsInLogText(json_encode($extraKeys)));
      }
    }

    return $content;
  }

  /**
   * Request a new OIDC client from the oa4mp server.
   *
   * @since COmanage Registry 2.0.1
   * @return Array array containing the new client ID and secret
   */

  function oa4mpNewClient($adminClient, $data) {
    $ret = array();

    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);
    $request['method'] = 'POST';

    $body = $this->oa4mpMarshallContent($adminClient, $data);

    $request['body'] = json_encode($body);

    $this->log("Request URI is " . print_r($request['uri'], true));
    $this->log("Request method is " . print_r($request['method'], true));
    $this->log("Request body is " . $this->redactSecretsInLogText(print_r($request['body'], true)));

    $response = $http->request($request);

    $this->log("Response is " . $this->redactSecretsInLogText(print_r($response, true)));

    # During OA4MP server evolution accept both 200 and 201 as
    # return code when creating a new client.
    if(($response->code == 200) || ($response->code == 201)) {
      $body = json_decode($response->body(), true);
      
      $ret['clientId'] = $body['client_id'];

      if(!empty($body['client_secret'])) {
        $ret['secret']   = $body['client_secret'];
      }
    }

    return $ret;
  }

  /**
   * Unmarshall oa4mp server object to Oa4mpClientCoOidcClient object.
   *
   * @since COmanage Registry 3.1.1
   * @param  Array $oa4mpObject json_decode'd OA4MP server response
   * @param  Array $adminClient admin client carrying CO context (used by the
   *                            legacy-format claim conversion to look up the
   *                            CoProvisioningTarget for constraint values)
   * @return Array
   */
  function oa4mpUnMarshallContent($oa4mpObject, $adminClient) {

    // The input oa4mpObject should already be converted from the
    // JSON returned by the Oa4mp server to an associative array
    // using the call json_decode($json, true).

    $oa4mpClient = array();
    $oa4mpClient['Oa4mpClientCoOidcClient']  = array();
    $oa4mpClient['Oa4mpClientCoAdminClient'] = array();
    $oa4mpClient['Oa4mpClientCoCallback']    = array();
    $oa4mpClient['Oa4mpClientClaim']         = array();
    $oa4mpClient['Oa4mpClientCoScope']       = array();
    $oa4mpClient['Oa4mpClientRefreshToken']  = array();
    $oa4mpClient['Oa4mpClientAccessToken']   = array();

    // Define the keys that are processed by this plugin or that should not
    // be stored and sent back to the OA4MP server during an edit action. Any
    // keys not in this list will be captured as "extra" JSON and stored in
    // the database so that they can be sent back to the OA4MP server.
    $knownKeys = array(
      'client_id',
      'client_name',
      'client_uri',
      'rt_lifetime',
      'comment',
      'contacts',
      'redirect_uris',
      'scope',
      'token_endpoint_auth_method',
      'cfg',
      'grant_types',
      'response_types',
      // Read-only keys from OA4MP server that should not be sent back.
      'registration_access_token',
      // The client's own credential. A client-read response (RFC 7592) carries
      // it, and without this entry it fell into the extras blob: logged in the
      // clear, persisted to oa4mp_server_extra, and echoed back to the server
      // on every subsequent edit. The plugin models the secret elsewhere and
      // has no business round-tripping it through an unmodelled-keys blob.
      'client_secret',
      'client_secret_expires_at',
      'client_id_issued_at',
      // The server builds this from its own endpoint and the client_id. It
      // was reaching the extras blob and being echoed back on every edit;
      // dev.cilogon.org tolerates that, but the plugin has no business
      // asserting a URL the server owns.
      'registration_client_uri',
    );

    try {
      // Try to unmarshall the server object and throw exception
      // for any errors.

      // Unmarshall basic client details.
      $oa4mpClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'] = $oa4mpObject['client_id'];
      $oa4mpClient['Oa4mpClientCoOidcClient']['name'] = $oa4mpObject['client_name'];

      if(array_key_exists('rt_lifetime', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientRefreshToken']['token_lifetime'] = $oa4mpObject['rt_lifetime'];
      }

      if(array_key_exists('comment', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoOidcClient']['comment'] = $oa4mpObject['comment'];
      }

      if(array_key_exists('contacts', $oa4mpObject)) {
        $oa4mpClient['Oa4mpClientCoEmailAddress'] = array();
        foreach ($oa4mpObject['contacts'] as $mail) {
          $oa4mpClient['Oa4mpClientCoEmailAddress'][] = array('mail' => $mail);
        }
      }

      // For now we set proxy_limited to always be false.
      $oa4mpClient['Oa4mpClientCoOidcClient']['proxy_limited'] = '0';

      // Unmarshall the callback URIs.
      foreach ($oa4mpObject['redirect_uris'] as $key => $uri) {
        $oa4mpClient['Oa4mpClientCoCallback'][]['url'] = $uri;
      }

      // Unmarshall the scope details.
      $scopeObject = $oa4mpObject['scope'];
      if(is_string($scopeObject)) {
        $scopeObject = explode(" ", $scopeObject);
      }

      foreach ($scopeObject as $key => $scope) {
        switch ($scope) {
          case Oa4mpClientScopeEnum::OpenId:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::OpenId;
            break;
          case Oa4mpClientScopeEnum::Profile:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::Profile;
            break;
          case Oa4mpClientScopeEnum::Email:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::Email;
            break;
          case Oa4mpClientScopeEnum::OrgCilogonUserInfo:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::OrgCilogonUserInfo;
            break;
          case Oa4mpClientScopeEnum::Getcert:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = Oa4mpClientScopeEnum::Getcert;
            break;
          default:
            $oa4mpClient['Oa4mpClientCoScope'][]['scope'] = $scope;
            break;
        }
      }

      // If and only if the server object has token_endpoint_auth_method value none
      // and the single scope openid then this is a public client.
      $oa4mpClient['Oa4mpClientCoOidcClient']['public_client'] = false;
      if(!empty($oa4mpObject['token_endpoint_auth_method'])) {
        if($oa4mpObject['token_endpoint_auth_method'] == 'none') {
          if((count($oa4mpClient['Oa4mpClientCoScope']) == 1) && ($oa4mpClient['Oa4mpClientCoScope'][0]['scope'] == Oa4mpClientScopeEnum::OpenId)) {
            $oa4mpClient['Oa4mpClientCoOidcClient']['public_client'] = true;
          }
        }
      }

      // Capture any keys from the OA4MP server response that are not in the
      // known keys list. These are stored in the database and sent back to
      // the OA4MP server during an edit action so that configuration details
      // not represented in the plugin's data model are not lost.
      $extraKeys = array();
      foreach($oa4mpObject as $key => $value) {
        if(!in_array($key, $knownKeys)) {
          $extraKeys[$key] = $value;
        }
      }

      if(!empty($extraKeys)) {
        $oa4mpClient['Oa4mpClientCoOidcClient']['oa4mp_server_extra'] = json_encode($extraKeys);
        // Masked JSON, not print_r: see the catch at the end of this method.
        // The extras blob is whatever the server returned outside $knownKeys,
        // so it is exactly the place an unmodelled credential arrives.
        $this->log("Captured extra keys from OA4MP server: "
                   . $this->redactSecretsInLogText(json_encode($extraKeys)));
      }

      // Unmarshall the cfg object, if any.

      // If no cfg object then we are done.
      if(!isset($oa4mpObject['cfg'])){
        $this->log("No cfg object found in oa4mpObject");
        return $oa4mpClient;
      }
  
      $cfg = $oa4mpObject['cfg'];
      // The cfg is the half of the server object that carries the DynamoDB
      // module's access_key_id and secret_access_key, so it goes to the log as
      // masked JSON like every other credential-bearing structure here. A
      // print_r rendering is not JSON-shaped and the redactor cannot see into
      // it; see the catch at the end of this method.
      $this->log("Cast JSON cfg from OA4MP server to "
                 . $this->redactSecretsInLogText(json_encode($cfg)));

      // Try cfg format 3 first.
      $configs = $this->oa4mpUnMarshallCfgQdlv3($cfg);

      if(!empty($configs)) {
        // Same reason: the QDLv3 unmarshalling carries the same two AWS
        // credentials forward under the plugin's own aws_* column names.
        $this->log("Unmarshalled cfg QDLv3 syntax to "
                   . $this->redactSecretsInLogText(json_encode($configs)));
        $oa4mpClient = array_merge($oa4mpClient, $configs);
          
        return $oa4mpClient;
      }

      // Per-call memoization for the CoProvisioningTarget lookup the legacy-format
      // claim conversion uses. Shared across the QDLv2 and deprecated paths.
      $lookupCache = array();

      // Try cfg format 2 next.
      $ldapConfigs = $this->oa4mpUnMarshallCfgQdlv2($cfg);

      if(!empty($ldapConfigs)) {
        $this->log("Unmarshalled cfg QDL syntax to " . print_r($ldapConfigs, true));
        foreach($ldapConfigs as $ldapConfig) {
          if(empty($ldapConfig['Oa4mpClientCoSearchAttribute'])) {
            continue;
          }
          foreach($ldapConfig['Oa4mpClientCoSearchAttribute'] as $sa) {
            $mapping = array(
              'ldap_attribute_name' => $sa['name'],
              'return_name'         => $sa['return_name'],
              'return_as_list'      => !empty($sa['return_as_list']),
            );
            $claim = $this->buildClaimFromLdapMapping($mapping, $ldapConfig['serverurl'], $adminClient, $lookupCache);
            if($claim !== null) {
              $oa4mpClient['Oa4mpClientClaim'][] = $claim;
            }
          }
        }

        return $oa4mpClient;
      }

      // If QDL syntax did not work try assuming older deprecated syntax.
      $ldapConfigs = $this->oa4mpUnMarshallCfgDeprecated($cfg);

      if(!empty($ldapConfigs)) {
        $this->log("Unmarshalled deprecated cfg to " . print_r($ldapConfigs, true));
        foreach($ldapConfigs as $ldapConfig) {
          if(empty($ldapConfig['Oa4mpClientCoSearchAttribute'])) {
            continue;
          }
          foreach($ldapConfig['Oa4mpClientCoSearchAttribute'] as $sa) {
            $mapping = array(
              'ldap_attribute_name' => $sa['name'],
              'return_name'         => $sa['return_name'],
              'return_as_list'      => !empty($sa['return_as_list']),
            );
            $claim = $this->buildClaimFromLdapMapping($mapping, $ldapConfig['serverurl'], $adminClient, $lookupCache);
            if($claim !== null) {
              $oa4mpClient['Oa4mpClientClaim'][] = $claim;
            }
          }
        }

        // Check the preProcessing block. Currently we should find a sincle claim source
        // of type 'LDAP' and its config identifier should be consistent with the cfg
        // object.
        if(isset($cfg['claims']['preProcessing'])) {
          $preProcessing = $cfg['claims']['preProcessing'];
          if(isset($preProcessing[0]['$then'][0]['$set_claim_source'])) {
            $claim_source = $preProcessing[0]['$then'][0]['$set_claim_source'];
            if($claim_source[0] != 'LDAP') {
              throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.preprocessing'));
            }
            if($claim_source[1] != $cfg['claims']['sourceConfig'][0]['ldap']['id']) {
              throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.preprocessing'));
            }
          }
        }
      }
      
      // cfg is set but we are not able to unmarshall it as a defined cfg format
      // that uses QDL or the deprecated cfg syntax. That is ok, however, since it
      // may now be a Named Configuration.
      return $oa4mpClient;

    } catch(Exception $e) {
      // JSON and masked, never print_r. redactSecretsInLogText() matches a
      // JSON-shaped "key": "value" pair, so a print_r rendering of this object
      // walks straight past it and lands in the log carrying the server's
      // client_secret, its registration_access_token and the cfg's AWS
      // access_key_id and secret_access_key in the clear. Those logs are not
      // private: the live-server tier writes them to a GitHub Actions log on a
      // public repository. Every other body-logging site in this model renders
      // JSON through the redactor for exactly this reason.
      $this->log("oa4mpObject: " . $this->redactSecretsInLogText(json_encode($oa4mpObject)));
      throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.unmarshall') . ': ' . $e->getMessage());
    }
  }

  /**
   * Build a claim array from an LDAP-search-attribute mapping descriptor.
   * Read-only mirror of Oa4mpClientCoSearchAttribute::toClaim() used by the
   * legacy-cfg unmarshall paths (deprecated, QDLv2) so the resulting claims
   * compare correctly against the persisted-side Oa4mpClientClaim records
   * that toClaim() produced at migration time.
   *
   * IMPORTANT: when changing the switch table or constraint construction
   * here, mirror the change in Oa4mpClientCoSearchAttribute::toClaim() —
   * the two are duplicates by design and must move in lockstep to avoid
   * silent sync drift on legacy-cfg clients.
   *
   * @since COmanage Registry 4.5.0
   * @param array $mapping     Array with 'ldap_attribute_name' and 'return_name'
   *                           keys. Callers may also include 'return_as_list'
   *                           but the value is currently not consumed.
   * @param string $serverUrl  LDAP server URL from the cfg's $ldapConfig['serverurl'];
   *                           used to match the CoLdapProvisionerTarget.
   * @param array $adminClient Admin client carrying CO context; must contain
   *                           $adminClient['Oa4mpClientCoAdminClient']['co_id'].
   * @param array &$lookupCache Per-call memoization for the CoProvisioningTarget
   *                            find result. Keyed by "<coId>|<serverUrl>".
   * @return array|null Claim array (with nested Oa4mpClientClaimConstraint) on
   *                    success, null when the claim cannot be fully reconstructed
   *                    (unknown attribute, missing context, no matching
   *                    provisioner target / attribute).
   */
  function buildClaimFromLdapMapping($mapping, $serverUrl, $adminClient, &$lookupCache) {
    if(empty($mapping['ldap_attribute_name'])) {
      return null;
    }
    $searchAttributeName = $mapping['ldap_attribute_name'];

    if(empty($adminClient['Oa4mpClientCoAdminClient']['co_id'])) {
      $this->log("buildClaimFromLdapMapping: missing co_id from adminClient; skipping " . $searchAttributeName);
      return null;
    }

    $coId = $adminClient['Oa4mpClientCoAdminClient']['co_id'];

    $claim = array();
    $claim['claim_name'] = $mapping['return_name'];
    $claimConstraints = array();
    $useLdapProvisionerConfig = false;

    switch($searchAttributeName) {
      case 'eduPersonOrcid':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'orcid'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'employeeNumber':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $useLdapProvisionerConfig = true;
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'gecos':
        $claim['source_model'] = 'Name';
        $claim['source_model_claim_value_field'] = 'all';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'all'
        );
        $claimConstraints[] = array(
          'constraint_field' => 'primary',
          'constraint_value' => 'true'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'gidNumber':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'gidNumber'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'number';
        break;
      case 'givenName':
        $claim['source_model'] = 'Name';
        $claim['source_model_claim_value_field'] = 'given';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'all'
        );
        $claimConstraints[] = array(
          'constraint_field' => 'primary',
          'constraint_value' => 'true'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'isMemberOf':
        $claim['source_model'] = 'CoGroupMember';
        $claim['source_model_claim_value_field'] = 'member';
        $claimConstraints[] = array(
          'constraint_field' => 'owner',
          'constraint_value' => 'false'
        );
        $claim['claim_value_selection'] = 'all';
        $claim['claim_value_json_format'] = 'string';
        $claim['claim_multiple_value_serialization'] = 'delimited_string';
        $claim['claim_value_string_serialization_delimiter'] = ',';
        break;
      case 'mail':
        $claim['source_model'] = 'EmailAddress';
        $claim['source_model_claim_value_field'] = 'mail';
        $useLdapProvisionerConfig = true;
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'sn':
        $claim['source_model'] = 'Name';
        $claim['source_model_claim_value_field'] = 'family';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'all'
        );
        $claimConstraints[] = array(
          'constraint_field' => 'primary',
          'constraint_value' => 'true'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'uid':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $useLdapProvisionerConfig = true;
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'uidNumber':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $claimConstraints[] = array(
          'constraint_field' => 'type',
          'constraint_value' => 'uidNumber'
        );
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'number';
        break;
      case 'voPersonApplicationUID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $useLdapProvisionerConfig = true;
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'voPersonExternalID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $useLdapProvisionerConfig = true;
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'voPersonID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';
        $useLdapProvisionerConfig = true;
        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      default:
        $this->log("buildClaimFromLdapMapping: did not convert LDAP search attribute " . $searchAttributeName . " (not in switch table)");
        return null;
    }

    if($useLdapProvisionerConfig) {
      if(empty($serverUrl)) {
        $this->log("buildClaimFromLdapMapping: missing serverUrl; skipping " . $searchAttributeName);
        return null;
      }
      $cacheKey = $coId . '|' . $serverUrl;
      if(!isset($lookupCache[$cacheKey])) {
        $coProvisioningTargetModel = ClassRegistry::init('CoProvisioningTarget');
        $coProvisioningTargetModel->bindModel(array(
          'hasOne' => array(
            'CoLdapProvisionerTarget' => array(
              'className' => 'LdapProvisioner.CoLdapProvisionerTarget',
              'foreignKey' => 'co_provisioning_target_id'
            )
          )
        ));

        $args = array();
        $args['conditions']['CoProvisioningTarget.co_id'] = $coId;
        $args['conditions']['CoProvisioningTarget.plugin'] = 'LdapProvisioner';
        $args['contain'] = array('CoLdapProvisionerTarget' => array('CoLdapProvisionerAttribute'));

        $lookupCache[$cacheKey] = $coProvisioningTargetModel->find('all', $args);
      }
      $coProvisioningTargets = $lookupCache[$cacheKey];

      if(empty($coProvisioningTargets)) {
        $this->log("buildClaimFromLdapMapping: no CoProvisioningTargets for co_id " . $coId . "; skipping " . $searchAttributeName);
        return null;
      }

      $ldapProvisionerTarget = null;
      foreach($coProvisioningTargets as $coProvisioningTarget) {
        if(!empty($coProvisioningTarget['CoLdapProvisionerTarget']['serverurl'])
            && $coProvisioningTarget['CoLdapProvisionerTarget']['serverurl'] == $serverUrl) {
          $ldapProvisionerTarget = $coProvisioningTarget['CoLdapProvisionerTarget'];
          break;
        }
      }

      if(empty($ldapProvisionerTarget)) {
        $this->log("buildClaimFromLdapMapping: no CoLdapProvisionerTarget matched serverurl " . $serverUrl . "; skipping " . $searchAttributeName);
        return null;
      }

      $matchedAttribute = null;
      if(!empty($ldapProvisionerTarget['CoLdapProvisionerAttribute'])) {
        foreach($ldapProvisionerTarget['CoLdapProvisionerAttribute'] as $ldapProvisionerAttribute) {
          if($ldapProvisionerAttribute['attribute'] == $searchAttributeName) {
            $matchedAttribute = $ldapProvisionerAttribute;
            break;
          }
        }
      }

      if(empty($matchedAttribute)) {
        $this->log("buildClaimFromLdapMapping: no CoLdapProvisionerAttribute named " . $searchAttributeName . " on target for serverurl " . $serverUrl . "; skipping");
        return null;
      }

      // Compute the constraint value via the shared helper on
      // Oa4mpClientCoSearchAttribute. This is the comparator side of the
      // lockstep-mirror contract -- the writer (toClaim) calls the same helper
      // with the same arguments and produces byte-identical output, so a
      // freshly-migrated client reports "in sync" here. When the helper
      // returns null (effective filter empty for voPersonApplicationUID with
      // attr_opts enabled), the persisted claim should not exist; return null
      // from buildClaimFromLdapMapping so isClientDataSynchronized reports
      // drift if a claim row is present despite the empty effective filter.
      $attrOpts = !empty($ldapProvisionerTarget['attr_opts']);
      $useCoServiceFilter = ($searchAttributeName === 'voPersonApplicationUID' && $attrOpts);

      $searchAttributeModel = ClassRegistry::init('Oa4mpClient.Oa4mpClientCoSearchAttribute');
      $constraintValue = $searchAttributeModel->computeVoPersonApplicationUidConstraint(
        $coId,
        $matchedAttribute['type'],
        $useCoServiceFilter,
        $lookupCache
      );

      if($useCoServiceFilter && $constraintValue === null) {
        $this->log("buildClaimFromLdapMapping: voPersonApplicationUID effective filter empty for co_id " . $coId . " (LdapProvisionerAttribute.type='" . $matchedAttribute['type'] . "', attr_opts=on, no matching CoService); expecting no claim");
        return null;
      }

      $claimConstraints[] = array(
        'constraint_field' => 'type',
        'constraint_value' => $constraintValue
      );
    }

    $claim['Oa4mpClientClaimConstraint'] = $claimConstraints;
    return $claim;
  }

  /**
   * Unmarshall oa4mp cfg object to oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   * assuming the deprecated cfg syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $cfg oa4mp cfg object
   * @return array of oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   */
  function oa4mpUnMarshallCfgDeprecated($cfg) {
    if(isset($cfg['config'])) {
      if($cfg['config'] != _txt('pl.oa4mp_client_co_oidc_client.signature')) {
        throw new LogicException(_txt('pl.oa4mp_client_co_oidc_client.er.bad_signature'));
      }
    }

    // Initialize empty array. We return an empty array if the oa4mp cfg object
    // does not contain the deprecated syntax.
    $ldapConfigs = array();

    if(isset($cfg['claims']['sourceConfig'])) {
      foreach($cfg['claims']['sourceConfig'] as $key => $sourceConfig) {
        $ldapConfig = array();

        if(isset($sourceConfig['ldap'])) {
          $ldap = $sourceConfig['ldap'];

          $ldapConfig['authorization_type'] = $ldap['authorizationType'];
          $ldapConfig['enabled'] = $ldap['enabled'];

          $address = $ldap['address'];
          $port = $ldap['port'];
          if($port == 636) {
            $ldapConfig['serverurl'] = 'ldaps://' . $address;
          } else {
            $ldapConfig['serverurl'] = 'ldap://' . $address;
          }

          $ldapConfig['binddn'] = $ldap['principal'];
          $ldapConfig['password'] = $ldap['password'];
          $ldapConfig['basedn'] = $ldap['searchBase'];
          $ldapConfig['search_name'] = $ldap['searchName'];

          if(isset($ldap['searchAttributes'])) {
            $ldapConfig['Oa4mpClientCoSearchAttribute'] = array();

            foreach($ldap['searchAttributes'] as $key => $mapping) {
              $sa = array();
              $sa['name'] = $mapping['name'];
              $sa['return_name'] = $mapping['returnName'];

              // The Oa4mp server currently returns a string value of
              // 'true' or 'false'. That should probably be fixed to
              // return a JSON boolean so detect both here.
              if(($mapping['returnAsList'] == 'true') || ($mapping['returnAsList'] === true)){
                $sa['return_as_list'] = true;
              } else {
                $sa['return_as_list'] = null;
              }

              $ldapConfig['Oa4mpClientCoSearchAttribute'][] = $sa;
            }
          }
        }

        if(!empty($ldapConfig)) {
          $ldapConfigs[] = $ldapConfig;
        }
      }
    }

    return $ldapConfigs;
  }

  /**
   * Unmarshall oa4mp cfg object to oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   * assuming QDL syntax.
   *
   * @since COmanage Registry 4.0.0
   * @param array $cfg oa4mp cfg object
   * @return array of oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   */
  function oa4mpUnMarshallCfgQdlv2($cfg) {
    // Initialize empty array. We return an empty array if the oa4mp cfg object
    // does not contain the expected QDL syntax.
    $ldapConfigs = array();

    // Try to parse the cfg as a defined format. See
    // https://github.com/cilogon/Oa4mpClient/blob/main/cfg_format.md
    try {
      if(!empty($cfg['tokens']['identity']['qdl'])) {
        $qdl_pre_auth = $cfg['tokens']['identity']['qdl'][0];

        if(!empty($qdl_pre_auth['args'])){ 
          $qdl_args = $qdl_pre_auth['args'];

          $ldapConfig = array();

          // This is required by the current schema but is deprecated after
          // the transition to QDL.
          $ldapConfig['authorization_type'] = 'simple';
          $ldapConfig['enabled'] = true;

          $address = $qdl_args['server_fqdn'];
          $port = $qdl_args['server_port'];
          if($port == 636) {
            $ldapConfig['serverurl'] = 'ldaps://' . $address;
          } else {
            $ldapConfig['serverurl'] = 'ldap://' . $address;
          }

          $ldapConfig['binddn'] = $qdl_args['bind_dn'];
          $ldapConfig['password'] = $qdl_args['bind_password'];
          $ldapConfig['basedn'] = $qdl_args['search_base'];
          $ldapConfig['search_name'] = $qdl_args['search_attribute'];

          // Default to an empty array when 'list_attributes' is absent so the
          // in_array() call below does not throw a TypeError under PHP 8.x.
          // An absent 'list_attributes' means no attributes are multi-valued
          // lists; every attribute defaults to return_as_list = false.
          $listAttributes = $qdl_args['list_attributes'] ?? array();

          // Initialize the LDAP to claim mappings as empty.
          $ldapToClaimMappings = array();

          if(array_key_exists('ldap_to_claim_mappings', $qdl_args)) {
            // COmanage Registry OA4MP plugin cfg format 2.0.0.
            $ldapToClaimMappings = $qdl_args['ldap_to_claim_mappings'];
          } else {
            // COmanage Registry OA4MP plugin cfg format 1.0.0.
            if(count($cfg['tokens']['identity']['qdl']) == 2) {
              if(array_key_exists('args', $cfg['tokens']['identity']['qdl'][1])){
                $ldapToClaimMappings = $cfg['tokens']['identity']['qdl'][1]['args'];
              }
            }
          }

          $ldapConfig['Oa4mpClientCoSearchAttribute'] = array();

          foreach($ldapToClaimMappings as $key => $mapping) {
            $sa = array();
            $sa['name'] = $key;
            $sa['return_name'] = $mapping;

            if(in_array($key, $listAttributes)) {
              $sa['return_as_list'] = true;
            } else {
              $sa['return_as_list'] = false;
            }

            $ldapConfig['Oa4mpClientCoSearchAttribute'][] = $sa;
          }

          // At this time we assume a single LDAP configuration in the QDL.
          $ldapConfigs[] = $ldapConfig;
        }
      }
    } catch (Exception $e) {
      $this->log("Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration"
                 . " (Exception at " . $e->getFile() . ":" . $e->getLine()
                 . " - " . $e->getMessage() . ")");
      return array();
    } catch (TypeError $e) {
      $this->log("Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration"
                 . " (TypeError at " . $e->getFile() . ":" . $e->getLine()
                 . " - " . $e->getMessage() . ")");
      return array();
    }

    return $ldapConfigs;
  }

  /**
   * Unmarshall oa4mp cfg object to oa4mpClient objects
   * assuming QDLv3 syntax.
   *
   * @since COmanage Registry 4.5.0
   * @param array $cfg oa4mp cfg object
   * @return array of oa4mpClient['Oa4mpClientCoLdapConfig'] objects
   */
  function oa4mpUnMarshallCfgQdlv3($cfg) {
    $oa4mpClient = array();

    // Unmarshall access token configuration.
    if(!empty($cfg['tokens']['access']['type'])) {
      if($cfg['tokens']['access']['type'] == 'access') {
        $oa4mpClient['Oa4mpClientAccessToken'] = array();
        $oa4mpClient['Oa4mpClientAccessToken']['is_jwt'] = true;
      }
    }

    // Unmarshall QDL arguments.
    if(!empty($cfg['tokens']['identity']['qdl']['args'])) {
      $qdlArgs = $cfg['tokens']['identity']['qdl']['args'];

      $authz = array();

    // Unmarshall client authorization configuration.
      if(!empty($qdlArgs['require_active_status'])) {
        $authz['require_active'] = $qdlArgs['require_active_status'];
      }
      if(!empty($qdlArgs['authorization_group_id'])) {
        $authz['authz_co_group_id'] = $qdlArgs['authorization_group_id'];
      }
      if(!empty($qdlArgs['authorization_group_redirect_url'])) {
        $authz['authz_group_redirect_url'] = $qdlArgs['authorization_group_redirect_url'];
      }
      if(!empty($qdlArgs['require_active_redirect_url'])) {
        $authz['require_active_redirect_url'] = $qdlArgs['require_active_redirect_url'];
      }

      if(!empty($authz)) {
        $oa4mpClient['Oa4mpClientAuthorization'] = $authz;
      }

    // Unmarshall DynamoDB configuration.
      $oa4mpClient['Oa4mpClientDynamoConfig']['partition_key_template'] = $qdlArgs['partition_key_template'];
      $oa4mpClient['Oa4mpClientDynamoConfig']['partition_key_claim_name'] = $qdlArgs['partition_key_claim_name'];

      // No sort_key or sort_key_template read-back. cfg_contract.json's
      // qdl_args group declares neither name, so no cfg the plugin writes
      // carries either; reading them back could only ever produce null, and
      // the comparison that consumed that null reported an operator-populated
      // sort key permanently out of sync. See the matching note in
      // isClientDataSynchronized().

      if(!empty($qdlArgs['dynamo_module_config'])) {
        $dynamoModuleConfig = $qdlArgs['dynamo_module_config'];

        $oa4mpClient['Oa4mpClientDynamoConfig']['aws_region'] = $dynamoModuleConfig['region'];
        $oa4mpClient['Oa4mpClientDynamoConfig']['aws_access_key_id'] = $dynamoModuleConfig['access_key_id'];
        $oa4mpClient['Oa4mpClientDynamoConfig']['aws_secret_access_key'] = $dynamoModuleConfig['secret_access_key'];
        $oa4mpClient['Oa4mpClientDynamoConfig']['table_name'] = $dynamoModuleConfig['table_name'];
        $oa4mpClient['Oa4mpClientDynamoConfig']['partition_key'] = $dynamoModuleConfig['partition_key'];
      }

    // Unmarshall claim mappings.
      if(!empty($qdlArgs['claim_mappings'])) {
        $qdlClaimMappings = $qdlArgs['claim_mappings'];
        $claimMappings = array();

        foreach($qdlClaimMappings as $qdlClaimMapping) {
          // Read back exactly the fields the contract declares a mapping may
          // carry, from the same group the marshaller emits from. Two
          // hand-written lists were free to drift apart, and did: a field the
          // marshaller emitted and this loop did not read was dropped on the
          // way back and never compared, so the round trip reported "in sync"
          // whatever that field held.
          $claimMapping = array();

          foreach($this->cfgContractNames('claim_mapping_fields') as $field) {
            if($field === self::CFG_CONSTRAINTS_FIELD) {
              // Republished below under the association key the comparator
              // reads, not under its cfg name.
              continue;
            }

            // empty(), matching the marshaller: a field it omitted for being
            // empty is absent here, and one it emitted is present.
            if(empty($qdlClaimMapping[$field])) {
              continue;
            }

            $claimMapping[$field] = $qdlClaimMapping[$field];
          }

          if(!empty($qdlClaimMapping[self::CFG_CONSTRAINTS_FIELD])) {
            $qdlClaimConstraints = $qdlClaimMapping[self::CFG_CONSTRAINTS_FIELD];
            $claimConstraints = array();

            foreach($qdlClaimConstraints as $qdlClaimConstraint) {
              $claimConstraint = array();

              foreach($this->cfgContractNames('claim_constraint_fields') as $field) {
                if(empty($qdlClaimConstraint[$field])) {
                  continue;
                }

                $claimConstraint[$field] = $qdlClaimConstraint[$field];
              }

              $claimConstraints[] = $claimConstraint;
            }
            $claimMapping['Oa4mpClientClaimConstraint'] = $claimConstraints;
          }
          $claimMappings[] = $claimMapping;
        }

        $oa4mpClient['Oa4mpClientClaim'] = $claimMappings;
      }
    }

    return $oa4mpClient;
  }

  /**
   * Verify existing OIDC client data is synchronized with the oa4mp server.
   *
   * @since COmanage Registry 3.2.5
   * @param  Array $adminClient admin client
   * @param  Array $curClient current client
   * @param  Boolean $returnExtras if true, return array with sync status and extra keys
   * @return Mixed Boolean if $returnExtras is false, otherwise array with
   *               'synchronized', 'oa4mp_server_extra' and 'error' keys. See
   *               compareToServerObject() for what 'error' distinguishes.
   */

  function oa4mpVerifyClient($adminClient, $curClient, $returnExtras = false) {
    $http = new HttpSocket();

    $request = $this->oa4mpInitializeRequest($adminClient);

    $client_id = $curClient['Oa4mpClientCoOidcClient']['oa4mp_identifier'];
    $request['uri']['query'] = array('client_id' => $client_id);

    $this->log("OA4MP Server request URI is " . print_r($request['uri'], true));
    $this->log("OA4MP Server request method is " . print_r($request['method'], true));
    $this->log("OA4MP Server request body is " . print_r(null, true));

    $response = $http->request($request);

    $this->log("OA4MP Server response is " . $this->redactSecretsInLogText(print_r($response, true)));

    $contentType = $response->getHeader('Content-Type');

    if(str_contains($contentType, 'ISO-8859-1')) {
      $oa4mpObject = json_decode(mb_convert_encoding($response->body(), 'UTF-8', 'ISO-8859-1'), true);
    } else {
      $oa4mpObject = json_decode($response->body(), true);
    }

    $oa4mpObject = json_decode($response->body(), true);

    $comparison = $this->compareToServerObject($adminClient, $curClient, $oa4mpObject);

    // Return based on whether extras were requested.
    if($returnExtras) {
      return $comparison;
    }

    return $comparison['synchronized'];
  }

  /**
   * Compare a client against the OA4MP server's representation of it, given
   * that representation rather than fetching it.
   *
   * Split out of oa4mpVerifyClient() so that the failure path below is
   * reachable without a socket. oa4mpVerifyClient() constructs its own
   * HttpSocket, and the hermetic test tier must never make an HTTP request, so
   * before this split the only way to exercise the catch was against a live
   * server -- which is why the misleading failure it produced was never
   * covered.
   *
   * The 'error' key is what the catch exists to report. An exception here
   * means the comparison did not happen: the cfg capability contract is
   * unreadable or malformed, the cfg is a shape the unmarshaller cannot read,
   * a TypeError fired mid-parse. None of that is evidence the client was
   * changed outside the Registry, and a caller that reads a failed comparison
   * as a mismatch tells the operator the client was tampered with. See
   * oa4mpEditClient(), which maps the two outcomes to different return codes.
   *
   * @since COmanage Registry 4.5.1
   * @param  Array $adminClient admin client
   * @param  Array $curClient current client
   * @param  Mixed $oa4mpObject decoded OA4MP server representation of the client
   * @return Array with keys 'synchronized' (Boolean), 'oa4mp_server_extra'
   *               (Mixed) and 'error' (Boolean: the comparison did not run).
   */

  protected function compareToServerObject($adminClient, $curClient, $oa4mpObject) {
    $comparison = array(
      'synchronized' => false,
      'oa4mp_server_extra' => null,
      'error' => false
    );

    try {
      // Unmarshall the Oa4mp server representation of the client
      // and compare it to the current client to detect if the client
      // has been changed outside of this plugin.
      $oa4mpServerData = $this->oa4mpUnMarshallContent($oa4mpObject, $adminClient);
      $comparison['synchronized'] = $this->isClientDataSynchronized($curClient, $oa4mpServerData);

      // Capture any extra keys from the OA4MP server response.
      if(!empty($oa4mpServerData['Oa4mpClientCoOidcClient']['oa4mp_server_extra'])) {
        $comparison['oa4mp_server_extra'] =
          $oa4mpServerData['Oa4mpClientCoOidcClient']['oa4mp_server_extra'];
      }
    }
    catch(Exception $e) {
      // Name the exception, where it was raised, and what it said. A catch
      // that logs only getMessage() hands the next operator an interpretation
      // with no evidence behind it; that is exactly the failure recorded in
      // docs/solutions/logic-errors/oa4mp-cfg-unmarshall-swallowed-typeerror-2026-05-12.md,
      // and the same shape is already applied in oa4mpUnMarshallCfgQdlv2().
      $comparison['error'] = true;
      $this->log("Caught exception during unmarshall of Oa4mp server object: "
                 . get_class($e) . " at " . $e->getFile() . ":" . $e->getLine()
                 . " - " . $e->getMessage());
    }
    catch(TypeError $e) {
      // Error does not extend Exception under PHP 8, so without this a
      // TypeError raised mid-parse leaves the request as an uncaught 500
      // rather than a reported internal failure. Same message shape, on
      // purpose: what the log needs is the identity, not the hierarchy.
      $comparison['error'] = true;
      $this->log("Caught error during unmarshall of Oa4mp server object: "
                 . get_class($e) . " at " . $e->getFile() . ":" . $e->getLine()
                 . " - " . $e->getMessage());
    }

    return $comparison;
  }
}