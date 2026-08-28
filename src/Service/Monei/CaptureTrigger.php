<?php

declare(strict_types=1);

namespace PsMonei\Service\Monei;

/**
 * Whether an order reaching a given state should capture its pre-authorization.
 *
 * Pure logic, free of Configuration and Context so it can be unit tested without
 * booting PrestaShop.
 *
 * Deliberately small. Every guard that matters — the payment exists, it is
 * AUTHORIZED, it is not captured already, the amount is sane — is enforced by
 * MoneiService::capturePayment, and duplicating those here would mean two places
 * to keep in step.
 */
class CaptureTrigger
{
    /**
     * Parse the configured trigger states.
     *
     * ⚠️ These are order state ids, which are per install. Uninstalling the module
     * deletes its order states and a reinstall reissues their ids, so a value that
     * survived that cycle would point at unrelated states. Uninstall clears the
     * setting for exactly that reason.
     *
     * @param string $configured Comma separated order state ids
     *
     * @return int[]
     */
    public static function parseStates(string $configured): array
    {
        $ids = [];

        foreach (explode(',', $configured) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && ctype_digit($candidate)) {
                $ids[] = (int) $candidate;
            }
        }

        return $ids;
    }

    /**
     * Should reaching this state capture the payment?
     *
     * @param string $orderModule  Module the order was paid with
     * @param string $moduleName   This module's name
     * @param int    $newStateId   State the order has just reached
     * @param string $configured   Comma separated trigger state ids
     */
    public static function shouldCapture(
        string $orderModule,
        string $moduleName,
        int $newStateId,
        string $configured
    ): bool {
        if ($orderModule !== $moduleName) {
            return false;
        }

        // No configured states means automatic capture is off, which is the
        // default. A merchant opts in by choosing states.
        return in_array($newStateId, self::parseStates($configured), true);
    }
}
