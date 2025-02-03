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
use Validate;
use OpenAPI\Client\Model\Payment;
use PsMonei\Entity\MoPayment;
use PsMonei\Repository\MoneiPaymentRepository;

class MoneiService
{
    private $moneiInstance;
    private $moneiPaymentRepository;
    private $legacyContext;

    public function __construct(
        Monei $moneiInstance,
        $legacyContext,
        MoneiPaymentRepository $moneiPaymentRepository
    ) {
        $this->moneiInstance = $moneiInstance;
        $this->legacyContext = $legacyContext;
        $this->moneiPaymentRepository = $moneiPaymentRepository;
    }

    public function createMoneiOrderId(int $cartId)
    {
        return str_pad($cartId . 'm' . time() % 1000, 12, '0', STR_PAD_LEFT);
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
            throw new MoneiException('The customer could not be loaded correctly');
        }

        $addressInvoice = new Address((int) $cartAddressInvoiceId);
        if (!Validate::isLoadedObject($addressInvoice)) {
            throw new MoneiException('The address could not be loaded correctly');
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
            throw new MoneiException('The address could not be loaded correctly');
        }

        $country = new Country($address->id_country, (int) $this->legacyContext->getLanguage()->id);
        if (!Validate::isLoadedObject($country)) {
            throw new MoneiException('The country could not be loaded correctly');
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

    public function getPaymentMethodsAllowed()
    {
        $paymentMethods = [];

        if (!Configuration::get('MONEI_ALLOW_ALL')) {
            if (Tools::isSubmit('method')) {
                $param_method = Tools::getValue('method', 'card');
                $paymentMethods[] = in_array($param_method, MoneiPaymentMethods::getAllowableEnumValues()) ? $param_method : 'card';
            } else {
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
            }
        }

        return $paymentMethods;
    }

    public function saveMoneiPayment(Payment $moneiPayment, int $cartId)
    {
        // Save monei payment
        $moPaymentEntity = new MoPayment();
        $moPaymentEntity->setPaymentId($moneiPayment->getId());
        $moPaymentEntity->setCartId($cartId);
        $moPaymentEntity->setOrderMoneiId($moneiPayment->getOrderId());
        $moPaymentEntity->setAmount($moneiPayment->getAmount());
        $moPaymentEntity->setCurrency($moneiPayment->getCurrency());
        $moPaymentEntity->setAuthorizationCode($moneiPayment->getAuthorizationCode());
        $moPaymentEntity->setStatus($moneiPayment->getStatus());
        $moPaymentEntity->setDateAdd($moneiPayment->getCreatedAt());
        $moPaymentEntity->setDateUpd($moneiPayment->getUpdatedAt());

        $this->moneiPaymentRepository->saveMoneiPayment($moPaymentEntity);
    }

    public function createMoneiPayment(Cart $cart, bool $tokenizeCard = false, int $moneiCardId = 0)
    {
        if (!$cart) {
            throw new MoneiException('The cart could not be loaded correctly');
        }

        $cartAmount = $this->getCartAmount($cart->getSummaryDetails(null, true), $cart->id_currency);
        if (empty($cartAmount)) {
            throw new MoneiException('The cart amount is empty');
        }

        $currency = new Currency($cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            throw new MoneiException('The currency could not be loaded correctly');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            throw new MoneiException('The customer could not be loaded correctly');
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
        $createPaymentRequest->setAllowedPaymentMethods($this->getPaymentMethodsAllowed());

        // Set the payment token
        if ($tokenizeCard) {
            $createPaymentRequest->setGeneratePaymentToken(true);
        } else if ($moneiCardId) {
            // $belongsToCustomer = MoneiCard::belongsToCustomer(
            //     $moneiCardId,
            //     $this->context->customer->id
            // );

            // if ($belongsToCustomer) {
            //     $tokenizedCard = new MoneiCard($moneiCardId);

            //     $createPaymentRequest->setPaymentToken($tokenizedCard->tokenized);
            //     $createPaymentRequest->setGeneratePaymentToken(false);
            // }
        }

        try {
            $moneiClient = $this->moneiInstance->getMoneiClient();
            if (!$moneiClient) {
                throw new MoneiException('Monei client not initialized');
            }
            if (!isset($moneiClient->payments)) {
                throw new MoneiException('Monei client payments not initialized');
            }

            if (!$createPaymentRequest->valid()) {
                throw new MoneiException('The payment request is not valid');
            }

            $moneiPaymentResponse = $moneiClient->payments->create($createPaymentRequest);

            $this->saveMoneiPayment($moneiPaymentResponse, $cart->id);

            return $moneiPaymentResponse;
        } catch (Exception $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - MoneiService.php - createMoneiPayment: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }
    }
}