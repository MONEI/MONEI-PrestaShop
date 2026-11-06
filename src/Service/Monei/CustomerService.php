<?php

namespace PsMonei\Service\Monei;

use Monei\Api\CustomersApi;
use Monei\ApiException;
use Monei\Model\CreateCustomerRequest;
use PsMonei\Repository\CustomerMappingStoreInterface;

/**
 * Links PrestaShop customers to MONEI customers and deletes them at MONEI.
 *
 * Implements docs/plans/20261105-monei-customers-api-contract.md. Every method is
 * fail-open: it logs and never throws, so a Customers API problem never stops a
 * checkout, a card delete or a customer delete.
 *
 * Pure on purpose: no PrestaShop classes, so the unit suite can load it. Settings,
 * clients and logging come in as callables built by Monei::buildCustomerService().
 */
final class CustomerService
{
    public const DEBUG = 1;
    public const WARNING = 2;
    public const ERROR = 3;

    /** @var CustomerMappingStoreInterface */
    private $store;

    /** @var callable(int): array{accountId: string, mode: string, apiKey: string} */
    private $accountForShop;

    /** @var callable(): array<int, array{accountId: string, mode: string, apiKeys: string[]}> */
    private $configuredAccounts;

    /** @var callable(string): CustomersApi */
    private $customersApi;

    /** @var callable(string, int): void */
    private $log;

    public function __construct(
        CustomerMappingStoreInterface $store,
        callable $accountForShop,
        callable $configuredAccounts,
        callable $customersApi,
        callable $log
    ) {
        $this->store = $store;
        $this->accountForShop = $accountForShop;
        $this->configuredAccounts = $configuredAccounts;
        $this->customersApi = $customersApi;
        $this->log = $log;
    }

    /**
     * An empty string for email, name or phone leaves that field out of the request:
     * the SDK setters throw on null.
     */
    public function getOrCreateCustomerId(int $idShop, int $idCustomer, string $email, string $name, string $phone): ?string
    {
        try {
            $account = ($this->accountForShop)($idShop);
            if ($account['accountId'] === '' || $account['apiKey'] === '') {
                $this->log('No MONEI account ID or API key for shop ' . $idShop . ', payment not linked', self::DEBUG);

                return null;
            }

            try {
                $stored = $this->store->findMapping($idCustomer, $account['accountId'], $account['mode']);
            } catch (\Throwable $e) {
                // Most likely the upgrade has not created the table yet. Creating a
                // customer now would only leave it unmapped.
                $this->log('Reading the mapping failed, payment not linked: ' . $e->getMessage(), self::ERROR);

                return null;
            }

            if ($stored !== null) {
                return $stored;
            }

            $api = ($this->customersApi)($account['apiKey']);
            $newId = (string) $api->create($this->createRequest($idCustomer, $email, $name, $phone))->getId();

            return $this->storeNewCustomer($api, $idCustomer, $account['accountId'], $account['mode'], $newId);
        } catch (\Throwable $e) {
            $this->log('Creating the MONEI customer failed for customer ' . $idCustomer . ': ' . $e->getMessage(), self::ERROR);

            return null;
        }
    }

    public function forgetCustomerId(int $idShop, int $idCustomer, string $staleCustomerId): void
    {
        try {
            $account = ($this->accountForShop)($idShop);
            if ($account['accountId'] === '') {
                return;
            }

            $this->store->deleteMappingIfHolds($idCustomer, $account['accountId'], $account['mode'], $staleCustomerId);
        } catch (\Throwable $e) {
            $this->log('Forgetting MONEI customer ' . $staleCustomerId . ' failed: ' . $e->getMessage(), self::ERROR);
        }
    }

    /**
     * Never removes the mapping, whatever the result: the customer still exists.
     */
    public function deletePaymentMethod(int $idShop, int $idCustomer, string $paymentToken): void
    {
        try {
            $account = ($this->accountForShop)($idShop);
            if ($account['accountId'] === '' || $account['apiKey'] === '') {
                return;
            }

            $moneiCustomerId = $this->store->findMapping($idCustomer, $account['accountId'], $account['mode']);
            if ($moneiCustomerId === null) {
                return;
            }

            $where = $this->describe($moneiCustomerId, $account['accountId'], $account['mode']);

            try {
                ($this->customersApi)($account['apiKey'])->deletePaymentMethod($moneiCustomerId, $paymentToken);
            } catch (ApiException $e) {
                if ($e->getCode() === 404) {
                    // A card saved before the link was never attached to the customer.
                    $this->log('Payment method not found at MONEI ' . $where, self::DEBUG);
                } elseif ($e->getCode() === 409) {
                    $this->log('Payment method is in use, not deleted ' . $where, self::WARNING);
                } else {
                    $this->log('Deleting the payment method failed ' . $where . ': ' . $e->getMessage(), self::ERROR);
                }
            }
        } catch (\Throwable $e) {
            $this->log('Deleting the payment method failed for customer ' . $idCustomer . ': ' . $e->getMessage(), self::ERROR);
        }
    }

    /**
     * Deletes the customer at MONEI under every account and mode it was linked in.
     *
     * Returns true when no mapping is left, so the GDPR hook can tell psgdpr whether
     * the erasure reached MONEI. This module has no subscriptions, so the contract's
     * step 0 (keep a customer whose sequences still renew) does not apply.
     */
    public function deleteCustomer(int $idCustomer): bool
    {
        try {
            $rows = $this->store->findAllForCustomer($idCustomer);
        } catch (\Throwable $e) {
            $this->log('Reading the mappings of customer ' . $idCustomer . ' failed: ' . $e->getMessage(), self::ERROR);

            return false;
        }

        if (!$rows) {
            return true;
        }

        try {
            $accounts = ($this->configuredAccounts)();
        } catch (\Throwable $e) {
            $this->log('Reading the configured MONEI accounts failed: ' . $e->getMessage(), self::ERROR);

            return false;
        }

        $allGone = true;

        foreach ($rows as $row) {
            $where = $this->describe($row['monei_customer_id'], $row['account_id'], $row['mode']);

            try {
                $key = self::keyFor($accounts, $row['account_id'], $row['mode']);
                if ($key === null) {
                    $this->log('No configured API key matches, MONEI customer not deleted ' . $where, self::ERROR);
                    $allGone = false;

                    continue;
                }

                try {
                    ($this->customersApi)($key)->delete($row['monei_customer_id']);
                } catch (ApiException $e) {
                    if ($e->getCode() !== 404) {
                        $this->log('Deleting the MONEI customer failed ' . $where . ': ' . $e->getMessage(), self::ERROR);
                        $allGone = false;

                        continue;
                    }

                    $this->log('MONEI customer already gone ' . $where, self::DEBUG);
                }

                $this->store->deleteMapping($idCustomer, $row['account_id'], $row['mode']);
            } catch (\Throwable $e) {
                $this->log('Deleting the MONEI customer failed ' . $where . ': ' . $e->getMessage(), self::ERROR);
                $allGone = false;
            }
        }

        return $allGone;
    }

    /**
     * Groups per-shop settings by (account ID, mode).
     *
     * An entry needs a non-empty account ID and key. Keys of active shops come first,
     * whatever the input order, and the first key is the one deleteCustomer uses:
     * an inactive shop is likelier to hold a stale key.
     *
     * @param array<int, array{accountId: string, mode: string, apiKey: string, active: bool}> $shopSettings
     *
     * @return array<int, array{accountId: string, mode: string, apiKeys: string[]}>
     */
    public static function mergeAccounts(array $shopSettings): array
    {
        $merged = [];

        foreach ([true, false] as $active) {
            foreach ($shopSettings as $setting) {
                if ((bool) $setting['active'] !== $active || $setting['accountId'] === '' || $setting['apiKey'] === '') {
                    continue;
                }

                $id = $setting['accountId'] . "\0" . $setting['mode'];
                if (!isset($merged[$id])) {
                    $merged[$id] = ['accountId' => $setting['accountId'], 'mode' => $setting['mode'], 'apiKeys' => []];
                }

                if (!in_array($setting['apiKey'], $merged[$id]['apiKeys'], true)) {
                    $merged[$id]['apiKeys'][] = $setting['apiKey'];
                }
            }
        }

        return array_values($merged);
    }

    public function log(string $message, int $severity): void
    {
        ($this->log)('[MONEI Customers] ' . $message, $severity);
    }

    /**
     * Matches on the row's own mode: test and live often share one account ID, and a
     * live row deleted with the test key gets 404, which would drop the row while the
     * live customer stays at MONEI.
     */
    private static function keyFor(array $accounts, string $accountId, string $mode): ?string
    {
        foreach ($accounts as $account) {
            if ($account['accountId'] === $accountId && $account['mode'] === $mode && $account['apiKeys']) {
                return $account['apiKeys'][0];
            }
        }

        return null;
    }

    private function storeNewCustomer(CustomersApi $api, int $idCustomer, string $accountId, string $mode, string $newId): ?string
    {
        try {
            $inserted = $this->store->insertMapping($idCustomer, $accountId, $mode, $newId);
        } catch (\Throwable $e) {
            $inserted = false;
        }

        if (!$inserted) {
            $this->log('Saving the mapping failed ' . $this->describe($newId, $accountId, $mode), self::ERROR);
            $this->deleteUnmapped($api, $newId, $accountId, $mode);

            return null;
        }

        try {
            $stored = $this->store->findMapping($idCustomer, $accountId, $mode);
        } catch (\Throwable $e) {
            // The insert may have stored this ID, so it is not deleted. If another
            // request won instead, this line is the only record of the new customer.
            $this->log('Re-reading the mapping failed ' . $this->describe($newId, $accountId, $mode) . ': ' . $e->getMessage(), self::ERROR);

            return null;
        }

        if ($stored === null) {
            $this->log('Saving the mapping stored nothing ' . $this->describe($newId, $accountId, $mode), self::ERROR);
            $this->deleteUnmapped($api, $newId, $accountId, $mode);

            return null;
        }

        if ($stored !== $newId) {
            // Another request created and stored a customer first.
            $this->deleteUnmapped($api, $newId, $accountId, $mode);

            return $stored;
        }

        // A delete that ran while MONEI was creating the customer found no mapping
        // to act on. PrestaShop fires the delete hooks after the row is deleted or
        // flagged, so a fresh read here sees every delete whose hook missed the row.
        try {
            $exists = $this->store->platformCustomerExists($idCustomer);
        } catch (\Throwable $e) {
            $this->log('Checking customer ' . $idCustomer . ' after the save failed ' . $this->describe($newId, $accountId, $mode) . ': ' . $e->getMessage(), self::ERROR);

            return null;
        }

        if (!$exists) {
            $this->deleteUnmapped($api, $newId, $accountId, $mode);

            try {
                $this->store->deleteMappingIfHolds($idCustomer, $accountId, $mode, $newId);
            } catch (\Throwable $e) {
                $this->log('Removing the mapping of deleted customer ' . $idCustomer . ' failed ' . $this->describe($newId, $accountId, $mode) . ': ' . $e->getMessage(), self::ERROR);
            }

            return null;
        }

        return $newId;
    }

    private function deleteUnmapped(CustomersApi $api, string $moneiCustomerId, string $accountId, string $mode): void
    {
        try {
            $api->delete($moneiCustomerId);
        } catch (\Throwable $e) {
            $this->log('Deleting an unmapped MONEI customer failed ' . $this->describe($moneiCustomerId, $accountId, $mode) . ': ' . $e->getMessage(), self::ERROR);
        }
    }

    private function createRequest(int $idCustomer, string $email, string $name, string $phone): CreateCustomerRequest
    {
        $request = new CreateCustomerRequest();

        if ($email !== '') {
            $request->setEmail($email);
        }

        if (trim($name) !== '') {
            $request->setName(trim($name));
        }

        if ($phone !== '') {
            $request->setPhone($phone);
        }

        $request->setMetadata((object) [
            'platform' => 'prestashop',
            'platformCustomerId' => (string) $idCustomer,
        ]);

        return $request;
    }

    private function describe(string $moneiCustomerId, string $accountId, string $mode): string
    {
        return '[monei_customer_id=' . $moneiCustomerId . ', account_id=' . $accountId . ', mode=' . $mode . ']';
    }
}
