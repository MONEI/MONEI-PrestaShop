<?php

namespace PsMonei\Service\Monei;

use Monei\ApiException;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\PaymentCustomer;

/**
 * Puts customerId on a payment and retries once when MONEI no longer knows it.
 *
 * Pure on purpose, so the unit suite can load it.
 */
final class PaymentCustomerLink
{
    /**
     * ⚠️ A PrestaShop guest has an id_customer too (is_guest = 1), and express checkout
     * creates guest rows. Guests and soft-deleted customers are never linked: core
     * keeps a soft-deleted customer's session alive, and linking would create a new
     * MONEI customer after an erasure.
     *
     * Catches \Throwable: the caller catches only \Exception, so an \Error here would
     * break checkout.
     */
    public static function link(
        CustomerService $customers,
        CreatePaymentRequest $request,
        int $idShop,
        int $idCustomer,
        bool $isGuest,
        bool $isDeleted,
        PaymentCustomer $data
    ): void {
        try {
            if ($isGuest || $isDeleted || $idCustomer <= 0) {
                return;
            }

            $customerId = $customers->getOrCreateCustomerId(
                $idShop,
                $idCustomer,
                (string) $data->getEmail(),
                (string) $data->getName(),
                (string) $data->getPhone()
            );

            if ($customerId !== null && $customerId !== '') {
                $request->setCustomerId($customerId);
            }
        } catch (\Throwable $e) {
            $customers->log('Linking the payment failed: ' . $e->getMessage(), CustomerService::ERROR);
        }
    }

    /**
     * Runs $create, and once more without customerId when MONEI rejects the customer
     * as deleted. Every other error is rethrown unchanged.
     *
     * @param callable(CreatePaymentRequest): mixed $create
     *
     * @return mixed what $create returns
     */
    public static function create(
        CustomerService $customers,
        CreatePaymentRequest $request,
        int $idShop,
        int $idCustomer,
        callable $create
    ) {
        $linkedId = $request->offsetExists('customer_id') ? (string) $request->getCustomerId() : '';

        try {
            return $create($request);
        } catch (ApiException $e) {
            if ($linkedId === '' || !self::isStaleCustomer($e)) {
                throw $e;
            }
        }

        $customers->log('MONEI customer ' . $linkedId . ' no longer exists, retrying the payment without it', CustomerService::WARNING);
        $customers->forgetCustomerId($idShop, $idCustomer, $linkedId);

        // Not setCustomerId(null), which throws, and not offsetUnset(), which leaves
        // the getter reading a missing key while the request is serialized.
        $request->offsetSet('customer_id', null);

        return $create($request);
    }

    /**
     * MONEI rejects a deleted customer at create with 404 and this exact message. The
     * status alone is not enough: a 404 also means other things.
     */
    public static function isStaleCustomer(ApiException $e): bool
    {
        if ($e->getCode() !== 404) {
            return false;
        }

        $body = $e->getResponseBody();
        if (is_string($body)) {
            $body = json_decode($body);
        }

        return is_object($body) && isset($body->message) && $body->message === 'Customer not found';
    }
}
