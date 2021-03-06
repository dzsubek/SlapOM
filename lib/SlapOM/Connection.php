<?php
namespace SlapOM;

use SlapOM\Exception\Ldap as LdapException;

class Connection
{
    protected $handler;
    protected $host;
    protected $port;
    protected $login;
    protected $password;
    protected $maps = array();
    protected $error;
    protected $logger;

    public function __construct($host, $login, $password = null, $port = 389)
    {
        $this->login = $login;
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
    }

    public function __destruct()
    {
        if ($this->isOpen())
        {
            ldap_unbind($this->handler);
        }
    }

    public function addLogger(\SlapOM\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function removeLogger()
    {
        $this->logger = null;
    }

    protected function log($message, $loglevel = LoggerInterface::LOGLEVEL_DEBUG)
    {
        if (!is_null($this->logger))
        {
            $this->logger->log($message, $loglevel);
        }
    }

	/**
	 * @param      $class
	 * @param bool $renew
	 *
	 * @return EntityMap
	 */
	public function getMapFor($class, $renew = false)
    {
        $class = ltrim($class, '\\');
        if (!isset($this->maps[$class]) or $renew === true)
        {
            $class_name = sprintf("%sMap", $class);
            $this->log(sprintf("Spawning a new instance of '%s'.", $class_name));
            $this->maps[$class] = new $class_name($this);
        }

        return $this->maps[$class];
    }

    public function search(EntityMap $map, $dn, $filter, $attributes, $limit = 0, $multiLevel = true)
    {
        $this->log(sprintf("SEARCH Class='%s'. DN='%s', filter='%s', attributes={%s}, limit=%d.", get_class($map), $dn, $filter, join(', ', $attributes), $limit));
		if ($multiLevel) {
			$ret = @ldap_search($this->getHandler(), $dn, $filter, $attributes, 0, $limit);
		} else {
			$ret = @ldap_list($this->getHandler(), $dn, $filter, $attributes, 0, $limit);
		}

        if ($ret === false)
        {
            throw new LdapException(sprintf("Error while filtering dn '%s' with filter '%s'.", $dn, $filter), $this->handler, $this->error);
        } 
        elseif (is_null($ret))
        {
            throw new LdapException(sprintf("It looks like your query '%s' on base dn '%s' did not return a valid result resource. Double check it and look into the server's logs.", $filter, $dn), $this->handler, $this->error);
        }

        $collection = new Collection($this->handler, $ret, $map);
        $this->log(sprintf("Query returned '%d' results.", $collection->count()));

        return $collection;
    }

    public function modify($dn, $entry)
    {
        $this->log(sprintf("MODIFY dn='%s'.", $dn));
        $del_attr = array();
        $mod_attr = array();
        foreach ($entry as $name => $value)
        {
			if (is_array($value))
			{
				$mod_attr[$name] = $value;
			}
			else
			{
				$mod_attr[$name] = array($value);
			}
        }

        if (count($mod_attr) > 0)
        {
            $ret = @ldap_mod_replace($this->getHandler(), $dn, $mod_attr);
            if ($ret === false)
            {
                $this->log(sprintf("LDAP ERROR '%s' -- Modifying {%s}.", ldap_error($this->getHandler()), print_r($mod_attr, true)), \SlapOM\LoggerInterface::LOGLEVEL_CRITICAL);

                throw new LdapException(sprintf("Error while MODIFYING values <pre>%s</pre> in dn='%s'.", print_r($mod_attr, true), $dn), $this->getHandler(), $this->error);
            }

            $this->log(sprintf("Changing attributes {%s} to {%s}.", join(', ', array_keys($mod_attr)), print_r($mod_attr, true)));
        }

        return true;
    }

	public function create($dn, $entry)
	{
		$this->log(sprintf("CREATE dn='%s'.", $dn));

		$attr = array();
		foreach ($entry as $name => $value)
		{
			if (!empty($value)) {
				$attr[$name] = is_array($value) ? $value : array($value);
			}
		}

		$ret = @ldap_add($this->getHandler(), $dn, $attr);
		if ($ret === false)
		{
			$this->log(sprintf("LDAP ERROR '%s' -- Adding {%s}.", ldap_error($this->getHandler()), print_r($attr, true)), \SlapOM\LoggerInterface::LOGLEVEL_CRITICAL);

			throw new LdapException(sprintf("Error while ADDING entry {%s} in dn='%s'.", join(', ', array_keys($attr)), $dn), $this->getHandler(), $this->error);
		}
	}

	public function delete($dn)
	{
		$this->log(sprintf("DELETE dn='%s'.", $dn));

		$ret = @ldap_delete($this->getHandler(), $dn);
		if ($ret === false)
		{
			$this->log(sprintf("LDAP ERROR '%s' -- DELETE {%s}.", ldap_error($this->getHandler()), print_r($dn, true)), \SlapOM\LoggerInterface::LOGLEVEL_CRITICAL);
			throw new LdapException(sprintf("Error while DELETE entry dn='%s'.",  $dn), $this->getHandler(), $this->error);
		}

		return true;
	}

    protected function isOpen()
    {
        return !(is_null($this->handler) or $this->handler === false);
    }

    protected function connect()
    {
        $this->handler = ldap_connect($this->host, $this->port);

        ldap_get_option($this->handler,LDAP_OPT_ERROR_STRING,$this->error);
        ldap_set_option($this->handler, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($this->handler, LDAP_OPT_REFERRALS, 0);

        if (!@ldap_bind($this->handler, $this->login, $this->password))
        {
            throw new LdapException(sprintf("Could not bind to LDAP host='%s:%s' with login='%s'.", $this->host, $this->port, $this->login), $this->handler, $this->error);
        }

        $this->log(sprintf("Connected to LDAP host='%s:%s' with login = '%s'.", $this->host, $this->port, $this->login));
    }

    protected function getHandler()
    {
        if (!$this->isOpen())
        {
            $this->connect();
        }

        return $this->handler;
    }
}
