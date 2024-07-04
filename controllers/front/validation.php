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
            die();
        }

        $data = Tools::file_get_contents('php://input');

        try {
            // Check if the data is a valid JSON
            $json_array = $this->vJSON($data);
            if (!$json_array) {
                throw new ApiException('Invalid JSON');
            }

            // Parse the JSON to a MoneiPayment object
            $moneiPayment = new MoneiPayment($json_array);

            $this->module->createOrUpdateOrder($moneiPayment->getId());

            echo 'OK';
        } catch (ApiException $ex) {
            PrestaShopLogger::addLog(
                'MONEI - validation:postProcess - ' . $ex->getMessage(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
        } catch (Exception $ex) {
            PrestaShopLogger::addLog($ex->getMessage());
        }

        exit;
    }
}
