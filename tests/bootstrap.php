<?php

// ⚠️ Every module file calls `exit` when _PS_VERSION_ is undefined. A unit test that
// reaches one, for example by autoloading the Monei class, would stop PHPUnit with
// exit code 0: a partial run that CI reports as green. With the constant defined, the
// same mistake fails loudly instead (monei.php then needs PaymentModule, which the
// suite does not have).
define('_PS_VERSION_', '0.0.0-unit');

require __DIR__ . '/../vendor/autoload.php';
