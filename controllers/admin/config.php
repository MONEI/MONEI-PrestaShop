<?php

class MoneiPaymentsSettingsController extends AdminController
{
    function __construct()
    {
        Tools::redirectAdmin('index.php?controller=AdminModules&configure=moneipayments&token='.Tools::getAdminTokenLite('AdminModules'));
    }
}
