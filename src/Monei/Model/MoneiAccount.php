<?php
namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiAccount implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'payment_methods' => 'payment_methods'
    ];

    private $attribute_type = [
        'payment_methods' => '\Monei\Model\MoneiPaymentMethod',
    ];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    public function getPaymentMethodsAllowed(): ?MoneiPaymentMethod
    {
        return $this->container['payment_methods'];
    }

    public function isLiveMode(): bool
    {
        return $this->container['livemode'];
    }
}
