<?php

namespace PsMonei\Service\Payment;

use Monei\Model\PaymentPaymentMethod;
use PsMonei\Entity\Monei2CustomerCard;
use PsMonei\Helper\PaymentMethodFormatter;
use PsMonei\Service\Monei\MoneiService;

class PaymentOptionService
{
    private $moneiService;
    private $context;
    private $paymentMethodFormatter;

    private $paymentMethodsAllowed = [];
    private $availableCardBrands = [];
    private $currencyIsoCode;
    private $countryIsoCode;
    private $paymentOptions;

    /**
     * Payment method icon configuration
     */
    private const PAYMENT_METHOD_ICONS = [
        'card' => [
            'path' => 'cards.svg',
            'width' => '40',
            'height' => '24',
        ],
        'bizum' => [
            'path' => 'bizum.svg',
            'width' => '70',
            'height' => '22',
        ],
        'applePay' => [
            'path' => 'apple-pay.svg',
            'width' => '50',
            'height' => '22',
        ],
        'googlePay' => [
            'path' => 'google-pay.svg',
            'width' => '50',
            'height' => '22',
        ],
        'paypal' => [
            'path' => 'paypal.svg',
            'width' => '70',
            'height' => '45',
        ],
        'multibanco' => [
            'path' => 'multibanco.svg',
            'width' => '105',
            'height' => '22',
        ],
        'mbway' => [
            'path' => 'mbway.svg',
            'width' => '45',
            'height' => '22',
        ],
    ];

    public function __construct(
        MoneiService $moneiService,
        $moneiCustomerCardModel,
        $configuration,
        $context,
        PaymentMethodFormatter $paymentMethodFormatter
    ) {
        $this->moneiService = $moneiService;
        $this->paymentMethodFormatter = $paymentMethodFormatter;

        // For PS1.7 compatibility, we accept context directly
        if (is_object($context) && method_exists($context, 'getContext')) {
            $this->context = $context->getContext();
        } else {
            $this->context = $context;
        }
        // Note: moneiCustomerCardModel and configuration parameters kept for compatibility but not used
        // as we use static methods on ObjectModel and Configuration classes directly
    }

    public function getPaymentOptions(): ?array
    {
        $this->paymentMethodsAllowed = $this->moneiService->getPaymentMethodsAllowed();
        $this->availableCardBrands = $this->moneiService->getAvailableCardBrands();

        $this->currencyIsoCode = $this->context->currency->iso_code;
        $this->countryIsoCode = $this->context->country->iso_code;

        $addressInvoice = new \Address($this->context->cart->id_address_invoice);
        if (\Validate::isLoadedObject($addressInvoice)) {
            $countryInvoice = new \Country($addressInvoice->id_country);
            $this->countryIsoCode = $countryInvoice->iso_code;
        }

        $this->getCardPaymentOption();
        $this->getCustomerCardsPaymentOption();
        $this->getBizumPaymentOption();
        $this->getApplePayPaymentOption();
        $this->getGooglePayPaymentOption();
        $this->getPaypalPaymentOption();
        $this->getMultibancoPaymentOption();
        $this->getMbwayPaymentOption();

        return $this->paymentOptions;
    }

    public function isSafariBrowser(): bool
    {
        return strpos(\Tools::getUserBrowser(), 'Safari') !== false;
    }

    public function getTransactionId(): string
    {
        $cart = $this->context->cart;

        // Use PS1.7 compatible encryption to match redirect controller
        return \Tools::encrypt((int) $cart->id . (int) $cart->id_customer);
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

        if ($paymentMethod === PaymentPaymentMethod::METHOD_BIZUM) {
            return $this->countryIsoCode === 'ES';
        } elseif ($paymentMethod === PaymentPaymentMethod::METHOD_MULTIBANCO || $paymentMethod === PaymentPaymentMethod::METHOD_MBWAY) {
            return $this->countryIsoCode === 'PT';
        } elseif ($paymentMethod === PaymentPaymentMethod::METHOD_KLARNA) {
            return in_array($this->countryIsoCode, ['AT', 'BE', 'CH', 'DE', 'DK', 'ES', 'FI', 'FR', 'GB', 'IT', 'NL', 'NO', 'SE']);
        }

        if ($paymentMethod === 'applePay' && !$this->isSafariBrowser()) {
            return false;
        } elseif ($paymentMethod === 'googlePay' && $this->isSafariBrowser()) {
            return false;
        }

        return true;
    }

    private function getCardPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_CARD') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_CARD)) {
            $customer = $this->context->customer;
            $smarty = $this->context->smarty;

            // Use dynamic cardlogos controller to show multiple brand icons
            $logoUrl = $this->getIconPath('card'); // Default fallback
            if (!empty($this->availableCardBrands)) {
                $logoUrl = $this->context->link->getModuleLink('monei', 'cardlogos', [
                    'brands' => implode(',', $this->availableCardBrands),
                ]);
            }

            $paymentOption = [
                'name' => 'card',
                'logo' => $logoUrl,
                'binary' => false,
                'availableCardBrands' => $this->availableCardBrands,
            ];

            if (\Configuration::get('MONEI_CARD_WITH_REDIRECT')) {
                $redirectUrl = $this->context->link->getModuleLink('monei', 'redirect', [
                    'method' => 'card',
                    'transaction_id' => $this->getTransactionId(),
                ]);
                // Decode HTML entities as URLs should never be HTML-escaped
                $decodedUrl = html_entity_decode($redirectUrl);
                $smarty->assign([
                    'link_create_payment' => $decodedUrl,
                    'module_dir' => _MODULE_DIR_ . 'monei/',
                    'payment_method' => 'card',
                ]);

                $paymentOption['form'] = $smarty->fetch('module:monei/views/templates/hook/paymentOptions.tpl');
            } else {
                $smarty->assign([
                    'isCustomerLogged' => \Validate::isLoadedObject($customer),
                    'tokenize' => (bool) \Configuration::get('MONEI_TOKENIZE'),
                    'module_dir' => _MODULE_DIR_ . 'monei/',
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
        if (\Configuration::get('MONEI_ALLOW_CARD')
            && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_CARD)
            && \Validate::isLoadedObject($customer)
            && ($customer->isLogged() || $customer->isGuest())
        ) {
            $link = $this->context->link;

            // Get current customer cards (not expired ones)
            $activeCustomerCards = Monei2CustomerCard::getByCustomer($customer->id);
            if ($activeCustomerCards) {
                foreach ($activeCustomerCards as $customerCard) {
                    $cardBrand = strtolower($customerCard->getBrand());

                    // Skip cards with brands that are no longer supported
                    if (!in_array($cardBrand, $this->availableCardBrands)) {
                        \PrestaShopLogger::addLog(
                            'MONEI - Skipping saved card with unsupported brand: ' . $cardBrand,
                            \Monei::getLogLevel('info')
                        );

                        continue;
                    }

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
                        'logo' => $this->getCardBrandIconPath($customerCard->getBrand()),
                        'action' => html_entity_decode($redirectUrl),
                    ];
                }
            }
        }
    }

    private function getBizumPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_BIZUM') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_BIZUM)) {
            $paymentOption = [
                'name' => 'bizum',
                'logo' => $this->getIconPath('bizum'),
                'binary' => (bool) !\Configuration::get('MONEI_BIZUM_WITH_REDIRECT'),
            ];

            if (\Configuration::get('MONEI_BIZUM_WITH_REDIRECT')) {
                $redirectUrl = $this->context->link->getModuleLink('monei', 'redirect', [
                    'method' => 'bizum',
                    'transaction_id' => $this->getTransactionId(),
                ]);
                $smarty = $this->context->smarty;
                // Decode HTML entities as URLs should never be HTML-escaped
                $smarty->assign([
                    'link_create_payment' => html_entity_decode($redirectUrl),
                    'module_dir' => _MODULE_DIR_ . 'monei/',
                    'payment_method' => 'bizum',
                ]);

                $paymentOption['form'] = $smarty->fetch('module:monei/views/templates/hook/paymentOptions.tpl');
            }

            $this->paymentOptions[] = $paymentOption;
        }
    }

    private function getApplePayPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_APPLE') && $this->isPaymentMethodAllowed('applePay')) {
            $this->paymentOptions[] = [
                'name' => 'applePay',
                'logo' => $this->getIconPath('applePay'),
                'binary' => true,
            ];
        }
    }

    private function getGooglePayPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_GOOGLE') && $this->isPaymentMethodAllowed('googlePay')) {
            $this->paymentOptions[] = [
                'name' => 'googlePay',
                'logo' => $this->getIconPath('googlePay'),
                'binary' => true,
            ];
        }
    }

    private function getPaypalPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_PAYPAL') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_PAYPAL)) {
            $paymentOption = [
                'name' => 'paypal',
                'logo' => $this->getIconPath('paypal'),
                'binary' => (bool) !\Configuration::get('MONEI_PAYPAL_WITH_REDIRECT'),
            ];

            if (\Configuration::get('MONEI_PAYPAL_WITH_REDIRECT')) {
                $redirectUrl = $this->context->link->getModuleLink('monei', 'redirect', [
                    'method' => 'paypal',
                    'transaction_id' => $this->getTransactionId(),
                ]);
                $smarty = $this->context->smarty;
                // Decode HTML entities as URLs should never be HTML-escaped
                $smarty->assign([
                    'link_create_payment' => html_entity_decode($redirectUrl),
                    'module_dir' => _MODULE_DIR_ . 'monei/',
                    'payment_method' => 'paypal',
                ]);

                $paymentOption['form'] = $smarty->fetch('module:monei/views/templates/hook/paymentOptions.tpl');
            }

            $this->paymentOptions[] = $paymentOption;
        }
    }

    private function getMultibancoPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_MULTIBANCO') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_MULTIBANCO)) {
            $this->paymentOptions[] = [
                'name' => 'multibanco',
                'logo' => $this->getIconPath('multibanco'),
                'binary' => false,
            ];
        }
    }

    private function getMbwayPaymentOption()
    {
        if (\Configuration::get('MONEI_ALLOW_MBWAY') && $this->isPaymentMethodAllowed(PaymentPaymentMethod::METHOD_MBWAY)) {
            $this->paymentOptions[] = [
                'name' => 'mbway',
                'logo' => $this->getIconPath('mbway'),
                'binary' => false,
            ];
        }
    }

    /**
     * Get icon configuration for a payment method
     *
     * @param string $paymentMethod Payment method name
     *
     * @return array Icon configuration with path, width, and height
     */
    private function getIconConfiguration(string $paymentMethod): array
    {
        if (isset(self::PAYMENT_METHOD_ICONS[$paymentMethod])) {
            $config = self::PAYMENT_METHOD_ICONS[$paymentMethod];

            return [
                'path' => \Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/' . $config['path']),
                'width' => $config['width'],
                'height' => $config['height'],
            ];
        }

        // Default icon configuration
        return [
            'path' => \Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/unknown.svg'),
            'width' => '40',
            'height' => '24',
        ];
    }

    /**
     * Get icon path for a payment method
     *
     * @param string $paymentMethod Payment method name
     *
     * @return string Icon path
     */
    private function getIconPath(string $paymentMethod): string
    {
        $config = $this->getIconConfiguration($paymentMethod);

        return $config['path'];
    }

    /**
     * Get icon path for a card brand
     *
     * @param string $brand Card brand name
     *
     * @return string Icon path
     */
    private function getCardBrandIconPath(string $brand): string
    {
        $brandLower = strtolower($brand);

        // Check if brand is available in the merchant's account
        if (!in_array($brandLower, $this->availableCardBrands)) {
            // Return generic card icon if brand is not available
            return \Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/cards.svg');
        }

        $iconFile = $brandLower . '.svg';
        $iconPath = _PS_MODULE_DIR_ . 'monei/views/img/payments/' . $iconFile;

        if (file_exists($iconPath)) {
            return \Media::getMediaPath($iconPath);
        }

        // Fallback to unknown card icon
        return \Media::getMediaPath(_PS_MODULE_DIR_ . 'monei/views/img/payments/unknown.svg');
    }

    /**
     * Get HTML for all available card brand icons
     *
     * @return string HTML string with all card brand icons
     */
    public function getCardBrandsHtml(): string
    {
        $html = '';
        foreach ($this->availableCardBrands as $brand) {
            $iconPath = $this->getCardBrandIconPath($brand);
            $brandName = ucfirst($brand);
            $html .= '<img src="' . $iconPath . '" alt="' . $brandName . '" style="height: 24px; margin-right: 5px;" loading="lazy" />';
        }

        return $html;
    }

    /**
     * Get dynamic card brands logo path
     * Creates or returns a combined SVG with all available brands
     *
     * @return string Path to the combined card brands logo
     */
    private function getDynamicCardBrandsLogo(): string
    {
        // If we have specific brands, try to use a pre-made combined SVG
        // For now, we'll use the generic cards.svg as it's the most reliable
        // In a future enhancement, we could generate SVGs on the fly

        // Check if we have all the common brands (Visa, Mastercard)
        $hasVisa = in_array('visa', $this->availableCardBrands);
        $hasMastercard = in_array('mastercard', $this->availableCardBrands);

        // If we have the two most common brands, use the generic logo
        if ($hasVisa && $hasMastercard) {
            return $this->getIconPath('card');
        }

        // Otherwise, try to use the first available brand's icon
        if (!empty($this->availableCardBrands)) {
            return $this->getCardBrandIconPath($this->availableCardBrands[0]);
        }

        // Fallback to generic card icon
        return $this->getIconPath('card');
    }
}
