<?php

namespace PsMonei\Repository;

/**
 * Mapping of a PrestaShop customer to a MONEI customer, per MONEI account and mode.
 *
 * Reads throw on a database error: "the read failed" must never look like "no
 * mapping", or a failed read creates a second MONEI customer.
 */
interface CustomerMappingStoreInterface
{
    public function findMapping(int $idCustomer, string $accountId, string $mode): ?string;

    /**
     * @return array<int, array{account_id: string, mode: string, monei_customer_id: string}>
     */
    public function findAllForCustomer(int $idCustomer): array;

    /**
     * Inserts unless the (customer, account, mode) key exists. Returns true also when
     * an existing row made the insert a no-op; false when nothing could be written.
     */
    public function insertMapping(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): bool;

    public function deleteMapping(int $idCustomer, string $accountId, string $mode): void;

    /**
     * Deletes the mapping only while it still holds $moneiCustomerId, so a newer ID
     * stored by another request is kept.
     */
    public function deleteMappingIfHolds(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): void;

    /**
     * Whether the PrestaShop customer still exists and is not soft-deleted, read
     * fresh: a delete may have run while a MONEI customer was being created.
     */
    public function platformCustomerExists(int $idCustomer): bool;
}
