<?php
namespace GDA\SQL;

use GDA\Entity;
use GDA\GenericDataIF;
use JKingWeb\DrUUID\UUID;
use Pentagonal\PhPass\PasswordHash;
use Exception;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Abstract implementation of the SqlAccessIf.
 * This class implements some of the necessary functions that
 * all implementations have in common
 * 
 * @author Matthias Kolonko
 *
 */
abstract class GenericSqlAccess implements SqlAccessIf {
  
  /**
   * The table prefix of the current connection
   *
   * @var string
   */
  private $prefix;
  
  /**
   * Holds the PDO object repsonsible for the database connection
   *
   * @var PDO
   */
  private $conn;
  
  /**
   * contains the already used SQL statements.
   * Using the string SQL statement as key and the prepared PDOStatement as value.
   *
   * @var array
   */
  private $stmtCache;
  
  /**
   * Holds the transaction status of the current session.
   * 0=no transaction running
   *
   * With every beginTransaction() this variable will increment.
   * With every commit() this variable will decrement
   * - if it decrements to 0 a commit will be performed in the DBS
   *
   * if the status is set to GeneralSqlAccess::noTransactions, the transaction handling ist disabled because the driver lacks functionality
   *
   * @var integer
   */
  private $taStatus;
  
  /**
   * The PasswordHash instance for hashing and checking passwords
   * @var PasswordHash
   */
  private $hash;
  
  /**
   * Stands in the transaction status variable for transactions being disabled.
   * @var integer
   * FIXME implement an alternative local transaction handling if not provided by DBMS
   */
  const noTransactions = -9;
  
  /**
   * Instantiating only via subclasses as this one is abstract.
   * Establishes a database connection via PDO
   *
   * @param string  $user         The username of the database user
   * @param string  $pwd          The password of the database user
   * @param string  $dsn          The PDO DSN to connect to
   * @param string  $prefix       The database prefix for the tables
   * @param integer $encStrength  The strength of the encryption for new passwords (between 4 and 31). Default: 8
   */
  public function __construct(string $user, string $pwd, string $dsn, string $prefix, int $encStrength=8){
    if($prefix == null || $prefix == '') throw new Exception('No table prefix provided!');
    if(!mb_ereg_match('[A-Za-z0-9_]*', $prefix)) throw new Exception('The table prefix contains invalid characters!');
    $this->prefix=$prefix;
    
    $this->conn = new PDO($dsn, $user, $pwd);
    $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $this->stmtCache = array();
    
    // testing transaction capabilities
    try{
      if(!$this->conn->beginTransaction()){
        $this->taStatus = self::noTransactions;
      } else {
        $this->conn->rollBack();
        $this->taStatus = 0;
      }
    }catch (PDOException $e){
      $this->taStatus = self::noTransactions;
    }
    
    $this->hash = new PasswordHash($encStrength, false);
  }
  
  /**
   * Closes down the PDO connection and check the transaction status before.
   * If there is a transaction still running, it will be rolled back and an exception will be thrown
   */
  public function __destruct(){
    $taStatus = $this->taStatus;
    while ($this->taStatus > 0) {
      try{
        $this->rollback();
      } catch (Exception $e){
        echo 'ERROR: While rolling back: '.$e->getMessage();
      }
    }
    $this->conn = null;
    
    if($taStatus > 0){
      echo 'ERROR: There was still a transaction running while disconnecting - rolling back: '.$taStatus;
    }
  }
  
  /** @see SqlAccessIf::getPrefix() */
  public function getPrefix():string{
    return $this->prefix;
  }
  
  /** @see SqlAccessIf::query_data() */
  public function query_data($stmt, array $params=array(), string $resultClass=null):array{
    if (is_array($stmt)) $stmt=implode("\n", $stmt);
    if (!is_string($stmt)) throw new Exception("Statement is neither array nor string!");
    
    $this->escape_strings($params);
    
    $pdoStmt = $this->getPdoStatement($stmt);
    if(!is_null($resultClass)) {
      if (!(is_a($resultClass, SqlEntity::class, true) || is_subclass_of($resultClass, SqlEntity::class, true))) {
        throw new Exception("ResultClass $resultClass is not an Entity!");
      }
      $pdoStmt->setFetchMode(PDO::FETCH_CLASS, $resultClass);
    }
    else $pdoStmt->setFetchMode(PDO::FETCH_BOTH);
    
    $this->bindParams($pdoStmt, $params);
    $pdoStmt->execute();
    return $pdoStmt->fetchAll();
  }
  
  /** @see SqlAccessIf::manipulate_data() */
  public function manipulate_data($stmt, array $params=array()):int{
    if (is_array($stmt)) $stmt=implode("\n", $stmt);
    if (!is_string($stmt)) throw new Exception("Statement is neither array nor string!");
    
    $this->escape_strings($params);
    
    $pdoStmt = $this->getPdoStatement($stmt);
    $this->bindParams($pdoStmt, $params);
    $pdoStmt->execute();
    
    return $pdoStmt->rowCount();
  }
  
  private function bindParams(PDOStatement $pdoStmt, array $params) {
    foreach(array_keys($params) as $i) {
      if (is_bool($params[$i])) $pdoStmt->bindParam($i, $params[$i], PDO::PARAM_BOOL);
      else $pdoStmt->bindParam($i, $params[$i]);
    }
  }
  
  /** @see SqlAccessIf::beginTransaction() */
  public function beginTransaction(){
    if($this->taStatus != self::noTransactions){
      if($this->taStatus == 0) $this->conn->beginTransaction();
      $this->taStatus++;
    }
  }
  
  /** @see SqlAccessIf::commit() */
  public function commit(){
    if($this->taStatus != self::noTransactions){
      if($this->taStatus > 0) $this->taStatus--;
      if($this->taStatus == 0) $this->conn->commit();
      if($this->taStatus < 0) {
        $this->rollback();
        throw new Exception('Transaction status is in an undefined condition: '.$this->taStatus);
      }
    }
  }
  
  /** @see SqlAccessIf::rollback() */
  public function rollback(){
    if($this->taStatus != self::noTransactions && $this->taStatus != 0){
      //in this case we call the function via the connection directly otherwise it could result in an endless loop!!!
      $this->conn->rollBack();
      $this->taStatus=0;
    }
  }
  
  /** @see SqlAccessIf::escape_strings() */
  public function escape_strings(array &$values){
    foreach(array_keys($values) as $i){
      if($values[$i]!==null){
        if(is_string($values[$i])){
          $values[$i] = trim($values[$i]);
          $values[$i] = htmlspecialchars($values[$i], ENT_QUOTES | ENT_XHTML, mb_internal_encoding(), false); // TODO move that to the view layer!
          if($values[$i] == "''" || $values[$i] == '' || mb_strtoupper($values[$i]) == "'NULL'") $values[$i]=null;
        }
      }
    }
  }
  
  /** @see SqlAccessIf::get_last_id() */
  public function get_last_id(string $tableName):string {
    return $this->conn->lastInsertId($tableName);
  }
  
  /** @see SqlAccessIf::hash() */
  public function hash(string $input):string {
    return $this->hash->HashPassword($input);
  }
  
  /** @see SqlAccessIf::hashCheck() */
  public function hashCheck(string $input, string $hash):bool{
    return $this->hash->CheckPassword($input, $hash);
  }
  
  public function adjustValueForDb(array $fieldDef, $value=null, bool $forStatement=false) {
    switch($fieldDef[SqlEntity::FIELD_DEF_POS_TYPE]) {
      case SqlEntity::TYPE_UUID:
        if ($value instanceof UUID) $value = $value->__toString();
        break;
    }
    if ($forStatement) {
      if (is_null($value)) return "NULL";
      if (is_string($value)) return "'".$value."'";
      if (is_bool($value)) {
        if ($value) return "true";
        return "false";
      }
    }
    return $value;
  }
  
  public function adjustValueForBLogic(array $fieldDef, $value) {
    return $value;
  }
  
  /**
   * Takes a PDOStatement object from the cache or creates a new one
   *
   * @param string $statement
   * @return PDOStatement the appropriate PDOStatement object to the given statement
   */
  private function getPdoStatement(string $statement):PDOStatement{
    $statement = trim($statement);
    
    if(array_key_exists($statement, $this->stmtCache)){
      return $this->stmtCache[$statement];
    }
    
    $pdoStmt = $this->conn->prepare($statement);
    $this->stmtCache[$statement] = $pdoStmt;
    return $pdoStmt;
  }
  
}

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
