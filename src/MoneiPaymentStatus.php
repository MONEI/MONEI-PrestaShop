<?php
namespace Monei;

class MoneiPaymentStatus
{
    /**
     * Possible values of this enum
     */
    public const SUCCEEDED = 'SUCCEEDED';
    public const PENDING = 'PENDING';
    public const FAILED = 'FAILED';
    public const CANCELED = 'CANCELED';
    public const REFUNDED = 'REFUNDED';
    public const PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const AUTHORIZED = 'AUTHORIZED';
    public const EXPIRED = 'EXPIRED';
    public const UPDATED = 'UPDATED';
    public const PAID_OUT = 'PAID_OUT';
    public const PENDING_PROCESSING = 'PENDING_PROCESSING';

    /**
     * Gets allowable values of the enum
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::SUCCEEDED,
            self::PENDING,
            self::FAILED,
            self::CANCELED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
            self::AUTHORIZED,
            self::EXPIRED,
            self::UPDATED,
            self::PAID_OUT,
            self::PENDING_PROCESSING,
        ];
    }
}
