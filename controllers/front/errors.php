<?php

require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiErrorsModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $error = [];
        
        // Check if we have a status code to translate
        if (!empty($this->context->cookie->monei_error_code)) {
            $statusCodeHandler = $this->module->getService('monei.service.status_code_handler');
            $statusCode = $this->context->cookie->monei_error_code;
            
            // Get the localized error message
            $error[] = $statusCodeHandler->getStatusMessage($statusCode);
            
            // Clear the status code from cookie
            unset($this->context->cookie->monei_error_code);
        } elseif (!empty($this->context->cookie->monei_error)) {
            // Fall back to the raw error message if no status code
            $error[] = $this->context->cookie->monei_error;
        }
        
        // Clear the error from cookie after displaying
        unset($this->context->cookie->monei_error);

        $this->context->smarty->assign([
            'errors' => $error,
        ]);

        $this->setTemplate('module:' . $this->module->name . '/views/templates/front/errors.tpl');
        parent::initContent();
    }
}
