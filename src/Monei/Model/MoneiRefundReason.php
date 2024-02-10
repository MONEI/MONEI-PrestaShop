<?php


namespace Monei\Model;

class MoneiRefundReason
{
    /**
     * Possible values of this enum
     */
    public const DUPLICATED = 'duplicated';
    public const FRAUDULENT = 'fraudulent';
    public const BYCUSTOMER = 'requested_by_customer';

    /**
     * Gets allowable values of the enum
     * @return string[]
     */
    public static function getAllowableEnumValues(): array
    {
        return [
            self::DUPLICATED,
            self::FRAUDULENT,
            self::BYCUSTOMER
        ];
    }
}
