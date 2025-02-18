<?php

namespace PsMonei\Tests\Service\Order;

use Context;
use Mockery;
use Module;
use Monei_Official;
use PHPUnit\Framework\TestCase;

class OrderServiceExampleTest extends TestCase
{
    private $module;

    public function testExample()
    {
        $contextMock = Mockery::mock(Context::class);
        var_dump(Monei_Official::class);
        // $this->module->shouldReceive('getContext1')
        //     ->andReturn($contextMock);

        $this->assertTrue(true);
    }
}
