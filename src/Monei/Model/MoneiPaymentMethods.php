<?php
namespace Monei\Model;

class MoneiPaymentMethods
{
    /**
     * Possible values of this enum
     */
    public const CARD = 'card';
    public const BIZUM = 'bizum';
    public const APPLE = 'applePay';
    public const GOOGLE = 'googlePay';
    public const CLICKTOPAY = 'clickToPay';
    public const PAYPAL = 'paypal';
    public const COFIDIS = 'cofidis';
    public const KLARNA = 'klarna';
    public const MULTIBANCO = 'multibanco';

    /**
     * Gets allowable values of the enum
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::CARD,
            self::BIZUM,
            self::APPLE,
            self::GOOGLE,
            self::CLICKTOPAY,
            self::PAYPAL,
            self::COFIDIS,
            self::KLARNA,
            self::MULTIBANCO,
        ];
    }

    private static function isPaymentMethodAllowedByIsoCode(string $paymentMethod, string $isoCode): bool
    {
        switch ($paymentMethod) {
            case self::BIZUM:
                return $isoCode === 'ES';
            case self::COFIDIS:
                return $isoCode === 'ES';
            case self::MULTIBANCO:
                return $isoCode === 'PT';
            case self::KLARNA:
                return in_array($isoCode, ['AT', 'BE', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IT', 'NL', 'NO', 'SE']);
            default:
                return true;
        }
    }

    public static function isBizumAvailable(string $isoCode): bool
    {
        return self::isPaymentMethodAllowedByIsoCode(self::BIZUM, $isoCode);
    }

    public static function isMultibancoAvailable(string $isoCode): bool
    {
        return self::isPaymentMethodAllowedByIsoCode(self::MULTIBANCO, $isoCode);
    }

    public static function isKlarnaAvailable(string $isoCode): bool
    {
        return self::isPaymentMethodAllowedByIsoCode(self::KLARNA, $isoCode);
    }

    public static function isCofidisAvailable(string $isoCode): bool
    {
        return self::isPaymentMethodAllowedByIsoCode(self::COFIDIS, $isoCode);
    }
}
