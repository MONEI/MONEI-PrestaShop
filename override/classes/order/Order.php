<?php

class Order extends OrderCore
{
    public static $moneiOrderReference;

    public static function generateReference()
    {
        if (self::$moneiOrderReference) {
            $reference = self::$moneiOrderReference;
            self::$moneiOrderReference = null;

            return $reference;
        }

        return parent::generateReference();
    }
}
