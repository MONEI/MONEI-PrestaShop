<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiConfirmationModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $moneiPaymentId = Tools::getValue('id');
        if (!empty($moneiPaymentId)) {
            $this->module->createOrUpdateOrder($moneiPaymentId, true);
        } else {
            Tools::redirect('index.php?controller=order');
        }
    }
}
