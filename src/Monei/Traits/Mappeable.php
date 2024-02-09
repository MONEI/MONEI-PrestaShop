<?php


namespace Monei\Traits;

use Tools;

trait Mappeable
{
    /**
     * Maps attributes with its values
     * @param array $attributes
     * @return void
     */
    protected function mapAttributes(?array $attributes): void
    {
        if ($attributes) {
            foreach ($attributes as $attribute => $value) {
                $local_attribute = $this->camelCase2UnderScore($attribute);
                if (array_key_exists($local_attribute, $this->attribute_type)) {
                    // Create an instance of this type
                    $new_instance = new $this->attribute_type[$local_attribute]((array)$value);
                    $this->container[$local_attribute] = $new_instance;
                } else {
                    // Set normal container Value
                    $this->container[$local_attribute] = $value;
                }
            }
        }
    }

    /**
     * Converts camelCaseString to under_score
     * From: https://blog.cpming.top/p/php-camel-case-to-spaces-or-underscore
     * @param mixed $str
     * @param string $separator
     * @return mixed
     */
    private function camelCase2UnderScore($str, $separator = "_")
    {
        if (empty($str)) {
            return $str;
        }
        $str = lcfirst($str);
        $str = preg_replace("/[A-Z]/", $separator . "$0", $str);
        return Tools::strtolower($str);
    }
}
