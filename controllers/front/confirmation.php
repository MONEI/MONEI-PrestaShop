<?php


require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiConfirmationModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        // For errors
        $sucess = (int)Tools::getValue('success');
        if ($sucess === 0) {
            $message = Tools::getValue('message');
            $errors[] = $message;
            $this->context->smarty->assign([
                'errors' => $errors,
                'monei_success' => false,
            ]);
        } else {
            $id_cart = (int)Tools::getValue('cart_id');
            $id_order = Tools::getValue('order_id');
            $monei_id_order = Tools::getValue('id');

            $this->context->smarty->assign([
                'module_dir' => $this->module->getPathUri(),
                'monei_success' => true,
                'monei_cart_id' => $id_cart,
                'monei_order_id' => $id_order,
                'monei_id' => $monei_id_order,
            ]);
        }

        $this->setTemplate('module:' . $this->module->name . '/views/templates/front/blank_confirmation.tpl');
        parent::initContent();
    }
}
