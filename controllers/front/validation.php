<?php
use Monei\ApiException;
use Monei\Model\MoneiPayment;
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
        if ($this->module->active == false) {
            die('Module is not active');
        }

        $data = Tools::file_get_contents('php://input');

        try {
            // Check if the data is a valid JSON
            $json_array = $this->vJSON($data);
            if (!$json_array) {
                throw new ApiException('Invalid JSON');
            }

            // Log the JSON array for debugging
            PrestaShopLogger::addLog(
                'MONEI - JSON Data: ' . json_encode($json_array),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            // Parse the JSON to a MoneiPayment object
            $moneiPayment = new MoneiPayment($json_array);

            // Create or update the order
            $this->module->createOrUpdateOrder($moneiPayment);

            // Log the order creation/update for debugging
            PrestaShopLogger::addLog(
                'MONEI - Order created/updated for Payment ID: ' . $moneiPayment->getId(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
        } catch (Exception $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - validation.php - postProcess: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            header('HTTP/1.1 400 Bad Request');
            echo '<h1>Internal Monei Exception</h1>';
            echo $ex->getMessage();
            exit;
        }

        exit;
    }
}
