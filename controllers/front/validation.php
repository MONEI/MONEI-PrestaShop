<?php

use Monei\Model\Payment;
use PsMonei\MoneiException;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // If the module is not active anymore, no need to process anything.
        if (!$this->module->active) {
            die('Module is not active');
        }

        if (!isset($_SERVER['HTTP_MONEI_SIGNATURE'])) {
            PrestaShopLogger::addLog(
                '[MONEI] Webhook validation failed - Missing signature header',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );
            die('Unauthorized error');
        }

        $requestBody = Tools::file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_MONEI_SIGNATURE'];

        try {
            $this->module->getMoneiClient()->verifySignature($requestBody, $sigHeader);
        } catch (MoneiException $e) {
            PrestaShopLogger::addLog(
                '[MONEI] Webhook signature verification failed [error=' . $e->getMessage() . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            header('HTTP/1.1 401 Unauthorized');
            echo '<h1>Unauthorized</h1>';
            echo $e->getMessage();
            exit;
        }

        try {
            // Check if the data is a valid JSON
            $json_array = json_decode($requestBody, true);
            if (!$json_array) {
                throw new MoneiException('Invalid JSON', MoneiException::INVALID_JSON_RESPONSE);
            }

            // Log webhook received with minimal data (avoid logging full payload)
            $paymentId = isset($json_array['id']) ? $json_array['id'] : 'unknown';
            $status = isset($json_array['status']) ? $json_array['status'] : 'unknown';
            $statusCode = isset($json_array['statusCode']) ? $json_array['statusCode'] : null;
            
            PrestaShopLogger::addLog(
                '[MONEI] Webhook received [payment_id=' . $paymentId . ', status=' . $status . 
                ($statusCode ? ', status_code=' . $statusCode : '') . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            $moneiPayment = new Payment($json_array);

            // Create or update the order
            $orderId = Monei::getService('service.order')->createOrUpdateOrder($moneiPayment->getId());
            
            PrestaShopLogger::addLog(
                '[MONEI] Webhook processed successfully [payment_id=' . $moneiPayment->getId() . 
                ', order_id=' . ($orderId ? $orderId : 'pending') . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
        } catch (MoneiException $ex) {
            $paymentId = isset($json_array['id']) ? $json_array['id'] : 'unknown';
            PrestaShopLogger::addLog(
                '[MONEI] Webhook processing failed [payment_id=' . $paymentId . ', error=' . $ex->getMessage() . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            header('HTTP/1.1 400 Bad Request');
            echo '<h1>Internal Monei Exception</h1>';
            echo $ex->getMessage();
        }

        exit;
    }
}
