<?php
namespace SlapOM;

use SlapOM\Exception\SlapOM as SlapOMException;

abstract class EntityMap
{
    const FIELD_MULTIVALUED = 1;
    const FIELD_BINARY      = 2;

    protected $connection;
    protected $base_dn;
    protected $ldap_object_class;
    protected $entity_class;
    protected $attributes = array('dn');

    public final function __construct(\SlapOM\Connection $connection)
    {
        $this->connection = $connection;
        $this->configure();

        if (!isset($this->base_dn))
        {
            throw new SlapOMException(sprintf("Base DN is not set after configured class '%s'.", get_class($this)));
        }

        if (!isset($this->ldap_object_class))
        {
            throw new SlapOMException(sprintf("LDAP 'objectClass' is not set after configured class '%s'.", get_class($this)));
        }

        if (!isset($this->entity_class))
        {
            throw new SlapOMException(sprintf("Entity class is not set after configured class '%s'.", get_class($this)));
        }

        if (count($this->attributes) <= 1)
        {
            throw new SlapOMException(sprintf("Attributes list is empty after configured class '%s'.", get_class($this)));
        }

    }

    abstract protected function configure();

    public function find($filter, $dn_suffix = null, $limit = 0)
    {
        $dn = is_null($dn_suffix) ? $this->base_dn : $dn_suffix.",".$this->base_dn;
        $filter = sprintf("(&(objectClass=%s)%s)", $this->ldap_object_class, $filter);

        $results = $this->connection->search($dn, $filter, $this->getAttributeNames(), $limit);

        return $this->processResults($results);
    }

    public function getAttributeNames()
    {
        return array_keys($this->attributes);
    }

    public function addAttribute($name, $modifier = 0)
    {
        $this->attributes[$name] = $modifier;
    }

    public function getAttributeModifiers($name)
    {
        return $this->attributes[$name];
    }

    protected function processResults($results)
    {
        $entity_class = $this->entity_class;
        $entities = array();

        if ($results['count'] > 0)
        {
            unset($results['count']);
            // iterate on results
            foreach ($results as $result)
            {
                $result = array_filter($result, function($val) { return is_array($val); });
                array_walk($result, array($this, 'processFieldValue'));

                $entities[] = new $entity_class($result);
            }
        }

        return new \ArrayIterator($entities);
    }

    protected function processFieldValue(&$value, $field)
    {
        unset($value['count']);

        if (!$this->getAttributeModifiers($field) & static::FIELD_MULTIVALUED)
        {
            $value = array_shift($value);
        }
    }

}
