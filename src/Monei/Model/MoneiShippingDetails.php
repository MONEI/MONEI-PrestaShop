<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiShippingDetails extends MoneiCustomer implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'company' => 'company',
        'address' => 'address'
    ];

    private $attribute_type = [
        'address' => '\Monei\Model\MoneiAddress'
    ];

    /**
     * Gets billing company name
     * @return string
     */
    public function getCompany(): string
    {
        return $this->container['company'];
    }

    /**
     * Sets billing company name
     * @param string $company
     * @return MoneiShippingDetails
     */
    public function setCompany(string $company): self
    {
        $this->container['company'] = $company;
        return $this;
    }

    /**
     * Get shipping address object
     * @return MoneiAddress
     */
    public function getAddress(): MoneiAddress
    {
        return $this->container['address'];
    }

    /**
     * Sets shipping address object
     * @param MoneiAddress $address
     * @return MoneiShippingDetails
     */
    public function setAddress(MoneiAddress $address): self
    {
        $this->container['address'] = $address;
        return $this;
    }
}
