<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use Monei\Api\CustomersApi;
use Monei\ApiException;
use Monei\Model\CreateCustomerRequest;
use Monei\Model\Customer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\CustomerService;
use PsMonei\Tests\Unit\Service\Fake\InMemoryCustomerMappingStore;

/**
 * Contract: docs/plans/20261105-monei-customers-api-contract.md. Test and live use the
 * same account ID throughout, as real MONEI accounts often do, so a store or matcher
 * that ignores the mode fails here.
 */
class CustomerServiceTest extends TestCase
{
    private const ACCOUNT = 'acc_1';
    private const SHOP = 1;
    private const CUSTOMER = 42;

    /** @var InMemoryCustomerMappingStore */
    private $store;

    /** @var array<string, CustomersApi&MockObject> */
    private $apis = [];

    /** @var array<int, array{0: string, 1: int}> */
    private $logs = [];

    /** @var array{accountId: string, mode: string, apiKey: string} */
    private $current = ['accountId' => self::ACCOUNT, 'mode' => 'test', 'apiKey' => 'pk_test'];

    /** @var array */
    private $configured = [
        ['accountId' => self::ACCOUNT, 'mode' => 'test', 'apiKeys' => ['pk_test']],
        ['accountId' => self::ACCOUNT, 'mode' => 'live', 'apiKeys' => ['pk_live']],
    ];

    protected function setUp(): void
    {
        $this->store = new InMemoryCustomerMappingStore();
    }

    // Contract 2
    public function testReusesAnExistingMappingWithoutCreating(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_old');
        $this->api('pk_test')->expects($this->never())->method('create');

        $this->assertSame('cus_old', $this->getOrCreate());
    }

    // Contract 4
    public function testATestMappingIsNeverReturnedInLiveMode(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->current = ['accountId' => self::ACCOUNT, 'mode' => 'live', 'apiKey' => 'pk_live'];
        $this->api('pk_live')->method('create')->willReturn(new Customer(['id' => 'cus_live']));

        $this->assertSame('cus_live', $this->getOrCreate());
        $this->assertSame('cus_test', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
    }

    // Contract 8
    public function testABuyerWithNoPhoneIsCreatedWithoutAPhoneKeyAndLinked(): void
    {
        $sent = null;
        $this->api('pk_test')->method('create')->willReturnCallback(function (CreateCustomerRequest $request) use (&$sent) {
            $sent = json_decode((string) $request, true);

            return new Customer(['id' => 'cus_new']);
        });

        $this->assertSame('cus_new', $this->service()->getOrCreateCustomerId(self::SHOP, self::CUSTOMER, 'ana@example.com', 'Ana Lopez', ''));
        $this->assertSame([
            'email' => 'ana@example.com',
            'name' => 'Ana Lopez',
            'metadata' => ['platform' => 'prestashop', 'platformCustomerId' => '42'],
        ], $sent);
        $this->assertSame('cus_new', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
    }

    // Contract 10, lost race
    public function testAConcurrentCreateKeepsTheWinnerAndDeletesTheNewCustomer(): void
    {
        $this->store->beforeInsert = function (InMemoryCustomerMappingStore $store) {
            $store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_winner');
        };
        $api = $this->api('pk_test');
        $api->method('create')->willReturn(new Customer(['id' => 'cus_new']));
        $api->expects($this->once())->method('delete')->with('cus_new');

        $this->assertSame('cus_winner', $this->getOrCreate());
        $this->assertSame('cus_winner', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
    }

    // Contract 14
    public function testACustomerDeletedWhileMoneiCreatesLeavesNothingBehind(): void
    {
        $service = $this->service();
        $api = $this->api('pk_test');
        $api->method('create')->willReturnCallback(function () use ($service) {
            // The delete hook runs now: the row is already gone, and no mapping exists yet.
            $this->store->deletedCustomers[] = self::CUSTOMER;
            $service->deleteCustomer(self::CUSTOMER);

            return new Customer(['id' => 'cus_new']);
        });
        $api->expects($this->once())->method('delete')->with('cus_new');

        $this->assertNull($service->getOrCreateCustomerId(self::SHOP, self::CUSTOMER, 'ana@example.com', 'Ana Lopez', ''));
        $this->assertSame([], $this->store->rows);
    }

    // Contract 10, save failed
    public function testASaveThatReportsFailureDeletesTheNewCustomer(): void
    {
        $this->store->insertOutcome = false;
        $this->expectNewCustomerDeleted();

        $this->assertNull($this->getOrCreate());
    }

    public function testASaveThatThrowsDeletesTheNewCustomer(): void
    {
        $this->store->insertOutcome = 'throw';
        $this->expectNewCustomerDeleted();

        $this->assertNull($this->getOrCreate());
    }

    public function testASaveThatStoresNothingDeletesTheNewCustomer(): void
    {
        $this->store->insertStoresNothing = true;
        $this->expectNewCustomerDeleted();

        $this->assertNull($this->getOrCreate());
    }

    public function testAFailedReReadKeepsTheNewCustomerAndLogsItsIds(): void
    {
        // The row may hold the new ID, so deleting the customer could leave the
        // mapping pointing at nothing.
        $this->store->throwOnFindCall = 2;
        $api = $this->api('pk_test');
        $api->method('create')->willReturn(new Customer(['id' => 'cus_new']));
        $api->expects($this->never())->method('delete');

        $this->assertNull($this->getOrCreate());
        $this->assertLogged('monei_customer_id=cus_new, account_id=acc_1, mode=test', CustomerService::ERROR);
    }

    // Contract 3, at the service: an Error, not only an Exception, is contained
    public function testACreateErrorReturnsNullAndStoresNothing(): void
    {
        $this->api('pk_test')->method('create')->willThrowException(new \Error('outage'));

        $this->assertNull($this->getOrCreate());
        $this->assertSame([], $this->store->rows);
    }

    public function testAFailedFirstReadMakesNoCustomer(): void
    {
        // The upgrade has not created the table yet: every checkout would otherwise
        // create and orphan a customer.
        $this->store->throwOnFindCall = 1;
        $this->api('pk_test')->expects($this->never())->method('create');

        $this->assertNull($this->getOrCreate());
    }

    // Contract 12
    public function testAnEmptyAccountIdMakesNoCustomerAndNoMapping(): void
    {
        $this->current['accountId'] = '';
        $this->api('pk_test')->expects($this->never())->method('create');

        $this->assertNull($this->getOrCreate());
        $this->assertSame([], $this->store->rows);
    }

    // Contract 11
    public function testDeletingACardOfAnUnlinkedBuyerCallsNothing(): void
    {
        $this->api('pk_test')->expects($this->never())->method('deletePaymentMethod');

        $this->service()->deletePaymentMethod(self::SHOP, self::CUSTOMER, 'tok_1');
    }

    public function testDeletingACardUsesTheCurrentModesCustomerAndKey(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'live', 'cus_live');
        $this->current = ['accountId' => self::ACCOUNT, 'mode' => 'live', 'apiKey' => 'pk_live'];
        $this->api('pk_test')->expects($this->never())->method('deletePaymentMethod');
        $this->api('pk_live')->expects($this->once())->method('deletePaymentMethod')->with('cus_live', 'tok_1');

        $this->service()->deletePaymentMethod(self::SHOP, self::CUSTOMER, 'tok_1');
    }

    public function testACardDeleteThatThrowsAnErrorDoesNotThrow(): void
    {
        // customerCards.php catches only Exception, so an Error would leave the card
        // impossible to delete.
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->api('pk_test')->method('deletePaymentMethod')->willThrowException(new \Error('boom'));

        $this->service()->deletePaymentMethod(self::SHOP, self::CUSTOMER, 'tok_1');

        $this->assertLogged('boom', CustomerService::ERROR);
    }

    // Contract 5
    public function testA404OnDeleteRemovesTheRowForCustomersButNotForCards(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $api = $this->api('pk_test');
        $api->method('deletePaymentMethod')->willThrowException(new ApiException('gone', 404));
        $api->method('delete')->willThrowException(new ApiException('gone', 404));

        $this->service()->deletePaymentMethod(self::SHOP, self::CUSTOMER, 'tok_1');
        $this->assertSame('cus_test', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));

        $this->assertTrue($this->service()->deleteCustomer(self::CUSTOMER));
        $this->assertSame([], $this->store->rows);
        $this->assertNotLoggedAt(CustomerService::ERROR);
    }

    public function testAServerErrorOnDeleteKeepsTheRowAndLogsTheIds(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->api('pk_test')->method('delete')->willThrowException(new ApiException('down', 500));

        $this->assertFalse($this->service()->deleteCustomer(self::CUSTOMER));
        $this->assertSame('cus_test', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
        $this->assertLogged('monei_customer_id=cus_test, account_id=acc_1, mode=test', CustomerService::ERROR);
    }

    public function testAConflictOnDeleteKeepsTheRowAtErrorLevel(): void
    {
        // The default log level drops warnings, so a lost customer must log an error.
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->api('pk_test')->method('delete')->willThrowException(new ApiException('in use', 409));

        $this->assertFalse($this->service()->deleteCustomer(self::CUSTOMER));
        $this->assertSame('cus_test', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
        $this->assertLogged('cus_test', CustomerService::ERROR);
    }

    // Contract 7
    public function testDeletesEveryModeWithItsOwnKeyAndGoesOnAfterAFailingRow(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'live', 'cus_live');
        $this->store->seed(self::CUSTOMER, 'acc_gone', 'live', 'cus_orphan');
        $this->api('pk_test')->expects($this->once())->method('delete')->with('cus_test')->willThrowException(new \Error('boom'));
        $this->api('pk_live')->expects($this->once())->method('delete')->with('cus_live');

        $this->assertFalse($this->service()->deleteCustomer(self::CUSTOMER));
        $this->assertNull($this->store->get(self::CUSTOMER, self::ACCOUNT, 'live'));
        $this->assertSame('cus_test', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
        $this->assertSame('cus_orphan', $this->store->get(self::CUSTOMER, 'acc_gone', 'live'));
        $this->assertLogged('monei_customer_id=cus_orphan, account_id=acc_gone, mode=live', CustomerService::ERROR);
    }

    public function testDeleteCustomerUsesTheFirstKeyOfAnAccount(): void
    {
        $this->configured = CustomerService::mergeAccounts([
            ['accountId' => self::ACCOUNT, 'mode' => 'test', 'apiKey' => 'pk_stale', 'active' => false],
            ['accountId' => self::ACCOUNT, 'mode' => 'test', 'apiKey' => 'pk_test', 'active' => true],
        ]);
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_test');
        $this->api('pk_stale')->expects($this->never())->method('delete');
        $this->api('pk_test')->expects($this->once())->method('delete')->with('cus_test');

        $this->assertTrue($this->service()->deleteCustomer(self::CUSTOMER));
    }

    public function testAFailedMappingReadReportsTheEraseAsFailed(): void
    {
        $this->store->throwOnFindAll = true;

        $this->assertFalse($this->service()->deleteCustomer(self::CUSTOMER));
    }

    public function testForgetKeepsANewerMapping(): void
    {
        $this->store->seed(self::CUSTOMER, self::ACCOUNT, 'test', 'cus_newer');

        $this->service()->forgetCustomerId(self::SHOP, self::CUSTOMER, 'cus_stale');

        $this->assertSame('cus_newer', $this->store->get(self::CUSTOMER, self::ACCOUNT, 'test'));
    }

    public function testMergeAccountsGroupsByModeWithActiveShopKeysFirst(): void
    {
        $merged = CustomerService::mergeAccounts([
            ['accountId' => 'A', 'mode' => 'test', 'apiKey' => 'T0', 'active' => false],
            ['accountId' => 'A', 'mode' => 'test', 'apiKey' => 'T1', 'active' => true],
            ['accountId' => 'A', 'mode' => 'live', 'apiKey' => 'L1', 'active' => true],
            ['accountId' => 'A', 'mode' => 'test', 'apiKey' => '', 'active' => true],
            ['accountId' => 'A', 'mode' => 'live', 'apiKey' => 'L2', 'active' => true],
            ['accountId' => '', 'mode' => 'test', 'apiKey' => 'T9', 'active' => true],
        ]);

        $this->assertSame([
            ['accountId' => 'A', 'mode' => 'test', 'apiKeys' => ['T1', 'T0']],
            ['accountId' => 'A', 'mode' => 'live', 'apiKeys' => ['L1', 'L2']],
        ], $merged);
    }

    private function getOrCreate(): ?string
    {
        return $this->service()->getOrCreateCustomerId(self::SHOP, self::CUSTOMER, 'ana@example.com', 'Ana Lopez', '600123456');
    }

    private function expectNewCustomerDeleted(): void
    {
        $api = $this->api('pk_test');
        $api->method('create')->willReturn(new Customer(['id' => 'cus_new']));
        $api->expects($this->once())->method('delete')->with('cus_new');
    }

    /**
     * @return CustomersApi&MockObject
     */
    private function api(string $key): CustomersApi
    {
        if (!isset($this->apis[$key])) {
            $this->apis[$key] = $this->createMock(CustomersApi::class);
        }

        return $this->apis[$key];
    }

    private function service(): CustomerService
    {
        return new CustomerService(
            $this->store,
            function (int $idShop): array {
                return $this->current;
            },
            function (): array {
                return $this->configured;
            },
            function (string $key): CustomersApi {
                return $this->api($key);
            },
            function (string $message, int $severity): void {
                $this->logs[] = [$message, $severity];
            }
        );
    }

    private function assertLogged(string $fragment, int $severity): void
    {
        foreach ($this->logs as [$message, $level]) {
            if ($level === $severity && strpos($message, $fragment) !== false && strpos($message, '[MONEI Customers]') === 0) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail('No [MONEI Customers] log at severity ' . $severity . ' containing "' . $fragment . '": ' . json_encode($this->logs));
    }

    private function assertNotLoggedAt(int $severity): void
    {
        foreach ($this->logs as [$message, $level]) {
            $this->assertNotSame($severity, $level, $message);
        }
    }
}
