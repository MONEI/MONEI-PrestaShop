<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves the unit harness runs without booting PrestaShop.
 *
 * The suite deliberately covers pure logic only. Anything that needs a Context, a
 * Db connection or a live cart belongs in the Playwright suite, where it runs
 * against a real store instead of against a pile of mocks.
 */
class HarnessTest extends TestCase
{
    public function testComposerAutoloaderIsAvailable(): void
    {
        $this->assertTrue(
            class_exists(TestCase::class),
            'PHPUnit should be autoloaded through the module vendor directory'
        );
    }

    public function testModuleSourceIsAutoloadable(): void
    {
        // PSR-4 maps PsMonei\ onto src/. If this breaks, every other unit test
        // fails for a reason that has nothing to do with the code under test.
        $this->assertTrue(
            interface_exists(\PsMonei\Repository\MoneiPaymentRepositoryInterface::class)
                || class_exists(\PsMonei\Entity\Monei2Payment::class),
            'the PsMonei PSR-4 namespace should resolve to src/'
        );
    }
}
