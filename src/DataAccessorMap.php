<?php 
namespace GDA;

use InvalidArgumentException;
use GDA\exceptions\{InvalidAccessorTypeException,AccessorNotFoundException};

class DataAccessorMap {
    
    public readonly string $ifClass;
    
    protected array $dataAccessors = array();
    
    public function __construct(string $ifClass) {
        if (!(is_a($ifClass, GenericDataIF::class, true) || is_subclass_of($ifClass, GenericDataIF::class, true))) throw new InvalidArgumentException("$ifClass is not a subtype of ".GenericDataIF::class);
        $this->ifClass = $ifClass;
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
