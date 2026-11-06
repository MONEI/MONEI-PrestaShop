<?php

namespace PsMonei\Service\Monei;

/**
 * Reads the actionDeleteGDPRCustomer payload of either psgdpr line.
 *
 *   Erasure    psgdpr 1.4 (bundled up to PS 9)          psgdpr 2.x
 *   by ID      (array) $customer: ['id' => int, ...]    ['<id>']
 *   by email   ['email' => ...]                         [['email' => ...]]
 *   by phone   -                                        [['phone' => ...]]
 *
 * Pure on purpose, so the unit suite can load it.
 */
final class GdprPayload
{
    /**
     * @param mixed $params
     *
     * @return array{id?: int, email?: string} empty when the payload names nobody
     */
    public static function parse($params): array
    {
        if (!is_array($params)) {
            return [];
        }

        // The 1.4 by-ID shape also carries the customer's email. The ID decides:
        // erasing by that email could reach other customers who share it.
        if (array_key_exists('id', $params)) {
            $id = self::toId($params['id']);

            return $id === null ? [] : ['id' => $id];
        }

        if (isset($params[0]) && is_scalar($params[0])) {
            $id = self::toId($params[0]);

            return $id === null ? [] : ['id' => $id];
        }

        $email = $params['email'] ?? (isset($params[0]) && is_array($params[0]) ? ($params[0]['email'] ?? null) : null);

        return is_string($email) && $email !== '' ? ['email' => $email] : [];
    }

    /**
     * @param mixed $value
     */
    private static function toId($value): ?int
    {
        // ctype_digit() reads an int from -128 to 255 as a character code, so an int
        // is checked as an int.
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
