<?php

declare(strict_types=1);

namespace PsMonei\Service\Order;

/**
 * Whether a MONEI payment is for the amount and currency of what it settles.
 *
 * Pure logic, free of Configuration and Context so it can be unit tested without
 * booting PrestaShop.
 *
 * A payment is resolved to its cart, not to its order, so any payment created for
 * the cart can reach an order made from that cart, including one created while
 * the cart held other products. Core validateOrder compares amounts only for
 * logable states, and the module's Authorized state is not logable.
 */
class PaymentAmountMatcher
{
    /**
     * Convert a shop amount to minor units the way MoneiService::getCartAmount
     * builds the payment amount.
     *
     * number_format rounds, where an (int) cast of $amount * 100 truncates
     * 19.99 to 1998.
     */
    public static function toMinorUnits(float $amount): int
    {
        return (int) number_format($amount, 2, '', '');
    }

    /**
     * @param int $paymentAmount Payment amount in minor units
     * @param string $paymentCurrency Payment currency ISO code
     * @param int $expectedAmount Order or cart total in minor units
     * @param string $expectedCurrency Order or cart currency ISO code
     */
    public static function matches(
        int $paymentAmount,
        string $paymentCurrency,
        int $expectedAmount,
        string $expectedCurrency
    ): bool {
        return $paymentAmount === $expectedAmount
            && strtoupper($paymentCurrency) === strtoupper($expectedCurrency);
    }
}
