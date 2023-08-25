<?php
namespace GDA;

use ReflectionClass;
use Exception;

/**
 * Base class for all entities for the generic data interface.
 * All entities must extend this class.
 *
 * @author Matthias Kolonko
 *
 */
abstract class Entity {
  
  const PREFIX_DELIM           = "_";
  const PREFIX_FIELD_DEF       = "FD";
  
  const FIELD_DEF_POS_NAME     = 0;
  const FIELD_DEF_POS_OPT      = 1;
  const FIELD_DEF_POS_TYPE     = 2;
  
  /*
   * // FIXME type declaration leads to fault during serialization!!!
   *  
   * @var original Entity  
   */
  protected $original;
  protected ?ReflectionClass $refl;
  
  public function __construct() {
    $this->__wakeup();
    $this->adjustFieldValues();
    $this->original = clone $this;
  }
  
  public function __wakeup() {
    $this->refl = new ReflectionClass(get_class($this));
  }
  
  public function __sleep() {
    $propertyNames = array_column($this->refl->getProperties(), 'name');
    $this->refl = null;
    return $propertyNames;
  }
  
  protected abstract function adjustFieldValues(): void;
  
  public function getModifications(): array {
    $this->validateValues();
    
    $result = array();
    
    foreach ($this->getFieldDefinitions() as $fieldDef) {
      $fieldName = $fieldDef[self::FIELD_DEF_POS_NAME];
      if ($this->$fieldName != $this->original->$fieldName) {
        $result[$fieldName] = $this->$fieldName;
      }
    }
    
    return $result;
  }
  
  /**
   * Empty method that can be overriden by particular entities that need to validate.
   */
  public function validateValues(): void {}
  
  /**
   * Get the field names that identify an entitiy.
   * 
   * @return array The array contains the field names of the key fields. Both, array key and array value contain the field name.
   */
  public abstract function getKeyFields(): array;
  
  /**
   * Get the values of the key fields in an associative array.
   * 
   * @return array The array contains the field names as keys and the field values as values.
   */
  public function getKeyValues(): array {
    $this->validateValues();
    
    $result = array();
    
    foreach ($this->getKeyFields() as $keyField) {
      if(!is_null($this->{$keyField})) $result[$keyField] = $this->{$keyField};
    }
    
    return $result;
  }
  
  public function getOriginalKeyValues(): array {
    return $this->original->getKeyValues();
  }
  
  public function getOriginal(): Entity {
    return clone $this->original;
  }
  
  public function getFieldDefinitions(): array {
    $result = $this->refl->getConstants();
    $match = "(".self::PREFIX_FIELD_DEF.self::PREFIX_DELIM.")";
    $result = array_filter($result, function($k) use ($match) {return preg_match($match, $k);}, ARRAY_FILTER_USE_KEY);
    return $result;
  }
  
  public function getFieldDefinition(string $fieldName): array {
    $result=$this->refl->getConstant(self::PREFIX_FIELD_DEF.self::PREFIX_DELIM.strtoupper($fieldName));
    if (!is_array($result)) throw new Exception("No field definition in Entity '".get_class($this)."' found for requested field: ".$fieldName." - received: ".gettype($result));
    return $result;
  }
  
  public static function getEntities(): array {
    return array_filter(get_declared_classes(), function($v,$k) {
      return is_subclass_of($v, self::class);
    }, ARRAY_FILTER_USE_BOTH);
  }
  
}
