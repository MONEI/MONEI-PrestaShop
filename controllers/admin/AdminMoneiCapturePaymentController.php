<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PsMonei\Exception\MoneiException;

class AdminMoneiCapturePaymentController extends ModuleAdminController
{
    public function __construct()
    {
        $this->module = 'monei';
        $this->table = false;
        $this->className = '';

        parent::__construct();

        $this->bootstrap = true;
        $this->ajax = true;
        $this->display = 'ajax';
    }

    public function initContent()
    {
        if (Tools::getValue('ajax')) {
            $this->ajax = true;
        }
        parent::initContent();
    }

    public function postProcess()
    {
        if (Tools::getValue('ajax')) {
            $action = Tools::getValue('action');

            switch ($action) {
                case 'capturePayment':
                    $this->ajaxProcessCapturePayment();

                    break;
                default:
                    die(json_encode([
                        'success' => false,
                        'message' => $this->module->l('Invalid action'),
                    ]));
            }
        }
    }

    public function renderView()
    {
        // This controller is only meant for AJAX requests
        // If accessed directly, return a simple message
        return '<div class="alert alert-info">This controller handles AJAX capture requests only. Please use the capture button from the order details page.</div>';
    }

    public function ajaxProcessCapturePayment()
    {
        // Get module instance
        if (!$this->module) {
            $this->module = Module::getInstanceByName('monei');
        }

        // Check permissions
        if (!$this->access('edit')) {
            die(json_encode([
                'success' => false,
                'message' => $this->module->l('You do not have permission to capture payments'),
            ]));
        }

        $orderId = (int) Tools::getValue('id_order');
        $amount = (float) Tools::getValue('amount');

        if (!$orderId) {
            die(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid order ID'),
            ]));
        }

        if ($amount <= 0) {
            die(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid capture amount'),
            ]));
        }

        // Verify order exists and belongs to this shop
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            die(json_encode([
                'success' => false,
                'message' => $this->module->l('Order not found'),
            ]));
        }

        // Check if order was paid with MONEI
        if ($order->module !== 'monei') {
            die(json_encode([
                'success' => false,
                'message' => $this->module->l('This order was not paid with MONEI'),
            ]));
        }

        try {
            // Get the services
            $captureService = Monei::getService('service.capture');
            $orderService = Monei::getService('service.order');
            $moneiService = Monei::getService('service.monei');
            $rateLimiter = Monei::getService('service.capture_rate_limiter');

            // Check rate limiting
            if (!$rateLimiter->isAllowed($orderId)) {
                $remainingAttempts = $rateLimiter->getRemainingAttempts($orderId);
                die(json_encode([
                    'success' => false,
                    'message' => $this->module->l('Too many capture attempts. Please try again later.'),
                    'remaining_attempts' => $remainingAttempts,
                ]));
            }

            // Record the attempt
            $rateLimiter->recordAttempt($orderId);

            // Convert amount to cents for MONEI API
            $amountInCents = (int) round($amount * 100);

            // Capture the payment
            $capturedPayment = $captureService->capturePayment($orderId, $amountInCents);

            // Update order status to payment accepted and update payment details
            $order = new Order($orderId);
            if (Validate::isLoadedObject($order)) {
                $newOrderStatus = (int) Configuration::get('MONEI_STATUS_SUCCEEDED');
                if ($newOrderStatus && $order->current_state != $newOrderStatus) {
                    $order->setCurrentState($newOrderStatus);
                    $order->save();
                }

                // Update order payment amount to reflect actual captured amount
                $orderPayments = $order->getOrderPaymentCollection();
                if (count($orderPayments) > 0) {
                    $orderPayment = $orderPayments[0];
                    $orderPayment->amount = $amount; // Update to captured amount
                    $orderPayment->update();
                }

                // Fetch full payment details from MONEI to get payment method information
                try {
                    $fullPayment = $moneiService->getMoneiPayment($capturedPayment->getId());
                    // Update payment method details from MONEI payment
                    $orderService->updateOrderPaymentMethodName($order, $fullPayment);
                    $orderService->updateOrderPaymentDetails($order, $fullPayment);
                } catch (Exception $e) {
                    // Log error but don't fail the capture
                    PrestaShopLogger::addLog(
                        'MONEI - Failed to update payment method details: ' . $e->getMessage(),
                        PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                    );
                }
            }

            die(json_encode([
                'success' => true,
                'message' => $this->module->l('Payment captured successfully'),
                'payment_id' => $capturedPayment->getId(),
                'status' => $capturedPayment->getStatus(),
            ]));
        } catch (MoneiException $e) {
            PrestaShopLogger::addLog(
                'MONEI - Capture payment error: ' . $e->getMessage(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR,
                $e->getCode(),
                'MoneiCapturePaymentController',
                $orderId
            );

            die(json_encode([
                'success' => false,
                'message' => $this->getErrorMessage($e),
            ]));
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'MONEI - Capture payment general error: ' . $e->getMessage(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR,
                null,
                'MoneiCapturePaymentController',
                $orderId
            );

            die(json_encode([
                'success' => false,
                'message' => $this->module->l('An unexpected error occurred while capturing the payment'),
            ]));
        }
    }

    /**
     * Get user-friendly error message based on exception
     *
     * @param MoneiException $exception
     *
     * @return string
     */
    private function getErrorMessage(MoneiException $exception)
    {
        switch ($exception->getCode()) {
            case MoneiException::ORDER_NOT_FOUND:
                return $this->module->l('Payment record not found for this order');
            case MoneiException::PAYMENT_ID_EMPTY:
                return $this->module->l('Payment ID is missing');
            case MoneiException::PAYMENT_ALREADY_CAPTURED:
                return $this->module->l('This payment has already been captured');
            case MoneiException::PAYMENT_NOT_AUTHORIZED:
                return $this->module->l('This payment is not in an authorized state');
            case MoneiException::INVALID_CAPTURE_AMOUNT:
                return $this->module->l('Invalid capture amount');
            case MoneiException::CAPTURE_AMOUNT_EXCEEDS_AUTHORIZED:
                return $this->module->l('Capture amount exceeds the authorized amount');
            case MoneiException::API_KEY_NOT_CONFIGURED:
                return $this->module->l('MONEI API key is not configured');
            case MoneiException::CAPTURE_FAILED:
                return $this->module->l('Failed to capture payment: ') . $exception->getMessage();
            default:
                return $this->module->l('An error occurred: ') . $exception->getMessage();
        }
    }
}
