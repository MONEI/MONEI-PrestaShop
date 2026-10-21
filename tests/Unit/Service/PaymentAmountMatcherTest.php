<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Order\PaymentAmountMatcher;

/**
 * A payment may settle an order only when it is for the order's total and
 * currency. One that is not must never move an order to paid or authorized.
 */
class PaymentAmountMatcherTest extends TestCase
{
    public function testPaymentForTheOrderTotalAndCurrencyMatches(): void
    {
        $this->assertTrue(PaymentAmountMatcher::matches(100000, 'EUR', 100000, 'EUR'));
    }

    public function testPaymentForADifferentAmountDoesNotMatch(): void
    {
        // A payment created for the cart at 10.00 must not settle a 1000.00 order.
        $this->assertFalse(PaymentAmountMatcher::matches(1000, 'EUR', 100000, 'EUR'));
    }

    public function testPaymentInADifferentCurrencyDoesNotMatch(): void
    {
        $this->assertFalse(PaymentAmountMatcher::matches(100000, 'USD', 100000, 'EUR'));
    }

    public function testCurrencyCodesCompareWithoutCase(): void
    {
        // A lowercase ISO code on either side must not block a valid payment.
        $this->assertTrue(PaymentAmountMatcher::matches(1999, 'eur', 1999, 'EUR'));
    }

    public function testMinorUnitsRoundInsteadOfTruncating(): void
    {
        // (int) (19.99 * 100) is 1998, one cent short of the payment amount.
        $this->assertSame(1999, PaymentAmountMatcher::toMinorUnits(19.99));
    }

    public function testMinorUnitsOfAThousandOrMoreHaveNoSeparator(): void
    {
        // number_format's default thousands separator would turn 1000.00 into "1,00000".
        $this->assertSame(100000, PaymentAmountMatcher::toMinorUnits(1000.0));
    }
}
