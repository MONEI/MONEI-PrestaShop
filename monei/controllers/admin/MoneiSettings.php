<?php

class MoneiSettingsController extends AdminController
{
    function __construct()
    {
        Tools::redirectAdmin('index.php?controller=AdminModules&configure=monei&token='.Tools::getAdminTokenLite('AdminModules'));
    }
}
