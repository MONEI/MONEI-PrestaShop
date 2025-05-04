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
            die('Unauthorized error');
        }

        $requestBody = Tools::file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_MONEI_SIGNATURE'];

        sleep(3); // Adding a delay to prevent processing too quickly and potentially generating duplicate orders.

        try {
            $this->module->getMoneiClient()->verifySignature($requestBody, $sigHeader);
        } catch (MoneiException $e) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - validation.php - postProcess: ' . $e->getMessage() . ' - ' . $e->getFile(),
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

            // Log the JSON array for debugging
            PrestaShopLogger::addLog(
                'MONEI - validation.php - postProcess - JSON Data: ' . json_encode($json_array),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            // Parse the JSON to a MoneiPayment object
            $moneiPayment = new Payment($json_array);

            // Create or update the order
            $this->module->getService('service.order')->createOrUpdateOrder($moneiPayment->getId());
        } catch (MoneiException $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - validation.php - postProcess: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            header('HTTP/1.1 400 Bad Request');
            echo '<h1>Internal Monei Exception</h1>';
            echo $ex->getMessage();
        }

        exit;
    }
}
