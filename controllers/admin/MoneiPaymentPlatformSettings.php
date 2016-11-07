<?php

class MoneiPaymentPlatformSettingsController extends AdminController
{
    function __construct()
    {
        Tools::redirectAdmin('index.php?controller=AdminModules&configure=moneipaymentplatform&token='.Tools::getAdminTokenLite('AdminModules'));
    }
}
