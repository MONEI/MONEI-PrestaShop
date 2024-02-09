<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiShop implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'name' => 'name',
        'country' => 'country'
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
     * Gets country of a Shop
     * @return string
     */
    public function getCountry(): string
    {
        return $this->container['country'];
    }
}
