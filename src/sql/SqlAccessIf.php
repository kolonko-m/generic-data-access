<?php
namespace GDA\SQL;

use Exception;

/**
 * This interface must be implemented by all database implementations.
 * It abstracts the non-SQL-standard functions (e.g. "NVL" in Oracle is "IFNULL" in MySQL)
 *
 * @author Matthias Kolonko
 *
 */
interface SqlAccessIf {
  
  /**
   * Returns the table prefix for the held database connection
   *
   * @return String the table prefix
   */
  function getPrefix(): string;
  
  /**
   * Query data with a SELECT-statement.
   * The SQL statement must be in prepared SQL syntax
   *
   * @param mixed $stmt
   *          SQL statement with prepare syntax. Regularly, a string. But can also be an array containing strings as lines of the query.
   * @param array $params
   *          contains the parameters for the prepared statement with parameter names as key or 1-based index when using "?"
   * @param string $resultClass
   *          class name of the class that shall be used to fill the result data in. If null is provided, the result lines will be returned as regular arrays. Default: NULL
   * @return array with an entry for every line of the result. every line is an array with the fieldnames as key.
   * @throws Exception containing the error message from the SQL server
   */
  function query_data($stmt, array $params = array (), string $resultClass = null): array;
  
  /**
   * Manipulate data and database with any SQL statement.
   * The SQL statement must be in prepared SQL syntax.
   *
   * @param mixed $stmt
   *          SQL statement with prepare syntax. Regularly, a string. But can also be an array containing strings as lines of the query.
   * @param array $params
   *          contains the parameters for the prepared statement with parameter names as key or 1-based index when using "?"
   * @return integer the number of affected rows
   * @throws Exception containing the error message from the SQL server
   */
  function manipulate_data($stmt, array $params = array ()): int;
  
  /**
   * Returns a string with the appropriate SQL-Function to change NULL values to sth.
   * else
   *
   * @param mixed $value
   *          the value to be checked for being NULL
   * @param mixed $insteadOf
   *          the value that should be used instead of NULL
   * @return string containing the correct SQL-Function call
   */
  function nvl($value, $insteadOf): string;
  
  /**
   * Makes strings given as references in an array safe for being used as SQL-Statement.
   * Alternatively the array can be given as a reference.
   *
   * This function does not have any returns. It just works on the given array -
   * therefore the references are necessary!
   *
   * @param array $values
   *          an array of the strings to be escaped. IMPORTANT: The Strings have to be in the array as reference!!!
   */
  function escape_strings(array &$values);
  
  /**
   * Returns the appropriate function call for concatenating strings
   *
   * @param
   *          $values
   * @return string
   */
  function concat(array $values): string;
  
  /**
   * Returns the last ID that was set by a sequence or auto increment field.
   *
   * @param string $tableName
   *          the name of the table where the insert occurred
   * @return integer the ID provided during the last insert
   */
  function get_last_id(string $tableName): string;
  
  /**
   * Returns a String with the appropriate encrypting function call for the current DBMS
   *
   * @param string $input
   * @return string
   * @deprecated
   */
  function encrypt(string $input): string;
  
  /**
   * Creates a hash value of the given input
   *
   * @param string $input
   *          the input to hash
   * @return string the hashed input
   */
  function hash(string $input): string;
  
  /**
   * Checks if the given input matches the given hash
   *
   * @param string $input
   * @param string $hash
   * @return boolean
   */
  function hashCheck(string $input, string $hash): bool;
  
  /**
   * Returns a String with the appropriate timestamp function call for the current DBMS
   *
   * @param boolean $unix
   *          if true, a Unix timestamp will be used. Default: false
   * @return string
   */
  function timestamp(bool $unix = false);
  
  /**
   * Returns a String with the appropriate function call for adding days to a date
   *
   * @param string $date
   * @param integer $days
   * @return string
   */
  function date_add(string $date, $days): string;
  
  /**
   * Returns a String with the appropraite function call for subtracting two dates returning the number of days.
   * The function subtracts $start from $end
   *
   * @param string $end
   * @param string $start
   * @return string
   */
  function date_diff(string $end, string $start): string;
  
  /**
   * Returns the appropriate function call for the DBMS to work with a date
   * that comes in ISO-8601 format.
   *
   * @param string $input
   *          the date that should be converted into the database representation
   * @return string the appropriate function call
   */
  function toDate(string $input): string;
  
  /**
   * Returns the appropriate function call that formats a given date into
   * ISO-8601.
   *
   * @param string $input
   *          the date field that should be formatted
   * @return string the appropriate function call
   */
  function toChar(string $input): string;
  
  /**
   * Returns a string with the appropriate function call that turns a Unix timestamp into its year
   *
   * @param string $unixtime
   *          the Unix timestamp
   * @return string the appropriate function call
   */
  function unixtimeYear(string $unixtime): string;
  
  /**
   * Returns a string with the appropriate function call for making a substring of the given one
   *
   * @param string $string
   *          the string to create the substring of
   * @param integer $pos
   *          the position from where to start from (natural count) - if null is given, starting from first character
   * @param integer $length
   *          the length of the substring - if null is given the whole rest after pos is used
   * @return string
   */
  function substring(string $string, int $pos, int $length): string;
  
  /**
   * Returns the appropriate function call for a bitwise or over a grouped field
   * Please keep in mind that the field has to be a numeric type!!!
   *
   * @param string $input
   *          the database field on which the function shall be used
   */
  function bitOr_agg(string $input): string;
  
  /**
   * Starts a transaction in the DBS if necessary
   */
  function beginTransaction();
  
  /**
   * Makes a commit in the DBS for a started transaction.
   */
  function commit();
  
  /**
   * Makes a rollback in the DBS for a started transaction.
   */
  function rollback();
  
  /**
   * Adapts the given value coming for the business logic 
   * according to the field definition for use in the database.
   *
   * @param array $fieldDef
   *          SqlEntity field definition array
   * @param mixed $value
   *          the value to be adjusted
   * @param bool $forStatement
   *          if set to true, the function will return in a way that the returned value 
   *          can be immediately used for building a SQL statement. Default: false
   * @return mixed
   */
  function adjustValueForDb(array $fieldDef, $value=null, bool $forStatement = false);
  
  /**
   * Adapts the given value coming from the database 
   * according to the field definition for use in the business logic.
   * 
   * @param array $fieldDef
   * @param mixed $value
   */
  function adjustValueForBLogic(array $fieldDef, $value);
}
