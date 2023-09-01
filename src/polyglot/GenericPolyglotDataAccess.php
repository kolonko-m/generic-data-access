<?php
namespace GDA\polyglot;

use InvalidArgumentException;
use GDA\{GenericDataIF,DataAccessorMap,Entity};
use GDA\exceptions\PolyglotException;

/**
 * XXX add coordinator database later
 * 
 * @author matthias
 *
 */
class GenericPolyglotDataAccess implements GenericDataIF {
  
  private readonly DataAccessorMap $accessorMap;
  private          array           $openAccessors = array();
  
  public function __construct(DataAccessorMap $accessorMap) {
    $this->accessorMap = $accessorMap;
  }
  
  public function getHandlingUnit(): string {
    return PolyglotEntity::class;
  }
  
  /* Transactional functionalities touch the particular database. */
  public function beginTransaction(): void {}
  public function commit(): void {
    $this->commitInvolvedAccessors();
  }
  public function rollback(): void {
    $this->rollbackInvolvedAccessors();
  }
  
  /* CRUD functions */
  
  public function getById(Entity $e): ?PolyglotEntity {
    if (!($e instanceof PolyglotEntity)) throw new PolyglotException("PolyglotEntity expected, received: ".get_class($e));
    
    $polyglotEntityClass=get_class($e);
    $e->amendMissingPersistents();
    
    /* @var $result PolyglotEntity */
    $result=new $polyglotEntityClass();
    $connectingValues = null;
    $missing=array();
    
    /* @var $persistent Entity */
    foreach ($e->getPersistentEntities() as $persistent) {
      if (count($persistent->getKeyValues()) == count($persistent->getKeyFields())) {
        $received=$this->getAccessorByType($persistent)->getById($persistent);
        if (is_null($received)) return null; // no entry found with given key values.
        $result->addPersistentEntity($received);
        if (is_null($connectingValues)) $connectingValues=$result->getConnectingValues(get_class($persistent));
      } else $missing[]=$persistent;
    }
    
    if (count($missing) > 0) {
      if (count($connectingValues)==0) throw new PolyglotException("Couldn't find all persistent entries by key and no connecting attributes defined! ".get_class($e));
      
      foreach ($missing as $persistent) {
        $e->setConnectingValues(get_class($persistent), $connectingValues);
        $received = $this->getAccessorByType($persistent)->getByTemplate($persistent);
        if (count($received) == 0) return null; // the given value combination in the key attributes of PolyglotEntity instance resulted in nothing found. 
        if (count($received) >  1) throw new PolyglotException("Found more than one entry with given key and connecting value combination!");
        $result->addPersistentEntity($received[0]);
      }
    }
    
    $result->fillFromPersistentEntities();
    $result->__construct();
    return $result;
  }
  
  public function getByTemplate(Entity $t, array $order=null): array {
    if (!($t instanceof PolyglotEntity)) throw new PolyglotException("PolyglotEntity expected, received: ".get_class($t));
    
    $polyglotEntityClass=get_class($t);
    $t->amendMissingPersistents();
    
    // check each persistent for amount of returns and begin with least amount
    $persistents = $t->getPersistentEntities();
    if (count($persistents) != count($t->getMappedEntityClasses())) throw new PolyglotException("Given polyglot template does not contain expected amount of mapped persistent entity classes.");
    
    $minAmount=null;
    $selectedPersistentKey=null;
    foreach ($persistents as $key => $persistent) {
      $current=$this->getAccessorByType($persistent)->countByTemplate($persistent);
      if (is_null($minAmount) || $minAmount > $current) {
        $minAmount=$current;
        $selectedPersistentKey = $key;
      }
    }
    
    if (is_null($selectedPersistentKey)) throw new PolyglotException("No minimal amount of results found!"); // this could only happen if there are no persistents at all!
    if ($minAmount == 0) return array(); // obviously nothing found.
    
    $received=$this->getAccessorByType($persistents[$selectedPersistentKey])->getByTemplate($persistents[$selectedPersistentKey]);
    
    $draftResult=array();
    /* @var $r Entity */
    foreach ($received as $r) {
      /* @var $current PolyglotEntity */
      $current=new $polyglotEntityClass();
      $current->addPersistentEntity($r);
      $connectingValues=$current->getConnectingValues(get_class($r));
      
      foreach ($persistents as $key => $persistent) {
        if ($key == $selectedPersistentKey) continue; // this has already been received!
        
        $t->setConnectingValues(get_class($persistent), $connectingValues);
        $receivedConnected = $this->getAccessorByType($persistent)->getByTemplate($persistent);
        if (count($receivedConnected) == 0) continue; // nothing found --> don't add to current
        if (count($receivedConnected) >  1) throw new PolyglotException("Found more than one persistent Entity despite using connection attributes!");
        
        $current->addPersistentEntity($receivedConnected[0]);
      }
      
      $draftResult[]=$current;
    }
    
    $result=array();
    /* @var $draft PolyglotEntity */
    foreach ($draftResult as $draft) {
      if (count($draft->getPersistentEntities())==count($t->getPersistentEntities())) {
        $draft->fillFromPersistentEntities();
        $result[]=$draft;
      }
    }
    
    if (!is_null($order)) {
      // check if field names exist
      foreach (array_keys($order) as $property) {
        if (!property_exists($t, $property)) throw new PolyglotException("Property for ordering provided that does not exist in $polyglotEntityClass: $property");
      }
      usort($result, function($a, $b) use ($order) {
        $compareResult=0;
        foreach ($order as $property => $asc) {
          $first=$a->$property;
          $second=$b->property;
          
          if     ( is_null($first) &&  is_null($second)) continue;
          if     ( is_null($first) && !is_null($second)) $compareResult=-1;
          elseif (!is_null($first) &&  is_null($second)) $compareResult=1;
          else {
            $type = gettype($first);
            switch ($type) {
              case "boolean":
                if ( $first ==  $second) continue 2;
                if ( $first && !$second) $compareResult=1;
                if (!$first &&  $second) $compareResult=-1;
                break;
              case "integer":
              case "double":
              case "float":
                $compareResult=$first-$second;
                break;
              case "string":
                $compareResult = strcmp($first, $second);
                break;
              case "array":
                // TODO implement comparison of array
              case "object":
                // TODO implement comparison of object
              default:
                throw new PolyglotException("Comparison of unknown types is impossible: $type");
            }
          }
          
          if ($compareResult!=0) {
            if (!$asc) $compareResult*=-1;
            break;
          }
        }
        return $compareResult;
      });
    }
    
    return $result;
  }
  
  public function countByTemplate(Entity $t): int {
    return count($this->getByTemplate($t));
  }
  
  public function checkExistenceByTemplate(Entity $t): bool {
    return $this->countByTemplate($t) > 0;
  }
  
  public function insert(Entity $e): PolyglotEntity {
    $this->checkPolyglotReferences($e);
    
    $e->amendMissingPersistents();
    
    $connectingValues=null;
    foreach ($e->getPersistentEntities() as $persistent) {
      if (!is_null($connectingValues)) {
        foreach ($connectingValues as $connectingAttribute => $connectingValue) {
          $mappedConnectingAttribute=$e->getMappedField($connectingAttribute, get_class($persistent));
          $persistent->$mappedConnectingAttribute=$connectingValue;
        }
      }
      
      $accessor = $this->getAccessorByType($persistent, true);
      $result=$accessor->insert($persistent);
      $e->addPersistentEntity($result);
      
      if (is_null($connectingValues)) {
        $connectingValues=array();
        foreach ($e->getConnectingAttributes() as $connectingAttribute) {
          $mappedConnectingAttribute=$e->getMappedField($connectingAttribute, get_class($persistent));
          $connectingValues[$connectingAttribute]=$result->$mappedConnectingAttribute;
        }
      } 
    }
    
    $e->fillFromPersistentEntities();
    $this->commitInvolvedAccessors();
    
    return $e;
  }
  
  public function update(Entity $e): bool {
    if (!($e instanceof PolyglotEntity)) throw new PolyglotException("PolyglotEntity expected, received: ".get_class($e));
    
    if (count($e->getPersistentEntities()) == 0) throw new InvalidArgumentException("Given PolyglotEntity did not contain any persistents!");
    $updated=0;
    $e->updatePersistentEntities();
    foreach ($e->getPersistentEntities() as $persistent) {
      $updated+=$this->getAccessorByType($persistent, true)->update($persistent);
    }
    $this->commitInvolvedAccessors();
    return $updated>0;
  }
  
  public function delete(Entity $e): bool {
    if (!($e instanceof PolyglotEntity)) throw new PolyglotException("PolyglotEntity expected, received: ".get_class($e));
    
    // check the key fields of the persistents! Only delete if all necessary keys are present.
    $keyFields = $e->getKeyFields();
    $keyValues = $e->getKeyValues();
    if (count($keyFields) != count($keyValues)) throw new PolyglotException("Not all key fields have been set to delete.");
    
    $deletedAll=true;
    $e->updatePersistentEntities();
    foreach ($e->getPersistentEntities() as $persistent) {
      $deletedAll&=$this->getAccessorByType($persistent, true)->delete($persistent);
    }
    $this->commitInvolvedAccessors();
    return $deletedAll;
  }
  
  public function deleteByTemplate(Entity $t): bool {
    if (!($t instanceof PolyglotEntity)) throw new PolyglotException("PolyglotEntity expected, received: ".get_class($t));
    
    $deletedAll=true;
    // must use getByTemplate. Using deleteByTemplate of every single accessor would lead to unpredictable results!
    foreach ($this->getByTemplate($t) as $i) {
      $deletedAll&=$this->delete($i);
    }
    return $deletedAll;
  }
  
  protected function checkPolyglotReferences(PolyglotEntity $e): void {
    $mod = $e->getModifications();
    
    $refCheck = array();
    foreach($e->getPolyglotReferences() as $ref) {
      if (count(array_intersect($ref[PolyglotEntity::POS_REF_FIELDS], array_keys($mod))) > 0 ) $refCheck[] = $ref;
    }
    
    foreach($refCheck as $ref) {
      /* @var $refEntity Entity */
      $refEntity = new $ref[PolyglotEntity::POS_REF_ENTITY]();
      $keyFields = $refEntity->getKeyFields();
      
      if (count($keyFields) != count($ref[PolyglotEntity::POS_REF_FIELDS])) throw new PolyglotException("Number of key fields and ref fields do not match!");
        
      for ($i=0; i < count($keyFields); $i++) {
        $refEntity->$keyFields[i] = $e->$ref[PolyglotEntity::POS_REF_FIELDS][i];
      }
      
      if (!$this->getAccessorByType($refEntity)->checkExistenceByTemplate($refEntity)) {
        throw new PolyglotException("Values do not reference an existing entry.");
      }
    }
  }
  
  protected function getAccessorByType(Entity $e, bool $forUpdate=false): GenericDataIF {
    return $this->getAccessorByClassName(get_class($e), $forUpdate);
  }
  
  protected function getAccessorByClassName(string $className, bool $forUpdate=false): GenericDataIF {
    $accessor = $this->accessorMap->getAccessorByClassName($className);
    
    if ($forUpdate) {
      $this->openAccessors[] = $accessor;
      $accessor->beginTransaction();
    }
    
    return $accessor;
  }
  
  protected function commitInvolvedAccessors() {
    foreach ($this->openAccessors as $accessor) $accessor->commit();
    $this->openAccessors = array();
  }
  
  protected function rollbackInvolvedAccessors() {
    foreach ($this->openAccessors as $accessor) $accessor->rollback();
    $this->openAccessors = array();
  }
  
}
