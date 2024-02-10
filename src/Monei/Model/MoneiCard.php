<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiCard implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'country' => 'country',
        'brand' => 'brand',
        'type' => 'type',
        'threed_secure' => 'threeDSecure',
        'threed_secure_version' => 'threeDSecureVersion',
        'expiration' => 'expiration', // UNIX EPOCH
        'last4' => 'last4'
    ];

    private $attribute_type = [];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Gets the country
     * @return string
     */
    public function getCountry(): ?string
    {
        return $this->getContainerValue('country');
    }

    /**
     * Gets CC Brand (visa, mastercard, diners, amex, jcb, unionpay, unknown)
     * @return string
     */
    public function getBrand(): ?string
    {
        return $this->getContainerValue('brand');
    }

    /**
     * Gets CC type (debit, credit)
     * @return string
     */
    public function getType(): ?string
    {
        return $this->getContainerValue('type');
    }

    /**
     * Gets if CC is 3DSecure
     * @return string
     */
    public function getThreeDSecure(): bool
    {
        return (bool)$this->getContainerValue('threed_secure') ?? false;
    }

    /**
     * Gets 3DSecure version
     * @return string
     */
    public function getThreeDSecureVersion(): ?string
    {
        return $this->getContainerValue('threed_secure_version');
    }

    /**
     * Gets CC expiration date
     * @return int
     */
    public function getExpiration(): int
    {
        return $this->getContainerValue('expiration') ?? 0;
    }

    /**
     * Gets last 4 digits of CC
     * @return string
     */
    public function getLast4(): ?string
    {
        return $this->getContainerValue('last4');
    }
}
