<?php

namespace PsMonei\Service\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\Model\PaymentStatus;
use Order;
use PsMonei\Exception\OrderException;
use PsMonei\Helper\PaymentMethodFormatter;
use PsMonei\Service\LockService;
use PsMonei\Service\Monei\MoneiService;

class OrderService
{
    private $moneiInstance;
    private $moneiService;
    private $paymentMethodFormatter;
    private $lockService;
    private $context;

    public function __construct(
        $moneiInstance,
        MoneiService $moneiService,
        PaymentMethodFormatter $paymentMethodFormatter,
        LockService $lockService,
        $context
    ) {
        $this->moneiInstance = $moneiInstance;
        $this->moneiService = $moneiService;
        $this->paymentMethodFormatter = $paymentMethodFormatter;
        $this->lockService = $lockService;
        // For PS1.7 compatibility, we accept context directly
        if (is_object($context) && method_exists($context, 'getContext')) {
            $this->context = $context->getContext();
        } else {
            $this->context = $context;
        }
    }

    public function createOrUpdateOrder($moneiPaymentId, bool $redirectToConfirmationPage = false)
    {
        \PrestaShopLogger::addLog(
            'MONEI - createOrUpdateOrder - START: payment_id=' . $moneiPaymentId
            . ', redirectToConfirmationPage=' . ($redirectToConfirmationPage ? 'true' : 'false'),
            \Monei::getLogLevel('info')
        );

        $connection = \Db::getInstance();

        // Get shop ID for multi-shop support
        $shopId = $this->context->shop->id;

        // Acquire lock for this payment to prevent concurrent processing
        // Include shop ID in lock name for multi-shop compatibility
        $lockName = 'payment_' . $moneiPaymentId . '_shop_' . $shopId;

        if (!$this->lockService->acquireLock($lockName, 30)) {
            \PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Could not acquire lock for payment: ' . $moneiPaymentId, \Monei::getLogLevel('warning'));

            // Another process is handling this payment, so we can safely return
            return;
        }

        try {
            // Check if order already exists
            $query = 'SELECT * FROM ' . _DB_PREFIX_ . 'monei2_order_payment WHERE id_payment = "' . pSQL($moneiPaymentId) . '"';
            $orderPaymentExists = $connection->getRow($query);

            if ($orderPaymentExists) {
                \PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Order: (' . $orderPaymentExists['id_order'] . ') already exists. Payment ID: ' . $moneiPaymentId . ' Date: ' . $orderPaymentExists['date_add'], \Monei::getLogLevel('warning'));

                // If order already exists but we need to redirect, handle it
                if ($redirectToConfirmationPage) {
                    $order = new \Order($orderPaymentExists['id_order']);
                    if (\Validate::isLoadedObject($order)) {
                        $cart = new \Cart($order->id_cart);
                        $customer = new \Customer($order->id_customer);

                        \PrestaShopLogger::addLog(
                            'MONEI - createOrUpdateOrder - Redirecting for existing order_id=' . $order->id,
                            \Monei::getLogLevel('info')
                        );

                        $this->handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order);
                    }
                }

                return;
            }

            try {
                $moneiPayment = $this->moneiService->getMoneiPayment($moneiPaymentId);
            } catch (\Exception $e) {
                throw $e;
            }

            \PrestaShopLogger::addLog(
                'MONEI - createOrUpdateOrder - Got MONEI payment, status=' . $moneiPayment->getStatus() . ', orderId=' . $moneiPayment->getOrderId(),
                \Monei::getLogLevel('info')
            );

            $cartId = $this->moneiService->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());

            \PrestaShopLogger::addLog(
                'MONEI - createOrUpdateOrder - Extracted cart_id=' . $cartId . ' from order_id=' . $moneiPayment->getOrderId(),
                \Monei::getLogLevel('info')
            );

            $cart = $this->validateCart($cartId);
            $customer = $this->validateCustomer($cart->id_customer);

            $orderStateId = $this->determineOrderStateId($moneiPayment->getStatus());
            $failed = $orderStateId === (int) \Configuration::get('MONEI_STATUS_FAILED');

            $order = $this->handleExistingOrder($cartId, $orderStateId, $moneiPayment);

            \PrestaShopLogger::addLog(
                'MONEI - createOrUpdateOrder - After handleExistingOrder, order=' . ($order ? 'EXISTS (id=' . $order->id . ')' : 'NULL'),
                \Monei::getLogLevel('info')
            );

            if (!$order && !$failed) {
                \PrestaShopLogger::addLog(
                    'MONEI - createOrUpdateOrder - Creating new order...',
                    \Monei::getLogLevel('info')
                );
                $order = $this->createNewOrder($cart, $customer, $orderStateId, $moneiPayment);

                \PrestaShopLogger::addLog(
                    'MONEI - createOrUpdateOrder - New order created, order_id=' . ($order ? $order->id : 'NULL'),
                    \Monei::getLogLevel('info')
                );
                // Update payment method name and details for new orders
                $this->updateOrderPaymentMethodName($order, $moneiPayment);
                $this->updateOrderPaymentDetails($order, $moneiPayment);
            }

            if (!\Validate::isLoadedObject($order)) {
                \PrestaShopLogger::addLog(
                    'MONEI - createOrUpdateOrder - ERROR: Order is not a valid object! cart_id=' . $cartId,
                    \Monei::getLogLevel('error')
                );

                throw new OrderException('Order not found', OrderException::ORDER_NOT_FOUND);
            }

            \PrestaShopLogger::addLog(
                'MONEI - createOrUpdateOrder - Order is valid, order_id=' . $order->id,
                \Monei::getLogLevel('info')
            );

            if (!$failed) {
                $this->moneiService->saveMoneiToken($moneiPayment, $customer->id);
            }

            $this->moneiService->saveMoneiPayment($moneiPayment, $order->id);

            // Flag order created or updated
            $sql = 'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'monei2_order_payment (id_order, id_payment, date_add)
                VALUES (' . (int) $order->id . ', "' . pSQL($moneiPaymentId) . '", NOW())';
            if ($connection->execute($sql)) {
                \PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Order (' . $order->id . ') created or updated.', \Monei::getLogLevel('info'));
            }

            \PrestaShopLogger::addLog(
                'MONEI - createOrUpdateOrder - About to call handlePostOrderCreation with order_id=' . $order->id,
                \Monei::getLogLevel('info')
            );

            $this->handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order);
        } catch (OrderException $e) {
            \PrestaShopLogger::addLog(
                'MONEI - CreateOrderService - ' . $e->getMessage(),
                \Monei::getLogLevel('warning')
            );

            throw $e;
        } finally {
            // Always release the lock
            $this->lockService->releaseLock($lockName);
        }
    }

    private function determineOrderStateId($moneiPaymentStatus)
    {
        \PrestaShopLogger::addLog(
            'MONEI - determineOrderStateId - START: moneiPaymentStatus=' . $moneiPaymentStatus,
            \Monei::getLogLevel('info')
        );

        $statusMap = [
            PaymentStatus::REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PARTIALLY_REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PENDING => 'MONEI_STATUS_PENDING',
            PaymentStatus::SUCCEEDED => 'MONEI_STATUS_SUCCEEDED',
            PaymentStatus::AUTHORIZED => 'MONEI_STATUS_AUTHORIZED',
        ];
        $configKey = $statusMap[$moneiPaymentStatus] ?? 'MONEI_STATUS_FAILED';

        \PrestaShopLogger::addLog(
            'MONEI - determineOrderStateId - Mapping payment status=' . $moneiPaymentStatus . ' to config_key=' . $configKey,
            \Monei::getLogLevel('info')
        );

        $orderStateId = (int) \Configuration::get($configKey);

        \PrestaShopLogger::addLog(
            'MONEI - determineOrderStateId - Configuration::get(' . $configKey . ') = ' . $orderStateId,
            \Monei::getLogLevel('info')
        );

        // Validate that the order state exists in PrestaShop
        if ($orderStateId > 0) {
            $orderState = new \OrderState($orderStateId);
            if (!\Validate::isLoadedObject($orderState)) {
                \PrestaShopLogger::addLog(
                    'MONEI - determineOrderStateId - ERROR: OrderState ID ' . $orderStateId . ' does not exist in PrestaShop!',
                    \Monei::getLogLevel('error')
                );
            } else {
                \PrestaShopLogger::addLog(
                    'MONEI - determineOrderStateId - OrderState validated: ID=' . $orderStateId . ', name=' . $orderState->name[1],
                    \Monei::getLogLevel('info')
                );
            }
        } else {
            \PrestaShopLogger::addLog(
                'MONEI - determineOrderStateId - ERROR: Invalid order state ID: ' . $orderStateId,
                \Monei::getLogLevel('error')
            );
        }

        return $orderStateId;
    }

    private function isValidStateTransition($currentOrderState, $newOrderState)
    {
        $validTransitions = [
            \Configuration::get('MONEI_STATUS_PENDING') => [
                \Configuration::get('MONEI_STATUS_SUCCEEDED'),
                \Configuration::get('MONEI_STATUS_FAILED'),
                \Configuration::get('MONEI_STATUS_AUTHORIZED'),
            ],
            \Configuration::get('MONEI_STATUS_AUTHORIZED') => [
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
                    \Monei::getLogLevel('warning')
                );

                throw new OrderException('Order (' . $existingOrder->id . ') already exists with a different payment method.', OrderException::ORDER_ALREADY_EXISTS);
            }

            \PrestaShopLogger::addLog(
                'MONEI - CreateOrderService - Order (' . $existingOrder->id . ') already exists.',
                \Monei::getLogLevel('info')
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
                    \Monei::getLogLevel('warning')
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

    public function updateOrderPaymentMethodName($order, $moneiPayment)
    {
        $paymentMethodName = $this->getPaymentMethodDisplayName($moneiPayment);
        // Update the order payment method name
        $order->payment = $paymentMethodName;
        $order->update();

        // Force update in database to ensure it's saved
        $updated = \Db::getInstance()->update(
            'orders',
            ['payment' => pSQL($paymentMethodName)],
            'id_order = ' . (int) $order->id
        );
        // Also update in order_payment table if exists
        $orderPayment = $order->getOrderPaymentCollection();
        if (count($orderPayment) > 0) {
            $orderPayment[0]->payment_method = $paymentMethodName;
            $orderPayment[0]->update();

            // Force update in database
            \Db::getInstance()->update(
                'order_payment',
                ['payment_method' => pSQL($paymentMethodName)],
                'order_reference = "' . pSQL($order->reference) . '"'
            );
        }
    }

    private function createNewOrder($cart, $customer, $orderStateId, $moneiPayment)
    {
        // Prepare extra vars with payment details
        $extraVars = ['transaction_id' => $moneiPayment->getId()];

        $paymentMethod = $moneiPayment->getPaymentMethod();
        if ($paymentMethod) {
            $method = $paymentMethod->getMethod();

            if ($method === 'bizum' && $paymentMethod->getBizum()) {
                // Bizum payment
                $bizum = $paymentMethod->getBizum();
                if ($bizum->getPhoneNumber()) {
                    $last4 = substr($bizum->getPhoneNumber(), -4);
                    $extraVars['card_number'] = '••••' . $last4;
                    $extraVars['card_brand'] = 'Bizum';
                }
            } elseif ($method === 'card' && $paymentMethod->getCard()) {
                // Card payments (including Apple Pay, Google Pay)
                $card = $paymentMethod->getCard();

                if ($card->getLast4()) {
                    $extraVars['card_number'] = '•••• ' . $card->getLast4();
                }

                // Determine card brand or wallet type
                if ($card->getTokenizationMethod()) {
                    switch ($card->getTokenizationMethod()) {
                        case 'applePay':
                            $extraVars['card_brand'] = 'Apple Pay';

                            break;
                        case 'googlePay':
                            $extraVars['card_brand'] = 'Google Pay';

                            break;
                        default:
                            if ($card->getBrand()) {
                                $extraVars['card_brand'] = ucfirst(strtolower($card->getBrand()));
                            }

                            break;
                    }
                } elseif ($card->getBrand()) {
                    $extraVars['card_brand'] = ucfirst(strtolower($card->getBrand()));
                }

                // Card expiration
                if ($card->getExpiration()) {
                    // Convert timestamp to MM/YY format
                    $extraVars['card_expiration'] = date('m/y', $card->getExpiration());
                }

                // Cardholder name
                if ($card->getCardholderName()) {
                    $extraVars['card_holder'] = $card->getCardholderName();
                }
            }
        }

        \PrestaShopLogger::addLog(
            'MONEI - createNewOrder - About to call validateOrder with cart_id=' . $cart->id
            . ', orderStateId=' . $orderStateId
            . ', amount=' . ($moneiPayment->getAmount() / 100)
            . ', payment_method=' . $this->getPaymentMethodDisplayName($moneiPayment),
            \Monei::getLogLevel('info')
        );

        try {
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

            \PrestaShopLogger::addLog(
                'MONEI - createNewOrder - validateOrder completed successfully',
                \Monei::getLogLevel('info')
            );
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'MONEI - createNewOrder - ERROR in validateOrder: ' . $e->getMessage() . ' - File: ' . $e->getFile() . ' - Line: ' . $e->getLine(),
                \Monei::getLogLevel('error')
            );

            throw $e;
        }

        $order = \Order::getByCartId($cart->id);

        \PrestaShopLogger::addLog(
            'MONEI - createNewOrder - After validateOrder, order=' . ($order ? 'FOUND (id=' . $order->id . ')' : 'NOT FOUND'),
            \Monei::getLogLevel('info')
        );

        return $order;
    }

    private function handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order)
    {
        \PrestaShopLogger::addLog(
            'MONEI - handlePostOrderCreation - START: redirectToConfirmationPage=' . ($redirectToConfirmationPage ? 'true' : 'false')
            . ', cart_id=' . $cart->id
            . ', order_id=' . ($order ? $order->id : 'NULL')
            . ', customer_id=' . $customer->id
            . ', secure_key=' . $customer->secure_key,
            \Monei::getLogLevel('info')
        );

        if ($redirectToConfirmationPage) {
            // Check if order is valid
            if (!$order || !$order->id) {
                \PrestaShopLogger::addLog(
                    'MONEI - handlePostOrderCreation - ERROR: Order is NULL or has no ID!',
                    \Monei::getLogLevel('error')
                );
            }

            // Use context link for proper URL generation in PS1.7
            $confirmationUrl = $this->context->link->getPageLink(
                'order-confirmation',
                null,
                null,
                [
                    'id_cart' => (int) $cart->id,
                    'id_module' => (int) $this->moneiInstance->id,
                    'id_order' => (int) $order->id,
                    'key' => $customer->secure_key,
                ]
            );

            \PrestaShopLogger::addLog(
                'MONEI - handlePostOrderCreation - Generated confirmation URL: ' . $confirmationUrl,
                \Monei::getLogLevel('info')
            );

            \PrestaShopLogger::addLog(
                'MONEI - handlePostOrderCreation - About to redirect to order confirmation page',
                \Monei::getLogLevel('info')
            );

            \Tools::redirect($confirmationUrl);
        } else {
            \PrestaShopLogger::addLog(
                'MONEI - handlePostOrderCreation - Not redirecting, returning OK response',
                \Monei::getLogLevel('info')
            );
            header('HTTP/1.1 200 OK');
            echo '<h1>OK</h1>';
        }
    }

    /**
     * Get formatted payment method display name
     */
    private function getPaymentMethodDisplayName($moneiPayment)
    {
        $paymentMethodName = 'Card';

        $paymentMethod = $moneiPayment->getPaymentMethod();
        if (!$paymentMethod) {
            return $paymentMethodName;
        }

        // Build flattened data array using SDK methods
        $flattenedData = [
            'method' => $paymentMethod->getMethod(),
        ];

        // Handle card payments
        if ($paymentMethod->getMethod() === 'card' && $paymentMethod->getCard()) {
            $card = $paymentMethod->getCard();

            // Add card details
            if ($card->getLast4()) {
                $flattenedData['last4'] = $card->getLast4();
            }
            if ($card->getBrand()) {
                $flattenedData['brand'] = $card->getBrand();
            }
            if ($card->getType()) {
                $flattenedData['type'] = $card->getType();
            }
            if ($card->getExpiration()) {
                $flattenedData['expiration'] = $card->getExpiration();
            }
            if ($card->getCardholderName()) {
                $flattenedData['cardholderName'] = $card->getCardholderName();
            }

            // IMPORTANT: Check for tokenizationMethod for wallet payments
            if ($card->getTokenizationMethod()) {
                $flattenedData['tokenizationMethod'] = $card->getTokenizationMethod();
            }
        }
        // Handle Bizum payments
        elseif ($paymentMethod->getMethod() === 'bizum' && $paymentMethod->getBizum()) {
            $bizum = $paymentMethod->getBizum();
            if ($bizum->getPhoneNumber()) {
                $flattenedData['phoneNumber'] = $bizum->getPhoneNumber();
            }
        }
        // Handle PayPal payments
        elseif ($paymentMethod->getMethod() === 'paypal' && $paymentMethod->getPaypal()) {
            $paypal = $paymentMethod->getPaypal();
            if ($paypal->getEmail()) {
                $flattenedData['email'] = $paypal->getEmail();
            }
        }
        // Format the payment method display name
        if (!empty($flattenedData)) {
            $displayName = $this->paymentMethodFormatter->formatPaymentMethodDisplay($flattenedData);
            if ($displayName) {
                $paymentMethodName = $displayName;
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

            // Special handling for method-specific data (card, paypal, bizum, etc.)
            if ($key === 'card' || $key === 'paypal' || $key === 'bizum') {
                // Flatten the nested payment method data
                foreach ($value as $nestedKey => $nestedValue) {
                    if (!is_array($nestedValue)) {
                        $result[$nestedKey] = $nestedValue;
                    }
                }
            } else {
                // For other arrays, try to flatten them
                foreach ($value as $nestedKey => $nestedValue) {
                    if (is_array($nestedValue)) {
                        foreach ($nestedValue as $deepKey => $deepValue) {
                            $result[$deepKey] = $deepValue;
                        }
                    } else {
                        $result[$nestedKey] = $nestedValue;
                    }
                }
            }
        }

        // No need to map tokenizationMethod as it's already in camelCase in the API response
        // But keep the mapping for other fields that might be in snake_case
        if (isset($result['cardholder_name'])) {
            $result['cardholderName'] = $result['cardholder_name'];
        }
        if (isset($result['cardholder_email'])) {
            $result['cardholderEmail'] = $result['cardholder_email'];
        }

        return $result;
    }

    /**
     * Update order payment details with card information
     *
     * @param \Order $order
     * @param Payment $moneiPayment
     */
    public function updateOrderPaymentDetails($order, $moneiPayment)
    {
        $orderPayment = $order->getOrderPaymentCollection();
        if (count($orderPayment) > 0) {
            $payment = $orderPayment[0];

            // Extract payment details using SDK methods
            $cardNumber = null;
            $cardBrand = null;
            $cardExpiration = null;
            $cardHolder = null;

            $paymentMethod = $moneiPayment->getPaymentMethod();
            if ($paymentMethod) {
                $method = $paymentMethod->getMethod();

                if ($method === 'bizum' && $paymentMethod->getBizum()) {
                    // Bizum payment
                    $cardBrand = 'Bizum';
                    $bizum = $paymentMethod->getBizum();
                    if ($bizum->getPhoneNumber()) {
                        $last4 = substr($bizum->getPhoneNumber(), -4);
                        $cardNumber = '••••' . $last4;
                    }
                } elseif ($method === 'card' && $paymentMethod->getCard()) {
                    // Card payments (including Apple Pay, Google Pay)
                    $card = $paymentMethod->getCard();

                    // Card number
                    if ($card->getLast4()) {
                        $cardNumber = '•••• ' . $card->getLast4();
                    }

                    // Determine card brand or wallet type
                    if ($card->getTokenizationMethod()) {
                        switch ($card->getTokenizationMethod()) {
                            case 'applePay':
                                $cardBrand = 'Apple Pay';

                                break;
                            case 'googlePay':
                                $cardBrand = 'Google Pay';

                                break;
                            default:
                                if ($card->getBrand()) {
                                    $cardBrand = ucfirst(strtolower($card->getBrand()));
                                }

                                break;
                        }
                    } elseif ($card->getBrand()) {
                        $cardBrand = ucfirst(strtolower($card->getBrand()));
                    }

                    // Expiration
                    if ($card->getExpiration()) {
                        $cardExpiration = date('m/y', $card->getExpiration());
                    }

                    // Cardholder
                    if ($card->getCardholderName()) {
                        $cardHolder = $card->getCardholderName();
                    }
                }
            }

            // Update the order payment object
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
