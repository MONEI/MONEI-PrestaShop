<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiPaymentMethod implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'method' => 'method',
        'card' => 'card',
        'bizum' => 'bizum',
        'paypal' => 'paypal',
        'cofidis' => 'cofidis',
        'klarna' => 'klarna',
        'multibanco' => 'multibanco'
    ];

    private $attribute_type = [
        'card' => '\Monei\Model\MoneiCard'
    ];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Gets MoneiCard object
     * @return MoneiCard
     */
    public function getCard(): MoneiCard
    {
        return $this->container['card'];
    }

    public function getMethod(): string
    {
        return $this->container['method'];
    }
}
