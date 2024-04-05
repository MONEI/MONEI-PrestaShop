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

    private function isPaymentMethodAllowedByIsoCode(string $paymentMethod, string $isoCode): bool
    {
        switch ($paymentMethod) {
            case MoneiPaymentMethods::BIZUM:
                return $isoCode === 'ES';
            case MoneiPaymentMethods::COFIDIS:
                return $isoCode === 'ES';
            case MoneiPaymentMethods::MULTIBANCO:
            case MoneiPaymentMethods::MBWAY:
                return $isoCode === 'PT';
            case MoneiPaymentMethods::KLARNA:
                return in_array($isoCode, ['AT', 'BE', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IT', 'NL', 'NO', 'SE']);
            default:
                return true;
        }
    }

    public function isMultibancoAvailable(string $isoCode): bool
    {
        if (in_array(MoneiPaymentMethods::MULTIBANCO, $this->container)) {
            return $this->isPaymentMethodAllowedByIsoCode(MoneiPaymentMethods::MULTIBANCO, $isoCode);
        }

        return false;
    }

    public function isMBWayAvailable(string $isoCode): bool
    {
        if (in_array(MoneiPaymentMethods::MBWAY, $this->container)) {
            return $this->isPaymentMethodAllowedByIsoCode(MoneiPaymentMethods::MBWAY, $isoCode);
        }

        return false;
    }

    public function isBizumAvailable(string $isoCode): bool
    {
        if (in_array(MoneiPaymentMethods::BIZUM, $this->container)) {
            return $this->isPaymentMethodAllowedByIsoCode(MoneiPaymentMethods::BIZUM, $isoCode);
        }

        return false;
    }

    public function isCofidisAvailable(string $isoCode): bool
    {
        if (in_array(MoneiPaymentMethods::COFIDIS, $this->container)) {
            return $this->isPaymentMethodAllowedByIsoCode(MoneiPaymentMethods::COFIDIS, $isoCode);
        }

        return false;
    }

    public function isKlarnaAvailable(string $isoCode): bool
    {
        if (in_array(MoneiPaymentMethods::KLARNA, $this->container)) {
            return $this->isPaymentMethodAllowedByIsoCode(MoneiPaymentMethods::KLARNA, $isoCode);
        }

        return false;
    }

    public function isApplePayAvailable(): bool
    {
        return in_array(MoneiPaymentMethods::APPLE, $this->container);
    }

    public function isGooglePayAvailable(): bool
    {
        return in_array(MoneiPaymentMethods::GOOGLE, $this->container);
    }

    public function isClickToPayAvailable(): bool
    {
        return in_array(MoneiPaymentMethods::CLICKTOPAY, $this->container);
    }

    public function isPaypalAvailable(): bool
    {
        return in_array(MoneiPaymentMethods::PAYPAL, $this->container);
    }

    public function isCardAvailable(): bool
    {
        return in_array(MoneiPaymentMethods::CARD, $this->container);
    }
}
