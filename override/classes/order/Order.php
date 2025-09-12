<?php

/**
 * MONEI Order Override
 *
 * This override ensures that PrestaShop orders use the same reference as MONEI payments
 * for consistent tracking between both systems.
 */
class Order extends OrderCore
{
    /**
     * Generate order reference using MONEI's deterministic reference if available
     *
     * @return string Order reference
     */
    public static function generateReference()
    {
        // Check if a MONEI reference has been stored in context
        $context = Context::getContext();
        if (isset($context->monei_order_reference) && !empty($context->monei_order_reference)) {
            return $context->monei_order_reference;
        }

        // Fall back to PrestaShop's native reference generation
        return parent::generateReference();
    }
}
