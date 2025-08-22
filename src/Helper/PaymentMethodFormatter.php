<?php

declare(strict_types=1);

namespace PsMonei\Helper;

class PaymentMethodFormatter
{
    private const CARD_BRANDS = [
        'visa' => 'Visa',
        'mastercard' => 'MasterCard',
        'amex' => 'American Express',
        'americanexpress' => 'American Express',
        'discover' => 'Discover',
        'dinersclub' => 'Diners Club',
        'jcb' => 'JCB',
        'unionpay' => 'UnionPay',
        'maestro' => 'Maestro',
    ];

    private const PAYMENT_METHODS = [
        'card' => 'Card',
        'bizum' => 'Bizum',
        'paypal' => 'PayPal',
        'applepay' => 'Apple Pay',
        'apple_pay' => 'Apple Pay',
        'googlepay' => 'Google Pay',
        'google_pay' => 'Google Pay',
        'multibanco' => 'Multibanco',
        'mbway' => 'MB Way',
    ];

    private const BIN_RANGES = [
        'visa' => [
            ['4', '4'],
        ],
        'mastercard' => [
            ['51', '55'],
            ['2221', '2720'],
        ],
        'amex' => [
            ['34', '34'],
            ['37', '37'],
        ],
        'discover' => [
            ['6011', '6011'],
            ['622126', '622925'],
            ['644', '649'],
            ['65', '65'],
        ],
        'dinersclub' => [
            ['300', '305'],
            ['36', '36'],
            ['54', '54'],
        ],
        'jcb' => [
            ['3528', '3589'],
        ],
        'maestro' => [
            ['5018', '5018'],
            ['5020', '5020'],
            ['5038', '5038'],
            ['5893', '5893'],
            ['6304', '6304'],
            ['6759', '6759'],
            ['6761', '6763'],
        ],
    ];

    /**
     * Format payment method display name
     */
    public function formatPaymentMethodName(string $method, ?string $brand = null): string
    {
        $method = strtolower($method);

        if ($method === 'card' && $brand) {
            $brand = strtolower($brand);

            return self::CARD_BRANDS[$brand] ?? ucfirst($brand);
        }

        return self::PAYMENT_METHODS[$method] ?? ucfirst($method);
    }

    /**
     * Format card number with last 4 digits
     */
    public function formatCardNumber(?string $last4): string
    {
        if (empty($last4)) {
            return '';
        }

        return '•••• ' . $last4;
    }

    /**
     * Obfuscate email address, showing only first 2 and last 2 characters before @
     */
    public function obfuscateEmail(string $email): string
    {
        if (empty($email)) {
            return '';
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $localPart = $parts[0];
        $domain = $parts[1];

        $localLength = strlen($localPart);
        if ($localLength <= 4) {
            // For short email addresses, show first character only
            $obfuscated = substr($localPart, 0, 1) . str_repeat('•', $localLength - 1);
        } else {
            // Show first 2 and last 2 characters
            $obfuscated = substr($localPart, 0, 2) . '••••' . substr($localPart, -2);
        }

        return $obfuscated . '@' . $domain;
    }

    /**
     * Format complete payment display
     */
    public function formatPaymentDisplay(string $method, ?string $brand = null, ?string $last4 = null): string
    {
        $methodName = $this->formatPaymentMethodName($method, $brand);

        if ($method === 'card' && $last4) {
            return $methodName . ' ' . $this->formatCardNumber($last4);
        }

        return $methodName;
    }

    /**
     * Get payment method icon path
     */
    public function getPaymentMethodIcon(string $method, ?string $brand = null): string
    {
        $baseUrl = \Context::getContext()->shop->getBaseURL(true) . 'modules/monei/views/img/payment-methods/';

        if ($method === 'card' && $brand) {
            $brand = strtolower($brand);
            $iconFile = $brand . '.svg';
        } else {
            $method = strtolower($method);
            $iconFile = str_replace('_', '-', $method) . '.svg';
        }

        // Check if icon exists, otherwise return fallback
        $iconPath = _PS_MODULE_DIR_ . 'monei/views/img/payment-methods/' . $iconFile;
        if (!file_exists($iconPath)) {
            $iconFile = 'generic-card.svg';
        }

        return $baseUrl . $iconFile;
    }

    /**
     * Render payment method icon HTML
     */
    public function renderPaymentMethodIcon(string $method, ?string $brand = null, array $options = []): string
    {
        $width = $options['width'] ?? 32;
        $height = $options['height'] ?? 32;
        $class = $options['class'] ?? 'payment-method-icon';
        $alt = $options['alt'] ?? $this->formatPaymentMethodName($method, $brand);

        $iconUrl = $this->getPaymentMethodIcon($method, $brand);

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="%s" loading="lazy" />',
            \Tools::safeOutput($iconUrl),
            \Tools::safeOutput($alt),
            $width,
            $height,
            \Tools::safeOutput($class)
        );
    }

    /**
     * Detect card brand from BIN (first 6 digits)
     */
    public function detectCardBrand(string $cardNumber): ?string
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        if (strlen($cardNumber) < 4) {
            return null;
        }

        foreach (self::BIN_RANGES as $brand => $ranges) {
            foreach ($ranges as $range) {
                $start = $range[0];
                $end = $range[1];
                $length = strlen($start);
                $prefix = substr($cardNumber, 0, $length);

                if ($prefix >= $start && $prefix <= $end) {
                    return $brand;
                }
            }
        }

        return null;
    }

    /**
     * Format payment details for admin display
     * Handles flattened payment data structure from MONEI API
     */
    public function formatAdminPaymentDetails(array $paymentData): array
    {
        $method = $paymentData['method'] ?? 'unknown';
        $brand = $paymentData['brand'] ?? null;
        $tokenizationMethod = $paymentData['tokenizationMethod'] ?? null;

        // Determine icon based on tokenization method first (for Apple Pay, Google Pay)
        if ($tokenizationMethod === 'applePay') {
            $iconMethod = 'apple-pay';
            $iconBrand = null;
        } elseif ($tokenizationMethod === 'googlePay') {
            $iconMethod = 'google-pay';
            $iconBrand = null;
        } elseif ($method === 'card' && $brand) {
            // For regular card payments, use the card brand icon
            $iconMethod = 'card';
            $iconBrand = $brand;
        } else {
            // For other payment methods (Bizum, PayPal, etc), use the method icon
            $iconMethod = $method;
            $iconBrand = null;
        }

        $formatted = [
            'method' => $method,
            'method_display' => $this->formatPaymentMethodDisplay($paymentData),
            'icon' => $this->renderPaymentMethodIcon($iconMethod, $iconBrand, ['width' => 24, 'height' => 24]),
            'details' => [],
        ];

        // Card details
        if (!empty($brand)) {
            $formatted['details']['brand'] = $this->formatPaymentMethodName('card', $brand);
        }

        // Only add card_number to details if it's not already in method_display
        // For regular cards, it's already included in method_display, so we don't duplicate it
        // We still keep it in details for the Payment Details section
        if (!empty($paymentData['last4'])) {
            $formatted['details']['card_number'] = $this->formatCardNumber($paymentData['last4']);
        }

        if (!empty($paymentData['cardholderName'])) {
            $formatted['details']['cardholder'] = $paymentData['cardholderName'];
        }

        // Authorization code
        if (!empty($paymentData['authorizationCode'])) {
            $formatted['details']['auth_code'] = $paymentData['authorizationCode'];
        }

        // PayPal details
        if (!empty($paymentData['email'])) {
            $formatted['details']['paypal_email'] = $this->obfuscateEmail($paymentData['email']);
        }

        // Bizum details
        if ($method === 'bizum' && !empty($paymentData['phoneNumber'])) {
            // Get last 4 digits of phone number
            $last4 = substr($paymentData['phoneNumber'], -4);
            $formatted['details']['bizum_phone'] = '••••' . $last4;
            // Don't add to card_number - it's already included in method_display
        }

        return $formatted;
    }

    /**
     * Format payment method display based on flattened payment information
     * This follows the Magento approach for consistent formatting
     *
     * @param array $paymentInfo Flattened payment information array
     *
     * @return string Formatted payment method display text
     */
    public function formatPaymentMethodDisplay(array $paymentInfo): string
    {
        $paymentMethodDisplay = '';
        $methodType = $paymentInfo['method'] ?? '';

        // Check tokenizationMethod first for wallet payments (Apple Pay, Google Pay)
        if (isset($paymentInfo['tokenizationMethod']) && !empty($paymentInfo['tokenizationMethod'])) {
            $tokenizationMethod = $paymentInfo['tokenizationMethod'];

            // Map tokenization method to display name
            switch ($tokenizationMethod) {
                case 'applePay':
                    $paymentMethodDisplay = 'Apple Pay';

                    break;
                case 'googlePay':
                    $paymentMethodDisplay = 'Google Pay';

                    break;
                default:
                    // If it's another tokenization method, use the brand logic below
                    break;
            }

            // Add card details if available
            if ($paymentMethodDisplay && isset($paymentInfo['last4']) && !empty($paymentInfo['last4'])) {
                $paymentMethodDisplay .= ' ' . $this->formatCardNumber($paymentInfo['last4']);
            }
        }

        // If no tokenization method or not a recognized wallet, use existing logic
        if (!$paymentMethodDisplay) {
            if (isset($paymentInfo['brand']) && !empty($paymentInfo['brand'])) {
                // Card payment display
                $brand = strtolower($paymentInfo['brand']);
                $paymentMethodDisplay = $this->formatPaymentMethodName('card', $brand);

                // Add card type inline if available
                if (isset($paymentInfo['type']) && !empty($paymentInfo['type'])) {
                    $paymentMethodDisplay .= ' ' . ucfirst($paymentInfo['type']);
                }

                if (isset($paymentInfo['last4']) && !empty($paymentInfo['last4'])) {
                    $paymentMethodDisplay .= ' ' . $this->formatCardNumber($paymentInfo['last4']);
                }
            } elseif (!empty($methodType)) {
                // Non-card payment methods
                $paymentMethodDisplay = $this->formatPaymentMethodName($methodType);

                // Handle specific methods with extra formatting
                switch ($methodType) {
                    case 'bizum':
                        // Add phone number for Bizum if available
                        if (isset($paymentInfo['phoneNumber']) && !empty($paymentInfo['phoneNumber'])) {
                            // Get last 4 digits of phone number
                            $last4 = substr($paymentInfo['phoneNumber'], -4);
                            $paymentMethodDisplay .= ' ••••' . $last4;
                        }

                        break;

                    case 'paypal':
                        // Add PayPal email if available (obfuscated)
                        if (isset($paymentInfo['email']) && !empty($paymentInfo['email'])) {
                            $paymentMethodDisplay .= ' (' . $this->obfuscateEmail($paymentInfo['email']) . ')';
                        }

                        break;
                }
            }
        }

        return $paymentMethodDisplay;
    }

    /**
     * Get localized payment method name
     */
    public function getLocalizedPaymentMethodName(string $method, ?string $brand = null): string
    {
        $context = \Context::getContext();
        $langId = (int) $context->language->id;

        // Use PrestaShop translation system
        $translationKey = 'payment_method_' . strtolower($method);
        if ($method === 'card' && $brand) {
            $translationKey = 'card_brand_' . strtolower($brand);
        }

        // This would use the module's translation system
        return $this->formatPaymentMethodName($method, $brand);
    }

    /**
     * Check if payment method icon exists
     */
    public function hasPaymentMethodIcon(string $method, ?string $brand = null): bool
    {
        if ($method === 'card' && $brand) {
            $iconFile = strtolower($brand) . '.svg';
        } else {
            $iconFile = str_replace('_', '-', strtolower($method)) . '.svg';
        }

        $iconPath = _PS_MODULE_DIR_ . 'monei/views/img/payment-methods/' . $iconFile;

        return file_exists($iconPath);
    }
}
