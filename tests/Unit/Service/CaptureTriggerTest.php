<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\CaptureTrigger;

/**
 * Automatic capture must fire for any context that moves an order, and must not
 * fire for orders this module did not take payment for.
 */
class CaptureTriggerTest extends TestCase
{
    public function testCapturesWhenTheNewStateIsConfigured(): void
    {
        $this->assertTrue(CaptureTrigger::shouldCapture('monei', 'monei', 4, '3,4,5'));
    }

    public function testDoesNotCaptureForAnUnconfiguredState(): void
    {
        $this->assertFalse(CaptureTrigger::shouldCapture('monei', 'monei', 9, '3,4,5'));
    }

    public function testDoesNotCaptureAnOrderPaidWithAnotherModule(): void
    {
        $this->assertFalse(CaptureTrigger::shouldCapture('ps_checkout', 'monei', 4, '3,4,5'));
    }

    public function testEmptyConfigurationMeansAutomaticCaptureIsOff(): void
    {
        // The default. A merchant opts in by choosing states, so an empty value
        // must never be read as "every state".
        $this->assertFalse(CaptureTrigger::shouldCapture('monei', 'monei', 4, ''));
    }

    public function testToleratesWhitespaceAndEmptyEntries(): void
    {
        $this->assertTrue(CaptureTrigger::shouldCapture('monei', 'monei', 4, ' 3 , 4 ,, '));
    }

    public function testIgnoresNonNumericEntries(): void
    {
        $this->assertSame([3, 5], CaptureTrigger::parseStates('3,abc,5'));
    }

    public function testParsesAnEmptyValueToNoStates(): void
    {
        $this->assertSame([], CaptureTrigger::parseStates(''));
    }

    public function testDoesNotMatchOnLooseComparison(): void
    {
        // '4abc' must not satisfy a state id of 4 through PHP's loose rules.
        $this->assertSame([], CaptureTrigger::parseStates('4abc'));
    }
}
