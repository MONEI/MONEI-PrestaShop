<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use Monei\Api\CustomersApi;
use Monei\ApiException;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\Customer;
use Monei\Model\PaymentCustomer;
use Monei\ObjectSerializer;
use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\CustomerService;
use PsMonei\Service\Monei\PaymentCustomerLink;
use PsMonei\Tests\Unit\Service\Fake\InMemoryCustomerMappingStore;

class PaymentCustomerLinkTest extends TestCase
{
    private const STALE_BODY = '{"status":"NotFoundError","statusCode":404,"message":"Customer not found"}';

    /** @var InMemoryCustomerMappingStore */
    private $store;

    /** @var CustomersApi&\PHPUnit\Framework\MockObject\MockObject */
    private $api;

    protected function setUp(): void
    {
        $this->store = new InMemoryCustomerMappingStore();
        $this->api = $this->createMock(CustomersApi::class);
    }

    // Contract 1
    public function testAGuestIsNeverLinked(): void
    {
        $this->api->expects($this->never())->method('create');
        $request = new CreatePaymentRequest();

        PaymentCustomerLink::link($this->service(), $request, 1, 42, true, false, $this->buyer());

        $this->assertFalse($request->offsetExists('customer_id'));
    }

    public function testASoftDeletedCustomerIsNeverLinked(): void
    {
        // Core keeps their session alive; linking would recreate them at MONEI.
        $this->api->expects($this->never())->method('create');

        PaymentCustomerLink::link($this->service(), new CreatePaymentRequest(), 1, 42, false, true, $this->buyer());
    }

    // Contract 3
    public function testACustomerApiErrorLeavesThePaymentUnlinked(): void
    {
        $this->api->method('create')->willThrowException(new \Error('outage'));
        $request = new CreatePaymentRequest();

        PaymentCustomerLink::link($this->service(), $request, 1, 42, false, false, $this->buyer());

        $this->assertFalse($request->offsetExists('customer_id'));
        $this->assertSame([], $this->store->rows);
    }

    // Contract 8, through the real caller shape: getPhone() is null when the address has none
    public function testANullPhoneStillLinks(): void
    {
        $this->api->method('create')->willReturn(new Customer(['id' => 'cus_new']));
        $request = new CreatePaymentRequest();

        PaymentCustomerLink::link($this->service(), $request, 1, 42, false, false, new PaymentCustomer([
            'email' => 'ana@example.com',
            'name' => 'Ana Lopez',
            'phone' => null,
        ]));

        $this->assertSame('cus_new', $request->getCustomerId());
    }

    // Contract 6
    public function testAStaleCustomerIsForgottenAndRetriedOnceWithoutIt(): void
    {
        $this->store->seed(42, 'acc_1', 'test', 'cus_stale');
        $request = (new CreatePaymentRequest())->setCustomerId('cus_stale');
        $sent = [];

        try {
            PaymentCustomerLink::create($this->service(), $request, 1, 42, function (CreatePaymentRequest $r) use (&$sent) {
                // Serialized here, as the SDK does: a missing key would warn and fail.
                $sent[] = ObjectSerializer::sanitizeForSerialization($r);

                throw new ApiException('[404] Not Found', 404, [], self::STALE_BODY);
            });
            $this->fail('The failed retry must be rethrown');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->assertCount(2, $sent);
        $this->assertSame('cus_stale', $sent[0]->customerId);
        $this->assertFalse(property_exists($sent[1], 'customerId'));
        $this->assertNull($this->store->get(42, 'acc_1', 'test'));
    }

    public function testTheRetryKeepsAMappingThatAnotherRequestRenewed(): void
    {
        $this->store->seed(42, 'acc_1', 'test', 'cus_newer');
        $request = (new CreatePaymentRequest())->setCustomerId('cus_stale');
        $calls = 0;

        $result = PaymentCustomerLink::create($this->service(), $request, 1, 42, function () use (&$calls) {
            if (++$calls === 1) {
                throw new ApiException('[404] Not Found', 404, [], self::STALE_BODY);
            }

            return 'payment';
        });

        $this->assertSame('payment', $result);
        $this->assertSame('cus_newer', $this->store->get(42, 'acc_1', 'test'));
    }

    public function testAnUnrelated404IsRethrownWithoutARetry(): void
    {
        $this->store->seed(42, 'acc_1', 'test', 'cus_1');
        $request = (new CreatePaymentRequest())->setCustomerId('cus_1');
        $calls = 0;

        $this->expectException(ApiException::class);

        try {
            PaymentCustomerLink::create($this->service(), $request, 1, 42, function () use (&$calls) {
                ++$calls;

                throw new ApiException('[404] Not Found', 404, [], '{"message":"Payment method not found"}');
            });
        } finally {
            $this->assertSame(1, $calls);
            $this->assertSame('cus_1', $this->store->get(42, 'acc_1', 'test'));
        }
    }

    public function testAnUnlinkedPaymentIsNeverRetried(): void
    {
        $calls = 0;

        $this->expectException(ApiException::class);

        try {
            PaymentCustomerLink::create($this->service(), new CreatePaymentRequest(), 1, 42, function () use (&$calls) {
                ++$calls;

                throw new ApiException('[404] Not Found', 404, [], self::STALE_BODY);
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    private function buyer(): PaymentCustomer
    {
        return new PaymentCustomer(['email' => 'ana@example.com', 'name' => 'Ana Lopez', 'phone' => '600123456']);
    }

    private function service(): CustomerService
    {
        return new CustomerService(
            $this->store,
            static function (): array {
                return ['accountId' => 'acc_1', 'mode' => 'test', 'apiKey' => 'pk_test'];
            },
            static function (): array {
                return [];
            },
            function (): CustomersApi {
                return $this->api;
            },
            static function (): void {
            }
        );
    }
}
