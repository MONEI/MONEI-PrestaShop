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
use Country;
use Exception;
use Monei\MoneiClient;
use Validate;
use PrestaShopLogger;
use OpenAPI\Client\Model\Payment;
use OpenAPI\Client\Model\PaymentPaymentMethod;
use OpenAPI\Client\Model\RefundPaymentRequest;
use PsMonei\Entity\MoCustomerCard;
use PsMonei\Entity\MoPayment;
use PsMonei\Repository\MoneiPaymentRepository;
use PsMonei\Entity\MoHistory;
use PsMonei\Entity\MoRefund;
use PsMonei\Repository\MoneiCustomerCardRepository;
use PsMonei\Repository\MoneiRefundRepository;
use PrestaShop\PrestaShop\Adapter\LegacyContext;

class MoneiService
{
    private $legacyContext;
    private $moneiPaymentRepository;
    private $moneiCustomerCardRepository;
    private $moneiRefundRepository;

    public function __construct(
        LegacyContext $legacyContext,
        MoneiPaymentRepository $moneiPaymentRepository,
        MoneiCustomerCardRepository $moneiCustomerCardRepository,
        MoneiRefundRepository $moneiRefundRepository
    ) {
        $this->legacyContext = $legacyContext;
        $this->moneiPaymentRepository = $moneiPaymentRepository;
        $this->moneiCustomerCardRepository = $moneiCustomerCardRepository;
        $this->moneiRefundRepository = $moneiRefundRepository;
    }

    public function getMoneiClient()
    {
        if ((bool) Configuration::get('MONEI_PRODUCTION_MODE')) {
            $apiKey = Configuration::get('MONEI_API_KEY');
        } else {
            $apiKey = Configuration::get('MONEI_TEST_API_KEY');
        }

        if (!$apiKey) {
            throw new MoneiException('Monei client not initialized.', MoneiException::MONEI_CLIENT_NOT_INITIALIZED);
        }

        return new MoneiClient($apiKey);
    }

    public function getMoneiAccountInformation()
    {
        if ((bool) Configuration::get('MONEI_PRODUCTION_MODE')) {
            $accountId = Configuration::get('MONEI_ACCOUNT_ID');
        } else {
            $accountId = Configuration::get('MONEI_TEST_ACCOUNT_ID');
        }

        if (!$accountId) {
            throw new MoneiException('Monei account id is not set.', MoneiException::MONEI_ACCOUNT_ID_IS_EMPTY);
        }

        $endpoint = 'https://api.monei.com/v1/payment-methods?accountId=' . $accountId;

        $response = Tools::file_get_contents($endpoint);
        if (!$response) {
            throw new MoneiException('Monei account information not found.', MoneiException::MONEI_ACCOUNT_INFORMATION_NOT_FOUND);
        }

        $responseJson = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MoneiException('Invalid JSON response from Monei.', MoneiException::INVALID_JSON_RESPONSE);
        }

        return $responseJson;
    }

    public function isPaymentMethodAllowedByCurrency(array $paymentMethodsAllowed, string $paymentMethod, string $currencyIsoCode, string $countryIsoCode = null): bool
    {
        if ($currencyIsoCode !== 'EUR') {
            return false;
        }

        if (!isset($paymentMethodsAllowed)) {
            // If the payment methods are not found, we allow the payment method
            return true;
        }

        if (!in_array($paymentMethod, $paymentMethodsAllowed)) {
            return false;
        }

        switch ($paymentMethod) {
            case PaymentPaymentMethod::METHOD_BIZUM:
                return $countryIsoCode === 'ES';
            case PaymentPaymentMethod::METHOD_COFIDIS:
                return $countryIsoCode === 'ES';
            case PaymentPaymentMethod::METHOD_MULTIBANCO:
            case PaymentPaymentMethod::METHOD_MBWAY:
                return $countryIsoCode === 'PT';
            case PaymentPaymentMethod::METHOD_KLARNA:
                return in_array($countryIsoCode, ['AT', 'BE', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IT', 'NL', 'NO', 'SE']);
            default:
                return true;
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

    public function getCartAmount(array $cartSummaryDetails, int $currencyId, bool $withoutFormatting = false)
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

    public function saveMoneiPayment(Payment $moneiPayment, int $orderId = 0, int $employeeId = 0)
    {
        $cartId = $this->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());

        $moPaymentEntity = $this->moneiPaymentRepository->findOneById($moneiPayment->getId()) ?? new MoPayment();

        $moPaymentEntity->setId($moneiPayment->getId());
        $moPaymentEntity->setCartId($cartId);
        $moPaymentEntity->setOrderId($orderId);
        $moPaymentEntity->setOrderMoneiId($moneiPayment->getOrderId());
        $moPaymentEntity->setAmount($moneiPayment->getAmount());
        $moPaymentEntity->setRefundedAmount($moneiPayment->getRefundedAmount());
        $moPaymentEntity->setCurrency($moneiPayment->getCurrency());
        $moPaymentEntity->setAuthorizationCode($moneiPayment->getAuthorizationCode());
        $moPaymentEntity->setStatus($moneiPayment->getStatus());
        $moPaymentEntity->setDateAdd($moneiPayment->getCreatedAt());
        $moPaymentEntity->setDateUpd($moneiPayment->getUpdatedAt());

        $moHistoryEntity = new MoHistory();
        $moHistoryEntity->setStatus($moneiPayment->getStatus());
        $moHistoryEntity->setStatusCode($moneiPayment->getStatusCode());
        $moHistoryEntity->setResponse(json_encode($moneiPayment->jsonSerialize()));
        $moPaymentEntity->addHistory($moHistoryEntity);

        if ($moneiPayment->getLastRefundAmount() > 0) {
            $moRefund = new MoRefund();
            $moRefund->setHistory($moHistoryEntity);
            $moRefund->setEmployeeId($employeeId);
            $moRefund->setReason($moneiPayment->getLastRefundReason());
            $moRefund->setAmount($moneiPayment->getLastRefundAmount());
            $moPaymentEntity->addRefund($moRefund);
        }

        $this->moneiPaymentRepository->saveMoneiPayment($moPaymentEntity);

        return $moPaymentEntity;
    }

    public function saveMoneiToken(Payment $moneiPayment, int $customerId): void
    {
        $cardPayment = $moneiPayment->getPaymentMethod()->getCard();
        $paymentToken = $moneiPayment->getPaymentToken();
        if ($cardPayment && $paymentToken) {
            $moCustomerCard = $this->moneiCustomerCardRepository->findOneBy([
                'tokenized' => $paymentToken,
                'expiration' => $cardPayment->getExpiration(),
                'last_four' => $cardPayment->getLast4()
            ]);

            if (!$moCustomerCard) {
                $moCustomerCard = new MoCustomerCard();
                $moCustomerCard->setCustomerId($customerId);
                $moCustomerCard->setBrand($cardPayment->getBrand());
                $moCustomerCard->setCountry($cardPayment->getCountry());
                $moCustomerCard->setLastFour($cardPayment->getLast4());
                $moCustomerCard->setThreeDS($cardPayment->getThreeDSecure());
                $moCustomerCard->setExpiration($cardPayment->getExpiration());
                $moCustomerCard->setTokenized($paymentToken);

                $this->moneiCustomerCardRepository->saveMoneiCustomerCard($moCustomerCard);
            }
        }
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

        $link = $this->legacyContext->getContext()->link;

        $orderId = $this->createMoneiOrderId($cart->id);

        $createPaymentRequest = new CreatePaymentRequest();
        $createPaymentRequest
            ->setOrderId($orderId)
            ->setAmount($cartAmount)
            ->setCurrency($currency->iso_code)
            ->setCompleteUrl(
                $link->getModuleLink('monei', 'confirmation', [
                    'success' => 1,
                    'cart_id' => $cart->id,
                    'order_id' => $orderId
                ])
            )
            ->setFailUrl(
                $link->getModuleLink('monei', 'confirmation', [
                    'success' => 0,
                    'cart_id' => $cart->id,
                    'order_id' => $orderId
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

        // Set the allowed payment methods
        $createPaymentRequest->setAllowedPaymentMethods($this->getPaymentMethodsAvailable());

        // Set the payment token
        if ($tokenizeCard) {
            $createPaymentRequest->setGeneratePaymentToken(true);
        } else if ($cardTokenId) {
            $moCustomerCard = $this->moneiCustomerCardRepository->findOneBy([
                'id' => $cardTokenId,
                'id_customer' => $customer->id
            ]);

            if ($moCustomerCard) {
                $createPaymentRequest->setPaymentToken($moCustomerCard->getTokenized());
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

    public function createRefund(int $orderId, int $amount, int $employeeId = 0, string $reason)
    {
        $moneiPayment = $this->moneiPaymentRepository->findOneBy(['id_order' => $orderId]);
        if (!$moneiPayment) {
            throw new MoneiException('The order could not be loaded correctly', MoneiException::ORDER_NOT_FOUND);
        }

        $refundPaymentRequest = new RefundPaymentRequest();
        $refundPaymentRequest->setAmount($amount);
        $refundPaymentRequest->setRefundReason($reason);

        $moneiPayment = $this->getMoneiClient()->payments->refund($moneiPayment->getId(), $refundPaymentRequest);

        $this->saveMoneiPayment($moneiPayment, $orderId, $employeeId);
    }
}