<?php
namespace GDA\sql;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use JKingWeb\DrUUID\UUID;
use Pentagonal\PhPass\PasswordHash;


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
