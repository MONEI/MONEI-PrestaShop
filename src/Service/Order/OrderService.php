<?php

namespace PsMonei\Service\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\Model\PaymentStatus;
use Order;
use PsMonei\Exception\OrderException;
use PsMonei\Helper\PaymentMethodFormatter;
use PsMonei\Service\Monei\MoneiService;

class OrderService
{
    private $moneiInstance;
    private $moneiService;
    private $paymentMethodFormatter;

    public function __construct($moneiInstance, MoneiService $moneiService, PaymentMethodFormatter $paymentMethodFormatter)
    {
        $this->moneiInstance = $moneiInstance;
        $this->moneiService = $moneiService;
        $this->paymentMethodFormatter = $paymentMethodFormatter;
    }

    public function createOrUpdateOrder($moneiPaymentId, bool $redirectToConfirmationPage = false)
    {
        $connection = \Db::getInstance();

        try {
            // Check if order already exists
            $query = 'SELECT * FROM ' . _DB_PREFIX_ . 'monei2_order_payment WHERE id_payment = "' . pSQL($moneiPaymentId) . '"';
            $orderPaymentExists = $connection->getRow($query);
            if ($orderPaymentExists) {
                \PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Order: (' . $orderPaymentExists['id_order'] . ') already exists. Payment ID: ' . $moneiPaymentId . ' Date: ' . $orderPaymentExists['date_add'], \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING);
            }

            $moneiPayment = $this->moneiService->getMoneiPayment($moneiPaymentId);
            $cartId = $this->moneiService->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());
            $cart = $this->validateCart($cartId);
            $customer = $this->validateCustomer($cart->id_customer);

            $orderStateId = $this->determineOrderStateId($moneiPayment->getStatus());
            $failed = $orderStateId === (int) \Configuration::get('MONEI_STATUS_FAILED');

            $order = $this->handleExistingOrder($cartId, $orderStateId, $moneiPayment);

            if (!$order && !$failed) {
                $order = $this->createNewOrder($cart, $customer, $orderStateId, $moneiPayment);
            }

            if (!\Validate::isLoadedObject($order)) {
                throw new OrderException('Order not found', OrderException::ORDER_NOT_FOUND);
            }

            if (!$failed) {
                $this->moneiService->saveMoneiToken($moneiPayment, $customer->id);
            }

            $this->moneiService->saveMoneiPayment($moneiPayment, $order->id);

            // Flag order created or updated
            $sql = 'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'monei2_order_payment (id_order, id_payment, date_add)
                VALUES (' . (int) $order->id . ', "' . pSQL($moneiPaymentId) . '", NOW())';
            if ($connection->execute($sql)) {
                \PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Order (' . $order->id . ') created or updated.', \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);
            }

            $this->handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order);
        } catch (OrderException $e) {
            \PrestaShopLogger::addLog(
                'MONEI - CreateOrderService - ' . $e->getMessage(),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );

            throw $e;
        }
    }

    private function determineOrderStateId($moneiPaymentStatus)
    {
        $statusMap = [
            PaymentStatus::REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PARTIALLY_REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PENDING => 'MONEI_STATUS_PENDING',
            PaymentStatus::SUCCEEDED => 'MONEI_STATUS_SUCCEEDED',
        ];
        $configKey = $statusMap[$moneiPaymentStatus] ?? 'MONEI_STATUS_FAILED';

        return (int) \Configuration::get($configKey);
    }

    private function isValidStateTransition($currentOrderState, $newOrderState)
    {
        $validTransitions = [
            \Configuration::get('MONEI_STATUS_PENDING') => [
                \Configuration::get('MONEI_STATUS_SUCCEEDED'),
                \Configuration::get('MONEI_STATUS_FAILED'),
            ],
            \Configuration::get('MONEI_STATUS_SUCCEEDED') => [
                \Configuration::get('MONEI_STATUS_REFUNDED'),
                \Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED'),
            ],
        ];

        return isset($validTransitions[$currentOrderState])
               && in_array($newOrderState, $validTransitions[$currentOrderState]);
    }

    public function validateCart($cartId)
    {
        $cart = new \Cart($cartId);
        if (!\Validate::isLoadedObject($cart)) {
            throw new OrderException('Cart #' . $cartId . ' not valid', OrderException::CART_NOT_VALID);
        }

        return $cart;
    }

    public function validateCustomer($customerId)
    {
        $customer = new \Customer($customerId);
        if (!\Validate::isLoadedObject($customer)) {
            throw new OrderException('Customer #' . $customerId . ' not valid', OrderException::CUSTOMER_NOT_VALID);
        }

        return $customer;
    }

    private function handleExistingOrder($cartId, $orderStateId, $moneiPayment)
    {
        $existingOrder = \Order::getByCartId($cartId);
        if (\Validate::isLoadedObject($existingOrder)) {
            if ($existingOrder->module !== $this->moneiInstance->name) {
                \PrestaShopLogger::addLog(
                    'MONEI - CreateOrderService - Order (' . $existingOrder->id . ') already exists with a different payment method.',
                    \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                );

                throw new OrderException('Order (' . $existingOrder->id . ') already exists with a different payment method.', OrderException::ORDER_ALREADY_EXISTS);
            }

            \PrestaShopLogger::addLog(
                'MONEI - CreateOrderService - Order (' . $existingOrder->id . ') already exists.',
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            $this->updateExistingOrder($existingOrder, $orderStateId, $moneiPayment);

            return $existingOrder;
        }

        return null;
    }

    private function updateExistingOrder($order, $orderStateId, $moneiPayment)
    {
        $orderState = new \OrderState($orderStateId);
        if (\Validate::isLoadedObject($orderState)) {
            if ($this->isValidStateTransition($order->current_state, $orderStateId)) {
                $order->setCurrentState($orderStateId);
                $this->updateOrderPaymentTransactionId($order, $moneiPayment->getId());
                $this->updateOrderPaymentMethodName($order, $moneiPayment);
                $this->updateOrderPaymentDetails($order, $moneiPayment);
            } else {
                \PrestaShopLogger::addLog(
                    'MONEI - Invalid state transition from ' . $order->current_state . ' to ' . $orderStateId,
                    \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                );
            }
        }
    }

    public function updateOrderStateAfterRefund(int $orderId)
    {
        if ((int) \Configuration::get('MONEI_SWITCH_REFUNDS') === 0) {
            return;
        }

        $order = new \Order($orderId);
        $totalOrderRefunded = $this->moneiService->getTotalRefundedByIdOrder($orderId);
        if ($order->getTotalPaid() > $totalOrderRefunded) {
            $order->setCurrentState(\Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED'));
        } else {
            $order->setCurrentState(\Configuration::get('MONEI_STATUS_REFUNDED'));
        }
    }

    private function updateOrderPaymentTransactionId($order, $transactionId)
    {
        $orderPayment = $order->getOrderPaymentCollection();
        if (count($orderPayment) > 0) {
            $orderPayment[0]->transaction_id = $transactionId;
            $orderPayment[0]->save();
        }
    }

    private function updateOrderPaymentMethodName($order, $moneiPayment)
    {
        $paymentMethodName = $this->getPaymentMethodDisplayName($moneiPayment);

        // Update the order payment method name
        $order->payment = $paymentMethodName;
        $order->save();

        // Also update in order_payment table if exists
        $orderPayment = $order->getOrderPaymentCollection();
        if (count($orderPayment) > 0) {
            $orderPayment[0]->payment_method = $paymentMethodName;
            $orderPayment[0]->save();
        }
    }

    private function createNewOrder($cart, $customer, $orderStateId, $moneiPayment)
    {
        // Extract payment details before order creation
        $paymentMethodData = $moneiPayment->getPaymentMethod() ? $moneiPayment->getPaymentMethod()->jsonSerialize() : [];
        $flattenedData = $this->flattenPaymentMethodData($paymentMethodData);

        // Prepare extra vars with payment details
        $extraVars = ['transaction_id' => $moneiPayment->getId()];

        // Extract payment details based on payment method
        $paymentMethod = $flattenedData['method'] ?? '';

        if ($paymentMethod === 'bizum' && !empty($flattenedData['phoneNumber'])) {
            // Bizum payment
            $last4 = substr($flattenedData['phoneNumber'], -4);
            $extraVars['card_number'] = '••••' . $last4;
            $extraVars['card_brand'] = 'Bizum';
        } else {
            // Card payments (including Apple Pay, Google Pay)
            if (!empty($flattenedData['last4'])) {
                $extraVars['card_number'] = '•••• ' . $flattenedData['last4'];
            }

            // Determine card brand
            if (isset($flattenedData['tokenizationMethod']) && !empty($flattenedData['tokenizationMethod'])) {
                switch ($flattenedData['tokenizationMethod']) {
                    case 'applePay':
                        $extraVars['card_brand'] = 'Apple Pay';

                        break;
                    case 'googlePay':
                        $extraVars['card_brand'] = 'Google Pay';

                        break;
                    default:
                        if (!empty($flattenedData['brand'])) {
                            $extraVars['card_brand'] = ucfirst(strtolower($flattenedData['brand']));
                        }

                        break;
                }
            } elseif (!empty($flattenedData['brand'])) {
                $extraVars['card_brand'] = ucfirst(strtolower($flattenedData['brand']));
            }

            // Card expiration
            if (!empty($flattenedData['expiration'])) {
                $expiration = $flattenedData['expiration'];
                if (strlen($expiration) === 4) {
                    $extraVars['card_expiration'] = substr($expiration, 0, 2) . '/' . substr($expiration, 2, 2);
                } else {
                    $extraVars['card_expiration'] = $expiration;
                }
            }

            // Cardholder name
            if (!empty($flattenedData['cardholderName'])) {
                $extraVars['card_holder'] = $flattenedData['cardholderName'];
            }
        }

        $this->moneiInstance->validateOrder(
            $cart->id,
            $orderStateId,
            $moneiPayment->getAmount() / 100,
            $this->getPaymentMethodDisplayName($moneiPayment),
            '',
            $extraVars,
            $cart->id_currency,
            false,
            $customer->secure_key
        );

        return \Order::getByCartId($cart->id);
    }

    private function handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order)
    {
        if ($redirectToConfirmationPage) {
            \Tools::redirect(
                'index.php?controller=order-confirmation'
                . '&id_cart=' . $cart->id
                . '&id_module=' . $this->moneiInstance->id
                . '&id_order=' . $order->id
                . '&key=' . $customer->secure_key
            );
        } else {
            header('HTTP/1.1 200 OK');
            echo '<h1>OK</h1>';
        }
    }

    /**
     * Get formatted payment method display name
     */
    private function getPaymentMethodDisplayName($moneiPayment)
    {
        // Extract payment method data
        $paymentMethodData = $moneiPayment->getPaymentMethod() ? $moneiPayment->getPaymentMethod()->jsonSerialize() : [];

        // Flatten the payment method data (like Magento does)
        $flattenedData = $this->flattenPaymentMethodData($paymentMethodData);

        // Format the payment method display name
        $paymentMethodName = 'MONEI';
        if (!empty($flattenedData)) {
            $displayName = $this->paymentMethodFormatter->formatPaymentMethodDisplay($flattenedData);
            if ($displayName) {
                $paymentMethodName = 'MONEI ' . $displayName;
            }
        }

        return $paymentMethodName;
    }

    /**
     * Flatten payment method data structure (like Magento does)
     */
    private function flattenPaymentMethodData($paymentMethodData)
    {
        $result = [];

        foreach ($paymentMethodData as $key => $value) {
            if (!is_array($value)) {
                $result[$key] = $value;

                continue;
            }

            // Flatten nested arrays (like 'card', 'paypal', 'bizum', etc.)
            foreach ($value as $nestedKey => $nestedValue) {
                $result[$nestedKey] = $nestedValue;
            }
        }

        return $result;
    }

    /**
     * Update order payment details with card information
     *
     * @param \Order $order
     * @param Payment $moneiPayment
     */
    private function updateOrderPaymentDetails($order, $moneiPayment)
    {
        $orderPayment = $order->getOrderPaymentCollection();
        if (count($orderPayment) > 0) {
            // Get payment method data
            $paymentMethodData = $moneiPayment->getPaymentMethod() ? $moneiPayment->getPaymentMethod()->jsonSerialize() : [];
            $flattenedData = $this->flattenPaymentMethodData($paymentMethodData);

            // Extract payment details
            $cardNumber = null;
            $cardBrand = null;
            $cardExpiration = null;
            $cardHolder = null;

            // Check payment method type
            $paymentMethod = $flattenedData['method'] ?? '';

            // Handle Bizum payments
            if ($paymentMethod === 'bizum') {
                $cardBrand = 'Bizum';

                // Get phone number and format last 4 digits
                if (isset($flattenedData['phoneNumber']) && !empty($flattenedData['phoneNumber'])) {
                    $last4 = substr($flattenedData['phoneNumber'], -4);
                    $cardNumber = '••••' . $last4;
                }
            } else {
                // Get last 4 digits and format as •••• XXXX for card payments
                if (isset($flattenedData['last4']) && !empty($flattenedData['last4'])) {
                    $cardNumber = '•••• ' . $flattenedData['last4'];
                }
            }

            // Determine card brand or wallet type (only for non-Bizum payments)
            if ($paymentMethod !== 'bizum') {
                if (isset($flattenedData['tokenizationMethod']) && !empty($flattenedData['tokenizationMethod'])) {
                    // For wallet payments, use the wallet type as brand
                    switch ($flattenedData['tokenizationMethod']) {
                        case 'applePay':
                            $cardBrand = 'Apple Pay';

                            break;
                        case 'googlePay':
                            $cardBrand = 'Google Pay';

                            break;
                        default:
                            // For other tokenization methods, use the card brand if available
                            if (isset($flattenedData['brand']) && !empty($flattenedData['brand'])) {
                                $cardBrand = ucfirst(strtolower($flattenedData['brand']));
                            }

                            break;
                    }
                } elseif (isset($flattenedData['brand']) && !empty($flattenedData['brand'])) {
                    // Regular card payment - use the card brand
                    $cardBrand = ucfirst(strtolower($flattenedData['brand']));
                }
            }

            // Get expiration date
            if (isset($flattenedData['expiration']) && !empty($flattenedData['expiration'])) {
                // Format as MM/YY
                $expiration = $flattenedData['expiration'];
                if (strlen($expiration) === 4) {
                    $cardExpiration = substr($expiration, 0, 2) . '/' . substr($expiration, 2, 2);
                } else {
                    $cardExpiration = $expiration;
                }
            }

            // Get cardholder name
            if (isset($flattenedData['cardholderName']) && !empty($flattenedData['cardholderName'])) {
                $cardHolder = $flattenedData['cardholderName'];
            }

            // Update the order payment object
            $payment = $orderPayment[0];

            if ($cardNumber !== null) {
                $payment->card_number = $cardNumber;
            }
            if ($cardBrand !== null) {
                $payment->card_brand = $cardBrand;
            }
            if ($cardExpiration !== null) {
                $payment->card_expiration = $cardExpiration;
            }
            if ($cardHolder !== null) {
                $payment->card_holder = $cardHolder;
            }

            $payment->save();
        }
    }
}
