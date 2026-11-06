<?php

namespace PsMonei\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * `monei2_customer` through Db, not Doctrine.
 *
 * ⚠️ Not Doctrine: the customer delete hooks fire from cron, webservice and CLI
 * contexts where the module's repositories are unavailable, and a duplicate-key
 * flush would close the EntityManager that the payment save in the same request
 * still needs.
 *
 * ⚠️ Every read passes `$use_cache = false`. With _PS_CACHE_ENABLED_, the re-read
 * after a lost insert race would otherwise return the cached empty first read,
 * and the caller would delete its own new customer instead of using the winner's.
 */
class CustomerMappingStore implements CustomerMappingStoreInterface
{
    private const TABLE = 'monei2_customer';

    public function findMapping(int $idCustomer, string $accountId, string $mode): ?string
    {
        $rows = $this->select(
            'SELECT `monei_customer_id` FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE ' . $this->key($idCustomer, $accountId, $mode) . ' LIMIT 1'
        );

        return $rows ? (string) $rows[0]['monei_customer_id'] : null;
    }

    public function findAllForCustomer(int $idCustomer): array
    {
        return $this->select(
            'SELECT `account_id`, `mode`, `monei_customer_id` FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE `id_customer` = ' . (int) $idCustomer
        );
    }

    public function insertMapping(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): bool
    {
        // Db::insert() quotes the values but does not escape them.
        return (bool) \Db::getInstance()->insert(
            self::TABLE,
            [
                'id_customer' => (int) $idCustomer,
                'account_id' => pSQL($accountId),
                'mode' => pSQL($mode),
                'monei_customer_id' => pSQL($moneiCustomerId),
                'date_add' => date('Y-m-d H:i:s'),
            ],
            false,
            false,
            \Db::INSERT_IGNORE
        );
    }

    public function deleteMapping(int $idCustomer, string $accountId, string $mode): void
    {
        $this->delete($this->key($idCustomer, $accountId, $mode));
    }

    public function deleteMappingIfHolds(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): void
    {
        $this->delete(
            $this->key($idCustomer, $accountId, $mode)
            . ' AND `monei_customer_id` = \'' . pSQL($moneiCustomerId) . '\''
        );
    }

    public function platformCustomerExists(int $idCustomer): bool
    {
        return (bool) $this->select(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . (int) $idCustomer . ' AND `deleted` = 0'
        );
    }

    private function key(int $idCustomer, string $accountId, string $mode): string
    {
        return '`id_customer` = ' . (int) $idCustomer
            . ' AND `account_id` = \'' . pSQL($accountId) . '\''
            . ' AND `mode` = \'' . pSQL($mode) . '\'';
    }

    private function select(string $sql): array
    {
        $rows = \Db::getInstance()->executeS($sql, true, false);
        if (!is_array($rows)) {
            throw new \RuntimeException('Reading ' . self::TABLE . ' failed');
        }

        return $rows;
    }

    private function delete(string $where): void
    {
        if (!\Db::getInstance()->delete(self::TABLE, $where, 0, false)) {
            throw new \RuntimeException('Deleting from ' . self::TABLE . ' failed');
        }
    }
}
