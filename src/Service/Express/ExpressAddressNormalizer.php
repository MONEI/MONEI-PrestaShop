<?php

declare(strict_types=1);

namespace PsMonei\Service\Express;

/**
 * Turn a wallet's address payload into something PrestaShop will accept.
 *
 * Pure logic, free of Context and Db so it can be unit tested without booting
 * PrestaShop.
 *
 * ⚠️ PayPal returns a partial address — name, email and country, with no street,
 * city or postcode — when the PayPal account has no address saved. This is a
 * property of the account, not of `requestShipping`; it was tested both ways in
 * the WooCommerce round. A flow that builds the order itself accepts it, but
 * PrestaShop validates `Address` strictly and rejects it outright.
 *
 * Rather than fail the payment after the shopper has already approved it, the
 * missing parts are filled with an explicit placeholder. The order is created, the
 * merchant can see at a glance that the address needs completing, and the money is
 * not lost to a validation error the shopper cannot act on.
 */
class ExpressAddressNormalizer
{
    /**
     * Marks a field the wallet did not provide.
     */
    public const PLACEHOLDER = '-';

    /**
     * Normalize a wallet address payload.
     *
     * @param array $payload Wallet supplied address
     *
     * @return array{firstName: string, lastName: string, address1: string, city: string, postcode: string, countryIso: string, phone: string, incomplete: bool}
     */
    public static function normalize(array $payload): array
    {
        $name = self::splitName((string) self::pick($payload, ['name', 'fullName']));

        $address1 = self::text($payload, ['address1', 'addressLine1', 'line1', 'street']);
        $city = self::text($payload, ['city', 'locality']);
        $postcode = self::text($payload, ['zip', 'postalCode', 'postcode']);
        $countryIso = strtoupper(self::text($payload, ['country', 'countryCode']));

        // Only the parts PrestaShop requires count towards "incomplete". A missing
        // phone number is normal and must not flag the address.
        $incomplete = $address1 === '' || $city === '' || $postcode === '';

        return [
            'firstName' => $name[0] !== '' ? $name[0] : self::PLACEHOLDER,
            'lastName' => $name[1] !== '' ? $name[1] : self::PLACEHOLDER,
            'address1' => $address1 !== '' ? $address1 : self::PLACEHOLDER,
            'city' => $city !== '' ? $city : self::PLACEHOLDER,
            'postcode' => $postcode,
            'countryIso' => $countryIso,
            'phone' => self::text($payload, ['phone', 'phoneNumber']),
            'incomplete' => $incomplete,
        ];
    }

    /**
     * Split a single name field into first and last.
     *
     * @param string $name Full name
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));

        if (!$parts) {
            return ['', ''];
        }

        if (count($parts) === 1) {
            // A wallet that returns only "Ada" still has to produce two fields,
            // both of which PrestaShop requires.
            return [$parts[0], $parts[0]];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }

    /**
     * First non-empty value among several possible keys.
     *
     * Wallets disagree on names: Apple Pay, Google Pay and PayPal each spell the
     * same field differently.
     *
     * @param array    $payload Payload
     * @param string[] $keys    Candidate keys
     */
    private static function text(array $payload, array $keys): string
    {
        return trim((string) self::pick($payload, $keys));
    }

    /**
     * @param array    $payload Payload
     * @param string[] $keys    Candidate keys
     *
     * @return mixed
     */
    private static function pick(array $payload, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return '';
    }
}
