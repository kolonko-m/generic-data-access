<?php
namespace GDA\sql;

/** This class implements the functions necessary for the MySQL access */
class MySqlAccess extends GenericSqlAccess implements SqlAccessIf {
  
  /**
   * Establishes a connection to a MySQL-Server with the given data.
   *
   * @param string $user      username for the server
   * @param string $pwd       password
   * @param string $database  the database the instance shall connect to
   * @param string $prefix    the prefix that each database table has
   * @param string $host      network address where the MySQL server resides. Default: localhost
   * @param string $encoding  the encoding of the DB connection. Default: ISO-8859-1
   * @param integer $port     port where the DB-Server listens. Default: 3306
   * @param integer $encStrength  The strength of the encryption for new passwords (between 4 and 31). Default: 8
   * @throws \Exception  if the connection can't be established
   */
  public function __construct(string $user, string $pwd, string $database, string $prefix, string $encoding, int $encStrength, string $host="localhost", int $port=3306){
    $dsn = "mysql:host=$host;port=$port;dbname=$database";
    parent::__construct($user, $pwd, $dsn, $prefix, $encStrength);
    
    /*
     * setting encoding.
     * As MySQL does not conform to the standard encoding names, only UTF-8 and ISO-8859-1 are supported!
     */
    $mysql_encoding="utf8";
    if($encoding == "ISO-8859-1") $mysql_encoding="latin1";
    $this->manipulate_data("SET NAMES ?", array(1 => $mysql_encoding));
  }
  
  /** @see SqlAccessIf::nvl() */
  public function nvl($value, $insteadOf):string {
    return "IFNULL($value, $insteadOf)";
  }
  
  /** @see SqlAccessIf::concat() */
  public function concat(array $values):string {
    return " CONCAT(".implode(", ", $values).") ";
  }
  
  /** @see SqlAccessIf::encrypt() */
  public function encrypt(string $input):string {
    return "sha1($input)";
  }
  
  /** @see SqlAccessIf::timestamp() */
  public function timestamp(bool $unix=false){
    if($unix) return " unix_timestamp() ";
    return " CURDATE() ";
  }
  
  /** @see SqlAccessIf::date_add() */
  public function date_add(string $date, $days):string {
    return " ADDDATE($date, $days) ";
  }
  
  /** @see SqlAccessIf::date_diff() */
  public function date_diff(string $end, string $start):string {
    return " DATEDIFF($end, $start) ";
  }
  
  /** @see SqlAccessIf::toDate() */
  public function toDate(string $input):string {
    return "STR_TO_DATE(".$input.", '%Y-%m-%d')";
  }
  
  /** @see SqlAccessIf::toChar() */
  public function toChar(string $input):string {
    return " DATE_FORMAT(".$input.", '%Y-%m-%d') ";
  }
  
  /** @see SqlAccessIf::unixtimeYear() */
  public function unixtimeYear(string $unixtime):string {
    return " FROM_UNIXTIME($unixtime, '%Y') ";
  }
  
  /** @see SqlAccessIf::substring() */
  public function substring(string $string, int $pos, int $length):string {
    if(is_null($pos)) $pos = 1;
    
    $result = " SUBSTRING($string FROM $pos";
    if(!is_null($length)) $result .= " FOR $length";
    $result.= ") ";
    return $result;
  }
  
  /** @see SqlAccessIf::bitOr_agg() */
  public function bitOr_agg(string $input):string {
    return " BIT_OR(".$input.") ";
  }
  
}
