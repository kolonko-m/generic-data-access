<?php 
namespace GDA\ldap;

use Exception;
use InvalidArgumentException;
use IntlDateFormatter;
use IntlTimeZone;
use LDAP\Connection;
use Pentagonal\PhPass\PasswordHash;
use JKingWeb\DrUUID\UUID;
use GDA\{GenericDataIF,Entity};
use GDA\exceptions\LdapException;

/**
 * @author matthias
 *
 */
abstract class GenericLdapDataAccess implements GenericDataIF {
  
  const DN_ATTR             = 'dn';
  const OBJECT_CLASS_ATTR   = 'objectclass';
  const MEMBER_OF_ATTR      = 'memberof';
  const MEMBER_ATTR         = 'member';
  
  const LDAP_NOT_FOUND      = 32;
  
  private readonly Connection $ldapConn;
  
  protected readonly string $baseDN;
  
  protected readonly IntlDateFormatter $isoDateFormatter;
  
  protected readonly IntlDateFormatter $ldapDateFormatter;
  
  protected readonly IntlDateFormatter $isoTimestampFormatter;
  
  protected readonly IntlDateFormatter $ldapTimestampFormatter;
  
  private readonly PasswordHash $hash;
  
  private int $taStatus;
  
  private array $taActions = array();
  
  public function __construct(string $baseDN, string $bindDN, string $password, string $host, int $port, bool $secure=true, int $encStrength=8) {
    $this->baseDN = $baseDN;
    
    $this->ldapConn = ldap_connect(($secure?"ldaps://":"").$host, $port);
    ldap_set_option($this->ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
    
    if(!ldap_bind($this->ldapConn, $bindDN, $password)) {
      throw new LdapException("Bind failed: ".ldap_errno($this->ldapConn), ldap_errno($this->ldapConn));
    }
    
    $this->hash = new PasswordHash($encStrength, false);
    $this->taStatus = 0;
    
    $this->isoDateFormatter  = new IntlDateFormatter(null, IntlDateFormatter::FULL, IntlDateFormatter::FULL, IntlTimeZone::getGMT(), IntlDateFormatter::GREGORIAN, "yyyy-MM-dd");
    $this->ldapDateFormatter = new IntlDateFormatter(null, IntlDateFormatter::FULL, IntlDateFormatter::FULL, IntlTimeZone::getGMT(), IntlDateFormatter::GREGORIAN, "yyyyMMddHHX");
    $this->isoTimestampFormatter  = new IntlDateFormatter(null, IntlDateFormatter::FULL, IntlDateFormatter::FULL, IntlTimeZone::getGMT(), IntlDateFormatter::GREGORIAN, "yyyy-MM-dd'T'HH:mm:ssX");
    $this->ldapTimestampFormatter = new IntlDateFormatter(null, IntlDateFormatter::FULL, IntlDateFormatter::FULL, IntlTimeZone::getGMT(), IntlDateFormatter::GREGORIAN, "yyyyMMddHHmmssX");
  }
  
  public function getHandlingUnit(): string {
    return LdapEntity::class;
  }
  
  public function __destruct() {
    ldap_close($this->ldapConn);
  }
  
  public function getByDN(string $dn, string ...$attributes): ?array {
    $searchResult = ldap_search($this->ldapConn, $dn, "(objectClass=*)", $attributes);
    if (!$searchResult) {
      $errNo = ldap_errno($this->ldapConn);
      if ($errNo == self::LDAP_NOT_FOUND) return null;
      throw new LdapException("Error during search for DN $dn:".ldap_err2str($errNo), $errNo);
    }
    $searchEntries = ldap_get_entries($this->ldapConn, $searchResult);
    
    if($searchEntries["count"]==0) return null;
    else return $searchEntries[0];
  }
  
  public function search(string $baseDN, string $filter, bool $count, string ...$attributes) {
    $searchResult = ldap_search($this->ldapConn, $baseDN, '('.$filter.')', $attributes);
    
    if (!$searchResult) {
      $errNo = ldap_errno($this->ldapConn);
      if ($errNo == self::LDAP_NOT_FOUND) return ($count ? 0 : array());
      throw new LdapException("Error during search:".ldap_err2str($errNo), $errNo);
    }
    
    if ($count) return ldap_count_entries($this->ldapConn, $searchResult);
    $entries = ldap_get_entries($this->ldapConn, $searchResult);
    return $entries;
  }
  
  /**
   * Remove elements from an LDAP result that would cause problems when reusing it for manipulating the LDAP tree.
   * This affects esp. the "count" and the "dn" elements.
   * 
   * @param array $ldapResult the array created e.g. by a ldap_get_entries
   * @param bool $stripDn if true, the "dn" element is also removed from the result. Default: false
   * @return array
   */
  protected function stripLdapResult(array &$ldapResult, bool $stripDn = false): array {
    unset($ldapResult['count']);
    if ($stripDn) unset($ldapResult['dn']);
    
    foreach ($ldapResult as $i) {
      if (is_array($i)) $this->stripLdapResult($i, $stripDn);
    }
    
    return $ldapResult;
  }
  
  protected function getLastLdapError(): int {
    return ldap_errno($this->ldapConn);
  }
  
  protected function bind(string $dn, string $password): bool {
    return ldap_bind($this->ldapConn, $dn, $password);
  }
  
  protected function addEntry(string $dn, array $entry): bool {
    $result = ldap_add($this->ldapConn, $dn, $entry);
    if ($result) $this->taActions[] = new LdapAction(LdapAction::TYPE_INSERT, $dn);
    return $result;
  }
  
  protected function modifyEntry(string $dn, array $entry): bool {
    $oldEntry = $this->stripLdapResult($this->getByDN($dn, ...array_keys($entry)), true);
    $result = ldap_modify($this->ldapConn, $dn, $entry);
    if ($result) $this->taActions[] = new LdapAction(LdapAction::TYPE_UPDATE, $dn, $oldEntry);
    return $result;
  }
  
  protected function deleteEntry(string $dn): bool {
    $oldEntry = $this->stripLdapResult($this->getByDN($dn), true);
    $result = ldap_delete($this->ldapConn, $dn);
    if ($result) $this->taActions[]=new LdapAction(LdapAction::TYPE_DELETE, $dn, $oldEntry);
    return $result;
  }
  
  protected function modAddAttr(string $dn, array $entry): bool {
    $result = ldap_mod_add($this->ldapConn, $dn, $entry);
    if ($result) $this->taActions[]=new LdapAction(LdapAction::TYPE_ATTR_ADD, $dn, $entry);
    return $result;
  }
  
  protected function modDelAttr(string $dn, array $entry): bool {
    $oldEntry = array();
    foreach ($entry as $field => $value) {
      if (is_array($value) && count($value) == 0) {
        $oldValue = $this->getByDN($dn, $field);
        unset($oldValue[$field]['count']);
        $oldEntry[$field] = $oldValue[$field];
      } else $oldEntry[$field] = $value;
    }
    
    $result = ldap_mod_del($this->ldapConn, $dn, $entry);
    if ($result) $this->taActions[] = new LdapAction(LdapAction::TYPE_ATTR_DEL, $dn, $oldEntry);
    return $result;
  }
  
  protected function modReplaceAttr(string $dn, array $entry): bool {
    $oldEntry = $this->stripLdapResult($this->getByDN($dn, ...array_keys($entry)), true);
    $result = ldap_mod_replace($this->ldapConn, $dn, $entry);
    if ($result) $this->taActions[] = new LdapAction(LdapAction::TYPE_ATTR_REP, $dn, $oldEntry);
    return $result;
  }
  
  public function beginTransaction(): void {
    $this->taStatus++;
  }
  
  public function commit(): void {
    if ($this->taStatus <  0) $this->taStatus = 0;
    if ($this->taStatus == 0) return;
    if ($this->taStatus == 1) $this->taActions = array();
    $this->taStatus--;
  }
  
  public function rollback(): void {
    trigger_error("Rollback for LDAP initiated.", E_USER_WARNING);
    /* @var $action LdapAction */
    foreach (array_reverse($this->taActions) as $action) {
      try {
        $this->undo($action);
      } catch (Exception $e) {
        $message = $e->getMessage()."\n".$e->getTraceAsString();
        trigger_error($message, E_USER_ERROR);
      }
    }
    $this->taActions = array();
    $this->taStatus = 0;
  }
  
  protected function undo(LdapAction $action): void {
    $success = false;
    switch($action->type) {
      case LdapAction::TYPE_INSERT:   $success = ldap_delete     ($this->ldapConn, $action->dn);                 break;
      case LdapAction::TYPE_UPDATE:   $success = ldap_modify     ($this->ldapConn, $action->dn, $action->entry); break;
      case LdapAction::TYPE_DELETE:   $success = ldap_add        ($this->ldapConn, $action->dn, $action->entry); break;
      case LdapAction::TYPE_ATTR_DEL: $success = ldap_mod_add    ($this->ldapConn, $action->dn, $action->entry); break;
      case LdapAction::TYPE_ATTR_REP: $success = ldap_mod_replace($this->ldapConn, $action->dn, $action->entry); break;
      case LdapAction::TYPE_ATTR_ADD: $success = ldap_mod_del    ($this->ldapConn, $action->dn, $action->entry); break;
      default:
        throw new LdapException("Unknown action type in LdapAction instance found: $action");
    }
    if (!$success) {
      $errno = ldap_errno($this->ldapConn);
      $errMsg = ldap_err2str($errno);
      throw new LdapException("Undo failed due to LDAP error: $errMsg ($errno) for action: $action");
    }
  }
  
  /**
   * Create the DN for a new LdapEntity
   * @param LdapEntity $e
   */
  protected function buildDn(LdapEntity $e): string {
    $result = "";
    foreach ($e->getKeyValues() as $key => $value) {
      if (is_null($value) || $value == '') throw new InvalidArgumentException("Key field empty!");
      $attr=$e->getFieldDefinition($key)[LdapEntity::FIELD_DEF_POS_ATTR];
      $result = $attr."=".$value;
    }
    
    $rdn = $e->getBaseDN();
    if (!is_null($rdn) && $rdn != '') $result .= ','.$rdn;
    if (!is_null($this->baseDN) && $this->baseDN != '') $result .= ','.$this->baseDN;
    
    return $result;
  }
  
  protected function buildBaseDN(string $relBaseDN) {
    $baseDN = $relBaseDN;
    if (is_null($baseDN)) $baseDN = $this->baseDN;
    else $baseDN .= ",".$this->baseDN;
    return $baseDN;
  }
  
  public function getById(Entity $e): ?LdapEntity {
    if (!($e instanceof LdapEntity)) throw new LdapException("LdapEntity expected, received: ".get_class($e));
    //  use getByDN preferably if DN is set
    if (isset($e->dn)) {
      $result = $this->getByDN($e->dn, ...array_column($e->getFieldDefinitions(), LdapEntity::FIELD_DEF_POS_ATTR));
      if (is_null($result)) return null;
      return $this->fillEntity($e, $result);
    }
    
    $t = $this->extractIdTemplate($e);
    $result = $this->getByTemplate($t);
    
    switch (count($result)) {
      case 0: return null;
      case 1: return $result[0];
      default: throw new LdapException("Database corrupted - more than one entry with the same ID found of type: ".get_class($e));
    }
  }
  
  protected function findByTemplate(LdapEntity $t, bool $count = false, array $order = null) {
    $baseDN = $this->buildBaseDN($t->getBaseDN());
    
    $filter = $this->getEntityFilterElements($t);
    
    foreach ($t->getModifications() as $k => $v) {
      if ($k == self::DN_ATTR) $baseDN = $v; // DN can't be searched via filter!
      else $filter[] = $t->getFieldDefinition($k)[LdapEntity::FIELD_DEF_POS_ATTR].'='.$v;
    }
    
    $attributes = array_column($t->getFieldDefinitions(), LdapEntity::FIELD_DEF_POS_ATTR);
    
    $found = $this->search($baseDN, $this->buildFilter($filter, '&'), $count, ...$attributes);
    if ($count) return $found;
    
    $result = array();
    foreach ($found as $e) {
      // use only arrays - the entry "count" of the ldap result must be ignored. 
      if (is_array($e)) $result[]=$this->fillEntity($t, $e);
    }
    return $result;
  }
  
  public function getByTemplate(Entity $t, array $order=null): array {
    return $this->findByTemplate($t, false, $order);
  }
  
  public function countByTemplate(Entity $t): int {
    return $this->findByTemplate($t, true);
  }
  
  public function checkExistenceByTemplate(Entity $t): bool {
    return $this->countByTemplate($t) > 0;
  }
  
  public function insert(Entity $e): LdapEntity {
    if (!($e instanceof LdapEntity)) throw new LdapException("LdapEntity expected, received: ".get_class($e));
    
    if (!isset($e->dn)) $e->dn = $this->buildDn($e);
    
    $entry = $this->generateLdapEntry($e);
    
    $objectClasses = $e->getObjectClasses();
    if (count($objectClasses) > 0) $entry[self::OBJECT_CLASS_ATTR] = $objectClasses;
    
    
    if(!$this->addEntry($e->dn, $entry)) {
      throw new LdapException("Error while inserting LDAP entry: ".ldap_err2str($this->getLastLdapError())." (".$this->getLastLdapError().")");
    }
    
    // add memberships
    foreach($e->getMemberships() as $membership) {
      $memberEntry = $this->getByDN($membership, self::MEMBER_ATTR);
      if (is_null($memberEntry)) throw new LdapException("Given group not found: $membership");
      if(!$this->modAddAttr($membership, array(self::MEMBER_ATTR=>$e->dn))){
        $errNo = $this->getLastLdapError();
        throw new LdapException("Error trying to add $e->dn as member to $membership: ".ldap_err2str($errNo), $errNo);
      }
    }
    
    return $e;
  }
  
  public function update(Entity $e): bool {
    if (!($e instanceof LdapEntity)) throw new LdapException("LdapEntity expected, received: ".get_class($e));
    
    if (!isset($e->dn)) $e->dn = $this->buildDn($e);
    
    $mod = $e->getModifications();
    if (isset($mod[self::DN_ATTR])) throw new LdapException("DN must not be modified!");
    
    $entry = $this->generateLdapEntry($e);
    $result = true;
    if (count($entry) > 0) {
      $result = $this->modifyEntry($e->dn, $entry);
      if (!$result) {
        throw new LdapException("Error while updating LDAP entry: ".ldap_err2str($this->getLastLdapError())." (".$this->getLastLdapError().")");
      }
    }
    
    return $result;
  }
  
  public function delete(Entity $e): bool {
    if (!($e instanceof LdapEntity)) throw new LdapException("LdapEntity expected, received: ".get_class($e));
    
    if (!isset($e->dn)) $e->dn = $this->buildDn($e);
    $result = $this->deleteEntry($e->dn);
    if (!$result) throw new LdapException("Error while deleting LDAP entry: ".ldap_err2str($this->getLastLdapError())." (".$this->getLastLdapError().")");
    return $result;
  }
  
  public function deleteByTemplate(Entity $t): bool {
    foreach ($this->getByTemplate($t) as $e) {
      $this->delete($e);
    }
    return true;
  }
  
  /**
   * Create a new LdapEntity like $e and fill with the given LDAP data
   * 
   * @param LdapEntity $e
   * @param array $ldapResult
   * @return LdapEntity
   */
  protected function fillEntity(LdapEntity $e, array $ldapResult): LdapEntity {
    $class = get_class($e);
    $result = new $class();
    
    $result->dn = $ldapResult[self::DN_ATTR];
    foreach ($e->getFieldDefinitions() as $def) {
      if ($def[LdapEntity::FIELD_DEF_POS_ATTR] == self::DN_ATTR) continue; // DN is handled separately!
      
      $fieldName = $def[LdapEntity::FIELD_DEF_POS_NAME];
      $attrName = $def[LdapEntity::FIELD_DEF_POS_ATTR];
      
      // it may happen that an attribute is not provided due to missing access rights.
      if (!isset($ldapResult[$attrName])) continue;
      
      $result->$fieldName = $this->convertLdapAttribute($ldapResult[$attrName], $def);
    }
    $result->__construct();
    return $result;
  }
  
  /**
   * Creates an LDAP conform array for according to the given  
   * 
   * @param LdapEntity $e
   * @return array
   */
  protected function generateLdapEntry(LdapEntity $e): array {
    $entry = array();
    $mods = $e->getModifications();
    foreach ($mods as $fieldName => $value) {
      if ($fieldName == self::DN_ATTR) continue; // DN must not be part of entry array
      $def = $e->getFieldDefinition($fieldName);
      $entry[$def[LdapEntity::FIELD_DEF_POS_ATTR]] = $this->convertEntityAttribute($value, $def);
    }
    return $entry;
  }
  
  protected function convertLdapAttribute(array $ldapAttribute, array $def) {
    $value = null;
    if ($def[LdapEntity::FIELD_DEF_POS_LIST]) {
      $value = array();
      for ($i = 0; $i < $ldapAttribute['count']; $i++) {
        $value[] = $this->convertLdapValue($ldapAttribute[$i], $def);
      }
      if (!is_null($def[LdapEntity::FIELD_DEF_POS_LIST_DELIM])) $value = implode($def[LdapEntity::FIELD_DEF_POS_LIST_DELIM], $value);
    } else $value = $this->convertLdapValue($ldapAttribute[0], $def);
    return $value;
  }
  
  /**
   * Convert a value provided from LDAP to an Entity conform value.
   * 
   * @param mixed $value
   * @param array $def
   * @throws LdapException
   * @return mixed
   */
  protected function convertLdapValue($value, array $def) {
    if (is_null($value)) return null;
    switch ($def[LdapEntity::FIELD_DEF_POS_TYPE]) {
      case LdapEntity::TYPE_BOOLEAN:
        if ($value) return true;
        return false;
      case LdapEntity::TYPE_DATE:
        $parsed = $this->ldapDateFormatter->parse($value);
        return $this->isoDateFormatter->format($parsed);
      case LdapEntity::TYPE_TIMESTAMP:
        $parsed = $this->ldapTimestampFormatter->parse($value);
        return $this->isoTimestampFormatter->format($parsed);
      case LdapEntity::TYPE_UUID:
        return UUID::import($value);
      case LdapEntity::TYPE_REFERENCE_DN:
        $refClass = $def[LdapEntity::FIELD_DEF_POS_REF_ENTITY];
        $ref = new $refClass();
        $ref->dn = $value;
        $ref = $this->getById($ref);
        if (is_null($ref)) throw new LdapException("LDAP search failed for $refClass with ID $value!");
        $keyValues = $ref->getKeyValues();
        return array_pop($keyValues);
      case LdapEntity::TYPE_PASSWORD:
        // leave empty
        return null;
      case LdapEntity::TYPE_JPEG:
      case LdapEntity::TYPE_INTEGER:   // XXX check if nothing to do for Integer conversion
      case LdapEntity::TYPE_NUMERIC:   // XXX check if nothing to do for Numeric conversion
      case LdapEntity::TYPE_STRING:
      case LdapEntity::TYPE_URL:
      case LdapEntity::TYPE_TEL:
      case LdapEntity::TYPE_MAIL:
      case LdapEntity::TYPE_OCTET:
      default:
        return $value;
    }
  }
  
  protected function convertEntityAttribute($value, array $def) {
    if ($def[LdapEntity::FIELD_DEF_POS_LIST]) {
      if (!is_null($def[LdapEntity::FIELD_DEF_POS_LIST_DELIM]) && !is_array($value)) $value = explode($def[LdapEntity::FIELD_DEF_POS_LIST_DELIM], $value);
      if (!is_array($value)) throw new LdapException("Array expected for list element ".$def[Entity::FIELD_DEF_POS_NAME].", but got ".gettype($value));
      array_walk($value, function(&$item, $key) use ($def) {
        $item=$this->convertEntityValue($item, $def);
      });
    } else $value = $this->convertEntityValue($value, $def);
    return $value;
  }
  
  protected function convertEntityValue($value, array $def) {
    if (is_null($value)) return null;
    if (is_string($value)) $value = trim($value);
    switch ($def[LdapEntity::FIELD_DEF_POS_TYPE]) {
      case LdapEntity::TYPE_BOOLEAN:
        if ($value) return 'TRUE';
        return 'FALSE';
      case LdapEntity::TYPE_DATE:
        $parsed = $this->isoDateFormatter->parse($value);
        return $this->ldapDateFormatter->format($parsed);
      case LdapEntity::TYPE_TIMESTAMP:
        $parsed = $this->isoTimestampFormatter->parse($value);
        return $this->ldapTimestampFormatter->format($value);
      case LdapEntity::TYPE_UUID:
        if ($value instanceof UUID) return $value->__toString();
        return UUID::import($value)->__toString();
      case LdapEntity::TYPE_REFERENCE_DN:
        $refClass = $def[LdapEntity::FIELD_DEF_POS_REF_ENTITY];
        /* @var $ref LdapEntity */
        $ref = new $refClass();
        $keyFields = $ref->getKeyFields();
        $keyField = array_pop($keyFields);
        $ref->$keyField = $value;
        $ref = $this->getById($ref);
        if (is_null($ref)) throw new LdapException("LDAP search failed for $refClass with ID $value!");
        return $ref->dn;
      case LdapEntity::TYPE_PASSWORD:
        return "{crypt}".$this->hash->HashPassword($value);
      case LdapEntity::TYPE_JPEG:
      case LdapEntity::TYPE_INTEGER:   // XXX check if nothing to do for Integer conversion
      case LdapEntity::TYPE_NUMERIC:   // XXX check if nothing to do for Numeric conversion
      case LdapEntity::TYPE_STRING:
      case LdapEntity::TYPE_URL:
      case LdapEntity::TYPE_TEL:
      case LdapEntity::TYPE_MAIL:
      case LdapEntity::TYPE_OCTET:
      default:
        return $value;
    }
  }
  
  /**
   * Returns an array with filter elements according to the general Entitie's conditions.
   * I.e.: object classes and memeberships.
   * 
   * @param LdapEntity $e
   * @return array
   */
  protected static function getEntityFilterElements(LdapEntity $e): array {
    $filter = array();
    
    foreach ($e->getObjectClasses() as $i) {
      $filter[] = self::OBJECT_CLASS_ATTR.'='.$i;
    }
    
    foreach ($e->getMemberships() as $i) {
      $filter[] = self::MEMBER_OF_ATTR.'='.$i;
    }
    
    return $filter;
  }
  
  /**
   * Creates an associative array from a DN (also RDN)
   * 
   * @param string $dn
   * @return array
   */
  protected static function explodeDN(string $dn): array {
    $result=array();
    foreach (explode(',', $dn) as $value) {
      $parts = explode('=', $value);
      if (count($parts) != 2) throw new LdapException("DN \"$dn\" not conformant. Found not matching value: $value.");
      $result[trim($parts[0])] = trim($parts[1]);
    }
    return $result;
  }
  
  protected static function extractIdTemplate(LdapEntity $e): LdapEntity {
    $keyFields = $e->getKeyFields();
    $keyValues = $e->getKeyValues();
    if (count(array_diff($keyFields, array_keys($keyValues)))>0) throw new LdapException("Key values don't match expected key fields!");
    
    $entityClass = get_class($e);
    $template = new $entityClass();
    
    foreach ($keyValues as $key=>$value) {
      $template->{$key}=$value;
    }
    
    return $template;
  }
  
  protected static function buildFilter(array $filter, string $operator): string {
    if (count($filter) == 0 ) throw new InvalidArgumentException("Empty array provided");
    if (count($filter) == 1 ) return $filter[0];
    return $operator.'('.implode(')(', $filter).")";
  }
    
}
