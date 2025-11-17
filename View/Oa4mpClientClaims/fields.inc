<?php
/**
 * COmanage Registry Oa4mp Client Plugin Claim Fields
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
 * @package       registry
 * @since         COmanage Registry v4.4.2
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

  // Determine if fields are editable
  $e = false;
  
  if(($this->action == "add" && $permissions['add']) || ($this->action == "edit" && $permissions['edit'])) {
    $e = true;
  }

  if(!$e && !$permissions['view']) {
    return false;
  }

  // Add breadcrumbs
  print $this->element("coCrumb");
  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_co_oidc_clients';
  $args['co'] = $cur_co['Co']['id'];
  $args['action'] = 'index';

  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_oidc_clients.pl'), $args);

  $args = array();
  $args['plugin'] = 'oa4mp_client';
  $args['controller'] = 'oa4mp_client_claims';
  $args['action'] = 'index';
  $args['clientid'] = $vv_client_id;
  $this->Html->addCrumb(_txt('ct.oa4mp_client_co_claims.pl'), $args);

  $crumbTxt = _txt('op.' . $this->action . '-a', array(_txt('ct.oa4mp_client_co_claims.1')));
  $this->Html->addCrumb($crumbTxt);

  print $this->Html->css("Oa4mpClient.oa4mpclient");

  $claim = $this->request->data['Oa4mpClientClaim'] ?? array();
  $constraint0 = $this->request->data['Oa4mpClientClaimConstraint'][0] ?? array();
  $constraint1 = $this->request->data['Oa4mpClientClaimConstraint'][1] ?? array();

  if(!empty($vv_client_id)) {
    print $this->Form->hidden('client_id', array('value' => $vv_client_id));
  }

  if(!empty($claim['id'])) {
    print $this->Form->hidden('id');
  }

  if(!empty($constraint0['id'])) {
    print $this->Form->hidden('Oa4mpClientClaimConstraint.0.id');
  }

  if(!empty($constraint1['id'])) {
    print $this->Form->hidden('Oa4mpClientClaimConstraint.1.id');
  }

  $this->Form->unlockField('Oa4mpClientClaim.source_model_claim_value_field');
  $this->Form->unlockField('Oa4mpClientClaimConstraint.0.constraint_field');
  $this->Form->unlockField('Oa4mpClientClaimConstraint.0.constraint_value');
  $this->Form->unlockField('Oa4mpClientClaimConstraint.1.constraint_field');
  $this->Form->unlockField('Oa4mpClientClaimConstraint.1.constraint_value');
  $this->Form->unlockField('Oa4mpClientClaim.claim_value_selection');
  $this->Form->unlockField('Oa4mpClientClaim.claim_multiple_value_serialization');

  // Build option sets for constraint 0 selections
  $emailConstraintOptions = array('' => '');
  $emailConstraintOptions['all'] = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.email.all');
  foreach(($vv_email_types ?? array()) as $value => $label) {
    $emailConstraintOptions[$value] = $label;
  }

  $identifierConstraintOptions = array('' => '');
  $identifierConstraintOptions['all'] = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.identifier.all');
  foreach(($vv_identifier_types ?? array()) as $value => $label) {
    $identifierConstraintOptions[$value] = $label;
  }

  $nameConstraintOptions = array('' => '');
  $nameConstraintOptions['all'] = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.name.all');
  foreach(($vv_name_types ?? array()) as $value => $label) {
    $nameConstraintOptions[$value] = $label;
  }

  $sshKeyConstraintOptions = array('' => '');
  $sshKeyConstraintOptions['all'] = _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.ssh_key.all');
  foreach(($vv_ssh_key_types ?? array()) as $value => $label) {
    $sshKeyConstraintOptions[$value] = $label;
  }

  $optionSets = array(
    'EmailAddress' => $emailConstraintOptions,
    'Identifier' => $identifierConstraintOptions,
    'Name' => $nameConstraintOptions,
    'SshKey' => $sshKeyConstraintOptions
  );

  $constraint0Texts = array(
    'CoGroupMember' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.co_group_member'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.co_group_member')
    ),
    'CoPersonRole' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.co_person_role'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.co_person_role')
    ),
    'EmailAddress' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.email'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.email')
    ),
    'Identifier' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.identifier'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.identifier')
    ),
    'Name' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.name'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.name')
    ),
    'SshKey' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.name.ssh_key'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint0.fd.value.description.ssh_key')
    )
  );

  $constraint1Texts = array(
    'EmailAddress' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.constraint_value.name.email'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.email')
    ),
    'Name' => array(
      'label' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.constraint_value.name.name'),
      'description' => _txt('pl.oa4mp_client_co_oidc_client.claimconstraint1.fd.value.description.name')
    )
  );

  $sourceModelConfigs = array(
    'EmailAddress' => array(
      'showFields' => array('constraint0', 'constraint1', 'valueSelection'),
      'selectors' => array(),
      'constraint0' => array(
        'type' => 'select',
        'optionSetKey' => 'EmailAddress'
      ),
      'constraint1' => array(
        'type' => 'checkbox',
        'checkedValue' => 'true',
        'uncheckedValue' => 'false',
        'defaultChecked' => true
      ),
      'defaults' => array(
        'sourceValueField' => 'mail',
        'constraint0Field' => 'type',
        'constraint1Field' => 'verified',
        'jsonFormat' => 'string',
        'multipleSerialization' => 'json_array'
      ),
      'jsonFormat' => array('visible' => false),
      'valueSelection' => array('visible' => true, 'forceValue' => null),
      'multipleSerialization' => array('mode' => 'auto', 'default' => 'json_array', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    ),
    'CoGroupMember' => array(
      'showFields' => array('constraint0'),
      'selectors' => array(),
      'constraint0' => array(
        'type' => 'checkbox',
        'checkedValue' => 'true',
        'uncheckedValue' => 'false',
        'onChange' => 'coGroupOwnership'
      ),
      'defaults' => array(
        'sourceValueField' => 'member',
        'constraint0Field' => 'owner',
        'valueSelection' => 'all',
        'jsonFormat' => 'string',
        'multipleSerialization' => 'json_array'
      ),
      'jsonFormat' => array('visible' => false),
      'valueSelection' => array('visible' => false, 'forceValue' => 'all'),
      'multipleSerialization' => array('mode' => 'always', 'default' => 'json_array', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    ),
    'CoPersonRole' => array(
      'showFields' => array('constraint0', 'valueSelection'),
      'selectors' => array('coPersonRoleSelector'),
      'constraint0' => array(
        'type' => 'checkbox',
        'checkedValue' => 'active',
        'uncheckedValue' => 'any',
        'defaultChecked' => true
      ),
      'defaults' => array(
        'sourceValueField' => 'all',
        'constraint0Field' => 'status',
        'jsonFormat' => 'string'
      ),
      'jsonFormat' => array('visible' => false),
      'valueSelection' => array('visible' => true, 'forceValue' => null),
      'multipleSerialization' => array('mode' => 'auto', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    ),
    'Identifier' => array(
      'showFields' => array('constraint0', 'valueSelection', 'jsonFormat'),
      'selectors' => array(),
      'constraint0' => array(
        'type' => 'select',
        'optionSetKey' => 'Identifier'
      ),
      'defaults' => array(
        'sourceValueField' => 'identifier',
        'constraint0Field' => 'type'
      ),
      'jsonFormat' => array('visible' => true),
      'valueSelection' => array('visible' => true, 'forceValue' => null),
      'multipleSerialization' => array('mode' => 'auto', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    ),
    'Name' => array(
      'showFields' => array('constraint0', 'constraint1', 'valueSelection'),
      'selectors' => array('nameSelector'),
      'constraint0' => array(
        'type' => 'select',
        'optionSetKey' => 'Name'
      ),
      'constraint1' => array(
        'type' => 'checkbox',
        'checkedValue' => 'true',
        'uncheckedValue' => 'false',
        'defaultChecked' => true,
        'onChange' => 'namePrimary'
      ),
      'defaults' => array(
        'sourceValueField' => 'all',
        'constraint0Field' => 'type',
        'constraint1Field' => 'primary',
        'jsonFormat' => 'string',
        'valueSelection' => 'first',
        'multipleSerialization' => 'json_array'
      ),
      'jsonFormat' => array('visible' => false),
      'valueSelection' => array('visible' => true, 'forceValue' => null),
      'multipleSerialization' => array('mode' => 'auto', 'default' => 'json_array', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    ),
    'SshKey' => array(
      'showFields' => array('constraint0', 'valueSelection'),
      'selectors' => array('sshSelector'),
      'constraint0' => array(
        'type' => 'select',
        'optionSetKey' => 'SshKey'
      ),
      'defaults' => array(
        'sourceValueField' => 'all',
        'constraint0Field' => 'type',
        'jsonFormat' => 'string'
      ),
      'jsonFormat' => array('visible' => false),
      'valueSelection' => array('visible' => true, 'forceValue' => null),
      'multipleSerialization' => array('mode' => 'auto', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    ),
    'CoTAndCAgreement' => array(
      'showFields' => array('valueSelection', 'multipleSerialization'),
      'selectors' => array(),
      'constraint0' => null,
      'defaults' => array(
        'sourceValueField' => 'all',
        'valueSelection' => 'all',
        'multipleSerialization' => 'json_array',
        'jsonFormat' => 'string'
      ),
      'jsonFormat' => array('visible' => false),
      'valueSelection' => array('visible' => true, 'forceValue' => 'all'),
      'multipleSerialization' => array('mode' => 'always', 'default' => 'json_array', 'allowDelimited' => false),
      'stringDelimiter' => array('mode' => 'hidden')
    ),
    'UnixClusterAccount' => array(
      'showFields' => array('valueSelection', 'jsonFormat'),
      'selectors' => array('unixSelector'),
      'constraint0' => null,
      'defaults' => array(),
      'jsonFormat' => array('visible' => true),
      'valueSelection' => array('visible' => true, 'forceValue' => null),
      'multipleSerialization' => array('mode' => 'auto', 'allowDelimited' => true),
      'stringDelimiter' => array('mode' => 'auto')
    )
  );

  $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
  $optionSetsJson = json_encode($optionSets, $jsonFlags);
  $constraint0TextsJson = json_encode($constraint0Texts, $jsonFlags);
  $constraint1TextsJson = json_encode($constraint1Texts, $jsonFlags);
  $sourceModelConfigsJson = json_encode($sourceModelConfigs, $jsonFlags);
?>

<script type="text/javascript">
  const oa4mpOptionSets = <?php print $optionSetsJson; ?>;
  const oa4mpConstraint0Texts = <?php print $constraint0TextsJson; ?>;
  const oa4mpConstraint1Texts = <?php print $constraint1TextsJson; ?>;
  const oa4mpSourceModelConfigs = <?php print $sourceModelConfigsJson; ?>;
  const STANDARD_CLAIMS = [
    "sub",
    "iss",
    "aud",
    "token_id",
    "email",
    "given_name",
    "family_name",
    "name",
    "idp",
    "idp_name",
    "eppn",
    "eptid",
    "affiliation",
    "ou",
    "oidc",
    "cert_subject_dn"
  ].map(function(value) { return value.toLowerCase(); });
  const claimOverrideMessage = <?php print json_encode(_txt('pl.oa4mp_client_co_scope.scope.override')); ?>;

  const FIELD_SELECTORS = {
    constraint0: '#Oa4mpClientClaimConstraint0ConstraintValue_li',
    constraint1: '#Oa4mpClientClaimConstraint1ConstraintValue_li',
    valueSelection: '#Oa4mpClientClaimClaimValueSelection_li',
    multipleSerialization: '#Oa4mpClientClaimClaimMultipleValueSerialization_li',
    stringDelimiter: '#Oa4mpClientClaimClaimValueStringSerializationDelimiter_li',
    jsonFormat: '#Oa4mpClientClaimClaimValueJsonFormat_li',
    nameSelector: '#NameFieldSelector_li',
    sshSelector: '#SshKeyFieldSelector_li',
    coPersonRoleSelector: '#CoPersonRoleFieldSelector_li',
    unixSelector: '#UnixClusterAccountFieldSelector_li'
  };

  const constraintCallbacks = {
    coGroupOwnership: function(checked) {
      if(checked) {
        setMultipleSerializationValue('json_array');
        disableDelimitedOption(true);
        hideStringDelimiter();
      } else {
        disableDelimitedOption(false);
        handleMultipleSerializationChange();
      }
    },
    namePrimary: function(checked) {
      if(checked) {
        setValueSelection('first');
        $(FIELD_SELECTORS.valueSelection).hide();
        $(FIELD_SELECTORS.multipleSerialization).hide();
        hideStringDelimiter();
        setMultipleSerializationValue('json_array');
      } else {
        setValueSelection('all');
        $(FIELD_SELECTORS.valueSelection).show();
        handleValueSelectionChange();
      }
    }
  };

  function showClaimOverrideMessage() {
    const $message = $('#ClaimNameOverrideMessage');
    $message.stop(true, true)
            .text(claimOverrideMessage)
            .fadeIn(200)
            .delay(5000)
            .fadeOut(400);
  }

  function checkClaimNameOverride(value) {
    const normalized = (value || '').trim().toLowerCase();
    if(normalized && STANDARD_CLAIMS.indexOf(normalized) !== -1) {
      showClaimOverrideMessage();
    }
  }

  function resetConstraint0UI() {
    const $li = $(FIELD_SELECTORS.constraint0);
    $li.find('.field-title label').text('');
    $li.find('.field-desc').text('');
    $('#Constraint0Select').hide().empty().off('change');
    $('#Constraint0Checkbox').hide().prop('checked', false).off('change');
    $('#Constraint0Text').hide().val('').off('input');
  }

  function resetConstraint1UI() {
    const $li = $(FIELD_SELECTORS.constraint1);
    $li.find('.field-title label').text('');
    $li.find('.field-desc').text('');
    $('#Constraint1Checkbox').hide().prop('checked', false).off('change');
  }

  function resetDynamicFields() {
    $.each(FIELD_SELECTORS, function(key, selector) {
      $(selector).hide();
    });
    resetConstraint0UI();
    resetConstraint1UI();
    unlockValueSelection();
    unlockMultipleSerialization();
  }

  function disableDelimitedOption(disable) {
    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"][value="delimited_string"]').prop('disabled', disable);
    if(disable) {
      setMultipleSerializationValue('json_array');
    }
  }

  function hideStringDelimiter() {
    $(FIELD_SELECTORS.stringDelimiter).hide();
    $('#Oa4mpClientClaimClaimValueStringSerializationDelimiter').val('');
  }

  function populateSelect($select, optionSetKey, hiddenValue) {
    const options = oa4mpOptionSets[optionSetKey] || {};
    $.each(options, function(value, label) {
      const option = $('<option></option>').attr('value', value).text(label);
      $select.append(option);
    });
    if(hiddenValue !== undefined && hiddenValue !== null) {
      $select.val(hiddenValue);
    }
  }

  function configureConstraint0(model, config) {
    if(!config || !config.type) {
      return;
    }

    const $li = $(FIELD_SELECTORS.constraint0);
    const text = oa4mpConstraint0Texts[model] || {};
    $li.find('.field-title label').text(text.label || '');
    $li.find('.field-desc').text(text.description || '');

    const $hidden = $('#Constraint0Hidden');
    const currentValue = $hidden.val();

    if(config.type === 'select') {
      const $select = $('#Constraint0Select');
      let selectedValue = currentValue;
      if((selectedValue === '' || selectedValue === null || selectedValue === undefined) && config.defaultValue) {
        selectedValue = config.defaultValue;
      }
      populateSelect($select, config.optionSetKey, selectedValue);
      $select.show();
      $hidden.val(selectedValue || '');
      $select.off('change').on('change', function() {
        $hidden.val($(this).val());
      });
    } else if(config.type === 'checkbox') {
      const $checkbox = $('#Constraint0Checkbox');
      const checkedValue = (config.checkedValue !== undefined) ? config.checkedValue : 'true';
      const uncheckedValue = (config.uncheckedValue !== undefined) ? config.uncheckedValue : 'false';
      const defaultChecked = (config.defaultChecked !== undefined) ? config.defaultChecked : false;
      const hasStoredValue = currentValue !== '' && currentValue !== null && currentValue !== undefined;
      const isChecked = hasStoredValue ? (currentValue === checkedValue) : defaultChecked;

      $checkbox.data('checkedValue', checkedValue);
      $checkbox.data('uncheckedValue', uncheckedValue);
      $checkbox.prop('checked', isChecked);
      $checkbox.show();

      $hidden.val(isChecked ? checkedValue : uncheckedValue);

      $checkbox.off('change').on('change', function() {
        const checked = $(this).is(':checked');
        $hidden.val(checked ? checkedValue : uncheckedValue);
        if(config.onChange && constraintCallbacks[config.onChange]) {
          constraintCallbacks[config.onChange](checked);
        }
      });

      if(config.onChange && constraintCallbacks[config.onChange]) {
        constraintCallbacks[config.onChange](isChecked);
      }
    } else if(config.type === 'text') {
      const $text = $('#Constraint0Text');
      $text.val(currentValue);
      $text.show();
      $text.off('input').on('input', function() {
        $hidden.val($(this).val());
      });
    }

    $li.show();
  }

  function configureConstraint1(model, config) {
    if(!config || !config.type) {
      return;
    }

    const $li = $(FIELD_SELECTORS.constraint1);
    const text = oa4mpConstraint1Texts[model] || {};
    $li.find('.field-title label').text(text.label || '');
    $li.find('.field-desc').text(text.description || '');

    const $hidden = $('#Constraint1Hidden');
    const currentValue = $hidden.val();
    const checkedValue = (config.checkedValue !== undefined) ? config.checkedValue : 'true';
    const uncheckedValue = (config.uncheckedValue !== undefined) ? config.uncheckedValue : 'false';
    const defaultChecked = (config.defaultChecked !== undefined) ? config.defaultChecked : true;
    const hasStoredValue = currentValue !== '' && currentValue !== null && currentValue !== undefined;
    const isChecked = hasStoredValue ? (currentValue === checkedValue) : defaultChecked;

    const $checkbox = $('#Constraint1Checkbox');
    $checkbox.data('checkedValue', checkedValue);
    $checkbox.data('uncheckedValue', uncheckedValue);
    $checkbox.prop('checked', isChecked);
    $hidden.val(isChecked ? checkedValue : uncheckedValue);
    $checkbox.show();

    $checkbox.off('change').on('change', function() {
      const checked = $(this).is(':checked');
      $hidden.val(checked ? checkedValue : uncheckedValue);
      if(config.onChange && constraintCallbacks[config.onChange]) {
        constraintCallbacks[config.onChange](checked);
      }
    });

    if(config.onChange && constraintCallbacks[config.onChange]) {
      constraintCallbacks[config.onChange](isChecked);
    }

    $li.show();
  }

  function configureSelectors(config) {
    const selectors = ['nameSelector', 'sshSelector', 'coPersonRoleSelector', 'unixSelector'];
    selectors.forEach(function(key) {
      const selector = FIELD_SELECTORS[key];
      if(!selector) {
        return;
      }
      if(config.selectors && config.selectors.indexOf(key) !== -1) {
        $(selector).show();
      } else {
        $(selector).hide();
      }
    });

    syncSelectorValues();

    if(config.selectors && config.selectors.indexOf('coPersonRoleSelector') !== -1) {
      handleCoPersonRoleFieldChange($('#CoPersonRoleFieldSelector').val());
    }
  }

  function setHiddenFieldIfEmpty(selector, value) {
    if(value === null || value === undefined) {
      return;
    }
    const $field = $(selector);
    if($field.val() === '') {
      $field.val(value);
    }
  }

  function setHiddenField(selector, value) {
    if(value === null || value === undefined) {
      return;
    }
    $(selector).val(value);
  }

  function setValueSelection(value) {
    if(value === null) {
      return;
    }
    $('#Oa4mpClientClaimClaimValueSelection').val(value);
    $('#ClaimValueSelectionHidden').val(value);
  }

  function setMultipleSerializationValue(value) {
    if(value === null) {
      return;
    }
    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"]').prop('checked', false);
    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"][value="' + value + '"]').prop('checked', true);
    $('#ClaimMultipleValueSerializationHidden').val(value);
  }

  function lockValueSelection(value) {
    setValueSelection(value);
    $('#Oa4mpClientClaimClaimValueSelection').prop('disabled', true);
    $('#ClaimValueSelectionHidden').prop('disabled', false).val(value);
  }

  function unlockValueSelection() {
    $('#Oa4mpClientClaimClaimValueSelection').prop('disabled', false);
    $('#ClaimValueSelectionHidden').prop('disabled', true);
    $('#ClaimValueSelectionHidden').val($('#Oa4mpClientClaimClaimValueSelection').val());
  }

  function lockMultipleSerialization(value) {
    setMultipleSerializationValue(value);
    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"]').prop('disabled', true);
    $('#ClaimMultipleValueSerializationHidden').prop('disabled', false).val(value);
    disableDelimitedOption(true);
  }

  function unlockMultipleSerialization() {
    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"]').prop('disabled', false);
    $('#ClaimMultipleValueSerializationHidden').prop('disabled', true);
    $('#ClaimMultipleValueSerializationHidden').val(getMultipleSerializationValue());
    disableDelimitedOption(false);
  }

  function getMultipleSerializationValue() {
    return $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"]:checked').val();
  }

  function clearMultipleSerializationValue() {
    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"]').prop('checked', false);
    $('#ClaimMultipleValueSerializationHidden').val('');
  }

  function hasExistingMultipleSerializationValue() {
    const current = getMultipleSerializationValue();
    if(current !== undefined && current !== null && current !== '') {
      return true;
    }

    const hiddenValue = $('#ClaimMultipleValueSerializationHidden').val();
    return hiddenValue !== undefined && hiddenValue !== null && hiddenValue !== '';
  }

  function syncSelectorValues() {
    const sourceFieldValue = $('#SourceModelValueFieldHidden').val();

    if($(FIELD_SELECTORS.nameSelector).is(':visible')) {
      const $nameOptions = $('input[name="name_field_selector"]');
      if(sourceFieldValue) {
        $nameOptions.filter('[value="' + sourceFieldValue + '"]').prop('checked', true);
      }
    }

    if($(FIELD_SELECTORS.sshSelector).is(':visible')) {
      const $sshOptions = $('input[name="ssh_key_field_selector"]');
      if(sourceFieldValue) {
        $sshOptions.filter('[value="' + sourceFieldValue + '"]').prop('checked', true);
      }
    }

    if($(FIELD_SELECTORS.coPersonRoleSelector).is(':visible')) {
      $('#CoPersonRoleFieldSelector').val(sourceFieldValue);
    }

    if($(FIELD_SELECTORS.unixSelector).is(':visible')) {
      $('#UnixClusterAccountFieldSelector').val(sourceFieldValue);
    }
  }

  function handleCoPersonRoleFieldChange(value) {
    if(value === 'all' || value === '') {
      lockMultipleSerialization('json_array');
      hideStringDelimiter();
    } else {
      unlockMultipleSerialization();
      handleMultipleSerializationChange();
    }
  }

  function applyDefaults(config) {
    const defaults = config.defaults || {};

    const initialSourceValue = (defaults.sourceValueField !== undefined && defaults.sourceValueField !== null) ? defaults.sourceValueField : null;
    setHiddenFieldIfEmpty('#SourceModelValueFieldHidden', initialSourceValue);
    if(defaults.constraint0Field !== undefined && defaults.constraint0Field !== null) {
      setHiddenField('#Constraint0FieldHidden', defaults.constraint0Field);
    }
    if(config.constraint1 && defaults.constraint1Field !== undefined && defaults.constraint1Field !== null) {
      setHiddenField('#Constraint1FieldHidden', defaults.constraint1Field);
    }

    if(defaults.jsonFormat) {
      setHiddenField('#Oa4mpClientClaimClaimValueJsonFormat', defaults.jsonFormat);
    }

    if(defaults.valueSelection) {
      setValueSelection(defaults.valueSelection);
    }

    if(defaults.multipleSerialization) {
      if(!hasExistingMultipleSerializationValue()) {
        setMultipleSerializationValue(defaults.multipleSerialization);
      }
    }
  }

  function configureValueSelection(config) {
    if(config.valueSelection && config.valueSelection.visible === false) {
      $(FIELD_SELECTORS.valueSelection).hide();
    } else {
      $(FIELD_SELECTORS.valueSelection).show();
    }

    if(config.valueSelection && config.valueSelection.forceValue !== null) {
      setValueSelection(config.valueSelection.forceValue);
    }

    if(config.constraint1 && config.constraint1.onChange && constraintCallbacks[config.constraint1.onChange]) {
      const isChecked = $('#Constraint1Checkbox').is(':checked');
      constraintCallbacks[config.constraint1.onChange](isChecked);
    }
  }

  function configureJsonFormat(config) {
    if(config.jsonFormat && config.jsonFormat.visible === false) {
      $(FIELD_SELECTORS.jsonFormat).hide();
    } else if(config.jsonFormat && config.jsonFormat.visible === true) {
      $(FIELD_SELECTORS.jsonFormat).show();
    }
  }

  function configureMultipleSerialization(config) {
    const mode = (config.multipleSerialization || {}).mode || 'auto';
    const allowDelimited = (config.multipleSerialization || {}).allowDelimited !== false;

    disableDelimitedOption(!allowDelimited);

    if(mode === 'hidden') {
      $(FIELD_SELECTORS.multipleSerialization).hide();
      hideStringDelimiter();
      return;
    }

    if(mode === 'always') {
      $(FIELD_SELECTORS.multipleSerialization).show();
      handleMultipleSerializationChange();
      return;
    }

    handleValueSelectionChange();
  }

  function configureStringDelimiter(config) {
    const mode = (config.stringDelimiter || {}).mode || 'auto';
    if(mode === 'hidden') {
      hideStringDelimiter();
    } else {
      handleMultipleSerializationChange();
    }
  }

  function handleValueSelectionChange() {
    const selection = $('#Oa4mpClientClaimClaimValueSelection').val();
    const model = $('#Oa4mpClientClaimSourceModel').val();
    const config = oa4mpSourceModelConfigs[model] || {};
    const mode = (config.multipleSerialization || {}).mode || 'auto';

    if(mode === 'always') {
      $(FIELD_SELECTORS.multipleSerialization).show();
      handleMultipleSerializationChange();
      return;
    }

    if(selection === 'all') {
      $(FIELD_SELECTORS.multipleSerialization).show();
      handleMultipleSerializationChange();
    } else {
      $(FIELD_SELECTORS.multipleSerialization).hide();
      hideStringDelimiter();
      clearMultipleSerializationValue();
    }
  }

  function handleMultipleSerializationChange() {
    const model = $('#Oa4mpClientClaimSourceModel').val();
    const config = oa4mpSourceModelConfigs[model] || {};
    const delimiterMode = (config.stringDelimiter || {}).mode || 'auto';
    const allowDelimited = (config.multipleSerialization || {}).allowDelimited !== false;
    const currentValue = getMultipleSerializationValue();

    if(!allowDelimited) {
      hideStringDelimiter();
      return;
    }

    if(delimiterMode === 'hidden') {
      hideStringDelimiter();
      return;
    }

    if(currentValue === 'delimited_string') {
      $(FIELD_SELECTORS.stringDelimiter).show();
      const delimiterField = $('#Oa4mpClientClaimClaimValueStringSerializationDelimiter');
      if(delimiterField.val() === '') {
        delimiterField.val(',');
      }
    } else {
      hideStringDelimiter();
    }
  }

  function applySourceModel(model) {
    resetDynamicFields();

    if(!model || !oa4mpSourceModelConfigs[model]) {
      return;
    }

    const config = oa4mpSourceModelConfigs[model];

    (config.showFields || []).forEach(function(fieldKey) {
      if(FIELD_SELECTORS[fieldKey]) {
        $(FIELD_SELECTORS[fieldKey]).show();
      }
    });

    configureSelectors(config);
    configureConstraint0(model, config.constraint0);
    configureConstraint1(model, config.constraint1);
    applyDefaults(config);
    configureValueSelection(config);
    configureJsonFormat(config);
    configureMultipleSerialization(config);
    configureStringDelimiter(config);
    handleMultipleSerializationChange();

    if(model === 'CoTAndCAgreement') {
      lockValueSelection('all');
      lockMultipleSerialization('json_array');
      $(FIELD_SELECTORS.valueSelection).show();
      $(FIELD_SELECTORS.multipleSerialization).show();
      $(FIELD_SELECTORS.jsonFormat).hide();
      hideStringDelimiter();
    }
  }

  function js_local_onload() {
    $('#ClaimNameField').addClass('claim-name-field');

    const $claimNameField = $('#Oa4mpClientClaimClaimName');
    if($claimNameField.length) {
      $claimNameField.on('input', function() {
        checkClaimNameOverride($(this).val());
      });
      checkClaimNameOverride($claimNameField.val());
    }

    $('#Oa4mpClientClaimSourceModel').on('change', function() {
      applySourceModel($(this).val());
    });

    $('#Oa4mpClientClaimClaimValueSelection').on('change', function() {
      $('#ClaimValueSelectionHidden').val($(this).val());
      handleValueSelectionChange();
    });

    $('input[name="data[Oa4mpClientClaim][claim_multiple_value_serialization]"]').on('change', function() {
      $('#ClaimMultipleValueSerializationHidden').val($(this).val());
      handleMultipleSerializationChange();
    });

    $('input[name="name_field_selector"]').on('change', function() {
      $('#SourceModelValueFieldHidden').val($(this).val());
    });

    $('input[name="ssh_key_field_selector"]').on('change', function() {
      $('#SourceModelValueFieldHidden').val($(this).val());
    });

    $('#CoPersonRoleFieldSelector').on('change', function() {
      $('#SourceModelValueFieldHidden').val($(this).val());
      handleCoPersonRoleFieldChange($(this).val());
    });

    $('#UnixClusterAccountFieldSelector').on('change', function() {
      $('#SourceModelValueFieldHidden').val($(this).val());
    });

    $('form').on('submit', function() {
      $('input[name="name_field_selector"]').remove();
      $('input[name="ssh_key_field_selector"]').remove();
      $('select[name="co_person_role_field_selector"]').remove();
      $('select[name="unix_cluster_account_field_selector"]').remove();
    });

    const initialModel = $('#Oa4mpClientClaimSourceModel').val();
    applySourceModel(initialModel);
  }
</script>

<ul id="<?php print $this->action; ?>_oa4mp_client_co_oidc_claim" class="fields form-list form-list-admin">

  <!-- Oa4mpClientClaim['claim_name'] -->
  <li id="ClaimNameField">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_name', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_name.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_name.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php print $this->Form->input('claim_name', array('label' => false, 'div' => false)); ?>
        <div id="ClaimNameOverrideMessage" class="oa4mp-override-message" style="display:none;"></div>
      </span>
    </div>
  </li>

  <!-- Oa4mpClientClaim['source_model'] -->
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('source_model', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.source_model.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.source_model.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          $attrs = array();
          $attrs['value'] = $claim['source_model'] ?? "";
          $attrs['empty'] = true;
          $options = array();
          $options['EmailAddress'] = 'EmailAddress';
          $options['CoGroupMember'] = 'Groups';
          $options['Identifier'] = 'Identifier';
          $options['Name'] = 'Name';
          $options['CoPersonRole'] = 'Role';
          $options['SshKey'] = 'SSH Key';
          $options['CoTAndCAgreement'] = 'Terms & Conditions';
          $options['UnixClusterAccount'] = 'Unix Cluster Account';

          if($e) {
            echo $this->Form->select('source_model', $options, $attrs);
          }
        ?>
      </span>
    </div>
  </li>

  <!-- Name field selector -->
  <li id="NameFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <input type="radio" id="NameFieldSelector_all" name="name_field_selector" value="all" />
        <label for="NameFieldSelector_all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.all'); ?></label>

        <input type="radio" id="NameFieldSelector_given" name="name_field_selector" value="given" />
        <label for="NameFieldSelector_given"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.given'); ?></label>

        <input type="radio" id="NameFieldSelector_middle" name="name_field_selector" value="middle" />
        <label for="NameFieldSelector_middle"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.middle'); ?></label>

        <input type="radio" id="NameFieldSelector_family" name="name_field_selector" value="family" />
        <label for="NameFieldSelector_family"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.name_field_selector.family'); ?></label>
      </span>
    </div>
  </li>

  <!-- SSH Key field selector -->
  <li id="SshKeyFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <input type="radio" id="SshKeyFieldSelector_all" name="ssh_key_field_selector" value="all" />
        <label for="SshKeyFieldSelector_all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.all'); ?></label>

        <input type="radio" id="SshKeyFieldSelector_skey" name="ssh_key_field_selector" value="skey" />
        <label for="SshKeyFieldSelector_skey"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.skey'); ?></label>

        <input type="radio" id="SshKeyFieldSelector_comment" name="ssh_key_field_selector" value="comment" />
        <label for="SshKeyFieldSelector_comment"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.comment'); ?></label>

        <input type="radio" id="SshKeyFieldSelector_type" name="ssh_key_field_selector" value="type" />
        <label for="SshKeyFieldSelector_type"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.ssh_key_field_selector.type'); ?></label>
      </span>
    </div>
  </li>

  <!-- CoPersonRole field selector -->
  <li id="CoPersonRoleFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <select id="CoPersonRoleFieldSelector" name="co_person_role_field_selector">
          <option value=""></option>
          <option value="all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.all'); ?></option>
          <option value="affiliation"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.affiliation'); ?></option>
          <option value="cou"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.cou'); ?></option>
          <option value="title"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.title'); ?></option>
          <option value="o"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.o'); ?></option>
          <option value="ou"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.co_person_role_field_selector.ou'); ?></option>
        </select>
      </span>
    </div>
  </li>

  <!-- Unix Cluster Account field selector -->
  <li id="UnixClusterAccountFieldSelector_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.name'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <select id="UnixClusterAccountFieldSelector" name="unix_cluster_account_field_selector">
          <option value=""></option>
          <option value="all"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.all'); ?></option>
          <option value="username"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.username'); ?></option>
          <option value="uid"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.uid'); ?></option>
          <option value="gecos"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.gecos'); ?></option>
          <option value="login_shell"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.login_shell'); ?></option>
          <option value="home_directory"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.unix_cluster_account_field_selector.home_directory'); ?></option>
        </select>
      </span>
    </div>
  </li>

  <!-- Hidden fields for source model and constraints -->
  <li style="display:none;">
    <?php
      print $this->Form->hidden('source_model_claim_value_field', array(
        'value' => $claim['source_model_claim_value_field'] ?? '',
        'id' => 'SourceModelValueFieldHidden'
      ));
    ?>
  </li>

  <li style="display:none;">
    <?php
      print $this->Form->hidden('Oa4mpClientClaimConstraint.0.constraint_field', array(
        'value' => $constraint0['constraint_field'] ?? '',
        'id' => 'Constraint0FieldHidden'
      ));
    ?>
  </li>

  <li style="display:none;">
    <?php
      print $this->Form->hidden('Oa4mpClientClaimConstraint.1.constraint_field', array(
        'value' => $constraint1['constraint_field'] ?? '',
        'id' => 'Constraint1FieldHidden'
      ));
    ?>
  </li>

  <!-- Constraint 0 value -->
  <li id="Oa4mpClientClaimConstraint0ConstraintValue_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.0.value', ''); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          print $this->Form->hidden('Oa4mpClientClaimConstraint.0.constraint_value', array(
            'value' => $constraint0['constraint_value'] ?? '',
            'id' => 'Constraint0Hidden'
          ));
        ?>
        <select id="Constraint0Select" style="display:none;"></select>
        <input type="checkbox" id="Constraint0Checkbox" value="true" style="display:none;" />
        <input type="text" id="Constraint0Text" style="display:none;" />
      </span>
    </div>
  </li>

  <!-- Constraint 1 value -->
  <li id="Oa4mpClientClaimConstraint1ConstraintValue_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('Oa4mpClientClaimConstraint.1.value', ''); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          print $this->Form->hidden('Oa4mpClientClaimConstraint.1.constraint_value', array(
            'value' => $constraint1['constraint_value'] ?? '',
            'id' => 'Constraint1Hidden'
          ));
        ?>
        <input type="checkbox" id="Constraint1Checkbox" value="true" style="display:none;" />
      </span>
    </div>
  </li>

  <!-- Claim value selection -->
  <li id="Oa4mpClientClaimClaimValueSelection_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_value_selection', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_selection.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_selection.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          $options = array();
          $options['first'] = 'First value found';
          $options['all'] = 'All values found';

          $attrs = array();
          $attrs['value'] = $claim['claim_value_selection'] ?? '';
          echo $this->Form->select('claim_value_selection', $options, $attrs);
          echo $this->Form->hidden('claim_value_selection', array(
            'value' => $claim['claim_value_selection'] ?? '',
            'id' => 'ClaimValueSelectionHidden',
            'disabled' => true
          ));
        ?>
      </span>
    </div>
  </li>

  <!-- Multiple value serialization -->
  <li id="Oa4mpClientClaimClaimMultipleValueSerialization_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_multiple_value_serialization', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          $options = array();
          $options['json_array'] = _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.json_array');
          $options['delimited_string'] = _txt('pl.oa4mp_client_co_oidc_client.claims.fd.multiple_value_serialization.delimited_string');

          $attrs = array();
          $attrs['legend'] = false;
          $attrs['separator'] = ' ';
          $attrs['value'] = $claim['claim_multiple_value_serialization'] ?? '';
          $attrs['hiddenField'] = false;
          echo $this->Form->radio('claim_multiple_value_serialization', $options, $attrs);
          echo $this->Form->hidden('claim_multiple_value_serialization', array(
            'value' => $claim['claim_multiple_value_serialization'] ?? '',
            'id' => 'ClaimMultipleValueSerializationHidden',
            'disabled' => true
          ));
        ?>
      </span>
    </div>
  </li>

  <!-- String delimiter -->
  <li id="Oa4mpClientClaimClaimValueStringSerializationDelimiter_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_value_string_serialization_delimiter', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_string_serialization_delimiter.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_string_serialization_delimiter.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          echo $this->Form->input('claim_value_string_serialization_delimiter', array(
            'value' => $claim['claim_value_string_serialization_delimiter'] ?? '',
            'div' => false,
            'label' => false
          ));
        ?>
      </span>
    </div>
  </li>

  <!-- JSON format -->
  <li id="Oa4mpClientClaimClaimValueJsonFormat_li" style="display:none;">
    <div class="field-name">
      <div class="field-title">
        <?php print $this->Form->label('claim_value_json_format', _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_json_format.name')); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.oa4mp_client_co_oidc_client.claims.fd.claim_value_json_format.description'); ?></div>
    </div>
    <div class="field-info">
      <span class="field-info-prefix">
        <?php
          $options = array();
          $options['string'] = 'JSON string';
          $options['number'] = 'JSON number';

          $attrs = array();
          $attrs['value'] = $claim['claim_value_json_format'] ?? '';
          echo $this->Form->select('claim_value_json_format', $options, $attrs);
        ?>
      </span>
    </div>
  </li>

  <?php if($e): ?>
    <li class="fields-submit">
      <div class="field-name"></div>
      <div class="field-info">
        <?php
          $submitText = ($this->action == "add") ? _txt('op.add') : _txt('op.save');
          print $this->Form->submit($submitText);
        ?>
      </div>
    </li>
  <?php endif; ?>

</ul>
