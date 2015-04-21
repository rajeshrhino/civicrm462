<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.6                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2015                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but   |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */
 
require_once 'CRM/Contribute/DAO/ContributionRecur.php';

/**
 * This class generates form components for processing a recurring contribution record.
 */
class CRM_Contribute_Form_ContributionRecur extends CRM_Core_Form {

  /**
   * The id of the contact associated with this recurring contribution record.
   *
   * @var int
   */
  public $_contactID;

  /**
   * form defaults
   * @todo can we define this a as protected? can we define higher up the chain
   * @var array
   */
  public $_defaults;

  /**
   * Values of existing recurring contribution record
   */
  public $_values;

  /**
   * The id of the related membership
   */
  public $_membershipID;

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() { 
    // Check permission for action.
    if (!CRM_Core_Permission::checkActionPermission('CiviContribute', $this->_action)) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page.'));
    }

    $this->_cdType = CRM_Utils_Array::value('type', $_GET);

    $this->assign('cdType', FALSE);
    if ($this->_cdType) {
      $this->assign('cdType', TRUE);
      CRM_Custom_Form_CustomData::preProcess($this);
      return;
    }

    // Get the contact id
    $this->_contactID = CRM_Utils_Request::retrieve('cid', 'Positive', $this);

    // Get the action.
    $this->_action = CRM_Utils_Request::retrieve('action', 'String', $this, FALSE, 'add');
    $this->assign('action', $this->_action);

    // Get the contribution recur id if update
    $this->_id = CRM_Utils_Request::retrieve('crid', 'Positive', $this);
    if (!empty($this->_id)) {
      $this->assign('contribRecurID', $this->_id);
    }

    $this->_context = CRM_Utils_Request::retrieve('context', 'String', $this);
    $this->assign('context', $this->_context);

    if ($this->_contactID) {
      list($this->userDisplayName, $this->userEmail) = CRM_Contact_BAO_Contact_Location::getEmailDetails($this->_contactID);
      $this->assign('displayName', $this->userDisplayName);
    }

    $this->_values = array();

    $ids = array();
    $params = array('id' => $this->_id);
    
    $recurring = new CRM_Contribute_BAO_ContributionRecur();
    $recurring->copyValues($params);
    $recurring->find(TRUE);
    $ids['contributionrecur'] = $recurring->id;
    CRM_Core_DAO::storeValues($recurring, $this->_values);
    
    // when custom data is included in this page
    if (!empty($_POST['hidden_custom'])) {
      CRM_Custom_Form_CustomData::preProcess($this);
      CRM_Custom_Form_CustomData::buildQuickForm($this);
      CRM_Custom_Form_CustomData::setDefaultValues($this);
    }

    $this->setPageTitle(ts('Recurring Contribution record'));

    $membership = new CRM_Member_DAO_Membership();
    $membership->contribution_recur_id = $this->_id;
    $membership->is_test = 0;
    if ($membership->find(true)) {
      $this->_membershipID = $membership->id;
    }

    parent::preProcess();
  }

  function setDefaultValues() {
    if ($this->_cdType) {
      return CRM_Custom_Form_CustomData::setDefaultValues($this);
    }

    $defaults = $this->_values;

    if ($this->_id) {
      $this->_contactID = $defaults['contact_id'];
    }

    if (isset($defaults['total_amount'])) {
      $defaults['amount'] = CRM_Utils_Money::format($defaults['amount'], NULL, '%a');
    }

    $dates = array('start_date', 'cancel_date', 'next_sched_contribution_date');
    foreach ($dates as $key) {
      if (!empty($defaults[$key])) {
        list($defaults[$key],
          $defaults[$key . '_time']
          ) = CRM_Utils_Date::setDateDefaults(CRM_Utils_Array::value($key, $defaults),
          'activityDateTime'
        );
      }
    }

    // Set move existing contributions to TRUE as default
    $defaults['move_existing_contributions'] = 1;
    $defaults['contact_id'] = $this->_contactID;
    $defaults['selected_cid'] = $this->_contactID;

    $this->_defaults = $defaults;
    return $defaults;
  }  
    
  /**
   * Build the form
   *
   * @access public
   * @return void
   */
  function buildQuickForm( ) {

    if ($this->_cdType) {
      CRM_Custom_Form_CustomData::buildQuickForm($this);
      return;
    }

    //need to assign custom data type to the template
    $this->assign('customDataType', 'ContributionRecur');
    $this->assign('entityID', $this->_id);
    
    $attributes = CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_ContributionRecur');

    $cid        = CRM_Utils_Request::retrieve('cid',  'Integer', $this);
    $id         = CRM_Utils_Request::retrieve('crid', 'Integer', $this);

    $paymentProcessor = $this->add('select', 'payment_processor_id',
      ts('Payment Processor'),
      array('' => ts('- select -')) + CRM_Core_PseudoConstant::paymentProcessor(),
      TRUE,
      NULL
    );

    $trxnId = $this->add('text', 'trxn_id', ts('Transaction ID'), array('class' => 'twelve'));

    $financialType = $this->add('select', 'financial_type_id',
      ts('Financial Type'),
      array('' => ts('- select -')) + CRM_Contribute_PseudoConstant::financialType(),
      TRUE,
      NULL
    );

    $totalAmount = $this->addMoney('amount',
      ts('Total Amount'),
      FALSE,
      NULL,
      TRUE, 'currency', NULL, FALSE
    );

    $paymentInstrument = $this->add('select', 'payment_instrument_id',
      ts('Paid By'),
      array('' => ts('- select -')) + CRM_Contribute_PseudoConstant::paymentInstrument(),
      TRUE, NULL
    );
    
    $frequencyUnit = $this->add('select', 'frequency_unit',
      NULL,
      array('' => ts('- select -')) + CRM_Core_OptionGroup::values('recur_frequency_units', FALSE, FALSE, FALSE, NULL, 'name'),
      TRUE, NULL
    );

    $frequencyInterval = $this->add('text', 'frequency_interval', ts('Every'), array('maxlength' => 2, 'size' => 2), true);
    
    // add dates
    $this->addDateTime('start_date', ts('Start Date'), FALSE, array('formatType' => 'activityDate'));
    $this->addDateTime('cancel_date', ts('Cancel Date'), FALSE, array('formatType' => 'activityDate'));
    $this->addDateTime('next_sched_contribution_date', ts('Next Scheduled Contribution Date'), FALSE, array('formatType' => 'activityDate'));

    $cycleDay = $this->add('text', 'cycle_day', ts('Cycle day'), array('maxlength' => 2, 'size' => 2), true);

    // Move recurring record to another contact/membership
    // Field for moving contribution to another contact/membership
    $this->addEntityRef('contact_id', ts('Contact'), array('create' => TRUE, 'api' => array('extra' => array('email'))), TRUE);
    $this->addElement('text', 'contact_name', 'Contact', array('size' => 50, 'maxlength' => 255));
    $this->addElement('hidden', 'selected_cid', 'selected_cid');
    $this->addElement('checkbox', 'move_recurring_record', ts('Move Recurring Record?'));
    $this->addElement('checkbox', 'move_existing_contributions', ts('Move Existing Contributions?'));


    // Get memberships of the contact
    // This will allow the recur record to be attached to a different membership of the same contact
    $memberships = array();
    $dao = new CRM_Member_DAO_Membership();
    $dao->contact_id = $this->_contactID;
    $dao->is_test = 0;
    $dao->find();
    while ($dao->fetch()) {
      $memberships[$dao->id] = array();
      CRM_Core_DAO::storeValues($dao, $memberships[$dao->id]);
    }

    $existingMemberships = array();
    if (!empty($memberships)) {
      foreach ($memberships as $membershipId => $membershipDetails) {
        $statusANDType = CRM_Member_BAO_Membership::getStatusANDTypeValues($membershipId);
        $existingMemberships[$membershipId] = $statusANDType[$membershipId]['membership_type']
            .' / '.$statusANDType[$membershipId]['status']
            .' / '.$membershipDetails['start_date']
            .' / '.$membershipDetails['end_date'];
      }
    }
    $this->add('select', 'membership_record', ts('Membership'), $existingMemberships, FALSE);
    $this->assign('show_move_membership_field', 1);

    $this->addButtons(array(
        array(
          'type' => 'upload',
          'name' => ts('Save'),
          'isDefault' => TRUE
        ),
        array(
          'type' => 'cancel',
          'name' => ts('Cancel')
        ),
      )
    );
  }
        
  /**
   * global validation rules for the form
   *
   * @param array $fields posted values of the form
   *
   * @return array list of errors to be posted back to the form
   * @static
   * @access public
   */
  static function formRule( $values ) 
  {
      $errors = array( );

      if (!empty($values['start_date']) && !empty($values['end_date']) ) {
          $start = CRM_Utils_Date::processDate( $values['start_date'] );
          $end   = CRM_Utils_Date::processDate( $values['end_date'] );
          if ( ($end < $start) && ($end != 0) ) {
              $errors['end_date'] = ts( 'End date should be greater than Start date' );
          }
      }  
      return $errors;
  }   
   
  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   * @return None
   */
  public function postProcess() {

    // get the submitted form values.
    $submittedValues = $this->controller->exportValues($this->_name);  

    // get the required field value only.
    $formValues = $submittedValues;
    $params = $ids = array();

    $params['contact_id'] = $this->_contactID;

    $params['currency'] = CRM_Contribute_Form_AbstractEditPayment::getCurrency($submittedValues);

    $dates = array(
      'start_date',
      'cancel_date',
      'next_sched_contribution_date',
    );

    foreach ($dates as $d) {
      $params[$d] = CRM_Utils_Date::processDate($formValues[$d]);
    }

    $fields = array(
        'payment_processor_id',
        'trxn_id',
        'financial_type_id',
        'amount',
        'payment_instrument_id',
        'frequency_interval',
        'frequency_unit',
        'cycle_day',
      );
      foreach ($fields as $f) {
        $params[$f] = CRM_Utils_Array::value($f, $formValues);
      }

    $params['id'] = $this->_id;

    // build custom data getFields array
    $customFields = CRM_Core_BAO_CustomField::getFields('ContributionRecur', FALSE, FALSE, NULL, NULL, TRUE);
    $params['custom'] = CRM_Core_BAO_CustomField::postProcess($formValues,
      $customFields,
      $this->_id,
      'ContributionRecur'
    );

    $contributionRecur = CRM_Contribute_BAO_ContributionRecur::create($params);
    
    // Move the recurring record
    if (isset($submittedValues['move_recurring_record']) && $submittedValues['move_recurring_record'] == 1 ) {
      self::moveRecurringRecord($submittedValues);
    }
  }

  public function displayStatusMessage($messageTitle, $message) {
    $out = array(
      'status' => 'fatal',
      'content' => '<div class="messages status no-popup"><div class="icon inform-icon"></div>' . ts( $message ) . '</div>',
    );
    CRM_Core_Session::setStatus($message, ts( $messageTitle ), 'error');
    CRM_Core_Page_AJAX::returnJsonResponse($out);
  }      

  public function moveRecurringRecord($submittedValues) {
    // Move recurring record to another contact
    if (!empty($submittedValues['selected_cid']) && $submittedValues['selected_cid'] != $this->_contactID) {

      $move_existing_contributions = $submittedValues['move_existing_contributions'];
      $selected_cid = $submittedValues['selected_cid'];

      // Update contact id in civicrm_contribution_recur table
      $update_recur_sql = "UPDATE civicrm_contribution_recur SET contact_id = %1 WHERE id = %2";
      $update_recur_params = array(
        1 =>  array($selected_cid,      'Integer'),
        2 =>  array($this->_id,  'Integer')
      );
      CRM_Core_DAO::executeQuery($update_recur_sql, $update_recur_params);

      // Update contact id in civicrm_contribution table, if 'Move Existing Contributions?' is ticked
      if ($move_existing_contributions == 1) {
        $update_contribution_sql = "UPDATE civicrm_contribution SET contact_id = %1 WHERE contribution_recur_id = %2";
        CRM_Core_DAO::executeQuery($update_contribution_sql, $update_recur_params);
      }
    }
    
    // FIXME: Not getting the below value in $submittedValues
    // So taking the value from $_POST
    if (isset($_POST['membership_record'])) {
      $membership_record = $_POST['membership_record'];
    }
    
    if (!empty($membership_record)) {
      // Remove the contribution_recur_id from existing membership
      if (!empty($this->_membershipID)) {
        $membership = new CRM_Member_DAO_Membership();
        $membership->id = $this->_membershipID;
        $membership->contribution_recur_id = NULL;
        $membership->save();
      }

      // Update contribution_recur_id to the new membership
      $membership = new CRM_Member_DAO_Membership();
      $membership->id =  $membership_record;
      $membership->contribution_recur_id = $this->_id;
      $membership->save();
    }
  }
}
