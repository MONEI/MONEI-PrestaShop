<?php

class MoneiApplePayModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = false;

    public function initContent()
    {
        // Disable header and footer for raw file output
        $this->display_header = false;
        $this->display_footer = false;

        // Path to the Apple Pay domain verification file
        $filePath = _PS_MODULE_DIR_ . $this->module->name . '/files/apple-developer-merchantid-domain-association';

        if (file_exists($filePath)) {
            // Set appropriate headers
            header('Content-Type: text/plain');
            header('Content-Disposition: inline; filename="apple-developer-merchantid-domain-association"');

            // Output the file content
            readfile($filePath);
            exit;
        } else {
            // File not found
            header('HTTP/1.1 404 Not Found');
            echo 'Apple Pay domain verification file not found';
            exit;
        }
    }
}
