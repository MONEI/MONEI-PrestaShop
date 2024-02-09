<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiAddress implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'country' => 'country',
        'city' => 'city',
        'line1' => 'line1',
        'line2' => 'line2',
        'zip' => 'zip',
        'state' => 'state'
    ];

    private $attribute_type = [];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Country ISO Code
     * https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2
     * @return string
     */
    public function getCountry(): string
    {
        return $this->container['country'];
    }

    /**
     * Sets Country ISO Code
     * @param string $country_iso_code
     * @return MoneiAddress
     */
    public function setCountry(string $country_iso_code): self
    {
        $this->container['country'] = $country_iso_code;
        return $this;
    }

    /**
     * Gets the city name
     * @return string
     */
    public function getCity(): string
    {
        return $this->container['city'];
    }

    /**
     * Sets the city name
     * @param string $city
     * @return MoneiAddress
     */
    public function setCity(string $city): self
    {
        $this->container['city'] = $city;
        return $this;
    }

    /**
     * Gets line 1 address
     * @return string
     */
    public function getLine1(): string
    {
        return $this->container['line1'];
    }

    /**
     * Gets line 2 address
     * @param string $line1
     * @return MoneiAddress
     */
    public function setLine1(string $line1): self
    {
        $this->container['line1'] = $line1;
        return $this;
    }

    /**
     * Gets line2 address
     * @return string
     */
    public function getLine2(): string
    {
        return $this->container['line2'];
    }

    /**
     * Sets line2 address
     * @param string $line2
     * @return MoneiAddress
     */
    public function setLine2(string $line2): self
    {
        $this->container['line2'] = $line2;
        return $this;
    }

    /**
     * Gets the ZIP/postal code
     * @return string
     */
    public function getZip(): string
    {
        return $this->container['zip'];
    }

    /**
     * Sets the ZIP/postal code
     * @param string $zip
     * @return MoneiAddress
     */
    public function setZip(string $zip): self
    {
        $this->container['zip'] = $zip;
        return $this;
    }

    /**
     * Get country state
     * @return string
     */
    public function getState(): string
    {
        return $this->container['state'];
    }

    /**
     * Sets country state
     * @param string $state
     * @return MoneiAddress
     */
    public function setState(string $state): self
    {
        $this->container['state'] = $state;
        return $this;
    }
}
