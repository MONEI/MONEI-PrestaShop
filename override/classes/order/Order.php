<?php

class Order extends OrderCore
{
    public static function generateReference()
    {
        // Use Context to store the reference in a request-scoped manner
        $context = Context::getContext();
        
        // Check if MONEI module has set a reference for this request
        if (isset($context->monei_order_reference) && $context->monei_order_reference) {
            $reference = $context->monei_order_reference;
            // Clear it after use to prevent reuse
            $context->monei_order_reference = null;

            return $reference;
        }

        return parent::generateReference();
    }
}
