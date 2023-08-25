<?php 
namespace GDA;

use InvalidArgumentException;

abstract class GenericDataAccess implements GenericDataIF {
  
  protected DataAccessorMap $accessorMap;
  
  public function __construct(DataAccessorMap $accessorMap) {
    $this->accessorMap = $accessorMap;
  }
  
  public function getHandlingUnit(): string {
    return Entity::class;
  }
  
  /* 
   * Transactional functions
   */
  
  public function beginTransaction(): void {
    foreach ($this->accessorMap->getAccessors() as $t) $t->beginTransaction();
  }

  public function commit(): void {
    foreach ($this->accessorMap->getAccessors() as $t) $t->commit();
  }
  
  public function rollback(): void {
    foreach ($this->accessorMap->getAccessors() as $t) $t->rollback();
  }
  
  /* 
   * DML functions
   */
  
  public function getById(Entity $e): ?Entity {
    return $this->getAccessorByType($e)->getById($e);
  }

  public function getByTemplate(Entity $t, array $order=null): array {
    return $this->getAccessorByType($t)->getByTemplate($t, $order);
  }
  
  public function countByTemplate($t): int {
    return $this->getAccessorByType($t)->countByTemplate($t);
  }
  
  public function checkExistenceByTemplate($t): bool {
    return $this->getAccessorByType($t)->checkExistenceByTemplate($t);
  }

  public function insert(Entity $e): Entity {
    return $this->getAccessorByType($e)->insert($e);
  }

  public function update(Entity $e): bool {
    return $this->getAccessorByType($e)->update($e);
  }

  public function delete(Entity $e): bool {
    return $this->getAccessorByType($e)->delete($e);
  }

  public function deleteByTemplate(Entity $t): bool {
    return $this->getAccessorByType($t)->deleteByTemplate($t);
  }
  
  protected function getAccessorByType(Entity $e): GenericDataIF {
    return $this->accessorMap->getAccessorByType($e);
  }
  
  protected function getAccessorByClassName(string $className): GenericDataIF {
    return $this->accessorMap->getAccessorByClassName($className);
  }
  
}

class DataAccessorMap {
  
  protected string $ifClass;
  
  protected array $dataAccessors = array();
  
  public function __construct(string $ifClass) {
    if (!(is_a($ifClass, GenericDataIF::class, true) || is_subclass_of($ifClass, GenericDataIF::class, true))) throw new InvalidArgumentException("$ifClass is not a subtype of ".GenericDataIF::class);
    $this->ifClass = $ifClass;
  }
  
  public function getIfClass(): string {
    return $this->ifClass;
  }
  
  public function registerDataAccessor(GenericDataIF $a) {
    $implementationClass = get_class($a);
    $handlingUnitClass = $a->getHandlingUnit();
    if (!(is_a($handlingUnitClass, Entity::class, true) || is_subclass_of($handlingUnitClass, Entity::class, true))) {
      throw new InvalidAccessorTypeException("Class for accessor type must be subtype of Entity class, which the given class is not: $handlingUnitClass");
    }
    if (!(is_a($implementationClass, $this->ifClass, true) || is_subclass_of($implementationClass, $this->ifClass, true))) {
      throw new InvalidAccessorTypeException("$implementationClass is not a subclass of $this->ifClass");
    }
    $this->dataAccessors[$handlingUnitClass]=$a;
  }
  
  public function unregisterDataAccessor(string $className) {
    unset($this->dataAccessors[$className]);
  }
  
  public function getAccessorByType(Entity $e): GenericDataIF {
    return $this->getAccessorByClassName(get_class($e));
  }
  
  public function getAccessorByClassName(string $className): GenericDataIF {
    foreach ($this->dataAccessors as $class=>$accessor) {
      if (is_a($className, $class, true) || is_subclass_of($className, $class, true)) return $accessor;
    }
    
    // no applicable data accessor found!
    throw new AccessorNotFoundException("No applicable data accessor found for type: $className");
  }
  
  public function getAccessors(): array {
    return $this->dataAccessors;
  }
  
}

class GenericDataAccessException extends \Exception {}
class AccessorNotFoundException extends GenericDataAccessException {}
class InvalidAccessorTypeException extends GenericDataAccessException {}
