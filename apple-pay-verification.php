<?php
/**
 * Apple Pay Domain Verification Handler
 * 
 * This standalone file serves the Apple Pay domain verification file
 * when nginx blocks access to .well-known directories.
 * 
 * Deploy this file to the PrestaShop root directory to handle
 * Apple Pay domain verification requests.
 */

// Security check - ensure this is a legitimate request
if ($_SERVER['REQUEST_URI'] !== '/.well-known/apple-developer-merchantid-domain-association') {
    header('HTTP/1.1 404 Not Found');
    exit('Not Found');
}

// Path to the verification file in the MONEI module
$modulePath = __DIR__ . '/files/apple-developer-merchantid-domain-association';

if (!file_exists($modulePath)) {
    // Try alternate path if this file is in root
    $modulePath = __DIR__ . '/modules/monei/files/apple-developer-merchantid-domain-association';
}

if (file_exists($modulePath)) {
    // Serve the file with correct headers
    header('Content-Type: text/plain');
    header('Content-Disposition: inline; filename="apple-developer-merchantid-domain-association"');
    readfile($modulePath);
} else {
    header('HTTP/1.1 404 Not Found');
    echo 'Apple Pay domain verification file not found';
}
exit;