<?php

namespace EntityMarshal\Marshal;

/**
 * Class Named
 *
 * @author    Merten van Gerven
 * @copyright (c) 2013, Merten van Gerven
 */
class Named implements MarshalInterface
{
    /**
     * {@inheritdoc}
     */
    public function ratify($name, $type, $value, $defined)
    {
        if (!$defined) {
            throw new InvalidArgumentException("Property '$name' is not a valid property");
        }

        return $value;
    }
}