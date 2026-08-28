<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Express\ExpressMethodResolver;

/**
 * Express settings must widen nothing: a method has to be wanted for express,
 * enabled as a payment method, and offered by the MONEI account.
 */
class ExpressMethodResolverTest extends TestCase
{
    private const ALL = ['applePay', 'googlePay', 'paypal'];

    public function testResolvesTheIntersectionOfAllThreeGates(): void
    {
        $this->assertSame(
            self::ALL,
            ExpressMethodResolver::resolve('applePay,googlePay,paypal', self::ALL, self::ALL)
        );
    }

    public function testAMethodTurnedOffUnderPaymentMethodsIsNotOfferedForExpress(): void
    {
        // The gate that matters most: express must never re-enable something the
        // merchant switched off.
        $this->assertSame(
            ['applePay', 'googlePay'],
            ExpressMethodResolver::resolve(
                'applePay,googlePay,paypal',
                ['applePay', 'googlePay'],
                self::ALL
            )
        );
    }

    public function testAMethodTheAccountDoesNotOfferIsDropped(): void
    {
        $this->assertSame(
            ['paypal'],
            ExpressMethodResolver::resolve('applePay,googlePay,paypal', self::ALL, ['paypal'])
        );
    }

    public function testOrderIsStableRegardlessOfHowTheSettingWasSaved(): void
    {
        $this->assertSame(
            self::ALL,
            ExpressMethodResolver::resolve('paypal,googlePay,applePay', self::ALL, self::ALL)
        );
    }

    public function testNoConfiguredMethodsResolvesToNothing(): void
    {
        $this->assertSame([], ExpressMethodResolver::resolve('', self::ALL, self::ALL));
    }

    public function testUnknownMethodsAreIgnored(): void
    {
        $this->assertSame(
            ['paypal'],
            ExpressMethodResolver::resolve('paypal,bitcoin', self::ALL, self::ALL)
        );
    }

    public function testLocationRequiresBothTheMasterSwitchAndTheLocation(): void
    {
        $this->assertTrue(ExpressMethodResolver::isLocationEnabled('cart', true, 'product,cart'));
        $this->assertFalse(ExpressMethodResolver::isLocationEnabled('checkout', true, 'product,cart'));
    }

    public function testTheMasterSwitchOffDisablesEveryLocation(): void
    {
        $this->assertFalse(
            ExpressMethodResolver::isLocationEnabled('cart', false, 'product,cart,checkout')
        );
    }

    public function testAnUnknownLocationIsNeverEnabled(): void
    {
        $this->assertFalse(ExpressMethodResolver::isLocationEnabled('blog', true, 'blog'));
    }

    public function testWhitespaceInTheStoredListIsTolerated(): void
    {
        $this->assertTrue(ExpressMethodResolver::isLocationEnabled('cart', true, ' product , cart '));
    }
}
