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
}
