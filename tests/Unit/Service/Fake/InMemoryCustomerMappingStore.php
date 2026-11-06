<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service\Fake;

use PsMonei\Repository\CustomerMappingStoreInterface;

/**
 * Behaves like the Db store: INSERT IGNORE keeps an existing row and still reports
 * success. Failure switches let a test break one step at a time.
 */
final class InMemoryCustomerMappingStore implements CustomerMappingStoreInterface
{
    /** @var array<string, string> */
    public $rows = [];

    /** @var int|null the findMapping call (1-based) that throws */
    public $throwOnFindCall;

    /** @var bool|string|null false: insert reports failure; 'throw': insert throws */
    public $insertOutcome;

    /** @var bool insert reports success but stores nothing */
    public $insertStoresNothing = false;

    /** @var callable|null runs before the insert, to play another request */
    public $beforeInsert;

    /** @var bool */
    public $throwOnFindAll = false;

    /** @var int[] PrestaShop customers deleted or soft-deleted */
    public $deletedCustomers = [];

    /** @var int */
    private $findCalls = 0;

    public function seed(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): void
    {
        $this->rows[self::key($idCustomer, $accountId, $mode)] = $moneiCustomerId;
    }

    public function get(int $idCustomer, string $accountId, string $mode): ?string
    {
        return $this->rows[self::key($idCustomer, $accountId, $mode)] ?? null;
    }

    public function findMapping(int $idCustomer, string $accountId, string $mode): ?string
    {
        ++$this->findCalls;
        if ($this->throwOnFindCall === $this->findCalls) {
            throw new \RuntimeException('read failed');
        }

        return $this->get($idCustomer, $accountId, $mode);
    }

    public function findAllForCustomer(int $idCustomer): array
    {
        if ($this->throwOnFindAll) {
            throw new \RuntimeException('read failed');
        }

        $rows = [];
        foreach ($this->rows as $key => $moneiCustomerId) {
            [$id, $accountId, $mode] = explode('|', $key);
            if ((int) $id === $idCustomer) {
                $rows[] = ['account_id' => $accountId, 'mode' => $mode, 'monei_customer_id' => $moneiCustomerId];
            }
        }

        return $rows;
    }

    public function insertMapping(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): bool
    {
        if ($this->beforeInsert) {
            ($this->beforeInsert)($this);
        }

        if ($this->insertOutcome === 'throw') {
            throw new \RuntimeException('insert failed');
        }

        if ($this->insertOutcome === false) {
            return false;
        }

        if (!$this->insertStoresNothing) {
            $this->rows += [self::key($idCustomer, $accountId, $mode) => $moneiCustomerId];
        }

        return true;
    }

    public function deleteMapping(int $idCustomer, string $accountId, string $mode): void
    {
        unset($this->rows[self::key($idCustomer, $accountId, $mode)]);
    }

    public function deleteMappingIfHolds(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): void
    {
        if ($this->get($idCustomer, $accountId, $mode) === $moneiCustomerId) {
            $this->deleteMapping($idCustomer, $accountId, $mode);
        }
    }

    public function platformCustomerExists(int $idCustomer): bool
    {
        return !in_array($idCustomer, $this->deletedCustomers, true);
    }

    private static function key(int $idCustomer, string $accountId, string $mode): string
    {
        return $idCustomer . '|' . $accountId . '|' . $mode;
    }
}
