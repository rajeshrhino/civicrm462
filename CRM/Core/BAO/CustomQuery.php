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
 | CiviCRM is distributed in the hope that it will be useful, but     |
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

/**
 *
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2015
 * $Id$
 *
 */
class CRM_Core_BAO_CustomQuery {
  const PREFIX = 'custom_value_';

  /**
   * The set of custom field ids.
   *
   * @var array
   */
  protected $_ids;

  /**
   * The select clause.
   *
   * @var array
   */
  public $_select;

  /**
   * The name of the elements that are in the select clause.
   * used to extract the values
   *
   * @var array
   */
  public $_element;

  /**
   * The tables involved in the query.
   *
   * @var array
   */
  public $_tables;
  public $_whereTables;

  /**
   * The where clause.
   *
   * @var array
   */
  public $_where;

  /**
   * The english language version of the query.
   *
   * @var array
   */
  public $_qill;

  /**
   * The cache to translate the option values into labels.
   *
   * @var array
   */
  public $_options;

  /**
   * The custom fields information.
   *
   * @var array
   */
  public $_fields;

  /**
   * Searching for contacts?
   *
   * @var boolean
   */
  protected $_contactSearch;

  protected $_locationSpecificCustomFields;

  /**
   * This stores custom data group types and tables that it extends.
   *
   * @var array
   */
  static $extendsMap = array(
    'Contact' => 'civicrm_contact',
    'Individual' => 'civicrm_contact',
    'Household' => 'civicrm_contact',
    'Organization' => 'civicrm_contact',
    'Contribution' => 'civicrm_contribution',
    'ContributionRecur' => 'civicrm_contribution_recur',
    'Membership' => 'civicrm_membership',
    'Participant' => 'civicrm_participant',
    'Group' => 'civicrm_group',
    'Relationship' => 'civicrm_relationship',
    'Event' => 'civicrm_event',
    'Case' => 'civicrm_case',
    'Activity' => 'civicrm_activity',
    'Pledge' => 'civicrm_pledge',
    'Grant' => 'civicrm_grant',
    'Address' => 'civicrm_address',
    'Campaign' => 'civicrm_campaign',
    'Survey' => 'civicrm_survey',
  );

  /**
   * Class constructor.
   *
   * Takes in a set of custom field ids andsets up the data structures to
   * generate a query
   *
   * @param array $ids
   *   The set of custom field ids.
   *
   * @param bool $contactSearch
   * @param array $locationSpecificFields
   */
  public function __construct($ids, $contactSearch = FALSE, $locationSpecificFields = array()) {
    $this->_ids = &$ids;
    $this->_locationSpecificCustomFields = $locationSpecificFields;

    $this->_select = array();
    $this->_element = array();
    $this->_tables = array();
    $this->_whereTables = array();
    $this->_where = array();
    $this->_qill = array();
    $this->_options = array();

    $this->_fields = array();
    $this->_contactSearch = $contactSearch;

    if (empty($this->_ids)) {
      return;
    }

    // initialize the field array
    $tmpArray = array_keys($this->_ids);
    $idString = implode(',', $tmpArray);
    $query = "
SELECT f.id, f.label, f.data_type,
       f.html_type, f.is_search_range,
       f.option_group_id, f.custom_group_id,
       f.column_name, g.table_name,
       f.date_format,f.time_format
  FROM civicrm_custom_field f,
       civicrm_custom_group g
 WHERE f.custom_group_id = g.id
   AND g.is_active = 1
   AND f.is_active = 1
   AND f.id IN ( $idString )";

    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      // get the group dao to figure which class this custom field extends
      $extends = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $dao->custom_group_id, 'extends');
      if (array_key_exists($extends, self::$extendsMap)) {
        $extendsTable = self::$extendsMap[$extends];
      }
      elseif (in_array($extends, CRM_Contact_BAO_ContactType::subTypes())) {
        // if $extends is a subtype, refer contact table
        $extendsTable = self::$extendsMap['Contact'];
      }
      $this->_fields[$dao->id] = array(
        'id' => $dao->id,
        'label' => $dao->label,
        'extends' => $extendsTable,
        'data_type' => $dao->data_type,
        'html_type' => $dao->html_type,
        'is_search_range' => $dao->is_search_range,
        'column_name' => $dao->column_name,
        'table_name' => $dao->table_name,
        'option_group_id' => $dao->option_group_id,
      );

      // store it in the options cache to make things easier
      // during option lookup
      $this->_options[$dao->id] = array();
      $this->_options[$dao->id]['attributes'] = array(
        'label' => $dao->label,
        'data_type' => $dao->data_type,
        'html_type' => $dao->html_type,
      );

      $optionGroupID = NULL;
      $htmlTypes = array('CheckBox', 'Radio', 'Select', 'Multi-Select', 'AdvMulti-Select', 'Autocomplete-Select');
      if (in_array($dao->html_type, $htmlTypes) && $dao->data_type != 'ContactReference') {
        if ($dao->option_group_id) {
          $optionGroupID = $dao->option_group_id;
        }
        elseif ($dao->data_type != 'Boolean') {
          $errorMessage = ts("The custom field %1 is corrupt. Please delete and re-build the field",
            array(1 => $dao->label)
          );
          CRM_Core_Error::fatal($errorMessage);
        }
      }
      elseif ($dao->html_type == 'Select Date') {
        $this->_options[$dao->id]['attributes']['date_format'] = $dao->date_format;
        $this->_options[$dao->id]['attributes']['time_format'] = $dao->time_format;
      }

      // build the cache for custom values with options (label => value)
      if ($optionGroupID != NULL) {
        $query = "
SELECT label, value
  FROM civicrm_option_value
 WHERE option_group_id = $optionGroupID
";

        $option = CRM_Core_DAO::executeQuery($query);
        while ($option->fetch()) {
          $dataType = $this->_fields[$dao->id]['data_type'];
          if ($dataType == 'Int' || $dataType == 'Float') {
            $num = round($option->value, 2);
            $this->_options[$dao->id]["$num"] = $option->label;
          }
          else {
            $this->_options[$dao->id][$option->value] = $option->label;
          }
        }
        $options = $this->_options[$dao->id];
        //unset attributes to avoid confussion
        unset($options['attributes']);
        CRM_Utils_Hook::customFieldOptions($dao->id, $options, FALSE);
      }
    }
  }

  /**
   * Generate the select clause and the associated tables.
   * for the from clause
   *
   * @return void
   */
  public function select() {
    if (empty($this->_fields)) {
      return;
    }

    foreach ($this->_fields as $id => $field) {
      $name = $field['table_name'];
      $fieldName = 'custom_' . $field['id'];
      $this->_select["{$name}_id"] = "{$name}.id as {$name}_id";
      $this->_element["{$name}_id"] = 1;
      $this->_select[$fieldName] = "{$field['table_name']}.{$field['column_name']} as $fieldName";
      $this->_element[$fieldName] = 1;
      $joinTable = NULL;
      // CRM-14265
      if ($field['extends'] == 'civicrm_group') {
        return;
      }
      elseif ($field['extends'] == 'civicrm_contact') {
        $joinTable = 'contact_a';
      }
      elseif ($field['extends'] == 'civicrm_contribution') {
        $joinTable = $field['extends'];
      }
      elseif (in_array($field['extends'], self::$extendsMap)) {
        $joinTable = $field['extends'];
      }
      else {
        return;
      }

      $this->_tables[$name] = "\nLEFT JOIN $name ON $name.entity_id = $joinTable.id";

      if ($this->_ids[$id]) {
        $this->_whereTables[$name] = $this->_tables[$name];
      }

      if ($joinTable) {
        $joinClause = 1;
        $joinTableAlias = $joinTable;
        // Set location-specific query
        if (isset($this->_locationSpecificCustomFields[$id])) {
          list($locationType, $locationTypeId) = $this->_locationSpecificCustomFields[$id];
          $joinTableAlias = "$locationType-address";
          $joinClause = "\nLEFT JOIN $joinTable `$locationType-address` ON (`$locationType-address`.contact_id = contact_a.id AND `$locationType-address`.location_type_id = $locationTypeId)";
        }
        $this->_tables[$name] = "\nLEFT JOIN $name ON $name.entity_id = `$joinTableAlias`.id";
        if ($this->_ids[$id]) {
          $this->_whereTables[$name] = $this->_tables[$name];
        }
        if ($joinTable != 'contact_a') {
          $this->_whereTables[$joinTableAlias] = $this->_tables[$joinTableAlias] = $joinClause;
        }
        elseif ($this->_contactSearch) {
          CRM_Contact_BAO_Query::$_openedPanes[ts('Custom Fields')] = TRUE;
        }
      }
    }
  }

  /**
   * Generate the where clause and also the english language.
   * equivalent
   *
   * @return void
   */
  public function where() {
    foreach ($this->_ids as $id => $values) {

      // Fixed for Isuue CRM 607
      if (CRM_Utils_Array::value($id, $this->_fields) === NULL ||
        !$values
      ) {
        continue;
      }

      $strtolower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';

      foreach ($values as $tuple) {
        list($name, $op, $value, $grouping, $wildcard) = $tuple;

        $field = $this->_fields[$id];

        $fieldName = "{$field['table_name']}.{$field['column_name']}";

        // Autocomplete comes back as a string not an array
        if ($field['data_type'] == 'String' && $field['html_type'] == 'Autocomplete-Select' && $op == '=') {
          $value = explode(',', $value);
        }

        // Handle multi-select search for any data type
        if (is_array($value) && !$field['is_search_range']) {
          $isSerialized = CRM_Core_BAO_CustomField::isSerialized($field);
          $wildcard = $isSerialized ? $wildcard : TRUE;
          $options = CRM_Utils_Array::value('values', civicrm_api3('contact', 'getoptions', array(
                'field' => $name,
                'context' => 'search',
              ), array()));
          $qillValue = '';
          $sqlOP = $wildcard ? ' OR ' : ' AND ';
          $sqlValue = array();
          foreach ($value as $num => &$v) {
            $sep = count($value) > (1 + $num) ? ', ' : (' ' . ($wildcard ? ts('OR') : ts('AND')) . ' ');
            $qillValue .= ($num ? $sep : '') . $options[$v];
            $v = CRM_Core_DAO::escapeString($v);
            if ($isSerialized) {
              $sqlValue[] = "( $fieldName like '%" . CRM_Core_DAO::VALUE_SEPARATOR . $v . CRM_Core_DAO::VALUE_SEPARATOR . "%' ) ";
            }
            else {
              $v = "'$v'";
            }
          }
          if (!$isSerialized) {
            $sqlValue = array("$fieldName IN (" . implode(',', $value) . ")");
          }
          $this->_where[$grouping][] = ' ( ' . implode($sqlOP, $sqlValue) . ' ) ';
          $this->_qill[$grouping][] = "$field[label] $op $qillValue";
          continue;
        }

        // fix $value here to escape sql injection attacks
        if (!is_array($value)) {
          $value = CRM_Core_DAO::escapeString(trim($value));
        }

        $qillValue = CRM_Core_BAO_CustomField::getDisplayValue($value, $id, $this->_options);

        switch ($field['data_type']) {
          case 'String':
            $sql = "$fieldName";

            if ($field['is_search_range'] && is_array($value)) {
              $this->searchRange($field['id'],
                $field['label'],
                $field['data_type'],
                $fieldName,
                $value,
                $grouping
              );
            }
            else {
              $val = CRM_Utils_Type::escape($strtolower(trim($value)), 'String');

              if ($wildcard) {
                $val = $strtolower(CRM_Core_DAO::escapeString($val));
                $val = "%$val%";
                $op = 'LIKE';
              }

              //FIX for custom data query fired against no value(NULL/NOT NULL)
              $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($sql, $op, $val, $field['data_type']);
              $this->_qill[$grouping][] = "$field[label] $op $qillValue";
            }
            break;

          case 'ContactReference':
            $label = $value ? CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $value, 'sort_name') : '';
            $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'String');
            $this->_qill[$grouping][] = $field['label'] . " $op $label";
            break;

          case 'Int':
            if ($field['is_search_range'] && is_array($value)) {
              $this->searchRange($field['id'], $field['label'], $field['data_type'], $fieldName, $value, $grouping);
            }
            else {
              $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'Integer');
              $this->_qill[$grouping][] = $field['label'] . " $op $value";
            }
            break;

          case 'Boolean':
            if (strtolower($value) == 'yes' || strtolower($value) == strtolower(ts('Yes'))) {
              $value = 1;
            }
            else {
              $value = (int) $value;
            }
            $value = ($value == 1) ? 1 : 0;
            $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'Integer');
            $value = $value ? ts('Yes') : ts('No');
            $this->_qill[$grouping][] = $field['label'] . " {$op} {$value}";
            break;

          case 'Link':
            $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'String');
            $this->_qill[$grouping][] = $field['label'] . " $op $value";
            break;

          case 'Float':
            if ($field['is_search_range'] && is_array($value)) {
              $this->searchRange($field['id'], $field['label'], $field['data_type'], $fieldName, $value, $grouping);
            }
            else {
              $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'Float');
              $this->_qill[$grouping][] = $field['label'] . " {$op} {$value}";
            }
            break;

          case 'Money':
            if ($field['is_search_range'] && is_array($value)) {
              foreach ($value as $key => $val) {
                $moneyFormat = CRM_Utils_Rule::cleanMoney($value[$key]);
                $value[$key] = $moneyFormat;
              }
              $this->searchRange($field['id'], $field['label'], $field['data_type'], $fieldName, $value, $grouping);
            }
            else {
              $moneyFormat = CRM_Utils_Rule::cleanMoney($value);
              $value = $moneyFormat;
              $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'Float');
              $this->_qill[$grouping][] = $field['label'] . " {$op} {$value}";
            }
            break;

          case 'Memo':
            $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $value, 'String');
            $this->_qill[$grouping][] = "$field[label] $op $value";
            break;

          case 'Date':
            $fromValue = CRM_Utils_Array::value('from', $value);
            $toValue = CRM_Utils_Array::value('to', $value);

            if (!$fromValue && !$toValue) {
              if (!CRM_Utils_Date::processDate($value) && !in_array($op, array('IS NULL', 'IS NOT NULL', 'IS EMPTY', 'IS NOT EMPTY'))) {
                continue;
              }

              // hack to handle yy format during search
              if (is_numeric($value) && strlen($value) == 4) {
                $value = "01-01-{$value}";
              }

              $date = CRM_Utils_Date::processDate($value);
              $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op, $date, 'String');
              $this->_qill[$grouping][] = $field['label'] . " {$op} " . CRM_Utils_Date::customFormat($date);
            }
            else {
              if (is_numeric($fromValue) && strlen($fromValue) == 4) {
                $fromValue = "01-01-{$fromValue}";
              }

              if (is_numeric($toValue) && strlen($toValue) == 4) {
                $toValue = "01-01-{$toValue}";
              }

              // TO DO: add / remove time based on date parts
              $fromDate = CRM_Utils_Date::processDate($fromValue);
              $toDate = CRM_Utils_Date::processDate($toValue);
              if (!$fromDate && !$toDate) {
                continue;
              }
              if ($fromDate) {
                $this->_where[$grouping][] = "$fieldName >= $fromDate";
                $this->_qill[$grouping][] = $field['label'] . ' >= ' . CRM_Utils_Date::customFormat($fromDate);
              }
              if ($toDate) {
                $this->_where[$grouping][] = "$fieldName <= $toDate";
                $this->_qill[$grouping][] = $field['label'] . ' <= ' . CRM_Utils_Date::customFormat($toDate);
              }
            }
            break;

          case 'StateProvince':
          case 'Country':
            $this->_where[$grouping][] = "$fieldName {$op} " . CRM_Utils_Type::escape($value, 'Int');
            $this->_qill[$grouping][] = $field['label'] . " {$op} {$qillValue}";
            break;

          case 'File':
            if ($op == 'IS NULL' || $op == 'IS NOT NULL' || $op == 'IS EMPTY' || $op == 'IS NOT EMPTY') {
              switch ($op) {
                case 'IS EMPTY':
                  $op = 'IS NULL';
                  break;

                case 'IS NOT EMPTY':
                  $op = 'IS NOT NULL';
                  break;
              }
              $this->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($fieldName, $op);
              $this->_qill[$grouping][] = $field['label'] . " {$op} ";
            }
            break;
        }
      }
    }
  }

  /**
   * Function that does the actual query generation.
   * basically ties all the above functions together
   *
   * @return array
   *   array of strings
   */
  public function query() {
    $this->select();

    $this->where();

    $whereStr = NULL;
    if (!empty($this->_where)) {
      $clauses = array();
      foreach ($this->_where as $grouping => $values) {
        if (!empty($values)) {
          $clauses[] = ' ( ' . implode(' AND ', $values) . ' ) ';
        }
      }
      if (!empty($clauses)) {
        $whereStr = ' ( ' . implode(' OR ', $clauses) . ' ) ';
      }
    }

    return array(
      implode(' , ', $this->_select),
      implode(' ', $this->_tables),
      $whereStr,
    );
  }

  /**
   * @param int $id
   * @param $label
   * @param $type
   * @param string $fieldName
   * @param $value
   * @param $grouping
   */
  public function searchRange(&$id, &$label, $type, $fieldName, &$value, &$grouping) {
    $qill = array();

    if (isset($value['from'])) {
      $val = CRM_Utils_Type::escape($value['from'], $type);

      if ($type == 'String') {
        $this->_where[$grouping][] = "$fieldName >= '$val'";
      }
      else {
        $this->_where[$grouping][] = "$fieldName >= $val";
      }
      $qill[] = ts('greater than or equal to \'%1\'', array(1 => $value['from']));
    }

    if (isset($value['to'])) {
      $val = CRM_Utils_Type::escape($value['to'], $type);
      if ($type == 'String') {
        $this->_where[$grouping][] = "$fieldName <= '$val'";
      }
      else {
        $this->_where[$grouping][] = "$fieldName <= $val";
      }
      $qill[] = ts('less than or equal to \'%1\'', array(1 => $value['to']));
    }

    if (!empty($qill)) {
      $this->_qill[$grouping][] = $label . ' - ' . implode(' ' . ts('and') . ' ', $qill);
    }
  }

}
