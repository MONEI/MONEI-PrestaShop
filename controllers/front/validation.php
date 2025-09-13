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
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Service Unavailable';
            exit;
        }

        // Enforce POST-only webhook endpoint
        if (Tools::strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed';
            exit;
        }

        if (!isset($_SERVER['HTTP_MONEI_SIGNATURE'])) {
            PrestaShopLogger::addLog('[MONEI] Missing webhook signature header', Monei::getLogLevel('warning'));
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
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
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unauthorized';
            exit;
        }

        try {
            // Robust JSON parsing with explicit error detection
            $json_array = json_decode($requestBody, true);
            if ($json_array === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new MoneiException(
                    'Invalid JSON: ' . json_last_error_msg(),
                    MoneiException::INVALID_JSON_RESPONSE
                );
            }
            if (!$json_array) {
                throw new MoneiException('Empty JSON response', MoneiException::INVALID_JSON_RESPONSE);
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
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OK';
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MONEI] Webhook processing error: ' . $e->getMessage(),
                Monei::getLogLevel('error')
            );

            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bad Request';
        }

        exit;
    }
}
