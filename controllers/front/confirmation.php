<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\MoneiException;
use Monei\Model\MoneiPaymentStatus;

class MoneiConfirmationModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $moneiPaymentId = Tools::getValue('id');
        $moneiStatus = Tools::getValue('status');

        try {
            if (!empty($moneiPaymentId) && $moneiStatus !== MoneiPaymentStatus::CANCELED) {
                $this->module->createOrUpdateOrder($moneiPaymentId, true);
            } else {
                Tools::redirect('index.php?controller=order');
            }
        } catch (MoneiException $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - confirmation.php - initContent: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                $this->module::LOG_SEVERITY_LEVELS['error']
            );

            $this->context->cookie->monei_error = $ex->getMessage();
            Tools::redirect(
                $this->context->link->getModuleLink($this->module->name, 'errors')
            );
        }
    }
}
