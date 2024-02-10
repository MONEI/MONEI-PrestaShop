<?php


$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei` (
    `id_monei` int(11) NOT NULL AUTO_INCREMENT,
    `id_cart` int(11) NOT NULL,
    `id_order` int(11) DEFAULT NULL,
    `id_order_monei` VARCHAR(50) DEFAULT NULL,
    `id_order_internal` VARCHAR(50) DEFAULT NULL,
    `amount` int(11) NOT NULL,
    `currency` VARCHAR(3) DEFAULT NULL,
    `authorization_code` VARCHAR(50) DEFAULT NULL,
    `status` ENUM("PENDING","SUCCEEDED","FAILED","CANCELED","REFUNDED","PARTIALLY_REFUNDED",
    "AUTHORIZED", "EXPIRED", "UNKNOWN") DEFAULT "PENDING",
    `locked` TINYINT(1) DEFAULT 0,
    `locked_at` INT(11) DEFAULT NULL,
    `date_add` DATETIME,
    `date_upd` DATETIME,
    PRIMARY KEY  (`id_monei`),
    INDEX (`id_cart`),
    INDEX (`id_order`),
    INDEX (`id_order_monei`),
    INDEX (`id_order_internal`),
    INDEX (`id_cart`, `id_order_monei`),
    INDEX (`id_order`, `id_order_monei`),
    INDEX (`id_cart`, `id_order`, `id_order_monei`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei_history` (
    `id_monei_history` int(11) NOT NULL AUTO_INCREMENT,
    `id_monei` int(11) NOT NULL,
    `status` ENUM("PENDING","SUCCEEDED","FAILED","CANCELED","REFUNDED","PARTIALLY_REFUNDED",
    "AUTHORIZED", "EXPIRED", "UNKNOWN") DEFAULT "PENDING",
    `id_monei_code` int(11) NOT NULL,
    `is_refund` BOOL DEFAULT FALSE,
    `is_callback` BOOL DEFAULT FALSE,
    `response` VARCHAR(4000) DEFAULT NULL,
    `date_add` DATETIME,
    PRIMARY KEY (`id_monei_history`),
    INDEX (`id_monei`),
    INDEX (`id_monei_code`),
    INDEX (`id_monei`, `id_monei_code`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE `' . _DB_PREFIX_ . 'monei_tokens` (
    `id_monei_tokens` int(11) NOT NULL AUTO_INCREMENT,
    `id_customer` int(11) NOT NULL,
    `brand` varchar(50) DEFAULT NULL,
    `country` varchar(4) DEFAULT NULL,
    `last_four` varchar(20) NOT NULL,
    `threeDS` tinyint(1) DEFAULT NULL,
    `threeDS_version` varchar(50) DEFAULT NULL,
    `expiration` int(11) NOT NULL,
    `tokenized` varchar(255) NOT NULL,
    `date_add` datetime DEFAULT NULL,
    PRIMARY KEY (`id_monei_tokens`),
    INDEX (`id_customer`)
  ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei_refund` (
    `id_monei_refund` bigint(20) NOT NULL AUTO_INCREMENT,
    `id_monei` int(11) DEFAULT NULL,
    `id_monei_history` int(11) DEFAULT NULL,
    `id_employee` int(11) DEFAULT NULL,
    `reason` ENUM("duplicated","fraudulent","requested_by_customer") DEFAULT "requested_by_customer",    
    `amount` int(11) DEFAULT NULL,
    `date_add` datetime DEFAULT NULL,
    PRIMARY KEY (`id_monei_refund`),
    INDEX (`id_monei`),
    INDEX (`id_monei_history`),
    INDEX (`id_employee`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei_codes` (
    `id_monei_codes` int(11) NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(4),
    `message` VARCHAR(255),
    PRIMARY KEY  (`id_monei_codes`),
    INDEX (`code`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

$sql[] = "INSERT INTO `" . _DB_PREFIX_ . "monei_codes` (`code`, `message`) VALUES
    ('E000','Transaction approved'),
    ('E101','Error with payment processor configuration. Check this in your dashboard or contact MONEI for support'),
    ('E102','Invalid or inactive MID. Please contact the acquiring entity'),
    ('E103',
    'Operation not allowed/configured for this merchant. Please contact the acquiring entity or MONEI for support'),
    ('E150','Invalid or malformed request. Please check the message format'),
    ('E151','Missing or malformed signature/auth'),
    ('E152','Error while decrypting request'),
    ('E200','Transaction failed during payment processing'),
    ('E201','Transaction declined by processor'),
    ('E202','Transaction declined by issuer'),
    ('E203','Payment method not allowed'),
    ('E204','Wrong or not allowed currency'),
    ('E205','Incorrect reference / transaction does not exist'),
    ('E206','Invalid payment token'),
    ('E207','Transaction failed: process time exceeded'),
    ('E208','Transaction is currently being processed'),
    ('E209','Duplicated operation'),
    ('E210','Wrong or not allowed payment amount'),
    ('E211','Refund declined by processor'),
    ('E212','Transaction has already been captured'),
    ('E213','Transaction has already been canceled'),
    ('E214','The amount to be captured cannot exceed the pre-authorized amount'),
    ('E215','The transaction to be captured has not been pre-authorized yet'),
    ('E216','The transaction to be canceled has not been pre-authorized yet'),
    ('E217','Transaction denied by processor to avoid duplicated operations'),
    ('E218','Error during payment request validation'),
    ('E219','Refund declined due to exceeded amount'),
    ('E220','Transaction has already been fully refunded'),
    ('E221','Transaction declined due to insufficient funds'),
    ('E222','The user has canceled the payment'),
    ('E300','Transaction declined due to security restrictions'),
    ('E301','3D Secure authentication failed'),
    ('E302','Authentication process timed out. Please try again'),
    ('E303','An error occurred during the 3D Secure process'),
    ('E304','Invalid or malformed 3D Secure request'),
    ('E305','Exemption not allowed'),
    ('E306','Exemption error'),
    ('E307','Fraud control error'),
    ('E308','External MPI received wrong. Please check the data'),
    ('E309','External MPI not enabled. Please contact support'),
    ('E500','Transaction declined during card payment process'),
    ('E501','Card rejected: invalid card number'),
    ('E502','Card rejected: wrong expiration date'),
    ('E503','Card rejected: wrong CVC/CVV2 number'),
    ('E504','Card number not registered'),
    ('E505','Card is expired'),
    ('E506','Error during payment authorization. Please try again'),
    ('E507','Cardholder has canceled the payment'),
    ('E508','Transaction declined: AMEX cards not accepted by payment processor'),
    ('E509','Card blocked temporarily or under suspicion of fraud'),
    ('E510','Card does not allow pre-authorization operations'),
    ('E511','CVC/CVV2 number is required'),
    ('E512','Transaction declined: card brand not accepted by payment processor'),
    ('E513','Transaction declined: DINERS cards not accepted by payment processor'),
    ('E514','Transaction type not allowed for this type of card'),
    ('E515','Transaction declined by card issuer'),
    ('E516','Transaction declined: DISCOVER cards not accepted by payment processor'),
    ('E600','Transaction declined during ApplePay/GooglePay payment process'),
    ('E601','Incorrect ApplePay or GooglePay configuration'),
    ('E620','Transaction declined during PayPal payment process'),
    ('E621','Transaction declined during PayPal payment process: invalid currency'),
    ('E640','Bizum transaction declined after three authentication attempts'),
    ('E641','Bizum transaction declined due to failed authorization'),
    ('E642','Bizum transaction declined due to insufficient funds'),
    ('E643','Bizum transaction canceled: the user does not want to continue'),
    ('E644','Bizum transaction rejected by destination bank'),
    ('E645','Bizum transaction rejected by origin bank'),
    ('E646','Bizum transaction rejected by processor'),
    ('E647','Bizum transaction failed while connecting with processor. Please try again'),
    ('E680','Transaction declined during ClickToPay payment process'),
    ('E681','Incorrect ClickToPay configuration'),
    ('E700','Transaction declined during Cofidis payment process'),
    ('E999','Service internal error. Please contact support');
";

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
