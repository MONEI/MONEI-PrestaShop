<?php
namespace PsMonei\Service\Monei;

if (!defined('_PS_VERSION_')) {
    exit;
}

use OpenAPI\Client\Model\CreatePaymentRequest;
use OpenAPI\Client\Model\PaymentBillingDetails;
use OpenAPI\Client\Model\PaymentCustomer;
use Cart;
use Configuration;
use Currency;
use PsMonei\Exception\MoneiException;
use Tools;
use Customer;
use Address;
use State;
use Monei;
use Country;
use Exception;
use Monei\MoneiClient;
use Validate;
use PrestaShopLogger;
use OpenAPI\Client\Model\Payment;
use OpenAPI\Client\Model\RefundPaymentRequest;
use PsMonei\Entity\MoPayment;
use PsMonei\Repository\MoneiPaymentRepository;
use PsMonei\Repository\MoneiTokenRepository;
use PsMonei\Repository\MoneiHistoryRepository;
use PsMonei\Entity\MoHistory;
use PsMonei\Entity\MoRefund;
use PsMonei\Entity\MoToken;
use PsMonei\Repository\MoneiRefundRepository;

class MoneiService
{
    private $moneiInstance;
    private $legacyContext;
    private $moneiPaymentRepository;
    private $moneiTokenRepository;
    private $moneiHistoryRepository;
    private $moneiRefundRepository;

    public function __construct(
        Monei $moneiInstance,
        MoneiPaymentRepository $moneiPaymentRepository,
        MoneiTokenRepository $moneiTokenRepository,
        MoneiHistoryRepository $moneiHistoryRepository,
        MoneiRefundRepository $moneiRefundRepository
    ) {
        $this->moneiInstance = $moneiInstance;
        $this->legacyContext = $this->moneiInstance->getLegacyContext();
        $this->moneiPaymentRepository = $moneiPaymentRepository;
        $this->moneiTokenRepository = $moneiTokenRepository;
        $this->moneiHistoryRepository = $moneiHistoryRepository;
        $this->moneiRefundRepository = $moneiRefundRepository;
    }

    private function getMoneiClient()
    {
        $apiKey = Configuration::get('MONEI_API_KEY');

        if (!$apiKey) {
            throw new MoneiException('The monei api key is not set.', MoneiException::MONEI_API_KEY_IS_EMPTY);
        }

        try {
            return new MoneiClient($apiKey);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - MoneiService.php - getMoneiClient: ' . $e->getMessage() . ' - ' . $e->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }
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
        return str_pad($cartId . 'm' . time() % 1000, 12, '0', STR_PAD_LEFT);
    }

    public function extractCartIdFromMoneiOrderId($moneiOrderId)
    {
        return (int) substr($moneiOrderId, 0, strpos($moneiOrderId, 'm'));
    }

    public function getCartAmount(array $cartSummaryDetails, int $currencyId, bool $withoutFormatting = false): int|float
    {
        $totalPrice = $cartSummaryDetails['total_price_without_tax'] + $cartSummaryDetails['total_tax'];

        $currency = new Currency($currencyId);
        $decimals = is_array($currency) ? (int) $currency['decimals'] : (int) $currency->decimals;
        $decimals *= _PS_PRICE_DISPLAY_PRECISION_; // _PS_PRICE_DISPLAY_PRECISION_ deprec 1.7.7 TODO

        $totalPriceRounded = Tools::ps_round($totalPrice + $cartSummaryDetails['total_shipping_tax_exc'], $decimals);

        return $withoutFormatting ? $totalPriceRounded : (int) number_format($totalPriceRounded, 2, '', '');
    }

    public function getCustomerData(Customer $customer, int $cartAddressInvoiceId, $returnMoneiCustomerObject = false)
    {
        if (!Validate::isLoadedObject($customer)) {
            throw new MoneiException('The customer could not be loaded correctly', MoneiException::CUSTOMER_NOT_LOADED);
        }

        $addressInvoice = new Address((int) $cartAddressInvoiceId);
        if (!Validate::isLoadedObject($addressInvoice)) {
            throw new MoneiException('The address could not be loaded correctly', MoneiException::ADDRESS_NOT_LOADED);
        }

        $customer->email = str_replace(':', '', $customer->email);

        $customerData = [
            'name' => $customer->firstname . ' ' . $customer->lastname,
            'email' => $customer->email,
            'phone' => $addressInvoice->phone_mobile ?: $addressInvoice->phone
        ];

        return $returnMoneiCustomerObject ? new PaymentCustomer($customerData) : $customerData;
    }

    public function getAddressData(int $addressId, string $customerEmail, bool $returnMoneiBillingObject = false)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address)) {
            throw new MoneiException('The address could not be loaded correctly', MoneiException::ADDRESS_NOT_LOADED);
        }

        $country = new Country($address->id_country, (int) $this->legacyContext->getLanguage()->id);
        if (!Validate::isLoadedObject($country)) {
            throw new MoneiException('The country could not be loaded correctly', MoneiException::COUNTRY_NOT_LOADED);
        }

        $state = new State((int) $address->id_state, (int) $this->legacyContext->getLanguage()->id);
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
                'country' => $country->iso_code
            ]
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
            'MONEI_ALLOW_CLICKTOPAY' => 'clickToPay',
            'MONEI_ALLOW_PAYPAL' => 'paypal',
            'MONEI_ALLOW_COFIDIS' => 'cofidis',
            'MONEI_ALLOW_KLARNA' => 'klarna',
            'MONEI_ALLOW_MULTIBANCO' => 'multibanco',
            'MONEI_ALLOW_MBWAY' => 'mbway',
        ];

        foreach ($allowedMethods as $configKey => $method) {
            if (Configuration::get($configKey)) {
                $paymentMethods[] = $method;
            }
        }

        return $paymentMethods;
    }

    public function getTotalRefundedByIdOrder(int $orderId)
    {
        $refunds = $this->moneiRefundRepository->findOneBy(['id_order' => $orderId]);
        $totalRefunded = 0;
        foreach ($refunds as $refund) {
            $totalRefunded += $refund->getAmount();
        }
        return $totalRefunded;
    }

    public function saveMoneiPayment(Payment $moneiPayment, int $orderId = 0, bool $isRefund = false, bool $isCallback = false)
    {
        $cartId = $this->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());

        $moPaymentEntity = $this->moneiPaymentRepository->findOneById($moneiPayment->getId());
        if ($moPaymentEntity) {
            $moPaymentEntity->setAuthorizationCode($moneiPayment->getAuthorizationCode());
            $moPaymentEntity->setStatus($moneiPayment->getStatus());
            $moPaymentEntity->setDateUpd($moneiPayment->getUpdatedAt());
        } else {
            $moPaymentEntity = new MoPayment();
            $moPaymentEntity->setId($moneiPayment->getId());
            $moPaymentEntity->setCartId($cartId);
            $moPaymentEntity->setOrderId($orderId);
            $moPaymentEntity->setOrderMoneiId($moneiPayment->getOrderId());
            $moPaymentEntity->setAmount($moneiPayment->getAmount());
            $moPaymentEntity->setCurrency($moneiPayment->getCurrency());
            $moPaymentEntity->setAuthorizationCode($moneiPayment->getAuthorizationCode());
            $moPaymentEntity->setStatus($moneiPayment->getStatus());
            $moPaymentEntity->setDateAdd($moneiPayment->getCreatedAt());
            $moPaymentEntity->setDateUpd($moneiPayment->getUpdatedAt());
        }

        $moHistoryEntity = new MoHistory();
        $moHistoryEntity->setStatus($moneiPayment->getStatus());
        $moHistoryEntity->setStatusCode($moneiPayment->getStatusCode());
        $moHistoryEntity->setIsRefund($isRefund);
        $moHistoryEntity->setIsCallback($isCallback);
        $moHistoryEntity->setResponse(json_encode($moneiPayment->jsonSerialize()));

        $moPaymentEntity->addHistory($moHistoryEntity);

        $this->moneiPaymentRepository->saveMoneiPayment($moPaymentEntity);
    }

    public function saveMoneiToken(Payment $moneiPayment, int $customerId): void
    {
        $cardPayment = $moneiPayment->getPaymentMethod()->getCard();
        if ($cardPayment) {
            $paymentToken = $moneiPayment->getPaymentToken();

            $moToken = $this->moneiTokenRepository->findOneBy([
                'tokenized' => $paymentToken,
                'expiration' => $cardPayment->getExpiration(),
                'last_four' => $cardPayment->getLast4()
            ]);

            if (!$moToken) {
                $moToken = new MoToken();
                $moToken->setCustomerId($customerId);
                $moToken->setBrand($cardPayment->getBrand());
                $moToken->setCountry($cardPayment->getCountry());
                $moToken->setLastFour($cardPayment->getLast4());
                $moToken->setThreeDS($cardPayment->getThreeDSecure());
                $moToken->setThreeDSVersion($cardPayment->getThreeDSecureVersion());
                $moToken->setExpiration($cardPayment->getExpiration());
                $moToken->setTokenized($paymentToken);

                $this->moneiTokenRepository->saveMoneiToken($moToken);
            }
        }
    }

    public function saveMoneiRefund(Payment $moneiPayment, int $moHistoryId = 0, int $employeeId = 0, string $reason): void
    {
        $moRefund = new MoRefund();
        $moRefund->setPaymentId($moneiPayment->getId());
        $moRefund->setHistoryId($moHistoryId);
        $moRefund->setEmployeeId($employeeId);
        $moRefund->setReason($moneiPayment->getCancellationReason());
        $moRefund->setAmount($moneiPayment->getRefundedAmount());

        $this->moneiRefundRepository->saveMoneiRefund($moRefund);
    }

    public function createMoneiPayment(Cart $cart, bool $tokenizeCard = false, int $cardTokenId = 0)
    {
        if (!$cart) {
            throw new MoneiException('The cart could not be loaded correctly');
        }

        $cartAmount = $this->getCartAmount($cart->getSummaryDetails(null, true), $cart->id_currency);
        if (empty($cartAmount)) {
            throw new MoneiException('The cart amount is empty', MoneiException::CART_AMOUNT_IS_EMPTY);
        }

        $currency = new Currency($cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            throw new MoneiException('The currency could not be loaded correctly', MoneiException::CURRENCY_NOT_LOADED);
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            throw new MoneiException('The customer could not be loaded correctly', MoneiException::CUSTOMER_NOT_LOADED);
        }

        $orderId = $this->createMoneiOrderId($cart->id);

        $createPaymentRequest = new CreatePaymentRequest();
        $createPaymentRequest
            ->setOrderId($orderId)
            ->setAmount($cartAmount)
            ->setCurrency($currency->iso_code)
            ->setCompleteUrl(
                $this->moneiInstance->getModuleLink('confirmation', [
                    'success' => 1,
                    'cart_id' => $cart->id,
                    'order_id' => $orderId
                ])
            )
            ->setFailUrl(
                $this->moneiInstance->  getModuleLink('confirmation', [
                    'success' => 0,
                    'cart_id' => $cart->id,
                    'order_id' => $orderId
                ])
            )
            ->setCallbackUrl(
                $this->moneiInstance->getModuleLink('validation')
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

        // Set the allowed payment methods
        $createPaymentRequest->setAllowedPaymentMethods($this->getPaymentMethodsAvailable());

        // Set the payment token
        if ($tokenizeCard) {
            $createPaymentRequest->setGeneratePaymentToken(true);
        } else if ($cardTokenId) {
            $moToken = $this->moneiTokenRepository->findOneBy([
                'id_token' => $cardTokenId,
                'id_customer' => $customer->id
            ]);

            if ($moToken) {
                $createPaymentRequest->setPaymentToken($moToken->getTokenized());
                $createPaymentRequest->setGeneratePaymentToken(false);
            }
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
        } catch (Exception $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - MoneiService.php - createMoneiPayment: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }
    }

    public function createRefund(int $orderId, int $amount, string $reason, int $employeeId = 0)
    {
        $moneiPayment = $this->moneiPaymentRepository->findOneByIdOrderMonei($orderId);
        if (!$moneiPayment) {
            throw new MoneiException('The order could not be loaded correctly', MoneiException::ORDER_NOT_FOUND);
        }

        $refundPaymentRequest = new RefundPaymentRequest();
        $refundPaymentRequest->setAmount($amount);
        $refundPaymentRequest->setRefundReason($reason);

        $moneiPayment = $this->getMoneiClient()->payments->refund($moneiPayment->getId(), $refundPaymentRequest);

        $moHistory = $this->saveMoneiHistory($moneiPayment, true, false);
        $this->saveMoneiRefund($moneiPayment, $moHistory->getHistoryId(), $employeeId, $reason);
    }
}