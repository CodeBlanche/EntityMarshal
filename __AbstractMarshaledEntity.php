<?php

namespace EntityMarshal;

use EntityMarshal\Exception\RuntimeException;
use EntityMarshal\RuntimeCache\RuntimeCacheEnabledInterface;
use EntityMarshal\RuntimeCache\RuntimeCacheSingleton;
use ReflectionClass;
use stdClass;
use Traversable;

/**
 * This class is intended to be used as a base for pure data object classes that contain typed (using phpdoc) public
 * properties. Control over these properties is deferred to EntityMarshal in order to validate inputs and auto-matically
 * cast values to the correct types.
 *
 * @author      Merten van Gerven
 * @category    EntityMarshal
 * @package     EntityMarshal
 */
abstract class AbstractMarshaledEntity extends AbstractEntity implements MarshaledEntityInterface, RuntimeCacheEnabledInterface
{
    /**
     * @var array       Maps phpdoc types to native (is_*) and/or user defined (instancof) types for validation.
     */
    private $typeMap = array(
        'array'    => 'array',
        'bool'     => 'bool',
        'callable' => 'callable',
        'double'   => 'double',
        'float'    => 'float',
        'int'      => 'int',
        'integer'  => 'integer',
        'long'     => 'long',
        'null'     => 'null',
        'numeric'  => 'numeric',
        'object'   => 'object',
        'real'     => 'real',
        'resource' => 'resource',
        'scalar'   => 'scalar',
        'string'   => 'string',
        'boolean'  => 'bool',
        'int'      => 'numeric',
        'integer'  => 'numeric',
        'double'   => 'numeric',
        'float'    => 'numeric',
        // default
        '*'        => 'object',
    );

    /**
     * @var array       Maps phpdoc types to native types for casting.
     */
    private $castMap = array(
        'int'     => 'integer',
        'integer' => 'integer',
        'long'    => 'integer',
        'bool'    => 'boolean',
        'boolean' => 'boolean',
        'float'   => 'float',
        'double'  => 'float',
        'real'    => 'float',
        'string'  => 'string',
        'charr'   => 'string',
    );

    /**
     * @var array       Key/type pairs of defined properties.
     */
    private $types = array(
        // default
        '*' => 'mixed',
    );

    /**
     * @var array       Generic types of public array/list properties declared within EntityMarshal extendor.
     */
    private $generics = array(
        // default
        '*' => null,
    );

    /**
     * @var boolean     Suppresses exceptions while setting non-existent properties.
     */
    private $graceful = false;

    /**
     * Initialize the definition arrays.
     *
     * @throws RuntimeException
     */
    protected function initialize()
    {
        $class       = $this->calledClassName();
        $cache       = $this->getRuntimeCache();
        $definitions = $cache->get($class);

        if (!is_null($definitions)) {
            $this->types    = $definitions['types'];
            $this->generics = $definitions['generics'];
        }
        else {
            $defaultType = $this->defaultPropertyType();
            $defaults    = $this->defaultValues();
            $properties  = $this->propertiesAndTypes();

            $this->initializeProperty('*', $defaultType);

            foreach ($properties as $name => $type) {
                $value = isset($defaults[$name]) ? $defaults[$name] : null;

                $this->initializeProperty($name, $type);
                $this->set($name, $value);
            }

            $cache->set($class, array(
                                     'types'    => $this->types,
                                     'generics' => $this->generics,
                                ));
        }

        parent::initialize();
    }

    private function initializeProperty($name, $type)
    {
        if (empty($type)) {
            $type    = $this->types['*'];
            $generic = $this->generics['*'];
        }
        else {
            $generic = $this->extractGeneric($type);
        }

        if (strpos($type, '|')) {
            throw new RuntimeException(sprintf("'%s' indicates a 'mixed' type in phpdoc for property '%s' of class '%s'. " . "Please use 'mixed' instead.", $type, $name, $this->calledClassName()));
        }

        if (!is_null($generic)) {
            if (!$this->isValidType($generic)) {
                throw new RuntimeException(sprintf("'%s' is not a valid native or object/class type in phpdoc for property '%s' of class '%s'", $generic, $name, $this->calledClassName()));
            }

            $this->generics[$name] = $generic;

            $type = 'array';
        }

        if (!$this->isValidType($type)) {
            throw new RuntimeException(sprintf("'%s' is not a valid native or object/class type in phpdoc for property '%s' of class '%s'", $type, $name, $this->calledClassName()));
        }

        $this->types[$name] = $type;
    }

    /**
     * Check if the specified type is valid.
     *
     * @param string $type
     *
     * @return boolean
     */
    private function isValidType($type)
    {
        if (!isset($this->typeMap[$type]) && $type !== 'mixed' && !class_exists($this->namespaced($type))
        ) {
            return false;
        }

        return true;
    }

    private function namespaced($type)
    {
        if (substr($type, 0, 1) === '\\') {
            return $type;
        }

        $class = $this->calledClassName();
        $space = substr($class, 0, strrpos($class, '\\'));

        return "\\$space\\$type";
    }

    /**
     * Extract the generic subtype from the specified type if there is one.
     *
     * @param string $type
     *
     * @return string|null
     */
    protected function extractGeneric($type)
    {
        if (empty($type)) {
            return null;
        }

        $generic = null;

        if (substr($type, -2) === '[]') {
            $generic = substr($type, 0, -2);
        }
        elseif (strtolower(substr($type, 0, 6)) === 'array<' && substr($type, -1) === '>'
        ) {
            $generic = preg_replace('/^array<([^>]+)>$/i', '$1', $type);
        }

        return $generic;
    }

    /**
     * {@inheritdoc}
     */
    public function set($name, $value)
    {
        if (!isset($this->types[$name])) {
            if ($this instanceof DynamicPropertyInterface) {
                $generic = $this->getGeneric('*');
                $type    = !is_null($generic) ? "{$generic}[]" : $this->getType('*');

                $this->initializeProperty($name, $type);
            }
            else {
                if ($this->graceful) {
                    return $this;
                }

                throw new RuntimeException(sprintf("Attempt to set property '%s' of class '%s' failed. Property does not exist.", $name, $this->calledClassName()));
            }
        }

        if (!is_null($value)) {
            $type    = $this->getType($name);
            $generic = $this->getGeneric($name);
            $value   = $this->prepareValue($value, $type, $generic, $name);
        }

        return parent::set($name, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function fromArray($data)
    {
        if (!is_array($data) && !($data instanceof Traversable)) {
            $className = $this->calledClassName();
            throw new Exception\RuntimeException("Unable to import from array in class '$className' failed. Argument must be an array or Traversable");
        }

        if ($this instanceof DynamicPropertyInterface) {
            return parent::fromArray($data);
        }

        $types = $this->types;

        unset($types['*']);

        $names  = array_keys($types);
        $usable = array();

        foreach ($names as $name) {
            if (array_key_exists($name, $data)) {
                $usable[$name] = & $data[$name];
            }
            else {
                $usable[$name] = null;
            }
        }

        return parent::fromArray($usable);
    }

    /**
     * Get the property type for the given property name
     *
     * @param string $name
     *
     * @return string
     */
    private function getType($name)
    {
        if (!isset($this->types[$name])) {
            $name = '*';
        }

        return $this->types[$name];
    }

    /**
     * Retrieve the generic type for the given property name
     *
     * @param string $name
     *
     * @return string
     */
    private function getGeneric($name)
    {
        if (!isset($this->generics[$name])) {
            $name = '*';
        }

        return $this->generics[$name];
    }

    /**
     * Prepare a value for storage according to required types.
     *
     * @param mixed  $value
     * @param string $type
     * @param string $generic
     * @param string $name
     *
     * @return mixed
     * @throws RuntimeException
     */
    private function prepareValue($value, $type, $generic = null, $name = '')
    {
        $definedType = $type;

        $castType = isset($this->castMap[$type]) ? $this->castMap[$type] : null;

        if (!is_null($castType) && is_scalar($value)) {
            $casted = $this->castScalar($value, $castType);

            if (empty($value)) {
                $value = $casted;
            }
            elseif ($value == $casted) {
                $value = $casted;
            }

            unset($casted);
        }

        if (!is_null($generic) && (is_array($value) || $value instanceof Traversable)) {
            foreach ($value as $key => $val) {
                $value[$key] = $this->prepareValue($val, $generic, null, "{$name}[{$key}]");
            }
        }

        $mappedType = isset($this->typeMap[$type]) ? $this->typeMap[$type] : $this->typeMap['*'];

        if ($mappedType === 'null') {
            return null;
        }

        if ($mappedType === 'object' && !is_object($value) && (is_array($value) || $value instanceof Traversable)) {
            $value = $this->castToObject($value, $definedType);
        }

        if (!$this->graceful && $type !== 'mixed' && !is_null($value) && (!call_user_func("is_$mappedType", $value) || ($mappedType === 'object' && $type !== 'object' && !($value instanceof $type)))) {
            $valueType = gettype($value);

            throw new RuntimeException(sprintf("Attempt to set property '%s' of class '%s' failed. " . "Property type '%s' expected while type '%s' was given for value '%s'", $name, $this->calledClassName(), $type, $valueType, var_export($value, true)));
        }

        return $value;
    }

    /**
     * Convert a value to the specified object type.
     *
     * @param mixed $value
     * @param mixed $type
     *
     * @return mixed
     */
    private function castToObject($value, $type)
    {
        if (!class_exists($type) && $type !== 'object') {
            return $value;
        }

        if (class_exists($type) && is_subclass_of($type, '\EntityMarshal\AbstractEntity')
        ) {
            $value = new $type($value);
        }
        else {
            $obj = $type === 'object' ? new stdClass() : new $type();

            foreach ($value as $key => $val) {
                $obj->$key = $val;
            }

            $value = $obj;
        }

        return $value;
    }

    /**
     * Cast a value to the desired scalar type. Value remains unchanged if type is not scalar.
     *
     * @param mixed  $value The value you went to cast
     * @param string $type  The type you want to cast to
     *
     * @return mixed
     */
    private function castScalar($value, $type)
    {
        switch ($type) {
            case 'integer':
                $value = (integer)$value;
                break;
            case 'boolean':
                $value = (boolean)$value;
                break;
            case 'float':
                $value = (float)$value;
                break;
            case 'string':
                $value = (string)$value;
                break;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getRuntimeCache()
    {
        return RuntimeCacheSingleton::getInstance();
    }

    /**
     * {@inheritdoc}
     */
    public function typeof($name)
    {
        $type = $this->getType($name);

        if ($type === 'array') {
            $generic = $this->getGeneric($name);
            if (!is_null($generic)) {
                $type = "{$generic}[]";
            }
        }

        return $type;
    }

    /**
     * Get the default property type to be used when no type is provided.
     * Default is 'mixed'
     *
     * @return string
     */
    abstract protected function defaultPropertyType();

    /**
     * Get the list of accessible properties and their associated types as an
     * associative array.
     * <code>
     * return array(
     *     'propertyName'  => 'propertyType'
     *     'propertyName2' => 'null'
     * );
     * </code>
     *
     * @return array
     */
    abstract protected function propertiesAndTypes();

    // Implement Serializable

    /**
     * {@inheritdoc}
     */
    public function serialize()
    {
        return serialize(array(
                              'types'      => $this->types,
                              'generics'   => $this->generics,
                              'properties' => parent::serialize(),
                         ));
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized)
    {
        $this->initialize();

        $data = unserialize($serialized);

        $this->types    = $data['types'];
        $this->generics = $data['generics'];

        parent::unserialize($data['properties']);
    }
}
