<?php 
namespace GDA\polyglot;

use InvalidArgumentException;
use GDA\Entity;
use GDA\exceptions\PolyglotException;

abstract class PolyglotEntity extends Entity {
  
  const CN_CONNECTING_ATTRIBUTES = "CONNECTING_ATTRS";
  const CN_FIELD_MAPPING         = "FIELD_MAPPING";
  
  const PREFIX_REFERENCE         = "REF";
  const POS_REF_ENTITY           = 0;
  const POS_REF_FIELDS           = 1;
  
  protected array $persistentEntities = array();
  
  public function addPersistentEntity(Entity $e): bool {
    if (!array_key_exists(get_class($e), $this->getFieldMapping())) {
      throw new PolyglotException("Entity class not configured for this PolyglotEntity: ".get_class($e));
    }
    
    $result = array_key_exists(get_class($e), $this->persistentEntities);
    $this->persistentEntities[get_class($e)] = $e;
    return $result;
  }
  
  public function clearPersistentEntities(): void {
    $this->persistentEntities = array();
  }
  
  public function getPersistentEntities(): array {
    return $this->persistentEntities;
  }
  
  public function getPersistentEntity(string $className): ?Entity {
    return $this->persistentEntities[$className];
  }
  
  protected function adjustFieldValues(): void {}
  
  public function getKeyFields(): array {
    if (count($this->persistentEntities) == 0) throw new InvalidArgumentException("Instance does not contain any persistents!");
    $result = array();
    foreach ($this->persistentEntities as $e) {
      foreach ($e->getKeyFields() as $keyField) {
        $field = $this->getFieldFromMapping($keyField, get_class($e));
        $result[$field]=$field;
      }
    }
    
    // check if key fields exist in the instance.
    foreach ($result as $keyField) {
      if (!property_exists($this, $keyField)) throw new PolyglotException("PolyglotEntity class does not contain required key field: $keyField");
    }
    
    return $result;
  }
  
  public function fillFromPersistentEntities() {
    /* @var $e Entity */
    foreach ($this->persistentEntities as $e) {
      foreach ($e->getFieldDefinitions() as $fieldDef) {
        $fieldName = $fieldDef[Entity::FIELD_DEF_POS_NAME];
        $mappedProperty = $this->getFieldFromMapping($fieldName, get_class($e));
        if (property_exists($this, $mappedProperty)) {
          $this->$mappedProperty = $e->$fieldName;
        }
      }
    }
  }
  
  public function updatePersistentEntities() {
    $mods = $this->getModifications();
    foreach ($this->persistentEntities as $e) {
      foreach ($mods as $property => $value) {
        $mappedProperty = $this->getMappedField($property, get_class($e));
        if (property_exists($e, $mappedProperty)) {
          $e->$mappedProperty = $value;
        }
      }
    }
  }
  
  public function amendMissingPersistents(): void {
    foreach (array_keys($this->getFieldMapping()) as $className) {
      if (!array_key_exists($className, $this->persistentEntities)) {
        $this->persistentEntities[$className] = new $className();
      }
    }
    $this->updatePersistentEntities();
  }
  
  public function getConnectingValues(string $className): array {
    $persistent = $this->getPersistentEntity($className);
    if (is_null($persistent)) throw new PolyglotException("No entity of provided class $className found");
    
    $connectingValues=array();
    foreach ($this->getConnectingAttributes() as $connectingAttr) {
      $mappedConnectingAttr=$this->getMappedField($connectingAttr, $className);
      $connectingValues[$connectingAttr] = $persistent->$mappedConnectingAttr;
    }
    return $connectingValues;
  }
  
  public function setConnectingValues(string $className, array $connectingValues): void {
    $persistent = $this->getPersistentEntity($className);
    if (is_null($persistent)) throw new PolyglotException("No entity of provided class $className found");
    
    foreach ($this->getConnectingAttributes() as $connectingAttr) {
      if (!array_key_exists($connectingAttr, $connectingValues)) throw new PolyglotException("Connecting $connectingAttr attribute missing in given values map.");
      if (is_null($connectingValues[$connectingAttr])) throw new PolyglotException("Value for connecting attribute $connectingAttr is NULL!");
    }
    
    foreach ($this->getConnectingAttributes() as $connectingAttr) {
      $mappedConnectingAttr = $this->getMappedField($connectingAttr, $className);
      $persistent->$mappedConnectingAttr=$connectingValues[$connectingAttr];
    }
  }
  
  /*
   * Functionalities for the PolyglotDefinition
   */
  
  public function getMappedEntityClasses(): array {
    return array_keys($this->getFieldMapping());
  }
  
  public function getFieldMappingFor(string $className): array {
    $fieldMapping = $this->getFieldMapping();
    if (!array_key_exists($className, $fieldMapping)) throw new PolyglotException("Class $className not registered for PolyglotEntity: ".get_class($this));
    return $fieldMapping[$className];
  }
  
  public function getStrictMappedField(string $fieldName, string $className): string {
    if (!property_exists($this, $fieldName)) throw new PolyglotException("Given fieldName $fieldName does not exist in PolyglotEntity: ".get_class($this));
    return $this->getMappedField($fieldName, $className);
  }
  
  public function getMappedField(string $fieldName, string $className): string {
    $fieldMap=$this->getFieldMappingFor($className);
    if (is_null($fieldMap) || !array_key_exists($fieldName, $fieldMap)) return $fieldName;
    return $fieldMap[$fieldName];
  }
  
  public function getFieldFromMapping(string $mappedField, string $className): string {
    $fieldMap=$this->getFieldMappingFor($className);
    if (is_null($fieldMap) || !in_array($mappedField, $fieldMap)) return $mappedField;
    return array_search($mappedField, $fieldMap);
  }
  
  public function validateMapping(): void {
    // check if mapped entities exist for field mapping
    foreach ($this->getFieldMapping() as $mappedEntityClass => $fieldMapping) {
      // check field mapping class properties
      foreach ($fieldMapping as $polyglotField => $mappedField) {
        if (!property_exists($mappedEntityClass, $mappedField)) throw new PolyglotException("Error in field mapping. Mapped field $mappedField does not exist in Entity class $mappedEntityClass for polyglot field $polyglotField!");
        // the next check is omitted as there could be fields that are not part of the polyglot entity - esp. the connecting attributes (e.g. a DN)
        // if (!property_exists($this->polyglotClass, $polyglotField)) throw new PolyglotException("Error in field mapping. Polyglot field $polyglotField does not exist in PolyglotEntity $this->polyglotClass!");
      }
    }
    
    // check connecting attributes
      foreach ($this->getConnectingAttributes() as $attr) {
        foreach ($this->getMappedEntityClasses() as $mappedEntityClass) {
          $mappedAttr = $this->getMappedField($attr, $mappedEntityClass);
          if (!property_exists($mappedEntityClass, $mappedAttr)) throw new PolyglotException("Connecting attribute $attr does not exist in mapped Entity class $mappedEntityClass as $mappedAttr!");
        }
      }
  }
  
  public function getFieldMapping(): array {
    $result = $this->refl->getConstant(self::CN_FIELD_MAPPING);
    if (!is_array($result))  throw new PolyglotException("PolyglotEntity '".get_class($this)."' incorrectly configured - no field found: ".self::CN_FIELD_MAPPING);
    if (count($result) == 0) throw new PolyglotException("PolyglotEntity '".get_class($this)."' incorrectly configured - field mapping is empty");
    return $result;
  }
  
  public function getConnectingAttributes(): array {
    $result = $this->refl->getConstant(self::CN_CONNECTING_ATTRIBUTES);
    if (!is_array($result))  throw new PolyglotException("PolyglotEntity '".get_class($this)."' incorrectly configured - no field found: ".self::CN_CONNECTING_ATTRIBUTES);
    if (count($result) == 0) throw new PolyglotException("PolyglotEntity '".get_class($this)."' incorrectly configured - no connecting attributes set.");
    return $result;
  }
  
  public function getPolyglotReferences(): array {
    $result = $this->refl->getConstants();
    $match = self::PREFIX_REFERENCE.self::PREFIX_DELIM;
    $result = array_filter($result, function($k) use ($match){return strpos($k, $match) === 0;}, ARRAY_FILTER_USE_KEY);
    return $result;
  }
  
  /**
   * Returns the class name of the persistent entity that
   * is first in the list carrying the provided field.
   * 
   * @param string $fieldName
   * @return string
   */
  public function getPersistentForField(string $fieldName): string {
    foreach($this->getMappedEntityClasses() as $mappedEntityClass) {
      $mappedField = $this->getMappedField($fieldName, $mappedEntityClass);
      if (property_exists($mappedEntityClass, $mappedField)){
        return $mappedEntityClass;
      }
    }
    throw new PolyglotException("No valid persistent Entity class found for fieldname $fieldName!");
  }
  
}
