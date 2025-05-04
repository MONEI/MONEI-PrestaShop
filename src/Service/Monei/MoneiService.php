<?php
namespace PsMonei\Service\Monei;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Address;
use Cart;
use Configuration;
use Country;
use Currency;
use Customer;
use Exception;
use Monei\MoneiClient;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\Payment;
use Monei\Model\PaymentBillingDetails;
use Monei\Model\PaymentCustomer;
use Monei\Model\RefundPaymentRequest;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShopLogger;
use PsMonei\Entity\Monei2CustomerCard;
use PsMonei\Entity\Monei2History;
use PsMonei\Entity\Monei2Payment;
use PsMonei\Entity\Monei2Refund;
use PsMonei\Exception\MoneiException;
use PsMonei\Repository\MoneiCustomerCardRepository;
use PsMonei\Repository\MoneiPaymentRepository;
use PsMonei\Repository\MoneiRefundRepository;
use State;
use Tools;
use Validate;

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

    public function getPaymentMethodsAllowed()
    {
        try {
            $moneiClient = $this->getMoneiClient();

            if ((bool) Configuration::get('MONEI_PRODUCTION_MODE')) {
                $accountId = Configuration::get('MONEI_ACCOUNT_ID');
            } else {
                $accountId = Configuration::get('MONEI_TEST_ACCOUNT_ID');
            }

            if (!$accountId) {
                throw new MoneiException('Monei account id is not set.', MoneiException::MONEI_ACCOUNT_ID_IS_EMPTY);
            }

            $moneiAccountInformation = $moneiClient->paymentMethods->get($accountId);

            return $moneiAccountInformation->getPaymentMethods();
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('MONEI - getPaymentMethodsAllowed - Error: ' . $e->getMessage(), PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING);

            return [];
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
        $suffix = time() % 1000;
        return str_pad($cartId . 'm' . $suffix, 12, '0', STR_PAD_LEFT);
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
            'phone' => $addressInvoice->phone_mobile ?: $addressInvoice->phone,
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
            'MONEI_ALLOW_COFIDIS' => 'cofidis',
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
        $refunds = $this->moneiRefundRepository->findBy(['id_order' => $orderId]);
        $totalRefunded = 0;
        foreach ($refunds as $refund) {
            $totalRefunded += $refund->getAmount();
        }

        return $totalRefunded;
    }

    public function saveMoneiPayment(Payment $moneiPayment, int $orderId = 0, int $employeeId = 0)
    {
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
        $monei2PaymentEntity->setDateAdd($moneiPayment->getCreatedAt()->getTimestamp());
        $monei2PaymentEntity->setDateUpd($moneiPayment->getUpdatedAt()->getTimestamp());

        $monei2HistoryEntity = new Monei2History();
        $monei2HistoryEntity->setStatus($moneiPayment->getStatus());
        $monei2HistoryEntity->setStatusCode($moneiPayment->getStatusCode());
        $monei2HistoryEntity->setResponse($moneiPayment);
        $monei2PaymentEntity->addHistory($monei2HistoryEntity);

        if ($moneiPayment->getLastRefundAmount() > 0) {
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
                    'order_id' => $orderId,
                ])
            )
            ->setFailUrl(
                $link->getModuleLink('monei', 'confirmation', [
                    'success' => 0,
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

        // Set the allowed payment methods
        $createPaymentRequest->setAllowedPaymentMethods($this->getPaymentMethodsAvailable());

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

    public function createRefund(int $orderId, int $amount, int $employeeId, string $reason)
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
