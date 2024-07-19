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
        'applePay' => 'applePay',
        'applePay' => 'googlePay',
        'clickToPay' => 'clickToPay',
        'paypal' => 'paypal',
        'cofidis' => 'cofidis',
        'klarna' => 'klarna',
        'multibanco' => 'multibanco',
        'mbway' => 'mbway',
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

    public function isPaymentMethodAllowed(string $paymentMethod, string $currencyIsoCode, string $countryIsoCode = null): bool
    {
        if (!in_array($paymentMethod, $this->container)) {
            return false;
        }

        if ($currencyIsoCode !== 'EUR') {
            return false;
        }

        switch ($paymentMethod) {
            case MoneiPaymentMethods::BIZUM:
                return $countryIsoCode === 'ES';
            case MoneiPaymentMethods::COFIDIS:
                return $countryIsoCode === 'ES';
            case MoneiPaymentMethods::MULTIBANCO:
            case MoneiPaymentMethods::MBWAY:
                return $countryIsoCode === 'PT';
            case MoneiPaymentMethods::KLARNA:
                return in_array($countryIsoCode, ['AT', 'BE', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IT', 'NL', 'NO', 'SE']);
            default:
                return true;
        }
    }
}
