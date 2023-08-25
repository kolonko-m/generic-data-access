<?php 
namespace GDA\SQL;

use Exception;
use ReflectionClass;
use GDA\Entity;
use JKingWeb\DrUUID\UUID;

abstract class SqlEntity extends Entity {
  const PREFIX_CONSTRAINT      = "CO";
  const PREFIX_PRIMARY_KEY     = "PK";
  const PREFIX_UNIQUE          = "UNQ";
  const PREFIX_FOREIGN_KEY     = "FK";
  const PREFIX_CHECK           = "CK";
  
  const CONST_TABLENAME        = "TABLENAME";
  const CONST_USERACCESS       = "DB_USER_ACCESS";
  const CONST_DEFAULT_ORDERING = "DEFAULT_ORDERING";
  
  const TYPE_VARCHAR           = "VARCHAR";
  const TYPE_CHAR              = "CHAR";
  const TYPE_URL               = "URL";
  const TYPE_TEL               = "TEL";
  const TYPE_MAIL              = "EMAIL";
  const TYPE_PASSWORD          = "PASSWORD";
  const TYPE_NUMERIC           = "NUMERIC";
  const TYPE_BOOLEAN           = "BOOLEAN";
  const TYPE_DATE              = "DATE";
  const TYPE_TIMESTAMP         = "TIMESTAMP";
  const TYPE_AUTO_TIMESTAMP    = "AUTO_".self::TYPE_TIMESTAMP;
  const TYPE_DEFAULT_TIMESTAMP = "DEFAULT_".self::TYPE_TIMESTAMP;
  const TYPE_INTEGER           = "INTEGER";
  const TYPE_UUID              = "UUID";
  
  const FIELD_DEF_POS_LENGTH   = 3;
  const FIELD_DEF_POS_DEFAULT  = 4;
  
  const OD_CASCADE             = "CASCADE";
  const OD_NULL                = "SET NULL";
  const DEFAULT_SEQUENCE       = "SEQUENCE";
  
  protected function adjustFieldValues(): void {
    foreach ($this->getFieldDefinitions() as $fieldDef) {
      $fieldName = $fieldDef[self::FIELD_DEF_POS_NAME];
      switch ($fieldDef[self::FIELD_DEF_POS_TYPE]) {
        case self::TYPE_BOOLEAN:
          if (!is_null($this->$fieldName) && !is_bool($this->$fieldName)) {
            $this->$fieldName = (((int)$this->$fieldName) === 1);
          }
          break;
        case self::TYPE_UUID:
          if (is_string($this->$fieldName)) $this->$fieldName = UUID::import($this->$fieldName);
          break;
      }
    }
  }
  
  public function getTableName(): string {
    $tableName = $this->refl->getConstant(self::CONST_TABLENAME);
    if (!$tableName) $tableName = get_class($this);
    return $tableName;
  }
  
  public function getEntityDefinition(): array {
    $result = $this->refl->getConstants();
    $match = "(".self::PREFIX_FIELD_DEF.self::PREFIX_DELIM."|".self::PREFIX_CONSTRAINT.self::PREFIX_DELIM.")";
    $result = array_filter($result, function($k) use ($match){return preg_match($match, $k);}, ARRAY_FILTER_USE_KEY);
    return $result;
  }
  
  public function getEntityDbUserAccess(): array {
    $result=$this->refl->getConstant(self::CONST_USERACCESS);
    if (!is_array($result)) throw new Exception("Entity '".get_class($this)."' incorrectly configured no field found: ".self::CONST_USERACCESS);
    return $result;
  }
  
  public function getKeyFields(): array {
    $result = $this->refl->getConstants();
    $match = "(".self::PREFIX_CONSTRAINT.self::PREFIX_DELIM.self::PREFIX_PRIMARY_KEY.self::PREFIX_DELIM.")";
    $result = array_filter($result, function($k) use ($match) {return preg_match($match, $k);}, ARRAY_FILTER_USE_KEY);
    if (count($result)<>1) throw new Exception("More or less than one primary key defined: ".count($result));
    $pkArray = array_pop($result);
    return array_combine($pkArray, $pkArray);
  }
  
  public function getForeignKeyDefs(): array {
    $result = $this->refl->getConstants();
    $match = "(".self::PREFIX_CONSTRAINT.self::PREFIX_DELIM.self::PREFIX_FOREIGN_KEY.self::PREFIX_DELIM.")";
    $result = array_filter($result, function($k) use ($match) {return preg_match($match, $k);}, ARRAY_FILTER_USE_KEY);
    return $result;
  }
  
  public function getReferencedTables(): array {
    $result = array();
    foreach ($this->getForeignKeyDefs() as $foreignKey) {
      $result[] = $foreignKey[1][0];
    }
    $result = array_unique($result);
    $result = array_diff($result, array($this->getTableName()));
    return $result;
  }
  
  public function getDefaultOrdering(): ?array {
    $result=$this->refl->getConstant(self::CONST_DEFAULT_ORDERING);
    if (!$result) return null;
    if (!is_array($result)) throw new Exception("Entity '".get_class($this)."' incorrectly configured no field found: ".self::CONST_DEFAULT_ORDERING);
    return $result;
  }
  
  public static function getSqlEntities(): array {
    return array_filter(get_declared_classes(), function($v,$k) {
      return is_subclass_of($v, self::class) && !(new ReflectionClass($v))->isAbstract();
    }, ARRAY_FILTER_USE_BOTH);
  }
  
  public static function getEntitySequences(): array {
    $match = "(".self::PREFIX_FIELD_DEF.self::PREFIX_DELIM.")";
    $sequences = array();
    foreach (self::getSqlEntities() as $e) {
      if ((new ReflectionClass($e))->isAbstract()) continue;
      /* @var $instance Entity */
      $instance = new $e();
      foreach ($instance->getEntityDefinition() as $name => $value) {
        if (preg_match($match, $name)) {
          if (isset($value[self::FIELD_DEF_POS_DEFAULT]) && $value[self::FIELD_DEF_POS_DEFAULT]===self::DEFAULT_SEQUENCE) {
            if (is_null($value[self::FIELD_DEF_POS_LENGTH])) $length = 9999999999;
            else $length = pow(10, $value[self::FIELD_DEF_POS_LENGTH])-1;
            $sequences[$instance->getTableName()] = $length;
          }
        }
      }
    }
    return $sequences;
  }
  
}
