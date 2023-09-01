<?php
namespace GDA\sql;

/** This class implements the functions necessary for the PostgreSQL access */
class PgSqlAccess extends GenericSqlAccess implements SqlAccessIf {
  
  const DEFAULT_PORT = 5432;
  
  /**
   * Establishes a connection to a PostgreSQL-Server with the given data.
   *
   * @param string  $user      username for the server
   * @param string  $pwd       password
   * @param string  $database  the database the instance shall connect to
   * @param string  $prefix    the prefix that each database table has
   * @param string  $host      network address where the MySQL server resides. Default: localhost
   * @param string  $encoding  the encoding of the DB connection. Default: ISO-8859-1
   * @param integer $port      port where the DB-Server listens. Default: 5432
   * @param integer $encStrength  The strength of the encryption for new passwords (between 4 and 31). Default: 8
   * @throws \Exception  if the connection can't be established
   */
  public function __construct(string $user, string $pwd, string $database, string $prefix, string $encoding, int $encStrength, string $host="localhost", ?int $port=self::DEFAULT_PORT) {
    if (is_null($port)) $port = self::DEFAULT_PORT;
    
    $dsn = "pgsql:host=$host;port=$port;dbname=$database;options='--client_encoding=$encoding'";
    parent::__construct($user, $pwd, $dsn, $prefix, $encStrength);
    //$this->manipulate_data("SET NAMES ?", array(1 => $encoding));
  }
  
  /** starting implementation of the inherited interface methods */
  
  /** @see SqlAccessIf::nvl() */
  public function nvl($value, $insteadOf):string {
    return "COALESCE($value, $insteadOf)";
  }
  
  /** @see GenericSqlAccess::get_last_id() */
  public function get_last_id(string $tableName):string {
    return parent::get_last_id($tableName."_seq");
  }
  
  /** @see SqlAccessIf::encrypt() */
  public function encrypt(string $input):string {
    return " encode(digest($input, 'sha1'),'hex') ";
  }
  
  /** @see SqlAccessIf::concat() */
  public function concat(array $values):string {
    return implode(" || ", $values);
  }
  
  /** @see SqlAccessIf::timestamp() */
  public function timestamp(bool $unix=false) {
    if($unix) return time();
    return " CURRENT_DATE ";
  }
  
  /** @see SqlAccessIf::date_add() */
  public function date_add(string $date, $days):string {
    return " $date + CAST($days AS INTEGER) ";
  }
  
  /** @see SqlAccessIf::date_diff() */
  public function date_diff(string $end, string $start):string {
    return " $end - $start ";
  }
  
  /** @see SqlAccessIf::toDate() */
  public function toDate(string $input):string {
    return " to_date($input, 'YYYY-MM-DD') ";
  }
  
  /** @see SqlAccessIf::toChar() */
  public function toChar(string $input):string {
    return " to_char(".$input.", 'YYYY-MM-DD') ";
  }
  
  /** @see SqlAccessIf::unixtimeYear() */
  public function unixtimeYear(string $unixtime):string {
    return " to_char(to_timestamp($unixtime), 'YYYY') ";
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
    return " BIT_OR(CAST(".$input." AS bigint)) ";
  }
  
}
