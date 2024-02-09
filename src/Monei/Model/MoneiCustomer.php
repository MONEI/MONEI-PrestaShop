<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiCustomer implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'email' => 'email',
        'name' => 'name',
        'phone' => 'phone',
        'company' => 'company'
    ];

    private $attribute_type = [];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Gets name of a Shop
     * @return string
     */
    public function getName(): string
    {
        return $this->container['name'];
    }

    /**
     * Sets the customer name
     * @param string $name
     * @return MoneiCustomer
     */
    public function setName(string $name): self
    {
        $this->container['name'] = $name;
        return $this;
    }

    /**
     * Gets the email of the customer
     * @return string
     */
    public function getEmail(): string
    {
        return $this->container['email'];
    }

    /**
     * Sets the customer email
     * @param string $email
     * @return MoneiCustomer
     */
    public function setEmail(string $email): self
    {
        $this->container['email'] = $email;
        return $this;
    }

    /**
     * Gets the customer phone
     * @return string
     */
    public function getPhone(): string
    {
        return $this->container['phone'];
    }

    /**
     * Sets the customer phone
     * @param string $phone
     * @return MoneiCustomer
     */
    public function setPhone(string $phone): self
    {
        $this->container['phone'] = $phone;
        return $this;
    }
}
