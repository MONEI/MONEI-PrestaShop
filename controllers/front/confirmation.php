<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\Model\PaymentStatus;

class MoneiConfirmationModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        // Get payment ID from various possible parameter names
        $moneiPaymentId = Tools::getValue('id') ?: Tools::getValue('payment_id') ?: Tools::getValue('paymentId');
        $cartId = Tools::getValue('cart_id');
        $orderId = Tools::getValue('order_id');

        PrestaShopLogger::addLog(
            'MONEI - confirmation.php - Payment completion received: payment_id=' . $moneiPaymentId . ', cart_id=' . $cartId . ', order_id=' . $orderId,
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        try {
            // Handle missing payment ID case
            if (!$moneiPaymentId) {
                PrestaShopLogger::addLog(
                    'MONEI - confirmation.php - Missing payment ID, URL params: ' . json_encode($_GET),
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
                
                $this->context->cookie->monei_checkout_error = $this->module->l('There was a problem processing your payment. Please try again.');
                $this->context->cookie->write();
                
                Tools::redirect($this->context->link->getPageLink('order'));
                return;
            }

            // Fetch payment data from MONEI API to determine actual status
            PrestaShopLogger::addLog(
                'MONEI - confirmation.php - About to fetch payment from API: ' . $moneiPaymentId,
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
            
            $moneiService = $this->module->getService('service.monei');
            $payment = null;
            
            try {
                $payment = $moneiService->getMoneiPayment($moneiPaymentId);
                
                PrestaShopLogger::addLog(
                    'MONEI - confirmation.php - Payment retrieved from API: status=' . $payment->getStatus() . ', statusCode=' . $payment->getStatusCode(),
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    'MONEI - confirmation.php - Error retrieving payment from API: ' . $e->getMessage() . ' - Stack: ' . $e->getTraceAsString(),
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
                
                $this->context->cookie->monei_checkout_error = $this->module->l('An error occurred while processing your payment. Please try again.');
                $this->context->cookie->write();
                
                Tools::redirect($this->context->link->getPageLink('order'));
                return;
            }

            // Route based on payment status
            $paymentStatus = $payment->getStatus();
            
            switch ($paymentStatus) {
                case PaymentStatus::SUCCEEDED:
                case PaymentStatus::AUTHORIZED:
                    $this->handleSuccessfulPayment($moneiPaymentId);
                    break;
                    
                case PaymentStatus::PENDING:
                    $this->handlePendingPayment($payment);
                    break;
                    
                case PaymentStatus::FAILED:
                case PaymentStatus::CANCELED:
                case PaymentStatus::EXPIRED:
                default:
                    $this->handleFailedPayment($payment);
                    break;
            }
            
        } catch (Exception $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - confirmation.php - initContent: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            // Store the exception message for technical errors
            $this->context->cookie->monei_checkout_error = $this->module->l('An unexpected error occurred. Please try again.');
            $this->context->cookie->write();
            
            Tools::redirect($this->context->link->getPageLink('order'));
        }
    }

    /**
     * Handle successful payment (SUCCEEDED or AUTHORIZED status)
     */
    private function handleSuccessfulPayment($moneiPaymentId)
    {
        PrestaShopLogger::addLog(
            'MONEI - confirmation.php - Processing successful payment: ' . $moneiPaymentId,
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Process the order through the order service
        $this->module->getService('service.order')->createOrUpdateOrder($moneiPaymentId, true);
    }

    /**
     * Handle pending payment (for MBWAY and similar methods)
     */
    private function handlePendingPayment($payment)
    {
        $paymentId = $payment->getId();
        
        PrestaShopLogger::addLog(
            'MONEI - confirmation.php - Payment still in pending state: ' . $paymentId,
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Check if this is a Multibanco payment (which can remain pending for days)
        $paymentMethod = $payment->getPaymentMethod();
        $isMultibanco = $paymentMethod && strtolower($paymentMethod->getType()) === 'multibanco';
        
        if ($isMultibanco) {
            // For Multibanco, create the order and show success page with pending message
            $this->module->getService('service.order')->createOrUpdateOrder($paymentId, true);
        } else {
            // For other pending payments (like MBWAY), redirect to a loading page
            // that will periodically check payment status
            $this->redirectToLoadingPage($paymentId);
        }
    }

    /**
     * Handle failed payment (FAILED, CANCELED, EXPIRED status)
     */
    private function handleFailedPayment($payment)
    {
        $paymentStatus = $payment->getStatus();
        $statusCode = $payment->getStatusCode();
        
        PrestaShopLogger::addLog(
            'MONEI - confirmation.php - Payment failed: status=' . $paymentStatus . ', statusCode=' . $statusCode,
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Get localized error message based on status code
        $errorMessage = '';
        $statusCodeHandler = $this->module->getService('service.status_code_handler');
        
        if ($statusCode) {
            $errorMessage = $statusCodeHandler->getStatusMessage($statusCode);
        }
        
        // Fallback error messages based on status
        if (!$errorMessage) {
            switch ($paymentStatus) {
                case PaymentStatus::CANCELED:
                    $errorMessage = $this->module->l('Payment was canceled.');
                    break;
                case PaymentStatus::EXPIRED:
                    $errorMessage = $this->module->l('Payment expired. Please try again.');
                    break;
                case PaymentStatus::FAILED:
                default:
                    $errorMessage = $this->module->l('Payment failed. Please try again.');
                    break;
            }
        }
        
        // Store error message in cookie for display on checkout page
        $this->context->cookie->monei_checkout_error = $errorMessage;
        $this->context->cookie->write();
        
        // Redirect directly to checkout page where user can retry
        Tools::redirect($this->context->link->getPageLink('order'));
    }

    /**
     * Redirect to loading page for pending payments that need real-time status checking
     */
    private function redirectToLoadingPage($paymentId)
    {
        // For now, redirect to a simple loading page
        // In future, this could be enhanced with JavaScript polling like Magento
        $this->context->smarty->assign([
            'payment_id' => $paymentId,
            'loading_message' => $this->module->l('Checking payment status, please wait...'),
            'complete_url' => $this->context->link->getModuleLink('monei', 'confirmation'),
        ]);
        
        $this->setTemplate('module:monei/views/templates/front/loading.tpl');
    }
}
