<?php

namespace PsMonei\Service\Monei;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\Model\CreatePaymentRequest;
use Monei\Model\Payment;
use Monei\Model\PaymentBillingDetails;
use Monei\Model\PaymentCustomer;
use Monei\Model\PaymentTransactionType;
use Monei\Model\RefundPaymentRequest;
use Monei\Model\CapturePaymentRequest;
use Monei\MoneiClient;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PsMonei\Entity\Monei2CustomerCard;
use PsMonei\Entity\Monei2History;
use PsMonei\Entity\Monei2Payment;
use PsMonei\Entity\Monei2Refund;
use PsMonei\Exception\MoneiException;
use PsMonei\Repository\MoneiCustomerCardRepository;
use PsMonei\Repository\MoneiPaymentRepository;
use PsMonei\Repository\MoneiRefundRepository;
use PsMonei\Repository\MoneiHistoryRepository;

class MoneiService
{
    // Payment methods that do not support AUTH transaction type
    const UNSUPPORTED_AUTH_METHODS = ['mbway', 'multibanco'];
    
    private $legacyContext;
    private $moneiPaymentRepository;
    private $moneiCustomerCardRepository;
    private $moneiRefundRepository;
    private $moneiHistoryRepository;

    /**
     * Static cache of payment methods to avoid repeated API calls within a single request
     *
     * @var array<string, array{data: array, timestamp: int}>
     */
    private static $paymentMethodsCache = [];

    /**
     * Cache lifetime in seconds (1 minute)
     */
    private const CACHE_LIFETIME = 60;

    public function __construct(
        LegacyContext $legacyContext,
        MoneiPaymentRepository $moneiPaymentRepository,
        MoneiCustomerCardRepository $moneiCustomerCardRepository,
        MoneiRefundRepository $moneiRefundRepository,
        MoneiHistoryRepository $moneiHistoryRepository
    ) {
        $this->legacyContext = $legacyContext;
        $this->moneiPaymentRepository = $moneiPaymentRepository;
        $this->moneiCustomerCardRepository = $moneiCustomerCardRepository;
        $this->moneiRefundRepository = $moneiRefundRepository;
        $this->moneiHistoryRepository = $moneiHistoryRepository;
    }

    public function getMoneiClient()
    {
        if ((bool) \Configuration::get('MONEI_PRODUCTION_MODE')) {
            $apiKey = \Configuration::get('MONEI_API_KEY');
        } else {
            $apiKey = \Configuration::get('MONEI_TEST_API_KEY');
        }

        if (!$apiKey) {
            throw new MoneiException('Monei client not initialized.', MoneiException::MONEI_CLIENT_NOT_INITIALIZED);
        }

        $client = new MoneiClient($apiKey);
        $client->setUserAgent('MONEI/PrestaShop/' . _PS_VERSION_);
        
        return $client;
    }

    /**
     * Get full payment methods response from MONEI API
     *
     * @return \Monei\Model\PaymentMethods|null
     */
    public function getPaymentMethodsResponse()
    {
        try {
            $moneiClient = $this->getMoneiClient();

            if ((bool) \Configuration::get('MONEI_PRODUCTION_MODE')) {
                $accountId = \Configuration::get('MONEI_ACCOUNT_ID');
            } else {
                $accountId = \Configuration::get('MONEI_TEST_ACCOUNT_ID');
            }

            if (!$accountId) {
                throw new MoneiException('Monei account id is not set.', MoneiException::MONEI_ACCOUNT_ID_IS_EMPTY);
            }

            // Create cache key based on account ID
            $cacheKey = $accountId . '_response';
            $currentTime = time();

            // Return from static cache if already fetched and not expired
            if (isset(self::$paymentMethodsCache[$cacheKey])
                    && ($currentTime - self::$paymentMethodsCache[$cacheKey]['timestamp'] < self::CACHE_LIFETIME)) {
                \PrestaShopLogger::addLog('[MONEI] Using cached payment methods response', \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);

                return self::$paymentMethodsCache[$cacheKey]['data'];
            }

            $moneiAccountInformation = $moneiClient->paymentMethods->get($accountId);

            // Store in static cache with timestamp
            self::$paymentMethodsCache[$cacheKey] = [
                'data' => $moneiAccountInformation,
                'timestamp' => $currentTime,
            ];

            return $moneiAccountInformation;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('MONEI - getPaymentMethodsResponse - Error: ' . $e->getMessage(), \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING);

            return null;
        }
    }

    public function getPaymentMethodsAllowed()
    {
        $response = $this->getPaymentMethodsResponse();
        if ($response) {
            return $response->getPaymentMethods();
        }

        return [];
    }

    /**
     * Get available card brands from MONEI API
     *
     * @return array List of available card brands
     */
    public function getAvailableCardBrands(): array
    {
        try {
            $response = $this->getPaymentMethodsResponse();
            if (!$response) {
                return $this->getDefaultCardBrands();
            }

            $metadata = $response->getMetadata();
            if (!$metadata) {
                return $this->getDefaultCardBrands();
            }

            $card = $metadata->getCard();
            if (!$card) {
                return $this->getDefaultCardBrands();
            }

            $brands = $card->getBrands();
            if (!$brands || empty($brands)) {
                return $this->getDefaultCardBrands();
            }

            // Normalize brands to lowercase for consistency
            return array_map('strtolower', $brands);
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('MONEI - getAvailableCardBrands - Error: ' . $e->getMessage(), \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING);

            return $this->getDefaultCardBrands();
        }
    }

    /**
     * Get default card brands as fallback
     *
     * @return array Default card brands
     */
    private function getDefaultCardBrands(): array
    {
        return ['visa', 'mastercard', 'amex', 'discover', 'diners', 'jcb', 'unionpay', 'maestro'];
    }

    public function getMoneiPayment($moneiPaymentId)
    {
        $moneiClient = $this->getMoneiClient();

        if (!isset($moneiClient->payments)) {
            throw new MoneiException('Monei client payments not initialized.', MoneiException::MONEI_CLIENT_NOT_INITIALIZED);
        }

        return $moneiClient->payments->get($moneiPaymentId);
    }

    public function createMoneiOrderId(int $cartId)
    {
        $suffix = time() % 1000;

        return str_pad($cartId . 'm' . $suffix, 12, '0', STR_PAD_LEFT);
    }

    public function extractCartIdFromMoneiOrderId($moneiOrderId)
    {
        return (int) substr($moneiOrderId, 0, strpos($moneiOrderId, 'm'));
    }

    public function getCartAmount(array $cartSummaryDetails, int $currencyId, bool $withoutFormatting = false)
    {
        if (empty($cartSummaryDetails)) {
            throw new MoneiException('Cart summary details cannot be empty', MoneiException::CART_SUMMARY_DETAILS_EMPTY);
        }

        $totalPrice = $cartSummaryDetails['total_price_without_tax'] + $cartSummaryDetails['total_tax'];

        $currency = new \Currency($currencyId);
        if (!\Validate::isLoadedObject($currency)) {
            throw new MoneiException('Invalid currency ID provided', MoneiException::INVALID_CURRENCY_ID_PROVIDED);
        }

        $decimals = is_array($currency) ? (int) $currency['decimals'] : (int) $currency->decimals;
        $precisionMultiplier = $decimals * 2;

        $totalPriceRounded = \Tools::ps_round($totalPrice, $precisionMultiplier);

        return $withoutFormatting ? $totalPriceRounded : (int) number_format($totalPriceRounded, 2, '', '');
    }

    public function getCustomerData(\Customer $customer, int $cartAddressInvoiceId, $returnMoneiCustomerObject = false)
    {
        if (!\Validate::isLoadedObject($customer)) {
            throw new MoneiException('The customer could not be loaded correctly', MoneiException::CUSTOMER_NOT_FOUND);
        }

        $addressInvoice = new \Address((int) $cartAddressInvoiceId);
        if (!\Validate::isLoadedObject($addressInvoice)) {
            throw new MoneiException('The address could not be loaded correctly', MoneiException::ADDRESS_NOT_FOUND);
        }

        $customer->email = str_replace(':', '', $customer->email);

        $customerData = [
            'name' => $customer->firstname . ' ' . $customer->lastname,
            'email' => $customer->email,
            'phone' => $addressInvoice->phone_mobile ?: $addressInvoice->phone,
        ];

        return $returnMoneiCustomerObject ? new PaymentCustomer($customerData) : $customerData;
    }

    public function getAddressData(int $addressId, string $customerEmail, bool $returnMoneiBillingObject = false)
    {
        $address = new \Address((int) $addressId);
        if (!\Validate::isLoadedObject($address)) {
            throw new MoneiException('The address could not be loaded correctly', MoneiException::ADDRESS_NOT_FOUND);
        }

        $country = new \Country($address->id_country, (int) $this->legacyContext->getLanguage()->id);
        if (!\Validate::isLoadedObject($country)) {
            throw new MoneiException('The country could not be loaded correctly', MoneiException::COUNTRY_NOT_FOUND);
        }

        $state = new \State((int) $address->id_state, (int) $this->legacyContext->getLanguage()->id);
        $stateName = $state->name ?: '';

        $billingData = [
            'name' => "{$address->firstname} {$address->lastname}",
            'email' => $customerEmail,
            'phone' => $address->phone_mobile ?: $address->phone,
            'company' => $address->company,
            'address' => [
                'line1' => $address->address1,
                'line2' => $address->address2,
                'zip' => $address->postcode,
                'city' => $address->city,
                'state' => $stateName,
                'country' => $country->iso_code,
            ],
        ];

        return $returnMoneiBillingObject ? new PaymentBillingDetails($billingData) : $billingData;
    }

    public function getPaymentMethodsAvailable()
    {
        $paymentMethods = [];

        $allowedMethods = [
            'MONEI_ALLOW_CARD' => 'card',
            'MONEI_ALLOW_BIZUM' => 'bizum',
            'MONEI_ALLOW_APPLE' => 'applePay',
            'MONEI_ALLOW_GOOGLE' => 'googlePay',
            'MONEI_ALLOW_PAYPAL' => 'paypal',
            'MONEI_ALLOW_MULTIBANCO' => 'multibanco',
            'MONEI_ALLOW_MBWAY' => 'mbway',
        ];

        foreach ($allowedMethods as $configKey => $method) {
            if (\Configuration::get($configKey)) {
                $paymentMethods[] = $method;
            }
        }

        return $paymentMethods;
    }

    public function getTotalRefundedByIdOrder(int $orderId)
    {
        $payment = $this->moneiPaymentRepository->findOneBy(['id_order' => $orderId]);
        if (!$payment) {
            return 0;
        }

        $totalRefunded = 0;
        foreach ($payment->getRefundList() as $refund) {
            $totalRefunded += $refund->getAmount();
        }

        return $totalRefunded;
    }

    public function saveMoneiPayment(Payment $moneiPayment, int $orderId = 0, int $employeeId = 0)
    {
        // Skip saving pending payments to history
        if ($moneiPayment->getStatus() === \Monei\Model\PaymentStatus::PENDING) {
            \PrestaShopLogger::addLog(
                'MONEI - saveMoneiPayment - Skipping pending payment: ' . $moneiPayment->getId(),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            return;
        }

        $cartId = $this->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());

        $monei2PaymentEntity = $this->moneiPaymentRepository->findOneById($moneiPayment->getId()) ?? new Monei2Payment();

        $monei2PaymentEntity->setId($moneiPayment->getId());
        $monei2PaymentEntity->setCartId($cartId);
        $monei2PaymentEntity->setOrderId($orderId);
        $monei2PaymentEntity->setOrderMoneiId($moneiPayment->getOrderId());
        $monei2PaymentEntity->setAmount($moneiPayment->getAmount());
        $monei2PaymentEntity->setRefundedAmount($moneiPayment->getRefundedAmount());
        $monei2PaymentEntity->setCurrency($moneiPayment->getCurrency());
        $monei2PaymentEntity->setAuthorizationCode($moneiPayment->getAuthorizationCode());
        $monei2PaymentEntity->setStatus($moneiPayment->getStatus());
        $monei2PaymentEntity->setStatusCode($moneiPayment->getStatusCode());
        $monei2PaymentEntity->setDateAdd($moneiPayment->getCreatedAt());
        $monei2PaymentEntity->setDateUpd($moneiPayment->getUpdatedAt());

        // Check if we should add a new history entry
        $shouldAddHistory = true;
        $currentStatus = $moneiPayment->getStatus();
        $currentStatusCode = $moneiPayment->getStatusCode();

        // Get existing history entries
        $historyList = $monei2PaymentEntity->getHistoryList();
        if ($historyList && count($historyList) > 0) {
            // Get the last history entry efficiently using Doctrine's last() method
            $lastHistory = $historyList->last();

            // Only add new history if status has changed
            if ($lastHistory && $lastHistory->getStatus() === $currentStatus && $lastHistory->getStatusCode() === $currentStatusCode) {
                $shouldAddHistory = false;
                \PrestaShopLogger::addLog(
                    'MONEI - saveMoneiPayment - Skipping duplicate history entry for payment: ' . $moneiPayment->getId()
                    . ' with status: ' . $currentStatus,
                    \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );
            }
        }

        if ($shouldAddHistory) {
            $monei2HistoryEntity = new Monei2History();
            $monei2HistoryEntity->setStatus($currentStatus);
            $monei2HistoryEntity->setStatusCode($currentStatusCode);

            // Build payment response data array
            $paymentData = [
                'id' => $moneiPayment->getId(),
                'status' => $moneiPayment->getStatus(),
                'statusCode' => $moneiPayment->getStatusCode(),
                'statusMessage' => $moneiPayment->getStatusMessage(),
                'authorizationCode' => $moneiPayment->getAuthorizationCode(),
                'amount' => $moneiPayment->getAmount(),
                'currency' => $moneiPayment->getCurrency(),
                'livemode' => $moneiPayment->getLivemode(),
            ];

            // Add payment method details if available
            if ($moneiPayment->getPaymentMethod()) {
                $paymentData['paymentMethod'] = $moneiPayment->getPaymentMethod()->jsonSerialize();
            }

            // Add trace details if available
            if ($moneiPayment->getTraceDetails()) {
                $paymentData['traceDetails'] = $moneiPayment->getTraceDetails()->jsonSerialize();
            }

            $monei2HistoryEntity->setResponse(json_encode($paymentData));
            $monei2PaymentEntity->addHistory($monei2HistoryEntity);
        }

        if ($moneiPayment->getLastRefundAmount() > 0 && $shouldAddHistory && isset($monei2HistoryEntity)) {
            $monei2Refund = new Monei2Refund();
            $monei2Refund->setHistory($monei2HistoryEntity);
            $monei2Refund->setEmployeeId($employeeId);
            $monei2Refund->setReason($moneiPayment->getLastRefundReason());
            $monei2Refund->setAmount($moneiPayment->getLastRefundAmount());
            $monei2PaymentEntity->addRefund($monei2Refund);
        }

        $this->moneiPaymentRepository->save($monei2PaymentEntity);

        return $monei2PaymentEntity;
    }

    public function saveMoneiToken(Payment $moneiPayment, int $customerId): void
    {
        $cardPayment = $moneiPayment->getPaymentMethod()->getCard();
        $paymentToken = $moneiPayment->getPaymentToken();
        if ($cardPayment && $paymentToken) {
            $monei2CustomerCard = $this->moneiCustomerCardRepository->findOneBy([
                'tokenized' => $paymentToken,
                'expiration' => $cardPayment->getExpiration(),
                'last_four' => $cardPayment->getLast4(),
            ]);

            if (!$monei2CustomerCard) {
                $monei2CustomerCard = new Monei2CustomerCard();
                $monei2CustomerCard->setCustomerId($customerId);
                $monei2CustomerCard->setBrand($cardPayment->getBrand());
                $monei2CustomerCard->setCountry($cardPayment->getCountry());
                $monei2CustomerCard->setLastFour($cardPayment->getLast4());
                $monei2CustomerCard->setExpiration($cardPayment->getExpiration());
                $monei2CustomerCard->setTokenized($paymentToken);

                $this->moneiCustomerCardRepository->save($monei2CustomerCard);
            }
        }
    }

    public function createMoneiPayment(\Cart $cart, bool $tokenizeCard = false, int $cardTokenId = 0, string $paymentMethod = '')
    {
        if (!$cart) {
            throw new MoneiException('The cart could not be loaded correctly');
        }

        $cartAmount = $this->getCartAmount($cart->getSummaryDetails(null, true), $cart->id_currency);
        if (empty($cartAmount)) {
            throw new MoneiException('The cart amount is empty', MoneiException::CART_AMOUNT_EMPTY);
        }

        $currency = new \Currency($cart->id_currency);
        if (!\Validate::isLoadedObject($currency)) {
            throw new MoneiException('The currency could not be loaded correctly', MoneiException::CURRENCY_NOT_FOUND);
        }

        $customer = new \Customer($cart->id_customer);
        if (!\Validate::isLoadedObject($customer)) {
            throw new MoneiException('The customer could not be loaded correctly', MoneiException::CUSTOMER_NOT_FOUND);
        }

        $link = $this->legacyContext->getContext()->link;

        $orderId = $this->createMoneiOrderId($cart->id);

        $createPaymentRequest = new CreatePaymentRequest();
        $createPaymentRequest
            ->setOrderId($orderId)
            ->setAmount($cartAmount)
            ->setCurrency($currency->iso_code)
            ->setCompleteUrl(
                $link->getModuleLink('monei', 'confirmation', [
                    'cart_id' => $cart->id,
                    'order_id' => $orderId,
                ])
            )
            ->setCallbackUrl(
                $link->getModuleLink('monei', 'validation')
            )
            ->setCancelUrl(
                $this->legacyContext->getFrontUrl('order')
            );

        $customerData = $this->getCustomerData($customer, (int) $cart->id_address_invoice, true);
        if (!empty($customerData)) {
            $createPaymentRequest->setCustomer($customerData);
        }

        $billingDetails = $this->getAddressData((int) $cart->id_address_invoice, $customer->email, true);
        if (!empty($billingDetails)) {
            $createPaymentRequest->setBillingDetails($billingDetails);
        }

        $shippingDetails = $this->getAddressData((int) $cart->id_address_delivery, $customer->email, true);
        if (!empty($shippingDetails)) {
            $createPaymentRequest->setShippingDetails($shippingDetails);
        }

        // Set the allowed payment methods based on the selected payment method
        if (!empty($paymentMethod)) {
            // Map the payment method names to MONEI API values
            $paymentMethodMap = [
                'multibanco' => 'multibanco',
                'mbway' => 'mbway',
                'paypal' => 'paypal',
                'card' => 'card',
                'bizum' => 'bizum',
                'applePay' => 'applePay',
                'googlePay' => 'googlePay',
            ];
            
            // Only set allowedPaymentMethods for specific redirect payment methods
            if (in_array($paymentMethod, ['multibanco', 'mbway', 'paypal'])) {
                $mappedMethod = $paymentMethodMap[$paymentMethod] ?? null;
                if ($mappedMethod) {
                    $createPaymentRequest->setAllowedPaymentMethods([$mappedMethod]);
                }
            }
        }
        // Note: When no specific payment method is provided, we don't set allowedPaymentMethods
        // This allows MONEI to handle all available methods on their end

        // Set the payment token
        if ($tokenizeCard) {
            $createPaymentRequest->setGeneratePaymentToken(true);
        } elseif ($cardTokenId) {
            $monei2CustomerCard = $this->moneiCustomerCardRepository->findOneBy([
                'id' => $cardTokenId,
                'id_customer' => $customer->id,
            ]);

            if ($monei2CustomerCard) {
                $createPaymentRequest->setPaymentToken($monei2CustomerCard->getTokenized());
                $createPaymentRequest->setGeneratePaymentToken(false);
            }
        }

        // Set transaction type based on payment action configuration (matching Magento logic)
        $paymentAction = \Configuration::get('MONEI_PAYMENT_ACTION');
        
        if ($paymentAction === 'auth') {
            $allowedMethods = $createPaymentRequest->getAllowedPaymentMethods();
            
            // Only check for unsupported methods if allowed methods are explicitly set
            // If no methods are specified (null/empty), all methods are available so use AUTH
            $hasUnsupportedMethod = $allowedMethods && 
                is_array($allowedMethods) && 
                !empty(array_intersect($allowedMethods, self::UNSUPPORTED_AUTH_METHODS));
            
            if (!$hasUnsupportedMethod) {
                $createPaymentRequest->setTransactionType(PaymentTransactionType::AUTH);
            }
            // Note: If unsupported methods are found, transaction type remains default (SALE)
        } else {
            // Default to SALE for immediate charge
            $createPaymentRequest->setTransactionType(PaymentTransactionType::SALE);
        }

        try {
            $moneiClient = $this->getMoneiClient();

            if (!isset($moneiClient->payments)) {
                throw new MoneiException('Monei client payments not initialized', MoneiException::MONEI_CLIENT_NOT_INITIALIZED);
            }

            if (!$createPaymentRequest->valid()) {
                throw new MoneiException('The payment request is not valid', MoneiException::PAYMENT_REQUEST_NOT_VALID);
            }

            $moneiPaymentResponse = $moneiClient->payments->create($createPaymentRequest);

            $this->saveMoneiPayment($moneiPaymentResponse);

            return $moneiPaymentResponse;
        } catch (\Exception $ex) {
            \PrestaShopLogger::addLog(
                'MONEI - Exception - MoneiService.php - createMoneiPayment: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }
    }

    public function createRefund(int $orderId, int $amount, int $employeeId, string $reason)
    {
        $moneiPayment = $this->moneiPaymentRepository->findOneBy(['id_order' => $orderId]);
        if (!$moneiPayment) {
            throw new MoneiException('The order could not be loaded correctly', MoneiException::ORDER_NOT_FOUND);
        }

        if ($amount <= 0) {
            throw new MoneiException('Refund amount must be greater than zero', MoneiException::REFUND_AMOUNT_MUST_BE_GREATER_THAN_ZERO);
        }

        $totalRefunded = $this->getTotalRefundedByIdOrder($orderId);
        $maxRefundable = $moneiPayment->getAmount() - $totalRefunded;

        if ($amount > $maxRefundable) {
            throw new MoneiException('Refund amount exceeds available refundable balance', MoneiException::REFUND_AMOUNT_EXCEEDS_AVAILABLE_REFUNDABLE_BALANCE);
        }

        $refundPaymentRequest = new RefundPaymentRequest();
        $refundPaymentRequest->setAmount($amount);
        $refundPaymentRequest->setRefundReason($reason);

        try {
            $moneiPayment = $this->getMoneiClient()->payments->refund($moneiPayment->getId(), $refundPaymentRequest);
        } catch (\Exception $ex) {
            \PrestaShopLogger::addLog(
                'MONEI - Exception - MoneiService.php - createRefund: ' . $ex->getMessage(),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            throw new MoneiException('Failed to create refund: ' . $ex->getMessage(), MoneiException::REFUND_CREATION_FAILED);
        }

        $this->saveMoneiPayment($moneiPayment, $orderId, $employeeId);
    }

    public function capturePayment(int $orderId, int $amount)
    {
        $moneiPayment = $this->moneiPaymentRepository->findOneBy(['id_order' => $orderId]);
        if (!$moneiPayment) {
            throw new MoneiException('Payment record not found for order', MoneiException::ORDER_NOT_FOUND);
        }

        $paymentId = $moneiPayment->getId();
        if (empty($paymentId)) {
            throw new MoneiException('Payment ID is empty', MoneiException::PAYMENT_ID_EMPTY);
        }

        if ($moneiPayment->getStatus() === 'SUCCEEDED' || $moneiPayment->getIsCaptured()) {
            throw new MoneiException('Payment is already captured', MoneiException::PAYMENT_ALREADY_CAPTURED);
        }

        if ($moneiPayment->getStatus() !== 'AUTHORIZED') {
            throw new MoneiException('Payment is not in authorized state', MoneiException::PAYMENT_NOT_AUTHORIZED);
        }

        if ($amount <= 0) {
            throw new MoneiException('Capture amount must be greater than zero', MoneiException::INVALID_CAPTURE_AMOUNT);
        }

        if ($amount > $moneiPayment->getAmount()) {
            throw new MoneiException('Capture amount exceeds authorized amount', MoneiException::CAPTURE_AMOUNT_EXCEEDS_AUTHORIZED);
        }

        $captureRequest = new CapturePaymentRequest();
        $captureRequest->setAmount($amount);

        try {
            $capturedPayment = $this->getMoneiClient()->payments->capture($paymentId, $captureRequest);
        } catch (\Exception $ex) {
            \PrestaShopLogger::addLog(
                'MONEI - Exception - MoneiService.php - capturePayment: ' . $ex->getMessage(),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            throw new MoneiException('Failed to capture payment: ' . $ex->getMessage(), MoneiException::CAPTURE_FAILED);
        }

        $moneiPayment->setStatus($capturedPayment->getStatus());
        $moneiPayment->setStatusCode($capturedPayment->getStatusCode());
        $moneiPayment->setAuthorizationCode($capturedPayment->getAuthorizationCode());
        $moneiPayment->setIsCaptured(true);
        $moneiPayment->setDateUpd(time());

        $this->moneiPaymentRepository->save($moneiPayment);

        $monei2History = new \PsMonei\Entity\Monei2History();
        $monei2History->setPayment($moneiPayment);
        $monei2History->setStatus($capturedPayment->getStatus());
        $monei2History->setStatusCode($capturedPayment->getStatusCode());
        $monei2History->setResponse(json_encode($capturedPayment));
        $monei2History->setDateAdd(new \DateTime());

        $this->moneiHistoryRepository->save($monei2History);

        return $capturedPayment;
    }
}
