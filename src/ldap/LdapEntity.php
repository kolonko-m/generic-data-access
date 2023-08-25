<?php
namespace GDA\LDAP;

use Exception;
use GDA\Entity;

/**
 * Entity representing an LDAP entry.
 * 
 * @author matthias
 *
 */
abstract class LdapEntity extends Entity {
  
  const RDN_DEF_NAME             = "RDN_FIELD";
  const BASE_DN_NAME             = "BASE_DN";
  const OBJECT_CLASS_NAME        = "OBJECT_CLASSES";
  const MEMBERSHIP_NAME          = "MEMBER_OF";
  
  const TYPE_STRING              = "string";
  const TYPE_URL                 = "URL";
  const TYPE_TEL                 = "TEL";
  const TYPE_MAIL                = "EMAIL";
  const TYPE_INTEGER             = "integer";
  const TYPE_NUMERIC             = "numeric";
  const TYPE_OCTET               = "octet";
  const TYPE_BOOLEAN             = "boolean";
  const TYPE_DATE                = "date";
  const TYPE_TIMESTAMP           = "timestamp";
  const TYPE_UUID                = "uuid";
  const TYPE_JPEG                = "jpeg";
  const TYPE_DN                  = "DN";
  const TYPE_REFERENCE_DN        = "REF_DN";
  const TYPE_PASSWORD            = "password";
  
  const FIELD_DEF_POS_ATTR       = 3;
  const FIELD_DEF_POS_LIST       = 4;
  const FIELD_DEF_POS_LIST_DELIM = 5;
  const FIELD_DEF_POS_REF_ENTITY = 6;
  
  const FD_DN = array("dn", self::TYPE_DN, false, "dn");
  
  public $dn;
  
  public function adjustFieldValues(): void {}
  
  /**
   * In LDAP there must only be one key field, i.e. the RDN!
   * @throws Exception if the entity defines more than one key field.
   * 
   * {@inheritDoc}
   * @see Entity::getKeyFields()
   */
  public function getKeyFields(): array {
    $result=$this->refl->getConstant(self::RDN_DEF_NAME);
    if (!is_array($result) || !is_string($result[self::FIELD_DEF_POS_NAME])) 
      throw new Exception("LdapEntity '".get_class($this)."' incorrectly configured - no field found: ".self::RDN_DEF_NAME);
    return array($result[self::FIELD_DEF_POS_NAME]);
  }
  
  public function getBaseDN(): ?string {
    $result=$this->refl->getConstant(self::BASE_DN_NAME);
    if (is_bool($result) && !$result) return null;
    if (!is_string($result)) throw new Exception("LdapEntity '".get_class($this)."' incorrectly configured - no field found: ".self::BASE_DN_NAME);
    return $result;
  }
  
  public function getObjectClasses(): array {
    $result=$this->refl->getConstant(self::OBJECT_CLASS_NAME);
    if (!is_array($result)) throw new Exception("LdapEntity '".get_class($this)."' incorrectly configured - no field found: ".self::OBJECT_CLASS_NAME);
    return $result;
  }
  
  public function getMemberships(): array {
    $result=$this->refl->getConstant(self::MEMBERSHIP_NAME);
    if (is_bool($result) && !$result) return array();
    if (is_null($result)) return array();
    if (!is_array($result)) throw new Exception("LdapEntity '".get_class($this)."' incorrectly configured - no field found: ".self::MEMBERSHIP_NAME);
    return $result;
  }
  
}
