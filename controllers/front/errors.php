<?php


require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiErrorsModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $error = [];
        $error[] = $this->context->cookie->monei_error;

        $this->context->smarty->assign([
            'errors' => $error,
        ]);

        $this->setTemplate('module:' . $this->module->name . '/views/templates/front/errors.tpl');
        parent::initContent();
    }
}
