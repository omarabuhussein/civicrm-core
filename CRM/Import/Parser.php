<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
abstract class CRM_Import_Parser {
  /**
   * Settings
   */
  const MAX_WARNINGS = 25, DEFAULT_TIMEOUT = 30;

  /**
   * Return codes
   */
  const VALID = 1, WARNING = 2, ERROR = 4, CONFLICT = 8, STOP = 16, DUPLICATE = 32, MULTIPLE_DUPE = 64, NO_MATCH = 128, UNPARSED_ADDRESS_WARNING = 256;

  /**
   * Parser modes
   */
  const MODE_MAPFIELD = 1, MODE_PREVIEW = 2, MODE_SUMMARY = 4, MODE_IMPORT = 8;

  /**
   * Codes for duplicate record handling
   */
  const DUPLICATE_SKIP = 1, DUPLICATE_REPLACE = 2, DUPLICATE_UPDATE = 4, DUPLICATE_FILL = 8, DUPLICATE_NOCHECK = 16;

  /**
   * Contact types
   */
  const CONTACT_INDIVIDUAL = 1, CONTACT_HOUSEHOLD = 2, CONTACT_ORGANIZATION = 4;


  /**
   * Total number of non empty lines
   * @var int
   */
  protected $_totalCount;

  /**
   * Running total number of valid lines
   * @var int
   */
  protected $_validCount;

  /**
   * Running total number of invalid rows
   * @var int
   */
  protected $_invalidRowCount;

  /**
   * Maximum number of non-empty/comment lines to process
   *
   * @var int
   */
  protected $_maxLinesToProcess;

  /**
   * Array of error lines, bounded by MAX_ERROR
   * @var array
   */
  protected $_errors;

  /**
   * Total number of conflict lines
   * @var int
   */
  protected $_conflictCount;

  /**
   * Array of conflict lines
   * @var array
   */
  protected $_conflicts;

  /**
   * Total number of duplicate (from database) lines
   * @var int
   */
  protected $_duplicateCount;

  /**
   * Array of duplicate lines
   * @var array
   */
  protected $_duplicates;

  /**
   * Running total number of warnings
   * @var int
   */
  protected $_warningCount;

  /**
   * Maximum number of warnings to store
   * @var int
   */
  protected $_maxWarningCount = self::MAX_WARNINGS;

  /**
   * Array of warning lines, bounded by MAX_WARNING
   * @var array
   */
  protected $_warnings;

  /**
   * Array of all the fields that could potentially be part
   * of this import process
   * @var array
   */
  protected $_fields;

  /**
   * Metadata for all available fields, keyed by unique name.
   *
   * This is intended to supercede $_fields which uses a special sauce format which
   * importableFieldsMetadata uses the standard getfields type format.
   *
   * @var array
   */
  protected $importableFieldsMetadata = [];

  /**
   * Get metadata for all importable fields in std getfields style format.
   *
   * @return array
   */
  public function getImportableFieldsMetadata(): array {
    return $this->importableFieldsMetadata;
  }

  /**
   * Set metadata for all importable fields in std getfields style format.
   *
   * @param array $importableFieldsMetadata
   */
  public function setImportableFieldsMetadata(array $importableFieldsMetadata): void {
    $this->importableFieldsMetadata = $importableFieldsMetadata;
  }

  /**
   * Array of the fields that are actually part of the import process
   * the position in the array also dictates their position in the import
   * file
   * @var array
   */
  protected $_activeFields;

  /**
   * Cache the count of active fields
   *
   * @var int
   */
  protected $_activeFieldCount;

  /**
   * Cache of preview rows
   *
   * @var array
   */
  protected $_rows;

  /**
   * Filename of error data
   *
   * @var string
   */
  protected $_errorFileName;

  /**
   * Filename of conflict data
   *
   * @var string
   */
  protected $_conflictFileName;

  /**
   * Filename of duplicate data
   *
   * @var string
   */
  protected $_duplicateFileName;

  /**
   * Contact type
   *
   * @var int
   */
  public $_contactType;
  /**
   * Contact sub-type
   *
   * @var int
   */
  public $_contactSubType;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->_maxLinesToProcess = 0;
  }

  /**
   * Abstract function definitions.
   */
  abstract protected function init();

  /**
   * @return mixed
   */
  abstract protected function fini();

  /**
   * Map field.
   *
   * @param array $values
   *
   * @return mixed
   */
  abstract protected function mapField(&$values);

  /**
   * Preview.
   *
   * @param array $values
   *
   * @return mixed
   */
  abstract protected function preview(&$values);

  /**
   * @param $values
   *
   * @return mixed
   */
  abstract protected function summary(&$values);

  /**
   * @param $onDuplicate
   * @param $values
   *
   * @return mixed
   */
  abstract protected function import($onDuplicate, &$values);

  /**
   * Set and validate field values.
   *
   * @param array $elements
   *   array.
   * @param $erroneousField
   *   reference.
   *
   * @return int
   */
  public function setActiveFieldValues($elements, &$erroneousField = NULL) {
    $maxCount = count($elements) < $this->_activeFieldCount ? count($elements) : $this->_activeFieldCount;
    for ($i = 0; $i < $maxCount; $i++) {
      $this->_activeFields[$i]->setValue($elements[$i]);
    }

    // reset all the values that we did not have an equivalent import element
    for (; $i < $this->_activeFieldCount; $i++) {
      $this->_activeFields[$i]->resetValue();
    }

    // now validate the fields and return false if error
    $valid = self::VALID;
    for ($i = 0; $i < $this->_activeFieldCount; $i++) {
      if (!$this->_activeFields[$i]->validate()) {
        // no need to do any more validation
        $erroneousField = $i;
        $valid = self::ERROR;
        break;
      }
    }
    return $valid;
  }

  /**
   * Format the field values for input to the api.
   *
   * @return array
   *   (reference) associative array of name/value pairs
   */
  public function &getActiveFieldParams() {
    $params = [];
    for ($i = 0; $i < $this->_activeFieldCount; $i++) {
      if (isset($this->_activeFields[$i]->_value)
        && !isset($params[$this->_activeFields[$i]->_name])
        && !isset($this->_activeFields[$i]->_related)
      ) {

        $params[$this->_activeFields[$i]->_name] = $this->_activeFields[$i]->_value;
      }
    }
    return $params;
  }

  /**
   * Add progress bar to the import process. Calculates time remaining, status etc.
   *
   * @param $statusID
   *   status id of the import process saved in $config->uploadDir.
   * @param bool $startImport
   *   True when progress bar is to be initiated.
   * @param $startTimestamp
   *   Initial timestamp when the import was started.
   * @param $prevTimestamp
   *   Previous timestamp when this function was last called.
   * @param $totalRowCount
   *   Total number of rows in the import file.
   *
   * @return NULL|$currTimestamp
   */
  public function progressImport($statusID, $startImport = TRUE, $startTimestamp = NULL, $prevTimestamp = NULL, $totalRowCount = NULL) {
    $statusFile = CRM_Core_Config::singleton()->uploadDir . "status_{$statusID}.txt";

    if ($startImport) {
      $status = "<div class='description'>&nbsp; " . ts('No processing status reported yet.') . "</div>";
      //do not force the browser to display the save dialog, CRM-7640
      $contents = json_encode([0, $status]);
      file_put_contents($statusFile, $contents);
    }
    else {
      $rowCount = $this->_rowCount ?? $this->_lineCount;
      $currTimestamp = time();
      $time = ($currTimestamp - $prevTimestamp);
      $recordsLeft = $totalRowCount - $rowCount;
      if ($recordsLeft < 0) {
        $recordsLeft = 0;
      }
      $estimatedTime = ($recordsLeft / 50) * $time;
      $estMinutes = floor($estimatedTime / 60);
      $timeFormatted = '';
      if ($estMinutes > 1) {
        $timeFormatted = $estMinutes . ' ' . ts('minutes') . ' ';
        $estimatedTime = $estimatedTime - ($estMinutes * 60);
      }
      $timeFormatted .= round($estimatedTime) . ' ' . ts('seconds');
      $processedPercent = (int ) (($rowCount * 100) / $totalRowCount);
      $statusMsg = ts('%1 of %2 records - %3 remaining',
        [1 => $rowCount, 2 => $totalRowCount, 3 => $timeFormatted]
      );
      $status = "<div class=\"description\">&nbsp; <strong>{$statusMsg}</strong></div>";
      $contents = json_encode([$processedPercent, $status]);

      file_put_contents($statusFile, $contents);
      return $currTimestamp;
    }
  }

  /**
   * @return array
   */
  public function getSelectValues(): array {
    $values = [];
    foreach ($this->_fields as $name => $field) {
      $values[$name] = $field->_title;
    }
    return $values;
  }

  /**
   * @return array
   */
  public function getSelectTypes() {
    $values = [];
    foreach ($this->_fields as $name => $field) {
      if (isset($field->_hasLocationType)) {
        $values[$name] = $field->_hasLocationType;
      }
    }
    return $values;
  }

  /**
   * @return array
   */
  public function getHeaderPatterns() {
    $values = [];
    foreach ($this->_fields as $name => $field) {
      if (isset($field->_headerPattern)) {
        $values[$name] = $field->_headerPattern;
      }
    }
    return $values;
  }

  /**
   * @return array
   */
  public function getDataPatterns() {
    $values = [];
    foreach ($this->_fields as $name => $field) {
      $values[$name] = $field->_dataPattern;
    }
    return $values;
  }

  /**
   * Remove single-quote enclosures from a value array (row).
   *
   * @param array $values
   * @param string $enclosure
   *
   * @return void
   */
  public static function encloseScrub(&$values, $enclosure = "'") {
    if (empty($values)) {
      return;
    }

    foreach ($values as $k => $v) {
      $values[$k] = preg_replace("/^$enclosure(.*)$enclosure$/", '$1', $v);
    }
  }

  /**
   * Setter function.
   *
   * @param int $max
   *
   * @return void
   */
  public function setMaxLinesToProcess($max) {
    $this->_maxLinesToProcess = $max;
  }

  /**
   * Determines the file extension based on error code.
   *
   * @var $type error code constant
   * @return string
   */
  public static function errorFileName($type) {
    $fileName = NULL;
    if (empty($type)) {
      return $fileName;
    }

    $config = CRM_Core_Config::singleton();
    $fileName = $config->uploadDir . "sqlImport";
    switch ($type) {
      case self::ERROR:
        $fileName .= '.errors';
        break;

      case self::CONFLICT:
        $fileName .= '.conflicts';
        break;

      case self::DUPLICATE:
        $fileName .= '.duplicates';
        break;

      case self::NO_MATCH:
        $fileName .= '.mismatch';
        break;

      case self::UNPARSED_ADDRESS_WARNING:
        $fileName .= '.unparsedAddress';
        break;
    }

    return $fileName;
  }

  /**
   * Determines the file name based on error code.
   *
   * @var $type error code constant
   * @return string
   */
  public static function saveFileName($type) {
    $fileName = NULL;
    if (empty($type)) {
      return $fileName;
    }
    switch ($type) {
      case self::ERROR:
        $fileName = 'Import_Errors.csv';
        break;

      case self::CONFLICT:
        $fileName = 'Import_Conflicts.csv';
        break;

      case self::DUPLICATE:
        $fileName = 'Import_Duplicates.csv';
        break;

      case self::NO_MATCH:
        $fileName = 'Import_Mismatch.csv';
        break;

      case self::UNPARSED_ADDRESS_WARNING:
        $fileName = 'Import_Unparsed_Address.csv';
        break;
    }

    return $fileName;
  }

  /**
   * Check if contact is a duplicate .
   *
   * @param array $formatValues
   *
   * @return array
   */
  protected function checkContactDuplicate(&$formatValues) {
    //retrieve contact id using contact dedupe rule
    $formatValues['contact_type'] = $formatValues['contact_type'] ?? $this->_contactType;
    $formatValues['version'] = 3;
    require_once 'CRM/Utils/DeprecatedUtils.php';
    $params = $formatValues;
    static $cIndieFields = NULL;
    static $defaultLocationId = NULL;

    $contactType = $params['contact_type'];
    if ($cIndieFields == NULL) {
      $cTempIndieFields = CRM_Contact_BAO_Contact::importableFields($contactType);
      $cIndieFields = $cTempIndieFields;

      $defaultLocation = CRM_Core_BAO_LocationType::getDefault();

      // set the value to default location id else set to 1
      if (!$defaultLocationId = (int) $defaultLocation->id) {
        $defaultLocationId = 1;
      }
    }

    $locationFields = CRM_Contact_BAO_Query::$_locationSpecificFields;

    $contactFormatted = [];
    foreach ($params as $key => $field) {
      if ($field == NULL || $field === '') {
        continue;
      }
      // CRM-17040, Considering only primary contact when importing contributions. So contribution inserts into primary contact
      // instead of soft credit contact.
      if (is_array($field) && $key != "soft_credit") {
        foreach ($field as $value) {
          $break = FALSE;
          if (is_array($value)) {
            foreach ($value as $name => $testForEmpty) {
              if ($name !== 'phone_type' &&
                ($testForEmpty === '' || $testForEmpty == NULL)
              ) {
                $break = TRUE;
                break;
              }
            }
          }
          else {
            $break = TRUE;
          }
          if (!$break) {
            _civicrm_api3_deprecated_add_formatted_param($value, $contactFormatted);
          }
        }
        continue;
      }

      $value = [$key => $field];

      // check if location related field, then we need to add primary location type
      if (in_array($key, $locationFields)) {
        $value['location_type_id'] = $defaultLocationId;
      }
      elseif (array_key_exists($key, $cIndieFields)) {
        $value['contact_type'] = $contactType;
      }

      _civicrm_api3_deprecated_add_formatted_param($value, $contactFormatted);
    }

    $contactFormatted['contact_type'] = $contactType;

    return _civicrm_api3_deprecated_duplicate_formatted_contact($contactFormatted);
  }

  /**
   * Parse a field which could be represented by a label or name value rather than the DB value.
   *
   * We will try to match name first or (per https://lab.civicrm.org/dev/core/issues/1285 if we have an id.
   *
   * but if not available then see if we have a label that can be converted to a name.
   *
   * @param string|int|null $submittedValue
   * @param array $fieldSpec
   *   Metadata for the field
   *
   * @return mixed
   */
  protected function parsePseudoConstantField($submittedValue, $fieldSpec) {
    // dev/core#1289 Somehow we have wound up here but the BAO has not been specified in the fieldspec so we need to check this but future us problem, for now lets just return the submittedValue
    if (!isset($fieldSpec['bao'])) {
      return $submittedValue;
    }
    /* @var \CRM_Core_DAO $bao */
    $bao = $fieldSpec['bao'];
    // For historical reasons use validate as context - ie disabled name matches ARE permitted.
    $nameOptions = $bao::buildOptions($fieldSpec['name'], 'validate');
    if (isset($nameOptions[$submittedValue])) {
      return $submittedValue;
    }
    if (in_array($submittedValue, $nameOptions)) {
      return array_search($submittedValue, $nameOptions, TRUE);
    }

    $labelOptions = array_flip($bao::buildOptions($fieldSpec['name'], 'match'));
    if (isset($labelOptions[$submittedValue])) {
      return array_search($labelOptions[$submittedValue], $nameOptions, TRUE);
    }
    return '';
  }

  /**
   * This is code extracted from 4 places where this exact snippet was being duplicated.
   *
   * FIXME: Extracting this was a first step, but there's also
   *  1. Inconsistency in the way other select options are handled.
   *     Contribution adds handling for Select/Radio/Autocomplete
   *     Participant/Activity only handles Select/Radio and misses Autocomplete
   *     Membership is missing all of it
   *  2. Inconsistency with the way this works vs. how it's implemented in Contact import.
   *
   * @param $customFieldID
   * @param $value
   * @param $fieldType
   * @return array
   */
  public static function unserializeCustomValue($customFieldID, $value, $fieldType) {
    $mulValues = explode(',', $value);
    $customOption = CRM_Core_BAO_CustomOption::getCustomOption($customFieldID, TRUE);
    $values = [];
    foreach ($mulValues as $v1) {
      foreach ($customOption as $customValueID => $customLabel) {
        $customValue = $customLabel['value'];
        if ((strtolower(trim($customLabel['label'])) == strtolower(trim($v1))) ||
          (strtolower(trim($customValue)) == strtolower(trim($v1)))
        ) {
          if ($fieldType == 'CheckBox') {
            $values[$customValue] = 1;
          }
          else {
            $values[] = $customValue;
          }
        }
      }
    }
    return $values;
  }

  /**
   * Get the ids of any contacts that match according to the rule.
   *
   * @param array $formatted
   *
   * @return array
   */
  protected function getIdsOfMatchingContacts(array $formatted):array {
    // the call to the deprecated function seems to add no value other that to do an additional
    // check for the contact_id & type.
    $error = _civicrm_api3_deprecated_duplicate_formatted_contact($formatted);
    if (!CRM_Core_Error::isAPIError($error, CRM_Core_ERROR::DUPLICATE_CONTACT)) {
      return [];
    }
    if (is_array($error['error_message']['params'][0])) {
      return $error['error_message']['params'][0];
    }
    else {
      return explode(',', $error['error_message']['params'][0]);
    }
  }

}
