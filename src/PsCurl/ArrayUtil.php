<?php

declare(strict_types=1);

namespace PsCurl;

class ArrayUtil
{
    /**
     * Is Array Assoc
     *
     * @access public
     * @param  $array
     *
     * @return boolean
     */
    public static function isArrayAssoc($array)
    {
        return (
            $array instanceof CaseInsensitiveArray ||
            (bool)count(array_filter(array_keys($array), 'is_string'))
        );
    }

    /**
     * Is Array Multidim
     *
     * @access public
     * @param  $array
     *
     * @return boolean
     */
    public static function isArrayMultidim($array)
    {
        if (!is_array($array)) {
            return false;
        }

        return (bool)count(array_filter($array, 'is_array'));
    }

    /**
     * Array Flatten Multidim
     *
     * @access public
     * @param  $array
     * @param  $prefix
     *
     * @return array
     */
    public static function arrayFlattenMultidim($array, $prefix = false)
    {
        $return = [];
        if (is_array($array) || is_object($array)) {
            if (empty($array)) {
                $return[$prefix] = '';
            } else {
                foreach ($array as $key => $value) {
                    if (is_scalar($value)) {
                        if ($prefix) {
                            $return[$prefix . '[' . $key . ']'] = $value;
                        } else {
                            $return[$key] = $value;
                        }
                    } else {
                        if ($value instanceof \CURLFile) {
                            $return[$key] = $value;
                        } else {
                            $return = array_merge(
                                $return,
                                self::arrayFlattenMultidim(
                                    $value,
                                    $prefix ? $prefix . '[' . $key . ']' : $key
                                )
                            );
                        }
                    }
                }
            }
        } elseif ($array === null) {
            $return[$prefix] = $array;
        }
        return $return;
    }

    /**
     * Array Random
     *
     * @access public
     * @param  $array
     *
     * @return mixed
     */
    public static function arrayRandom($array)
    {
        return $array[mt_rand(0, count($array) - 1)];
    }
}
