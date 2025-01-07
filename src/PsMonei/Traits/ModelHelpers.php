<?php
namespace PsMonei\Traits;

trait ModelHelpers
{
    /**
     * Convert Model to JSON
     * @return null|string
     */
    public function toJSON(): ?string
    {
        return json_encode($this->toArray());
    }

    /**
     * Convert Models multidimensional array
     * @param mixed|null $elements
     * @return array
     */
    public function toArray($elements = null): array
    {
        $object_array_elements = $elements !== null ? $elements->getContainer() : $this->container;
        $tmp_elements = [];

        foreach ($object_array_elements as $element_key => $element_value) {
            if (is_object($element_value)) {
                if (method_exists($element_value, 'getContainer')) { // Avoid non supported Models
                    $tmp_elements[$element_key] = $this->toArray($object_array_elements[$element_key]);
                } else {
                    $tmp_elements[$element_key] = (array)($object_array_elements[$element_key]);
                }
            } else {
                $tmp_elements[$element_key] = $object_array_elements[$element_key];
            }
        }
        return $tmp_elements;
    }

    /**
     * Gets the Model container
     * @return array
     */
    public function getContainer(): array
    {
        return $this->container;
    }

    /**
     * Converts Models to API multidimensional array
     * @param mixed|null $elements
     * @return array
     */
    public function toAPI($elements = null): array
    {
        $object_array_elements = $elements !== null ? $elements->getContainer() : $this->container;
        $object_array_attributes = $elements !== null ? $elements->getAttributesMap() : $this->attribute_map;
        $tmp_elements = [];

        foreach ($object_array_elements as $element_key => $element_value) {
            if (is_object($element_value)) {
                $tmp_elements[$object_array_attributes[$element_key]] =
                    $this->toAPI($object_array_elements[$element_key]);
            } else {
                $tmp_elements[$object_array_attributes[$element_key]] = $object_array_elements[$element_key];
            }
        }

        // Before returning the structure for the API, we need to clean empty or null values
        return $this->cleanEmpty($tmp_elements);
    }

    /**
     * Gets the Model attribute map
     * @return array
     */
    public function getAttributesMap(): array
    {
        return $this->attribute_map;
    }

    /**
     * Clean empty or nulled value keys
     * @param mixed|null $elements
     * @return array
     */
    private function cleanEmpty($elements = null): array
    {
        foreach ($elements as $key => $value) {
            if ($value === null || $value === '') {
                unset($elements[$key]);
            }
        }
        return $elements;
    }

    /**
     * Calls the magic method
     * @return string
     */
    public function toString(): string
    {
        return $this->__toString();
    }

    /**
     * Magic method, Converts the object to a JSON string
     * @return string
     */
    public function __toString(): string
    {
        return json_encode((array)$this);
    }

    /**
     * Gets the container value, if exists
     * @param string $key
     * @return mixed
     */
    private function getContainerValue(string $key)
    {
        return array_key_exists($key, $this->container) && !is_null($this->container[$key]) ?
            $this->container[$key] : null;
    }
}
