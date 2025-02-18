<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PsMonei\Exception\MoneiException;
use OpenAPI\Client\Model\PaymentStatus;
class MoneiConfirmationModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $moneiPaymentId = Tools::getValue('id');
        $moneiStatus = Tools::getValue('status');

        try {
            if (!empty($moneiPaymentId) && $moneiStatus !== PaymentStatus::CANCELED) {
                PrestaShopLogger::addLog(
                    'MONEI - confirmation.php - initContent - Monei Payment ID: ' . $moneiPaymentId . ' - Monei Status: ' . $moneiStatus,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );

                $this->module->getService('service.order')->createOrUpdateOrder($moneiPaymentId, true);
            } else {
                Tools::redirect('index.php?controller=order');
            }
        } catch (MoneiException $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - confirmation.php - initContent: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            $this->context->cookie->monei_error = $ex->getMessage();
            Tools::redirect(
                $this->context->link->getModuleLink($this->module->name, 'errors')
            );
        }
    }
}
