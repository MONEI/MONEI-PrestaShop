<?php

class MoneiPaymentPlatformSettingsController extends AdminController
{
    function __construct()
    {
        Tools::redirectAdmin('index.php?controller=AdminModules&configure=MoneiPaymentPlatform&token='.Tools::getAdminTokenLite('AdminModules'));
    }
}
