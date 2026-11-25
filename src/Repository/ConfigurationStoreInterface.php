<?php

namespace PsMonei\Repository;

/**
 * The configuration values that ConnectService and CallbackVerifier read and write.
 *
 * An interface, so the unit suite runs both classes without PrestaShop. A shop
 * value is read and written at a scope: a shop group id and a shop id, where 0
 * means none, as Configuration::updateValue reads them.
 */
interface ConfigurationStoreInterface
{
    /**
     * @return string The value, or '' when it is not set
     */
    public function get(string $key, int $idShopGroup, int $idShop): string;

    public function set(string $key, string $value, int $idShopGroup, int $idShop): void;

    /**
     * A row of no shop, read past every cache.
     */
    public function getGlobal(string $key): ?string;

    public function setGlobal(string $key, string $value): void;

    /**
     * @return bool True only when this call removed the row
     */
    public function deleteGlobal(string $key): bool;

    /**
     * @return string[] Names of the rows that start with the prefix
     */
    public function globalNamesWithPrefix(string $prefix): array;
}
