<?php 
namespace GDA\sql;

use Exception;
use GDA\GenericDataIF;
use GDA\Entity;

/**
 * General implementation of the GenericDataIF.
 * This class can be extended for a particular application to make use of the generic methods.
 * Hence, it needs only to implement the additional application specific methods.
 *
 * Needs an implementation of SqlAccessIf.
 *
 * @author Matthias Kolonko
 *
 */
abstract class GenericSqlDataAccess implements GenericDataIF {
    
    /**
     * Holds the SqlAccessIf implementation.
     *
     * @var SqlAccessIf
     */
    protected SqlAccessIf $sqlAccess;
    
    /**
     * Create the general SQL access instance
     *
     * @param SqlAccessIf $sqlAccess  an instance of the SqlAccessIf interface to work on the DBMS
     */
    public function __construct(SqlAccessIf $sqlAccess){
        $this->sqlAccess=$sqlAccess;
    }
    
    public function getHandlingUnit(): string {
        return SqlEntity::class;
    }
    
    public function getSqlAccess(): SqlAccessIf {
        return $this->sqlAccess;
    }
    
    /* Starting with functions inherited from Interface */
    /** @see GenericDataIF::beginTransaction() */
    public function beginTransaction(): void {
        $this->sqlAccess->beginTransaction();
    }
    
    /** @see GenericDataIF::commit() */
    public function commit(): void {
        $this->sqlAccess->commit();
    }
    
    /** @see GenericDataIF::rollback() */
    public function rollback(): void {
        $this->sqlAccess->rollback();
    }
    
    protected function createParams(SqlEntity $e, array $values, string $prefix=null): array {
        if (is_null($prefix)) $prefix = "";
        $result = array();
        foreach ($values as $field=>$value) {
            $fd = $e->getFieldDefinition($field);
            $value = $this->sqlAccess->adjustValueForDb($fd, $value);
            if (!($fd[SqlEntity::FIELD_DEF_POS_TYPE] == SqlEntity::TYPE_TIMESTAMP)) $result[":".$prefix.$field] = $value;
        }
        return $result;
    }
    
    protected function amendColumnsByType(SqlEntity $e, array &$columns, string $prefix=""): array {
        $paramPrefix = ":".$prefix;
        array_walk($columns, function(&$item, $key) use (&$e, &$paramPrefix) {
            $fieldDef = $e->getFieldDefinition($key);
            switch ($fieldDef[SqlEntity::FIELD_DEF_POS_TYPE]) {
                case SqlEntity::TYPE_DATE:
                    $item = $this->sqlAccess->toDate($paramPrefix.$item);
                    break;
                case SqlEntity::TYPE_TIMESTAMP:
                case SqlEntity::TYPE_AUTO_TIMESTAMP:
                case SqlEntity::TYPE_DEFAULT_TIMESTAMP:
                    $item = $this->sqlAccess->timestamp(true);
                    break;
                default:
                    $item = $paramPrefix.$item;
            }
        });
            return $columns;
    }
    
    protected function getAutomaticFields(SqlEntity $e, bool $forUpdate=false): array {
        $result = array();
        $fieldDefinitions = array_filter($e->getEntityDefinition(), function($k){return substr($k, 0, strlen(SqlEntity::PREFIX_FIELD_DEF))===SqlEntity::PREFIX_FIELD_DEF;}, ARRAY_FILTER_USE_KEY);
        foreach ($fieldDefinitions as $fieldDef) {
            switch ($fieldDef[SqlEntity::FIELD_DEF_POS_TYPE]) {
                case SqlEntity::TYPE_DEFAULT_TIMESTAMP:
                    if ($forUpdate) break;
                case SqlEntity::TYPE_AUTO_TIMESTAMP:
                    $result[$fieldDef[SqlEntity::FIELD_DEF_POS_NAME]] = $fieldDef[SqlEntity::FIELD_DEF_POS_NAME];
            }
        }
        return $result;
    }
    
    protected function formatColumnsForSelect(array $fieldDefinitions, string $prefix=null): array {
        if (is_null($prefix)) $prefix="";
        if ($prefix != "") $prefix.=".";
        
        $columns=array();
        foreach($fieldDefinitions as $fieldDef) {
            $fieldName = $fieldDef[SqlEntity::FIELD_DEF_POS_NAME];
            switch($fieldDef[SqlEntity::FIELD_DEF_POS_TYPE]) {
                case SqlEntity::TYPE_DATE:
                    $columns[]=$this->sqlAccess->toChar($prefix.$fieldName)." AS ".$fieldName;
                    break;
                default:
                    $columns[]=$prefix.$fieldName;
            }
        }
        return $columns;
    }
    
    protected abstract function checkEntityPermission(SqlEntity $e): void ;
    
    protected function extractIdTemplate(SqlEntity $e): SqlEntity {
        $keyFields = $e->getKeyFields();
        $keyFields = $this->amendColumnsByType($e, $keyFields);
        $keyValues = $e->getKeyValues();
        if (count(array_diff(array_keys($keyFields), array_keys($keyValues)))>0) throw new Exception("Key values don't match expected key fields!");
        
        $entityClass = get_class($e);
        $template=new $entityClass();
        
        foreach ($keyValues as $key=>$value) {
            $template->{$key}=$value;
        }
        
        return $template;
    }
    
    public function getById(Entity $e): ?Entity {
        return $this->getSqlById($e);
    }
    
    public function getSqlById(SqlEntity $e): ?SqlEntity {
        $template=$this->extractIdTemplate($e);
        $result = $this->getByTemplate($template);
        
        switch (count($result)) {
            case 0: return null;
            case 1: return $result[0];
            default: throw new Exception("Database corrupted - more than one entry with the same ID found of type: ".get_class($e));
        }
    }
    
    protected function selectByTemplate(SqlEntity $t, bool $count=false, array $order=null) {
        $searchValues = $t->getModifications();
        $columns = array_combine(array_keys($searchValues), array_keys($searchValues));
        $columns = $this->amendColumnsByType($t, $columns);
        
        $stmt  = array();
        $stmt[]="SELECT ".($count ? "count(*)" : implode("\n     , ", $this->formatColumnsForSelect($t->getFieldDefinitions())));
        $stmt[]="FROM ".$this->sqlAccess->getPrefix().$t->getTableName();
        if(count($searchValues) > 0) $stmt[]="WHERE ".implode("\n  AND " , array_map(function($c, $v){return $c."=".$v;}, array_keys($columns), $columns));
        
        if (is_null($order)){
            $order = $t->getDefaultOrdering();
        }
        
        if (!$count && !is_null($order) && count($order)>0) {
            $stmt[]="ORDER BY ".implode(",", array_map(function($k, $v){return $k.($v?" ASC":" DESC");}, array_keys($order), $order));
        }
        
        $params = $this->createParams($t, $searchValues);
        $result = $this->sqlAccess->query_data($stmt, $params, ($count ? null : get_class($t)) );
        
        if ($count) return $result[0][0]; // count function must return one row with one column!
        return $result;
    }
    
    public function getByTemplate(Entity $t, array $order=null): array {
        return $this->selectByTemplate($t, false, $order);
    }
    
    public function countByTemplate(Entity $t): int {
        return $this->selectByTemplate($t, true);
    }
    
    public function checkExistenceByTemplate(Entity $t): bool {
        return $this->countByTemplate($t) > 0;
    }
    
    public function insert(Entity $e): Entity {
        return $this->insertEntity($e);
    }
    
    protected function insertEntity(SqlEntity $e): SqlEntity {
        $this->checkEntityPermission($e);
        
        $tableName = $this->sqlAccess->getPrefix().$e->getTableName();
        $modifications = $e->getModifications();
        $columns = array_combine(array_keys($modifications), array_keys($modifications));
        $columns = array_merge($columns, $this->getAutomaticFields($e));
        $columns = $this->amendColumnsByType($e, $columns);
        
        $this->beginTransaction();
        $stmt = "INSERT INTO ".$tableName."(".implode(",", array_keys($columns)).")\nVALUES (".implode(",", $columns).")";
        $this->sqlAccess->manipulate_data($stmt, $this->createParams($e, $modifications));
        
        $keyFields = $e->getKeyFields();
        if (count($keyFields)==1) {
            $keyField = current($keyFields);
            if (is_null($e->$keyField)) {
                $newID = $this->sqlAccess->get_last_id($tableName);
                $e->$keyField=$newID;
            }
        }
        
        $this->commit();
        
        // reload the whole entity
        $e = $this->getById($e);
        
        return $e;
    }
    
    public function update(Entity $e): bool {
        return $this->updateEntity($e);
    }
    
    protected function updateEntity(SqlEntity $e): bool {
        $this->checkEntityPermission($e);
        
        $modifications = $e->getModifications();
        if (count($modifications) == 0) return false;
        
        $keyParamPrefix = "old_";
        $keyFields = $e->getKeyFields();
        $keyFields = $this->amendColumnsByType($e, $keyFields, $keyParamPrefix);
        $keyValues = $e->getOriginalKeyValues();
        if (count(array_diff(array_keys($keyFields), array_keys($keyValues)))>0) throw new Exception("Key values don't match expected key fields!");
        if (in_array(null, $keyValues)) throw new Exception("Null as key value provided! Was this really a loaded Entity from the database?");
        
        $columns = array_combine(array_keys($modifications), array_keys($modifications));
        $columns = array_merge($columns, $this->getAutomaticFields($e, true));
        $columns = $this->amendColumnsByType($e, $columns);
        
        $this->beginTransaction();
        $stmt  =array();
        $stmt[]="UPDATE ".$this->sqlAccess->getPrefix().$e->getTableName();
        $stmt[]="SET ".implode("\n  ,", array_map(function($c, $v){return $c."=".$v;}, array_keys($columns), $columns));
        $stmt[]="WHERE ".implode("\n  AND " , array_map(function($c, $v){return $c."=".$v;}, array_keys($keyFields), $keyFields));
        
        $params = $this->createParams($e, $keyValues, $keyParamPrefix);
        $params = array_merge($params, $this->createParams($e, $modifications));
        
        $affected = $this->sqlAccess->manipulate_data($stmt, $params);
        $this->commit();
        
        return $affected!=0;
    }
    
    public function delete(Entity $e): bool {
        $template=$this->extractIdTemplate($e);
        return $this->deleteEntity($template);
    }
    
    public function deleteByTemplate(Entity $t): bool {
        return $this->deleteEntity($t);
    }
    
    protected function deleteEntity(SqlEntity $e): bool {
        $this->checkEntityPermission($e);
        
        $searchValues = $e->getModifications();
        $columns = array_combine(array_keys($searchValues), array_keys($searchValues));
        $columns = $this->amendColumnsByType($e, $columns);
        
        $this->beginTransaction();
        $stmt="DELETE FROM ".$this->sqlAccess->getPrefix().$e->getTableName();
        if(count($searchValues) > 0) $stmt.="\nWHERE ".implode("\n  AND " , array_map(function($c, $v){return $c."=".$v;}, array_keys($columns), $columns));
        $result = $this->sqlAccess->manipulate_data($stmt, $this->createParams($e, $searchValues));
        $this->commit();
        
        if($result>0) return true;
        return false;
    }
    
}
