<?php

/**
 * MONEI Order Override
 *
 * This override ensures that MONEI payments use the same reference
 * for both MONEI and PrestaShop orders, providing consistency across systems.
 */
class Order extends OrderCore
{
    /**
     * Override the reference generation to use MONEI's deterministic reference
     * when processing MONEI payments
     */
    public static function generateReference()
    {
        // Check if MONEI has stored a reference in the context
        $context = Context::getContext();
        if (isset($context->monei_order_reference) && !empty($context->monei_order_reference)) {
            // Use the MONEI reference to ensure consistency
            return $context->monei_order_reference;
        }

        // Otherwise, use the default PrestaShop reference generation
        return parent::generateReference();
    }
}
