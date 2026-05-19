<?php
/**
 * COmanage Registry Oa4mp Client Plugin CO Search Attribute Model
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
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClientCoSearchAttribute extends AppModel {
  // Define class name for cake
  public $name = "Oa4mpClientCoSearchAttribute";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    // An Oa4mp Client search attribute is attached to an LDAP config
    "Oa4mpClient.Oa4mpClientCoLdapConfig" => array(
      'foreignKey' => 'ldap_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'ldap_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
    ),
    'name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'return_as_list' => array(
      'rule' => 'boolean',
      'required' => true,
      'allowEmpty' => false
    ),
    'return_name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'claim_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    )
  );

  /**
   * Compute the persisted 'type' constraint_value for a voPersonApplicationUID
   * claim, mirroring the LdapProvisioner's effective LDAP export filter.
   *
   * When LDAP attribute options are enabled on the CoLdapProvisionerTarget,
   * the LdapProvisioner (see comanage-registry app/Plugin/LdapProvisioner/
   * Model/CoLdapProvisionerTarget.php lines 692-718) exports the
   * voPersonApplicationUID identifier ONLY for identifier types where at
   * least one CoService in the CO has a matching identifier_type. Without
   * attribute options, the LdapProvisioner exports every identifier matching
   * the configured 'type' filter (or all types if the filter is empty/'all').
   *
   * This helper is the single source of truth for both the writer
   * (Oa4mpClientCoSearchAttribute::toClaim) and the sync comparator
   * (Oa4mpClientOa4mpServer::buildClaimFromLdapMapping) so the constraint
   * value persisted by toClaim is byte-identical to the value the
   * comparator computes from current CoService state -- the architectural
   * enforcement of the lockstep-mirror discipline documented in
   * docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md.
   *
   * @param integer $coId CO ID.
   * @param string|null $ldapProvisionerAttributeType The CoLdapProvisionerAttribute.type value ('' or null = "All Types").
   * @param boolean $useCoServiceFilter True only when the effective-filter (CoService-based) path
   *                                    should apply: i.e. the search attribute is voPersonApplicationUID
   *                                    AND the CoLdapProvisionerTarget has attribute options enabled.
   *                                    Callers pre-compose this compound boolean from
   *                                    ($searchAttributeName === 'voPersonApplicationUID' AND attr_opts).
   *                                    When false, the helper falls into the bare-literal-with-empty
   *                                    normalization branch regardless of which attribute type the
   *                                    caller is processing.
   * @param array|null $lookupCache Optional reference to the buildClaimFromLdapMapping lookup cache so
   *                                the CoService query result is reused across multiple search attributes
   *                                within a single sync run. Pass null from the writer (single-shot edit).
   *                                Cache key shape: 'coService|{coId}' (will not collide with the
   *                                existing CoProvisioningTarget cache keys which use a different shape).
   * @return string|null The encoded constraint_value, or null to signal "suppress the claim entirely".
   *                     Encoding:
   *                       - $useCoServiceFilter false: bare literal type with empty/null normalized to 'all'.
   *                       - $useCoServiceFilter true, effective set empty: null (caller suppresses).
   *                       - $useCoServiceFilter true, effective set non-empty: uniform anchored regex.
   *                         Single element: '^<escaped>$'. Multi: '^(<escaped1>|<escaped2>|...)$'
   *                         with alternation parts sorted lexicographically on the escaped form.
   */

  public function computeVoPersonApplicationUidConstraint($coId, $ldapProvisionerAttributeType, $useCoServiceFilter, &$lookupCache = null) {
    // Bare-literal branch: existing literal-with-empty-normalization shape. The empty-to-'all'
    // normalization here mirrors what toClaim has emitted since commit f298ba0 (see
    // docs/solutions/logic-errors/oa4mp-ldap-provisioner-empty-type-claim-constraint-2026-05-18.md).
    // This branch fires for every search attribute when attr_opts is OFF, and for every search
    // attribute other than voPersonApplicationUID even when attr_opts is ON -- the CoService-derived
    // filter is voPersonApplicationUID-specific in the LdapProvisioner, so the helper mirrors that.
    if(!$useCoServiceFilter) {
      if($ldapProvisionerAttributeType === '' || $ldapProvisionerAttributeType === null) {
        return 'all';
      }
      return $ldapProvisionerAttributeType;
    }

    // CoService-filter branch (only reached when the caller has confirmed both
    // $searchAttributeName === 'voPersonApplicationUID' AND attr_opts is enabled).
    // Compute the effective identifier-type set from CoService.
    // Cache the per-CO CoService query alongside the buildClaimFromLdapMapping
    // lookupCache so a sync run over a client with many search attributes does
    // not repeat the query.
    $cacheKey = 'coService|' . $coId;
    if(is_array($lookupCache) && isset($lookupCache[$cacheKey])) {
      $coServiceIdentifierTypes = $lookupCache[$cacheKey];
    } else {
      $coServiceModel = ClassRegistry::init('CoService');

      $args = array();
      $args['conditions']['CoService.co_id'] = $coId;
      $args['fields'] = array('CoService.identifier_type');
      $args['contain'] = false;

      $rows = $coServiceModel->find('all', $args);

      // CakePHP 2's find('all') can return false on a DB error (e.g., connection
      // lost mid-request). foreach(false) on PHP 8 emits a warning but iterates
      // zero times, which would silently treat the error as "no CoServices
      // matched" -- and worse, poison the per-sync-run $lookupCache with that
      // empty array so every subsequent voPersonApplicationUID claim in the
      // same sync run also returns null (driving false-positive drift reports
      // in isClientDataSynchronized). Bail out before the foreach and before
      // writing the cache when the find failed. Returning null here is
      // semantically overloaded with "genuinely empty effective set"; callers
      // distinguish via the log line below for incident diagnostics.
      if($rows === false) {
        $this->log("computeVoPersonApplicationUidConstraint: CoService->find('all') returned false for co_id " . $coId . "; treating as suppress-claim without caching the empty result");
        return null;
      }

      // Mirror the accumulator pattern from commit c503465 -- no foreach self-assign.
      $coServiceIdentifierTypes = array();
      foreach($rows as $row) {
        if(!empty($row['CoService']['identifier_type'])) {
          $coServiceIdentifierTypes[] = $row['CoService']['identifier_type'];
        }
      }
      $coServiceIdentifierTypes = array_values(array_unique($coServiceIdentifierTypes));

      if(is_array($lookupCache)) {
        $lookupCache[$cacheKey] = $coServiceIdentifierTypes;
      }
    }

    // If the LdapProvisionerAttribute.type is specific (non-empty, non-null), intersect
    // with the CoService set. If empty/null ("All Types"), use the full CoService set.
    if($ldapProvisionerAttributeType === '' || $ldapProvisionerAttributeType === null) {
      $effectiveSet = $coServiceIdentifierTypes;
    } else {
      $effectiveSet = array();
      foreach($coServiceIdentifierTypes as $type) {
        if($type === $ldapProvisionerAttributeType) {
          $effectiveSet[] = $type;
        }
      }
    }

    // Empty effective set -> no claim should exist (mirrors LdapProvisioner suppressing the export).
    if(empty($effectiveSet)) {
      return null;
    }

    // Encode the effective set as a uniform anchored regex. Single-element: '^X$'. Multi: '^(A|B|...)$'.
    // Each element is regex-escaped (preg_quote) before inclusion so future operator-defined
    // identifier types containing regex metacharacters cannot break matching at the QDL layer.
    // Sort on the escaped form so writer and comparator produce byte-identical strings regardless
    // of database row order.
    $escaped = array();
    foreach($effectiveSet as $type) {
      $escaped[] = preg_quote($type, '/');
    }
    sort($escaped);

    if(count($escaped) == 1) {
      return '^' . $escaped[0] . '$';
    }
    return '^(' . implode('|', $escaped) . ')$';
  }

  /**
   * Convert an LDAP search attribute to a claim object (and persist it).
   *
   * IMPORTANT: the read-only output shape of this function (the switch
   * table and provisioner-target lookup, lines 98-313) is mirrored by
   * Oa4mpClientOa4mpServer::buildClaimFromLdapMapping() so the legacy-cfg
   * unmarshall paths can produce comparable claims for sync verification.
   * When changing the switch cases, source_model/field assignments, or
   * constraint construction here, mirror the change there to avoid
   * silent sync drift on legacy-cfg clients.
   *
   * @param integer $clientId The ID of the OIDC client.
   * @param integer $coId The ID of the CO.
   * @param array $dynamoConfig Instance of Oa4mpClientDynamoConfig.
   * @param array $coLdapConfig Instance of Oa4mpClientCoLdapConfig.
   * @param array $searchAttribute Instance of Oa4mpClientCoSearchAttribute.
   * @return void
   */

  public function toClaim($clientId, $coId, $dynamoConfig, $coLdapConfig, $searchAttribute) {
    $claim = array();
    $claimConstraints = array();

    $claim['client_id'] = $clientId;
    $claim['claim_name'] = $searchAttribute['return_name'];

    // Different logic is required for different LDAP Provisioner Attributes.
    $useLdapProvisionerConfig = false;

    $searchAttributeName = $searchAttribute['name'];

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

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
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

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
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

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
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

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'voPersonExternalID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      case 'voPersonID':
        $claim['source_model'] = 'Identifier';
        $claim['source_model_claim_value_field'] = 'identifier';

        // The claim constraint field is always 'type' and the constraint value is determined
        // by inspecting the configured LDAP Provisioning Config for the LDAP Config.
        $useLdapProvisionerConfig = true;

        $claim['claim_value_selection'] = 'first';
        $claim['claim_value_json_format'] = 'string';
        break;
      default:
        $this->log("Did not convert LDAP search attribute " . $searchAttribute['name'] . " to claim object because it is not supported");
        return;
        break;
    }

    if($useLdapProvisionerConfig) {
      $args = array();
      $args['conditions']['CoProvisioningTarget.co_id'] = $coId;
      $args['conditions']['CoProvisioningTarget.plugin'] = 'LdapProvisioner';
      $args['contain'] = array('CoLdapProvisionerTarget' => array('CoLdapProvisionerAttribute'));

      // We need to dynamically bind the CoLdapProvisionerTarget model to the CoProvisioningTarget model.
      $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoAdminClient->Co->CoProvisioningTarget->bindModel(array(
        'hasOne' => array(
          'CoLdapProvisionerTarget' => array(
            'className' => 'LdapProvisioner.CoLdapProvisionerTarget',
            'foreignKey' => 'co_provisioning_target_id'
          )
        )
      ));

      $coProvisioningTargets = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoAdminClient->Co->CoProvisioningTarget->find('all', $args);

      if(empty($coProvisioningTargets)) {
        $this->log("No coProvisioningTargets found for LDAP Config " . $coLdapConfig['id']);
        $this->log("Did not convert LDAP search attribute " . $searchAttributeName . " to claim object because no coProvisioningTargets were found");
        return;
      }

      // Loop over the coProvisioningTargets and pick out the one where the serverurl matches the LDAP Config's serverurl.
      $ldapProvisionerTarget = null;
      foreach($coProvisioningTargets as $coProvisioningTarget) {
        if($coProvisioningTarget['CoLdapProvisionerTarget']['serverurl'] == $coLdapConfig['serverurl']) {
          $ldapProvisionerTarget = $coProvisioningTarget['CoLdapProvisionerTarget'];
          break;
        }
      }

      if(empty($ldapProvisionerTarget)) {
        $this->log("No ldapProvisionerTarget found for LDAP Config " . $coLdapConfig['id']);
        $this->log("Did not convert LDAP search attribute " . $searchAttributeName . " to claim object because no ldapProvisionerTarget was found");
        return;
      }

      // Loop over the ldapProvisionerTarget's CoLdapProvisionerAttributes and pick out the one
      // where the name matches the searchAttribute's name. Mirror the accumulator pattern used
      // by Oa4mpClientOa4mpServer::buildClaimFromLdapMapping so a non-empty list with no match
      // leaves $ldapProvisionerAttribute null instead of leaking the last iterated element
      // (PHP foreach preserves the loop variable after fall-through).
      $ldapProvisionerAttribute = null;
      foreach($ldapProvisionerTarget['CoLdapProvisionerAttribute'] as $candidate) {
        if($candidate['attribute'] == $searchAttributeName) {
          $ldapProvisionerAttribute = $candidate;
          break;
        }
      }

      if(empty($ldapProvisionerAttribute)) {
        $this->log("No ldapProvisionerAttribute found for LDAP Config " . $coLdapConfig['id']);
        $this->log("Did not convert LDAP search attribute " . $searchAttributeName . " to claim object because no ldapProvisionerAttribute was found");
        return;
      }

      // Compute the constraint value via the shared helper. For voPersonApplicationUID
      // with attr_opts enabled, the helper consults CoService to compute the effective
      // identifier-type set the LdapProvisioner would actually export, returning either
      // a uniform anchored regex or null to signal "suppress the claim entirely". For
      // all other cases (attr_opts OFF, or any other search attribute) the helper
      // returns the existing bare literal with empty -> 'all' normalization.
      $attrOpts = !empty($ldapProvisionerTarget['attr_opts']);
      $useCoServiceFilter = ($searchAttributeName === 'voPersonApplicationUID' && $attrOpts);

      $constraintValue = $this->computeVoPersonApplicationUidConstraint(
        $coId,
        $ldapProvisionerAttribute['type'],
        $useCoServiceFilter
      );

      if($useCoServiceFilter && $constraintValue === null) {
        // The effective filter is empty -- no CoService in this CO has a matching
        // identifier_type, so the LdapProvisioner would not export any value. Mirror
        // that on the plugin side by suppressing the claim entirely (no claim row,
        // no back-pointer). This matches the existing "did not convert" early-return
        // pattern in this function.
        $this->log("voPersonApplicationUID claim suppressed for client " . $clientId . " co_id " . $coId . ": effective filter is empty (LdapProvisionerAttribute.type='" . $ldapProvisionerAttribute['type'] . "', attr_opts=on, no matching CoService)");
        return;
      }

      $claimConstraints[] = array(
        'constraint_field' => 'type',
        'constraint_value' => $constraintValue
      );
    }

    $claim['Oa4mpClientClaimConstraint'] = $claimConstraints;

    // Orphan-recovery: before persisting a new claim row, check whether an
    // existing claim row for this (client_id, claim_name) is orphaned (no
    // Oa4mpClientCoSearchAttribute back-pointer) AND matches the identity
    // and constraints we are about to write. If exactly one such matching
    // orphan exists, rewire this search attribute's claim_id to it and skip
    // creation -- this avoids accumulating duplicate claim rows on clients
    // that suffered the pre-1659690 non-atomic-save bug (DynamoConfig->save()
    // between saveAssociated and saveField could fail, leaving the claim row
    // committed but the back-pointer NULL; see
    // docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md
    // Bug 1). Re-running migration after 1659690 lands creates a new claim
    // and sets the back-pointer atomically, but without this guard the old
    // orphans remain forever and drive the "Number of claims is out of sync"
    // comparator error (server side dedupes via ldap_to_claim_mappings JSON
    // object key uniqueness; plugin side counts every claim row).
    //
    // Matching is strict: every claim identity field this function sets, plus
    // the full constraint set, must match canonically. Orphans with the same
    // name but different shape (e.g. voPersonApplicationUID orphans from
    // before the CoService-derived effective filter landed) intentionally
    // fall through to new-claim creation; the operator cleanup runbook in
    // docs/solutions/ handles those.
    $claimModel = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim;

    $candidatesArgs = array();
    $candidatesArgs['conditions']['Oa4mpClientClaim.client_id'] = $clientId;
    $candidatesArgs['conditions']['Oa4mpClientClaim.claim_name'] = $claim['claim_name'];
    $candidatesArgs['contain'] = array('Oa4mpClientClaimConstraint');
    $candidates = $claimModel->find('all', $candidatesArgs);

    if($candidates === false) {
      // CakePHP 2 find('all') can return false on DB error. Skip the orphan-
      // recovery path and fall through to normal claim creation -- if the DB
      // is genuinely broken the saveAssociated below will surface it; we do
      // not want to silently iterate false as empty and proceed without ever
      // having checked for orphans.
      $this->log("toClaim: orphan-recovery: Oa4mpClientClaim->find('all') returned false for client " . $clientId . " name '" . $claim['claim_name'] . "'; falling through to normal claim creation");
    } else {
      // Canonicalize the new claim's identity fields and constraint set so
      // candidates can be compared with byte-equality. Mirrors the lockstep
      // R5 discipline used between toClaim and buildClaimFromLdapMapping:
      // rewire only when the orphan would be a perfect substitute for the
      // new claim, so the very next sync run finds no drift.
      $identityFields = array(
        'source_model',
        'source_model_claim_value_field',
        'claim_value_selection',
        'claim_value_json_format',
        'claim_multiple_value_serialization',
        'claim_value_string_serialization_delimiter'
      );

      $newIdentityParts = array();
      foreach($identityFields as $f) {
        $val = isset($claim[$f]) ? $claim[$f] : null;
        $newIdentityParts[] = $f . '=' . ($val === null ? '<null>' : $val);
      }
      $newIdentityCanonical = implode("\n", $newIdentityParts);

      $newConstraintParts = array();
      foreach($claimConstraints as $c) {
        $newConstraintParts[] = $c['constraint_field'] . '=' . $c['constraint_value'];
      }
      sort($newConstraintParts);
      $newConstraintsCanonical = implode("\n", $newConstraintParts);

      $matchingOrphanIds = array();
      $unmatchingOrphanIds = array();
      foreach($candidates as $candidate) {
        $candidateId = $candidate['Oa4mpClientClaim']['id'];

        // Orphan test: no Oa4mpClientCoSearchAttribute row currently points at
        // this claim. Two-step find rather than a NOT-EXISTS subquery because
        // CakePHP 2's find DSL has no clean expression for the subquery form
        // and the candidate count per (client_id, claim_name) is bounded
        // small by the duplication pattern we are recovering from.
        $pointers = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->find('count', array(
          'conditions' => array('Oa4mpClientCoSearchAttribute.claim_id' => $candidateId),
          'recursive' => -1
        ));
        if($pointers !== 0) {
          // Either a non-zero pointer count (claim is in active use by some
          // search attribute -- skip) or a DB error returning false/null
          // (treat conservatively as "do not rewire to this candidate").
          continue;
        }

        $orphanIdentityParts = array();
        foreach($identityFields as $f) {
          $val = isset($candidate['Oa4mpClientClaim'][$f]) ? $candidate['Oa4mpClientClaim'][$f] : null;
          $orphanIdentityParts[] = $f . '=' . ($val === null ? '<null>' : $val);
        }
        $orphanIdentityCanonical = implode("\n", $orphanIdentityParts);

        $orphanConstraintParts = array();
        $orphanConstraintRows = isset($candidate['Oa4mpClientClaimConstraint']) ? $candidate['Oa4mpClientClaimConstraint'] : array();
        foreach($orphanConstraintRows as $cc) {
          $orphanConstraintParts[] = $cc['constraint_field'] . '=' . $cc['constraint_value'];
        }
        sort($orphanConstraintParts);
        $orphanConstraintsCanonical = implode("\n", $orphanConstraintParts);

        if($orphanIdentityCanonical === $newIdentityCanonical
           && $orphanConstraintsCanonical === $newConstraintsCanonical) {
          $matchingOrphanIds[] = $candidateId;
        } else {
          $unmatchingOrphanIds[] = $candidateId;
        }
      }

      if(count($matchingOrphanIds) === 1) {
        $orphanId = $matchingOrphanIds[0];
        $this->log("toClaim: orphan-recovery: rewiring search_attribute " . $searchAttribute['id'] . " to existing matching orphan claim " . $orphanId . " for client " . $clientId . " name '" . $claim['claim_name'] . "' (avoids creating duplicate)");
        $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->id = $searchAttribute['id'];
        $ret = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->saveField('claim_id', $orphanId);
        if(!$ret) {
          $this->log("toClaim: orphan-recovery: saveField failed for search_attribute " . $searchAttribute['id'] . " to claim " . $orphanId);
        }
        return;
      } elseif(count($matchingOrphanIds) > 1) {
        $this->log("toClaim: orphan-recovery: client " . $clientId . " has " . count($matchingOrphanIds) . " orphan claims (ids: " . implode(',', $matchingOrphanIds) . ") matching new identity+constraints for name '" . $claim['claim_name'] . "'; ambiguous, falling through to new-claim creation -- operator cleanup required");
      }
      if(!empty($unmatchingOrphanIds)) {
        $this->log("toClaim: orphan-recovery: client " . $clientId . " has " . count($unmatchingOrphanIds) . " orphan claim(s) (ids: " . implode(',', $unmatchingOrphanIds) . ") with matching name '" . $claim['claim_name'] . "' but differing identity/constraints; not rewiring -- operator cleanup required");
      }
    }

    // Save the claim and the associated claim constraint(s).
    $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->clear();
    if(!$this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->saveAssociated($claim)) {
      $this->log("saveAssociated failed for claim " . print_r($claim, true));
      $this->log("Validation errors are " . print_r($this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->validationErrors, true));
      $this->log("Did not convert LDAP search attribute " . $searchAttribute['name'] . " to claim object");
      return;
    }

    // Set the searchAttribute's claim_id back-pointer immediately after the claim
    // row is persisted, before any other save that could fail. This keeps the
    // claim row and its back-pointer atomic: the inner per-search-attr guard in
    // the controller's migration block can then reliably suppress reconversion
    // (and the duplicate-claim accumulation that motivated the coarse gate
    // added in d6ffbe1).
    $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->id = $searchAttribute['id'];
    $newId = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientClaim->id;
    $ret = $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoSearchAttribute->saveField('claim_id', $newId);
    if(!$ret) {
      $this->log("saveField failed for searchAttribute with ID " . $searchAttribute['id'] . " to mark it as converted");
      return;
    }

    // Save the Dynamo configuration. If this fails, the claim row and its
    // back-pointer above remain in place — the search attribute is correctly
    // marked as migrated and will not be reprocessed. A missing dynamoConfig
    // is a separate recoverable state and is logged below.
    $this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientDynamoConfig->clear();
    $dynamoConfig['client_id'] = $clientId;
    unset($dynamoConfig['admin_id']);
    unset($dynamoConfig['id']);
    unset($dynamoConfig['created']);
    unset($dynamoConfig['modified']);

    if(!$this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientDynamoConfig->save($dynamoConfig)) {
      $this->log("save failed for dynamoConfig " . print_r($dynamoConfig, true));
      $this->log("Validation errors are " . print_r($this->Oa4mpClientCoLdapConfig->Oa4mpClientCoOidcClient->Oa4mpClientDynamoConfig->validationErrors, true));
      $this->log("LDAP search attribute " . $searchAttribute['name'] . " converted to claim but dynamoConfig save failed");
      return;
    }

    $this->log("Converted LDAP search attribute " . $searchAttribute['name'] . " to claim object " . print_r($claim, true));

    return;
  }
}