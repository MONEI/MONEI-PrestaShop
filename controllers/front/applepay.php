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

        // Validate module name to prevent path traversal
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $this->module->name)) {
            header('HTTP/1.1 400 Bad Request');
            echo 'Invalid module name';
            exit;
        }

        // Path to the Apple Pay domain verification file
        $filePath = rtrim(_PS_MODULE_DIR_, '/') . '/' . $this->module->name . '/files/apple-developer-merchantid-domain-association';

        if (file_exists($filePath)) {
            // Validate file is within expected directory
            $realPath = realpath($filePath);
            $expectedDir = realpath(_PS_MODULE_DIR_ . $this->module->name . '/files/');
            if (!$realPath || !$expectedDir || strpos($realPath, $expectedDir) !== 0) {
                header('HTTP/1.1 403 Forbidden');
                echo 'Access denied';
                exit;
            }

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
