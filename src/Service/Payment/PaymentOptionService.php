<?php
namespace PsMonei\Service\Payment;

use Address;
use Country;
use Media;
use Monei\Model\PaymentPaymentMethod;
use PrestaShop\PrestaShop\Adapter\Configuration as ConfigurationLegacy;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PsMonei\Repository\MoneiCustomerCardRepository;
use PsMonei\Service\Monei\MoneiService;
use Tools;
use Validate;

class PaymentOptionService
{
    private $moneiService;
    private $moneiCustomerCardRepository;
    private $configuration;
    private $context;

    private $paymentMethodsAllowed = [];
    private $currencyIsoCode;
    private $countryIsoCode;
    private $paymentOptions;

    public function __construct(
        MoneiService $moneiService,
        MoneiCustomerCardRepository $moneiCustomerCardRepository,
        ConfigurationLegacy $configuration,
        LegacyContext $legacyContext
    ) {
        $this->moneiService = $moneiService;
        $this->moneiCustomerCardRepository = $moneiCustomerCardRepository;
        $this->configuration = $configuration;

        $this->context = $legacyContext->getContext();
    }

    public function getPaymentOptions(): array
    {
        $this->paymentMethodsAllowed = $this->moneiService->getPaymentMethodsAllowed();

        $this->currencyIsoCode = $this->context->currency->iso_code;
        $this->countryIsoCode = $this->context->country->iso_code;

        $addressInvoice = new Address($this->context->cart->id_address_invoice);
        if (Validate::isLoadedObject($addressInvoice)) {
            $countryInvoice = new Country($addressInvoice->id_country);
            $this->countryIsoCode = $countryInvoice->iso_code;
        }

        $this->getCardPaymentOption();
        $this->getCustomerCardsPaymentOption();
        $this->getBizumPaymentOption();
        $this->getApplePayPaymentOption();
        $this->getGooglePayPaymentOption();
        $this->getPaypalPaymentOption();
        $this->getCofidisPaymentOption();
        $this->getMultibancoPaymentOption();
        $this->getMbwayPaymentOption();

        return $this->paymentOptions;
    }

    public function isSafariBrowser(): bool
    {
        return preg_match('/Safari\/[0-9.]+$/', Tools::getUserAgent()) === 1
            && strpos(Tools::getUserAgent(), 'Chrome') === false;
    }

    public function getTransactionId(): string
    {
        $cart = $this->context->cart;
        $crypto = new \PrestaShop\PrestaShop\Core\Crypto\Hashing();

        return $crypto->hash($cart->id . $cart->id_customer, _COOKIE_KEY_);
    }

    private function isPaymentMethodAllowed($paymentMethod)
    {
        if ($this->currencyIsoCode !== 'EUR') {
            return false;
        }

        if (!isset($this->paymentMethodsAllowed)) {
            // If the payment methods are not found, we allow the payment method
            return true;
        }

        if (!in_array($paymentMethod, $this->paymentMethodsAllowed)) {
            return false;
        }

        if ($paymentMethod === PaymentPaymentMethod::METHOD_BIZUM || $paymentMethod === PaymentPaymentMethod::METHOD_COFIDIS) {
            return $this->countryIsoCode === 'ES';
        } elseif ($paymentMethod === PaymentPaymentMethod::METHOD_MULTIBANCO || $paymentMethod === PaymentPaymentMethod::METHOD_MBWAY) {
            return $this->countryIsoCode === 'PT';
        } elseif ($paymentMethod === PaymentPaymentMethod::METHOD_KLARNA) {
            return in_array($this->countryIsoCode, ['AT', 'BE', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IT', 'NL', 'NO', 'SE']);
        }

        return true;
    }

    private function getCardPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_CARD') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_CARD)) {
            $customer = $this->context->customer;
            $smarty = $this->context->smarty;

            $paymentOption = [
                'name' => 'card',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/cards.svg'),
                'binary' => false,
            ];

            if ($this->configuration->get('MONEI_CARD_WITH_REDIRECT')) {
                if ($this->configuration->get('MONEI_TOKENIZE')) {
                    $redirectUrl = $this->context->link->getModuleLink('monei', 'redirect', [
                        'method' => 'card',
                        'transaction_id' => $this->getTransactionId(),
                    ]);
                    $smarty->assign('link_create_payment', $redirectUrl);

                    $paymentOption['form'] = $smarty->fetch('module:monei/views/templates/hook/paymentOptions.tpl');
                }
            } else {
                $smarty->assign([
                    'isCustomerLogged' => Validate::isLoadedObject($customer),
                    'tokenize' => (bool) $this->configuration->get('MONEI_TOKENIZE'),
                ]);
                $paymentOption['additionalInformation'] = $smarty->fetch('module:monei/views/templates/front/onsite_card.tpl');
                $paymentOption['binary'] = true;
            }

            $this->paymentOptions[] = $paymentOption;
        }
    }

    private function getCustomerCardsPaymentOption()
    {
        $customer = $this->context->customer;
        if ($this->configuration->get('MONEI_ALLOW_CARD')
            && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_CARD)
            && Validate::isLoadedObject($customer)
            && ($customer->isLogged() || $customer->isGuest())
        ) {
            $link = $this->context->link;

            // Get current customer cards (not expired ones)
            $activeCustomerCards = $this->moneiCustomerCardRepository->getActiveCustomerCards($customer->id);
            if ($activeCustomerCards) {
                foreach ($activeCustomerCards as $customerCard) {
                    $optionTitle = $customerCard->getBrand() . ' ' . $customerCard->getLastFourWithMask();
                    $optionTitle .= ' (' . $customerCard->getExpirationFormatted() . ')';

                    $redirectUrl = $link->getModuleLink('monei', 'redirect', [
                        'method' => 'tokenized_card',
                        'transaction_id' => $this->getTransactionId(),
                        'id_monei_card' => $customerCard->getId(),
                    ]);

                    $this->paymentOptions[] = [
                        'name' => 'tokenized_card',
                        'title' => $optionTitle,
                        'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/' . strtolower($customerCard->getBrand()) . '.svg'),
                        'action' => $redirectUrl,
                    ];
                }
            }
        }
    }

    private function getBizumPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_BIZUM') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_BIZUM)) {
            $this->paymentOptions[] = [
                'name' => 'bizum',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/bizum.svg'),
                'binary' => $this->configuration->get('MONEI_BIZUM_WITH_REDIRECT') ? false : true,
            ];
        }
    }

    private function getApplePayPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_APPLE') && $this->isPaymentMethodAllowed('applePay', $this->isSafariBrowser())) {
            $this->paymentOptions[] = [
                'name' => 'applePay',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/apple-pay.svg'),
                'binary' => true,
            ];
        }
    }

    private function getGooglePayPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_GOOGLE') && $this->isPaymentMethodAllowed('googlePay', !$this->isSafariBrowser())) {
            $this->paymentOptions[] = [
                'name' => 'googlePay',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/google-pay.svg'),
                'binary' => true,
            ];
        }
    }

    private function getPaypalPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_PAYPAL') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_PAYPAL)) {
            $this->paymentOptions[] = [
                'name' => 'paypal',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/paypal.svg'),
                'binary' => false,
            ];
        }
    }

    private function getCofidisPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_COFIDIS') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_COFIDIS)) {
            $this->paymentOptions[] = [
                'name' => 'cofidis',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/cofidis.svg'),
                'binary' => false,
            ];
        }
    }

    private function getMultibancoPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_MULTIBANCO') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_MULTIBANCO)) {
            $this->paymentOptions[] = [
                'name' => 'multibanco',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/multibanco.svg'),
                'binary' => false,
            ];
        }
    }

    private function getMbwayPaymentOption()
    {
        if ($this->configuration->get('MONEI_ALLOW_MBWAY') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_MBWAY)) {
            $this->paymentOptions[] = [
                'name' => 'mbway',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/mbway.svg'),
                'binary' => false,
            ];
        }
    }
}
