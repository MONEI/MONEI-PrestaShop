<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service\Fake;

use PsMonei\Repository\ConfigurationStoreInterface;

/**
 * Reads like Configuration::get: the shop's own row, else its group's, else the
 * row of no shop. So a delete where an empty value belongs shows as an inherited
 * value.
 */
final class InMemoryConfigurationStore implements ConfigurationStoreInterface
{
    /** @var array<string, string> "<idShopGroup>:<idShop>:<key>" => value */
    public $values = [];

    /** @var array<string, string> */
    public $globals = [];

    /** @var string[] Rows that another request deletes first: this delete removes nothing */
    public $lostDeletes = [];

    public function get(string $key, int $idShopGroup, int $idShop): string
    {
        if ($idShop && isset($this->values["0:$idShop:$key"])) {
            return $this->values["0:$idShop:$key"];
        }
        if ($idShopGroup && isset($this->values["$idShopGroup:0:$key"])) {
            return $this->values["$idShopGroup:0:$key"];
        }

        return $this->values["0:0:$key"] ?? '';
    }

    public function set(string $key, string $value, int $idShopGroup, int $idShop): void
    {
        // As Configuration::updateValue: a shop row is keyed by the shop alone.
        $this->values[($idShop ? '0' : (string) $idShopGroup) . ':' . $idShop . ':' . $key] = $value;
    }

    public function getGlobal(string $key): ?string
    {
        return $this->globals[$key] ?? null;
    }

    public function setGlobal(string $key, string $value): void
    {
        $this->globals[$key] = $value;
    }

    public function deleteGlobal(string $key): bool
    {
        $existed = array_key_exists($key, $this->globals);
        unset($this->globals[$key]);

        return $existed && !in_array($key, $this->lostDeletes, true);
    }

    public function globalNamesWithPrefix(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->globals), static function ($name) use ($prefix) {
            return strpos($name, $prefix) === 0;
        }));
    }
}
