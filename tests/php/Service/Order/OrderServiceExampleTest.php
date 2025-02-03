<?php

namespace PsMonei\Tests\Service\Order;

use PHPUnit\Framework\TestCase;
use Mockery;
use Monei_Official;
use Context;
use Module;

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
