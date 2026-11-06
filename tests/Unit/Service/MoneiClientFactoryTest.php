<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\MoneiClientFactory;

class MoneiClientFactoryTest extends TestCase
{
    // Contract 9
    public function testAClientForAnotherKeyDoesNotChangeTheMainClientsKey(): void
    {
        // Without its own Configuration, the second client would write its key into
        // the shared default one and the main client would send it too.
        $main = MoneiClientFactory::create('pk_test_main', 'agent');
        $other = MoneiClientFactory::create('pk_live_other', 'agent');

        $this->assertSame('pk_test_main', $main->getConfig()->getApiKey('Authorization'));
        $this->assertSame('pk_live_other', $other->getConfig()->getApiKey('Authorization'));
        $this->assertSame('pk_test_main', $main->customers->getConfig()->getApiKey('Authorization'));
    }
}
