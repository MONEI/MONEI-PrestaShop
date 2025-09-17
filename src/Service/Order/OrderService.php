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
        $connection = \Db::getInstance();

        // Get shop ID for multi-shop support
        $shopId = $this->context->shop->id;

        // Acquire lock for this payment to prevent concurrent processing
        // Include shop ID in lock name for multi-shop compatibility
        $lockName = 'payment_' . $moneiPaymentId . '_shop_' . $shopId;

        if (!$this->lockService->acquireLock($lockName, 30)) {
            // Another process is handling this payment, so we can safely return
            return;
        }

        try {
            // Check if order already exists
            $query = 'SELECT * FROM ' . _DB_PREFIX_ . 'monei2_order_payment WHERE id_payment = "' . pSQL($moneiPaymentId) . '"';
            $orderPaymentExists = $connection->getRow($query);

            if ($orderPaymentExists) {
                // If order already exists and redirect is requested, redirect to confirmation page
                if ($redirectToConfirmationPage) {
                    $order = new \Order($orderPaymentExists['id_order']);
                    if (\Validate::isLoadedObject($order)) {
                        $cart = new \Cart($order->id_cart);
                        $customer = new \Customer($order->id_customer);
                        $this->handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order);
                    }
                }

                return;
            }

            $moneiPayment = $this->moneiService->getMoneiPayment($moneiPaymentId);

            $cartId = $this->moneiService->getCartIdFromPayment($moneiPayment);

            $cart = $this->validateCart($cartId);
            $customer = $this->validateCustomer($cart->id_customer);

            $orderStateId = $this->determineOrderStateId($moneiPayment->getStatus());
            $failed = $orderStateId === (int) \Configuration::get('MONEI_STATUS_FAILED');

            $order = $this->handleExistingOrder($cartId, $orderStateId, $moneiPayment);

            // Create order only for non-failed payments
            if (!$order && !$failed) {
                $order = $this->createNewOrder($cart, $customer, $orderStateId, $moneiPayment);
                // Update payment method name and details for new orders
                $this->updateOrderPaymentMethodName($order, $moneiPayment);
                $this->updateOrderPaymentDetails($order, $moneiPayment);
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
            }

            // Store variables for post-processing
            $postProcessData = [
                'redirect' => $redirectToConfirmationPage,
                'cart' => $cart,
                'customer' => $customer,
                'order' => $order,
            ];
        } catch (OrderException $e) {
            \Monei::logWarning('[MONEI] Order processing warning [payment_id=' . $moneiPaymentId . ', error=' . $e->getMessage() . ']');

            throw $e;
        } catch (\Throwable $e) {
            // Catch any unexpected exceptions to ensure they're logged before lock release
            \Monei::logError('[MONEI] Unexpected error during order processing [payment_id=' . $moneiPaymentId
                . ', error=' . $e->getMessage()
                . ', file=' . $e->getFile()
                . ', line=' . $e->getLine() . ']');

            throw $e;
        } finally {
            // Always release the lock before any operation that might exit
            $this->lockService->releaseLock($lockName);
        }

        // Call handlePostOrderCreation only if no exception was thrown
        // Variables are guaranteed to be set here since we only reach this point on success
        if (isset($postProcessData)) {
            $this->handlePostOrderCreation(
                $postProcessData['redirect'],
                $postProcessData['cart'],
                $postProcessData['customer'],
                $postProcessData['order']
            );
        }
    }

    private function determineOrderStateId($moneiPaymentStatus)
    {
        $statusMap = [
            PaymentStatus::REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PARTIALLY_REFUNDED => 'MONEI_STATUS_PARTIALLY_REFUNDED',
            PaymentStatus::PENDING => 'MONEI_STATUS_PENDING',
            PaymentStatus::SUCCEEDED => 'MONEI_STATUS_SUCCEEDED',
            PaymentStatus::AUTHORIZED => 'MONEI_STATUS_AUTHORIZED',
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
                \Configuration::get('MONEI_STATUS_AUTHORIZED'),
            ],
            \Configuration::get('MONEI_STATUS_AUTHORIZED') => [
                \Configuration::get('MONEI_STATUS_SUCCEEDED'),
                \Configuration::get('MONEI_STATUS_FAILED'),
            ],
            \Configuration::get('MONEI_STATUS_FAILED') => [
                \Configuration::get('MONEI_STATUS_SUCCEEDED'),
                \Configuration::get('MONEI_STATUS_AUTHORIZED'),
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
                \Monei::logWarning('[MONEI] Order conflict - Different payment method [order_id=' . $existingOrder->id . ', existing_module=' . $existingOrder->module . ']');

                throw new OrderException('Order (' . $existingOrder->id . ') already exists with a different payment method.', OrderException::ORDER_ALREADY_EXISTS);
            }

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
                \Monei::logDebug('[MONEI] Order status transition [order_id=' . $order->id
                    . ', from_state=' . $order->current_state
                    . ', to_state=' . $orderStateId
                    . ', payment_status=' . $moneiPayment->getStatus() . ']');

                $order->setCurrentState($orderStateId);
                $this->updateOrderPaymentTransactionId($order, $moneiPayment->getId());
                $this->updateOrderPaymentMethodName($order, $moneiPayment);
                $this->updateOrderPaymentDetails($order, $moneiPayment);
            } else {
                \Monei::logWarning('[MONEI] Invalid order state transition [order_id=' . $order->id . ', from_state=' . $order->current_state . ', to_state=' . $orderStateId . ']');
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
            $newState = \Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED');
            \Monei::logDebug('[MONEI] Order partially refunded [order_id=' . $orderId
                . ', total_paid=' . $order->getTotalPaid()
                . ', total_refunded=' . $totalOrderRefunded . ']');
            $order->setCurrentState($newState);
        } else {
            $newState = \Configuration::get('MONEI_STATUS_REFUNDED');
            \Monei::logDebug('[MONEI] Order fully refunded [order_id=' . $orderId
                . ', total_refunded=' . $totalOrderRefunded . ']');
            $order->setCurrentState($newState);
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

        // Store the MONEI order ID in context to use as PrestaShop order reference
        $context = \Context::getContext();
        $context->monei_order_reference = $moneiPayment->getOrderId();

        \Monei::logDebug('[MONEI] Creating new order [cart_id=' . $cart->id
            . ', payment_id=' . $moneiPayment->getId()
            . ', amount=' . ($moneiPayment->getAmount() / 100)
            . ', currency=' . $cart->id_currency
            . ', payment_status=' . $moneiPayment->getStatus() . ']');

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

        $order = \Order::getByCartId($cart->id);

        if ($order && \Validate::isLoadedObject($order)) {
            \Monei::logDebug('[MONEI] Order created successfully [order_id=' . $order->id
                . ', reference=' . $order->reference . ']');
        }

        return $order;
    }

    private function handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order)
    {
        if ($redirectToConfirmationPage) {
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

            \Tools::redirect($confirmationUrl);
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
