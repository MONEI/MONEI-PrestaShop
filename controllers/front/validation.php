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
            http_response_code(503);
            echo 'Service Unavailable';
            exit;
        }

        if (!isset($_SERVER['HTTP_MONEI_SIGNATURE'])) {
            http_response_code(401);
            echo 'Unauthorized';
            exit;
        }

        $requestBody = Tools::file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_MONEI_SIGNATURE'];

        try {
            $this->module->getMoneiClient()->verifySignature($requestBody, $sigHeader);
        } catch (Throwable $e) {
            // Catch all exceptions during signature verification
            PrestaShopLogger::addLog(
                '[MONEI] Webhook signature verification failed: ' . $e->getMessage(),
                Monei::getLogLevel('error')
            );

            http_response_code(401);
            echo 'Unauthorized';
            exit;
        }

        try {
            // Check if the data is a valid JSON
            $json_array = json_decode($requestBody, true);
            if (!$json_array) {
                throw new MoneiException('Invalid JSON', MoneiException::INVALID_JSON_RESPONSE);
            }

            // Parse the JSON to a MoneiPayment object
            $moneiPayment = new Payment($json_array);

            PrestaShopLogger::addLog(
                '[MONEI] Webhook received [payment_id=' . $moneiPayment->getId() . ']',
                Monei::getLogLevel('info')
            );

            // Create or update the order (returns void)
            Monei::getService('service.order')->createOrUpdateOrder($moneiPayment->getId());

            // Success response
            http_response_code(200);
            echo 'OK';
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MONEI] Webhook processing error: ' . $e->getMessage(),
                Monei::getLogLevel('error')
            );

            http_response_code(400);
            echo 'Bad Request';
        }

        exit;
    }
}
