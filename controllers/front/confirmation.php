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

        $moneiPaymentId = Tools::getValue('id');
        $moneiStatus = Tools::getValue('status');
        $moneiStatusCode = Tools::getValue('statusCode');

        try {
            if (!empty($moneiPaymentId) && $moneiStatus !== PaymentStatus::CANCELED) {
                PrestaShopLogger::addLog(
                    'MONEI - confirmation.php - initContent - Monei Payment ID: ' . $moneiPaymentId . ' - Monei Status: ' . $moneiStatus . ' - Status Code: ' . $moneiStatusCode,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );

                // Store status code if payment failed
                if ($moneiStatus === PaymentStatus::FAILED && $moneiStatusCode) {
                    $this->context->cookie->monei_error_code = $moneiStatusCode;
                }

                $this->module->getService('service.order')->createOrUpdateOrder($moneiPaymentId, true);
            } else {
                Tools::redirect('index.php?controller=order');
            }
        } catch (Exception $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - confirmation.php - initContent: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            // Store the exception message for technical errors
            $this->context->cookie->monei_error = $ex->getMessage();
            
            // If it's a MoneiException with a payment response, try to extract status code
            if ($ex instanceof \PsMonei\Exception\MoneiException && method_exists($ex, 'getPaymentData')) {
                $paymentData = $ex->getPaymentData();
                if ($paymentData && isset($paymentData['statusCode'])) {
                    $this->context->cookie->monei_error_code = $paymentData['statusCode'];
                }
            }
            
            Tools::redirect(
                $this->context->link->getModuleLink($this->module->name, 'errors')
            );
        }
    }
}
