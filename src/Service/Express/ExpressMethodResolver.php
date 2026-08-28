<?php

declare(strict_types=1);

namespace PsMonei\Service\Express;

/**
 * Which express payment methods may render, and where.
 *
 * Pure logic, free of Configuration and Context so it can be unit tested without
 * booting PrestaShop.
 *
 * A method has to clear three independent gates:
 *   1. the merchant enabled it for express checkout,
 *   2. the merchant enabled the payment method at all, and
 *   3. the MONEI account actually offers it.
 *
 * The second gate matters: turning PayPal off under Payment methods must also
 * remove the express PayPal button. Express settings widen nothing.
 */
class ExpressMethodResolver
{
    /**
     * Express methods the module knows about.
     *
     * @var string[]
     */
    public const SUPPORTED_METHODS = ['applePay', 'googlePay', 'paypal'];

    /**
     * Surfaces express buttons can render on.
     *
     * @var string[]
     */
    public const SUPPORTED_LOCATIONS = ['product', 'cart', 'checkout'];

    /**
     * Expand a stored comma separated list.
     *
     * @param string $configured Comma separated value
     *
     * @return string[]
     */
    public static function parseList(string $configured): array
    {
        $values = array_map('trim', explode(',', $configured));

        return array_values(array_filter($values, static function ($value) {
            return $value !== '';
        }));
    }

    /**
     * Is express checkout switched on for this surface?
     *
     * @param string $location             Surface being rendered
     * @param bool   $expressEnabled       Master switch
     * @param string $configuredLocations  Comma separated locations
     */
    public static function isLocationEnabled(
        string $location,
        bool $expressEnabled,
        string $configuredLocations
    ): bool {
        if (!$expressEnabled || !in_array($location, self::SUPPORTED_LOCATIONS, true)) {
            return false;
        }

        return in_array($location, self::parseList($configuredLocations), true);
    }

    /**
     * Methods that may render, in a stable order.
     *
     * @param string   $configuredMethods Comma separated express methods
     * @param string[] $allowedByMerchant Methods enabled under Payment methods
     * @param string[] $offeredByAccount  Methods the MONEI account offers
     *
     * @return string[]
     */
    public static function resolve(
        string $configuredMethods,
        array $allowedByMerchant,
        array $offeredByAccount
    ): array {
        $wanted = self::parseList($configuredMethods);
        $resolved = [];

        // Iterating the supported list rather than the configured one keeps the
        // rendering order stable regardless of how the setting was saved.
        foreach (self::SUPPORTED_METHODS as $method) {
            if (
                in_array($method, $wanted, true)
                && in_array($method, $allowedByMerchant, true)
                && in_array($method, $offeredByAccount, true)
            ) {
                $resolved[] = $method;
            }
        }

        return $resolved;
    }
}
