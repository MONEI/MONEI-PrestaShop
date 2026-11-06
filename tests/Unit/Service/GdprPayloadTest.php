<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\GdprPayload;

class GdprPayloadTest extends TestCase
{
    /**
     * @dataProvider payloads
     *
     * @param mixed $params
     */
    public function testReadsEveryPsgdprShape($params, array $expected): void
    {
        $this->assertSame($expected, GdprPayload::parse($params));
    }

    public function payloads(): array
    {
        return [
            // psgdpr 1.4 sends (array) $customer: an int id, and the email beside it.
            // ctype_digit(42) is false, so an int must not go through it.
            '1.4 by id' => [['id' => 42, 'email' => 'a@b.c'], ['id' => 42]],
            '2.x by id' => [['7'], ['id' => 7]],
            'not a plain id' => [['1e3'], []],
            'zero string' => [['0'], []],
            'zero int' => [[0], []],
            '1.4 by email' => [['email' => 'a@b.c'], ['email' => 'a@b.c']],
            '2.x by email' => [[['email' => 'a@b.c']], ['email' => 'a@b.c']],
            '2.x by phone' => [[['phone' => '600']], []],
        ];
    }
}
