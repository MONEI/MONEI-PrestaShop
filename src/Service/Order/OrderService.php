<?php
namespace PsMonei\Service\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Cart;
use Configuration;
use Customer;
use Db;
use OpenAPI\Client\Model\PaymentStatus;
use Order;
use OrderState;
use PrestaShopLogger;
use PsMonei\Exception\OrderException;
use PsMonei\Service\Monei\MoneiService;
use Tools;
use Validate;

class OrderService
{
    private $moneiInstance;
    private $moneiService;

    public function __construct($moneiInstance, MoneiService $moneiService)
    {
        $this->moneiInstance = $moneiInstance;
        $this->moneiService = $moneiService;
    }

    public function createOrUpdateOrder($moneiPaymentId, bool $redirectToConfirmationPage = false)
    {
        try {
            // Check if order already exists
            $query = 'SELECT * FROM ' . _DB_PREFIX_ . 'monei2_order_payment WHERE id_payment = "' . $moneiPaymentId . '"';
            $orderPaymentExists = Db::getInstance()->getRow($query);
            if ($orderPaymentExists) {
                PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Order: (' . $result['id_order'] . ') already exists. Payment ID: ' . $moneiPaymentId . ' Date: ' . $result['date_add'], PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING);
            }

            $moneiPayment = $this->moneiService->getMoneiPayment($moneiPaymentId);
            $cartId = $this->moneiService->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());
            $cart = $this->validateCart($cartId);
            $customer = $this->validateCustomer($cart->id_customer);

            $orderStateId = $this->determineOrderStateId($moneiPayment->getStatus());
            $failed = $orderStateId === (int) Configuration::get('MONEI_STATUS_FAILED');

            $order = $this->handleExistingOrder($cartId, $orderStateId, $moneiPayment);

            if (!$order && !$failed) {
                $order = $this->createNewOrder($cart, $customer, $orderStateId, $moneiPayment);
            }

            if (!$failed) {
                $this->moneiService->saveMoneiToken($moneiPayment, $customer->id);
            }

            $this->moneiService->saveMoneiPayment($moneiPayment, $order->id);

            // Flag order created or updated
            $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'monei2_order_payment (id_order, id_payment, date_add) VALUES (' . $order->id . ', "' . $moneiPaymentId . '", NOW())';
            if (!$orderPaymentExists && Db::getInstance()->execute($sql)) {
                PrestaShopLogger::addLog('MONEI - createOrUpdateOrder - Order (' . $order->id . ') created or updated.', PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);
            }

            $this->handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order);
        } catch (OrderException $e) {
            PrestaShopLogger::addLog(
                'MONEI - CreateOrderService - ' . $e->getMessage(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );
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

        return (int) Configuration::get($configKey);
    }

    public function validateCart($cartId)
    {
        $cart = new Cart($cartId);
        if (!Validate::isLoadedObject($cart)) {
            throw new OrderException('Cart #' . $cartId . ' not valid', OrderException::CART_NOT_VALID);
        }

        return $cart;
    }

    public function validateCustomer($customerId)
    {
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer)) {
            throw new OrderException('Customer #' . $customerId . ' not valid', OrderException::CUSTOMER_NOT_VALID);
        }

        return $customer;
    }

    private function handleExistingOrder($cartId, $orderStateId, $moneiPayment)
    {
        $existingOrder = Order::getByCartId($cartId);
        if (Validate::isLoadedObject($existingOrder)) {
            if ($existingOrder->module !== $this->moneiInstance->name) {
                PrestaShopLogger::addLog(
                    'MONEI - CreateOrderService - Order (' . $existingOrder->id . ') already exists with a different payment method.',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                );

                throw new OrderException('Order (' . $existingOrder->id . ') already exists with a different payment method.', OrderException::ORDER_ALREADY_EXISTS);
            }

            PrestaShopLogger::addLog(
                'MONEI - CreateOrderService - Order (' . $existingOrder->id . ') already exists.',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            $this->updateExistingOrder($existingOrder, $orderStateId, $moneiPayment);

            return $existingOrder;
        }

        return null;
    }

    private function updateExistingOrder($order, $orderStateId, $moneiPayment)
    {
        $orderState = new OrderState($orderStateId);
        if (Validate::isLoadedObject($orderState)) {
            $pendingStates = [(int) Configuration::get('MONEI_STATUS_PENDING')];
            if (in_array((int) $order->current_state, $pendingStates)) {
                $order->setCurrentState($orderStateId);
                $this->updateOrderPaymentTransactionId($order, $moneiPayment->getId());
            }
        }
    }

    public function updateOrderStateAfterRefund(int $orderId)
    {
        if ((int) Configuration::get('MONEI_SWITCH_REFUNDS') === 0) {
            return;
        }

        $order = new Order($orderId);
        $totalOrderRefunded = $this->moneiService->getTotalRefundedByIdOrder($orderId);
        if ($order->getTotalPaid() > $totalOrderRefunded) {
            $order->setCurrentState(Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED'));
        } else {
            $order->setCurrentState(Configuration::get('MONEI_STATUS_REFUNDED'));
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

    private function createNewOrder($cart, $customer, $orderStateId, $moneiPayment)
    {
        $this->moneiInstance->validateOrder(
            $cart->id,
            $orderStateId,
            $moneiPayment->getAmount() / 100,
            'MONEI ' . $moneiPayment->getPaymentMethod()->getMethod(),
            '',
            ['transaction_id' => $moneiPayment->getId()],
            $cart->id_currency,
            false,
            $customer->secure_key
        );

        return Order::getByCartId($cart->id);
    }

    private function handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order)
    {
        if ($redirectToConfirmationPage) {
            Tools::redirect(
                'index.php?controller=order-confirmation'
                . '&id_cart=' . $cart->id
                . '&id_module=' . $this->moneiInstance->id
                . '&id_order=' . $order->id
                . '&key=' . $customer->secure_key
            );
        } else {
            echo 'OK';
        }
    }
}
