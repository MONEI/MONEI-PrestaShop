<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\PaymentMethodAvailability;

/**
 * MB WAY and Multibanco cannot be pre-authorized, so `auth` removes them from the
 * storefront rather than charging them immediately. These tests pin that down in
 * both directions: what survives, and what the merchant must be warned about.
 */
class PaymentMethodAvailabilityTest extends TestCase
{
    public function testSaleKeepsEveryEnabledMethod(): void
    {
        $enabled = ['card', 'bizum', 'mbway', 'multibanco', 'paypal'];

        $this->assertSame($enabled, PaymentMethodAvailability::filter($enabled, 'sale'));
    }

    public function testAuthDropsOnlyTheMethodsThatCannotAuthorize(): void
    {
        $enabled = ['card', 'bizum', 'mbway', 'multibanco', 'paypal'];

        $this->assertSame(
            ['card', 'bizum', 'paypal'],
            PaymentMethodAvailability::filter($enabled, 'auth'),
            'cards, Bizum and PayPal all support pre-authorization and must survive'
        );
    }

    public function testAuthLeavesASupportedOnlySelectionUntouched(): void
    {
        $enabled = ['card', 'applePay', 'googlePay'];

        $this->assertSame($enabled, PaymentMethodAvailability::filter($enabled, 'auth'));
    }

    public function testWalletsAreNotTreatedAsUnsupported(): void
    {
        // Apple Pay and Google Pay are card payments; MONEI authorizes them like
        // any other card. A previous WooCommerce bug hard coded them as
        // unsupported, which is what this pins against.
        $this->assertSame(
            ['applePay', 'googlePay'],
            PaymentMethodAvailability::filter(['applePay', 'googlePay'], 'auth')
        );
    }

    public function testNothingIsHiddenInSaleMode(): void
    {
        $this->assertSame(
            [],
            PaymentMethodAvailability::hiddenBy(['card', 'mbway', 'multibanco'], 'sale')
        );
    }

    public function testHiddenReportsExactlyWhatAuthRemoves(): void
    {
        $this->assertSame(
            ['mbway', 'multibanco'],
            PaymentMethodAvailability::hiddenBy(['card', 'mbway', 'multibanco'], 'auth')
        );
    }

    public function testHiddenReportsASingleMethodWhenOnlyOneIsEnabled(): void
    {
        $this->assertSame(
            ['mbway'],
            PaymentMethodAvailability::hiddenBy(['card', 'mbway'], 'auth')
        );
    }

    public function testHiddenIsEmptyWhenNeitherIsEnabled(): void
    {
        $this->assertSame([], PaymentMethodAvailability::hiddenBy(['card', 'paypal'], 'auth'));
    }

    public function testEmptySelectionIsHandled(): void
    {
        $this->assertSame([], PaymentMethodAvailability::filter([], 'auth'));
        $this->assertSame([], PaymentMethodAvailability::hiddenBy([], 'auth'));
    }
}
