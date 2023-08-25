<?php 
namespace GDA\ldap;

use GDA\exceptions\LdapException;

class LdapAction {
    const TYPE_INSERT   = "entryInsert";
    const TYPE_UPDATE   = "entryUpdate";
    const TYPE_DELETE   = "entryDelete";
    const TYPE_ATTR_DEL = "attributeDelete";
    const TYPE_ATTR_REP = "attributeReplace";
    const TYPE_ATTR_ADD = "attributeAdd";
    
    protected string $type;
    protected string $dn;
    protected array $entry;
    
    public function __construct(string $type, string $dn, array $entry = null) {
        $this->type = $type;
        $this->dn = $dn;
        $this->entry = $entry;
        
        if ($type != self::TYPE_INSERT && is_null($entry)) throw new LdapException("Entry array missing for type $type!");
    }
    
    public function __get($name) {
        return $this->$name;
    }
    
    public function __toString() {
        return self::class."[type: $this->type; dn: $this->dn; entry: ".print_r($this->entry, true)."]";
    }
}
