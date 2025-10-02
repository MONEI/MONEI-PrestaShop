<?php

class Order extends OrderCore
{
    public static function generateReference()
    {
        // Use MONEI order ID as reference if available
        $context = Context::getContext();

        if (isset($context->monei_order_reference) && $context->monei_order_reference) {
            $reference = $context->monei_order_reference;
            // Clear it after use to prevent reuse
            $context->monei_order_reference = null;

            return $reference;
        }

        // Fall back to PrestaShop's native reference generation
        return parent::generateReference();
    }
}
