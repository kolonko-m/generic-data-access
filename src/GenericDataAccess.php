<?php 
namespace GDA;

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
