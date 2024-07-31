<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\Model\MoneiPaymentStatus;

class MoneiConfirmationModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $moneiPaymentId = Tools::getValue('id');
        $moneiStatus = Tools::getValue('status');
        if (!empty($moneiPaymentId) && $moneiStatus !== MoneiPaymentStatus::CANCELED) {
            $this->module->createOrUpdateOrder($moneiPaymentId, true);
        } else {
            Tools::redirect('index.php?controller=order');
        }
    }
}
