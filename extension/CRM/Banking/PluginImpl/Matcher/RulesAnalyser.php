<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2018 SYSTOPIA                            |
| Author: B. Endres (endres -at- systopia.de)            |
|         R. Lott (hello -at- artfulrobot.uk)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/


require_once 'CRM/Banking/Helpers/OptionValue.php';

define('BANKING_MATCHER_RULE_TYPE_ANALYSER', 1);
define('BANKING_MATCHER_RULE_TYPE_MATCHER',  2);

/**
 * This matcher will try to match any transaction
 *  to the rules recorded in a rule table
 *
 * It will also offer the user to to create new rules
 */
class CRM_Banking_PluginImpl_Matcher_RulesAnalyser extends CRM_Banking_PluginModel_Matcher {

  /**
   * class constructor
   */
  function __construct($config_name) {
    parent::__construct($config_name);

    // read config, set defaults
    $config = $this->_plugin_config;
    if (!isset($config->show_matched_rules))    $config->show_matched_rules = TRUE;
    if (!isset($config->suggest_create_new))    $config->suggest_create_new = TRUE;
    if (!isset($config->create_new_confidence)) $config->create_new_confidence = 0.75;
    if (!isset($config->field_mapping))         $config->field_mapping = array();
    if (!isset($config->fields_to_set))         $config->fields_to_set = array(
                                                  'campaign_id'           => ts('Campaign ID'),
                                                  'contact_id'            => ts('Contact ID'),
                                                  'membership_id'         => ts('Membership ID'),
                                                  'financial_type_id'     => ts('Financial Type ID'),
                                                  'payment_instrument_id' => ts('Payment Instrument ID'));
  }

  /**
   * Suggestion listing the currently matched rules and/or
   *  offer to create new ones
   *
   * @return array(match structures)
   */
  public function match(CRM_Banking_BAO_BankTransaction $btx, CRM_Banking_Matcher_Context $context) {

    $config = $this->_plugin_config;

    // TODO: threshold
    $threshold = 0; // FIXME

    // run the rule matcher
    $rule_matches = CRM_Banking_Rules_Match::matchTransaction($btx, $config->field_mapping, $context, BANKING_MATCHER_RULE_TYPE_ANALYSER, $threshold);
    $matched_rule_ids = array();

    // Execute the rule matches (which will enrich the parsed data).
    foreach ($rule_matches as $rule_match) {
      // apply the match
      $rule_match->execute();

      // add the ID
      $matched_rule_ids[] = $rule_match->getRule()->getId();
    }

    // see if we want to create a "suggestion"
    if (   $config->suggest_create_new
        || ($config->show_matched_rules && !empty($rule_matches)) ) {

      // create a suggestion
      $suggestion = new CRM_Banking_Matcher_Suggestion($this, $btx);
      $suggestion->setTitle("BankingRules");
      $suggestion->setProbability($config->create_new_confidence);

      // add all matches rules to be displayed
      $rule2confidence = array();
      foreach ($rule_matches as $rule_match) {
        $rule2confidence[$rule_match->getRule()->getId()] = $rule_match->getConfidence();
      }
      $suggestion->setParameter('matched_rules', $rule2confidence);

      if ($config->suggest_create_new) {
        $suggestion->setParameter('matched_rules', $rule2confidence);
      }

      $btx->addSuggestion($suggestion);
    }

    return $this->_suggestions;
  }

  /**
   * DISABLE auto-exec for this.
   */
  public function autoExecute() {
    return FALSE;
  }

  /**
   * Handle the different actions, should probably be handles at base class level ...
   *
   * @param type $match
   * @param type $btx
   */
  public function execute($match, $btx) {
    // Is this this correct way to do it?
    $input = $_POST;

    if (empty($input['rules-analyser__create-new-rule'])) {
      // User did not want to create a new rule.
      CRM_Core_Session::setStatus(ts("No new rule was created."), ts('Nothing to do'), 'warn');
      return 're-run';
    }


    // User wants to create a rule.
    try {
      $rule = static::createRule($input);
      CRM_Core_Session::setStatus(ts("New rule created."), ts('Success'), 'success');
    }
    catch (InvalidArgumentException $e) {
      CRM_Core_Session::setStatus(ts($e->getMessage()), ts('Error'), 'error');
    }

    // return 're-run' to indicate that this transaction needs to
    //  be analysed again
    return 're-run';
  }
  /**
   * If the user has modified the input fields provided by the "visualize" html code,
   * the new values will be passed here BEFORE execution
   *
   * CAUTION: there might be more parameters than provided. Only process the ones that
   *  'belong' to your suggestion.
   */
  public function update_parameters(CRM_Banking_Matcher_Suggestion $match, $parameters) {
    // TODO: implement 'create new' based on $parameters
  }

 /**
   * Generate html code to visualize the given match. The visualization may also provide interactive form elements.
   *
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */
  function visualize_match( CRM_Banking_Matcher_Suggestion $match, $btx) {
    $config = $this->_plugin_config;
    $smarty_vars = array();

    // add rule render information
    $matched_rules = $match->getParameter('matched_rules');
    $rules_data    = array();
    foreach ($matched_rules as $rule_id => $confidence) {
      $rule_data = [
        'id' => $rule_id,
        'confidence' => $confidence,
      ];
      $rule = CRM_Banking_Rules_Rule::get($rule_id);
      $rule->addRenderParameters($rule_data);
      $rules_data[$rule_id] = $rule_data;
    }
    $smarty_vars['rules']         = $rules_data;
    $smarty_vars['fields_to_set'] = isset($config->fields_to_set) ? $config->fields_to_set : [];
    $smarty_vars['btx_id']        = (int) $btx->id;
    $smarty_vars['matcher_id']    = (int) $this->_plugin_id;

    // render template
    $smarty = CRM_Banking_Helpers_Smarty::singleton();
    $smarty->pushScope($smarty_vars);
    $html_snippet = $smarty->fetch('CRM/Banking/PluginImpl/Matcher/RulesAnalyser.suggestion.tpl');
    $smarty->popScope();
    return $html_snippet;
  }


  /**
   * Creates a new rule from user input from UI.
   *
   * Called from execute() and also by the BankingRule.Match API action.
   *
   * @throw InvalidArgumentException if anything invalid.
   * @param array $input
   * @return CRM_Banking_Rules_Rule object.
   */
  public static function createRule($input) {

    $i = 1;
    $params = [];
    // Collect data to create rule with in an array.
    $row = [];

    // Simple fields.
    $map = [
      'rules-analyser__party-iban'   => 'party_ba_ref',
      'rules-analyser__our-iban'     => 'ba_ref',
      'rules-analyser__party-name'   => 'party_name',
      'rules-analyser__tx-reference' => 'tx_reference',
      'rules-analyser__tx-purpose'   => 'tx_purpose',
    ];
    foreach ($map as $i => $o) {
      if (!empty($input["$i-cb"])) {
        // This field is needed.
        $row[$o] = $input[$i];
      }
    }

    // Name
    if (!empty($input['rules-analyser__rule-name'])) {
      $row['name'] = $input['rules-analyser__rule-name'];
    }

    // Amount.
    if (!empty($input['rules-analyser__amount-cb'])) {
      // Amount is needed.
      $row['amount_min'] = $input['rules-analyser__amount'];

      if ($input['rules-analyser__amount-op'] == 'equals') {
        // Use same value for amount if 'equals'.
        $row['amount_max'] = $input['rules-analyser__amount'];
      }
      else {
        // 'between' case.
        $row['amount_max'] = $input['rules-analyser__amount-2'];
      }
    }

    //
    // Custom conditions.
    //
    // conditions: {
    //   <field_name>: { full_match: <full string match> },
    //   ...
    // }
    $max = empty($input['rules-analyser__custom-fields-count']) ? 0 : $input['rules-analyser__custom-fields-count'];
    $conditions = [];
    for ($i=1; $i<=$max; $i++) {
      // Only add fields with names(!) silently ignore others.
      if (!empty($input["rules-analyser__custom-name-$i"])) {

        // Found a custom condition.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $input["rules-analyser__custom-name-$i"])) {
          // Invalid field name.
          throw new InvalidArgumentException('Invalid custom field name.');
          return;
        }
        // Store in this format conditions.fieldname = { full_match: 'value' }
        $conditions[$input["rules-analyser__custom-name-$i"]] = ['full_match' => $input["rules-analyser__custom-value-$i"]];
      }
    }
    $row['conditions'] = $conditions;

    //
    // Instructions ("Actions") stored in the execution field:
    //
    // execution: [
    //   { set_param_name: <field e.g. contact_id>, set_param_value: <the value> },
    //   ...
    // ]
    //
    // These will be executed in defined order.
    //
    $execution = [];
    foreach ([
      'contact_id',
      'campaign_id',
      'financial_type_id',
      'payment_instrument_id',
      'membership_id',
    ] as $_) {
      if (!empty($input["rules-analyser__set-$_-cb"])) {

        $execution[] = [
          'set_param_name' => $_,
          'set_param_value' => $input["rules-analyser__set-$_"],
        ];

      }
    }
    $row['execution'] = $execution;
    if (!$execution) {
      throw new InvalidArgumentException("Cannot create a rule with no actions.");
      return;
    }

    // is_enabled is only set when testing.
    if (isset($input['is_enabled'])) {
      $row['is_enabled'] = $input['is_enabled'] ? 1 : 0;
    }

    // Create rule.
    $rule = CRM_Banking_Rules_Rule::createRule($row);
    return $rule;
  }
}