<?php
use Monei\ApiException;
use Monei\Model\MoneiPayment;
use Monei\MoneiException;
use Monei\Traits\ValidationHelpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiValidationModuleFrontController extends ModuleFrontController
{
    use ValidationHelpers;

    public function postProcess()
    {
        // If the module is not active anymore, no need to process anything.
        if (!$this->module->active) {
            die('Module is not active');
        }

        if (!isset($_SERVER['HTTP_MONEI_SIGNATURE'])) {
            die('HTTP_MONEI_SIGNATURE is not set');
        }

        $requestBody = Tools::file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_MONEI_SIGNATURE'];

        try {
            $this->module->getMoneiClient()->verifySignature($requestBody, $sigHeader);
        } catch (ApiException $e) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - validation.php - postProcess: ' . $e->getMessage() . ' - ' . $e->getFile(),
                $this->module::LOG_SEVERITY_LEVELS['error']
            );

            header('HTTP/1.1 401 Unauthorized');
            echo '<h1>Unauthorized</h1>';
            echo $e->getMessage();
            exit;
        }

        try {
            // Check if the data is a valid JSON
            $json_array = $this->vJSON($requestBody);
            if (!$json_array) {
                throw new ApiException('Invalid JSON');
            }

            // Log the JSON array for debugging
            PrestaShopLogger::addLog(
                'MONEI - JSON Data: ' . json_encode($json_array),
                $this->module::LOG_SEVERITY_LEVELS['info']
            );

            // Parse the JSON to a MoneiPayment object
            $moneiPayment = new MoneiPayment($json_array);

            // Create or update the order
            // The ID is sent instead of the object, as if the card token is to be saved, it must be queried via the API and cannot be done from the object.
            // https://docs.monei.com/docs/guides/save-payment-method/#2-obtain-and-store-payment-token
            $this->module->createOrUpdateOrder($moneiPayment->getId());

            // Log the order creation/update for debugging
            PrestaShopLogger::addLog(
                'MONEI - Order created/updated for Payment ID: ' . $moneiPayment->getId(),
                $this->module::LOG_SEVERITY_LEVELS['info']
            );
        } catch (MoneiException $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - validation.php - postProcess: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                $this->module::LOG_SEVERITY_LEVELS['error']
            );

            header('HTTP/1.1 400 Bad Request');
            echo '<h1>Internal Monei Exception</h1>';
            echo $ex->getMessage();
        }

        exit;
    }
}
