<?php

declare(strict_types=1);

namespace PsMonei\Service\Monei;

/**
 * Which payment methods survive the configured transaction type.
 *
 * Pure logic, deliberately free of Configuration and Context so it can be unit
 * tested without booting PrestaShop.
 *
 * MB WAY and Multibanco cannot be pre-authorized. When a merchant sets the module
 * to `auth` those two are removed from the storefront entirely — they do not fall
 * back to an immediate charge, they simply stop being offered. That is deliberate,
 * but it used to happen in silence, which is how a merchant loses two payment
 * methods and only finds out from a customer.
 */
class PaymentMethodAvailability
{
    /**
     * Methods that cannot be pre-authorized.
     *
     * @var string[]
     */
    public const UNSUPPORTED_AUTH_METHODS = ['mbway', 'multibanco'];

    /**
     * Remove methods the transaction type cannot serve.
     *
     * @param string[] $enabledMethods Methods the merchant switched on
     * @param string   $paymentAction  'auth' or 'sale'
     *
     * @return string[]
     */
    public static function filter(array $enabledMethods, string $paymentAction): array
    {
        if ($paymentAction !== 'auth') {
            return array_values($enabledMethods);
        }

        return array_values(array_diff($enabledMethods, self::UNSUPPORTED_AUTH_METHODS));
    }

    /**
     * Methods the merchant enabled that the transaction type then hides.
     *
     * This is what the settings screen warns about, so the loss is visible where
     * the decision is made rather than discovered later.
     *
     * @param string[] $enabledMethods Methods the merchant switched on
     * @param string   $paymentAction  'auth' or 'sale'
     *
     * @return string[]
     */
    public static function hiddenBy(array $enabledMethods, string $paymentAction): array
    {
        if ($paymentAction !== 'auth') {
            return [];
        }

        return array_values(array_intersect($enabledMethods, self::UNSUPPORTED_AUTH_METHODS));
    }
}
