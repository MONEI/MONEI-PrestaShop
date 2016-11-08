<?php
/**
 * universalpay module main file.
 *
 * @author    Microapss
 * @link http://microapps.com/
 * @copyright Copyright &copy; 2016 http://microapps.com/
 * @version 0.0.1
 */

if (!defined('_PS_VERSION_'))
    exit;

class Monei extends PaymentModule
{

    public function __construct()
    {

        $this->name = 'monei';
        $this->tab = 'payments_gateways';
        $this->version = '0.0.1';
        $this->author = 'microapps';
        $this->need_instance = 1;
        $this->ps_versions_compliancy['min'] = '1.6.0';
        $this->author_uri = 'http://microapps.com/';
        $this->prefix = "mpp_";
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->isSubmitted = false;
        parent::__construct();

        $this->displayName = $this->l('MONEI Payment Gateway');
        $this->description = $this->l('Payment Gateway for MONEI.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

    }

    public function getContent()
    {
        $output = '';
        $hasErrors = false;
        if (Tools::isSubmit('submit' . $this->name)) {
            $this->isSubmitted = true;
            $userUpdatedValues = $this->verifyAndGetValues(array(
                'operationMode_testMode', 'acceptedPayment_visa', 'acceptedPayment_mastercard', 'acceptedPayment_maestro',
                'acceptedPayment_jcb', 'moneiData_AppID', 'moneiData_ChannelID', 'moneiData_UserID', 'moneiData_Password'
            ));


            if ($userUpdatedValues['acceptedPayment_visa'] == null && $userUpdatedValues['acceptedPayment_mastercard'] == null &&
                $userUpdatedValues['acceptedPayment_maestro'] == null && $userUpdatedValues['acceptedPayment_jcb'] == null
            ) {
                $hasErrors = true;
                $output .= $this->displayError($this->l('Please select at least one Payment Method'));
            }

            if ($userUpdatedValues['moneiData_AppID'] == null) {
                $hasErrors = true;
                $output .= $this->displayError($this->l('Please fill the App ID field'));
            }
            if ($userUpdatedValues['moneiData_ChannelID'] == null) {
                $hasErrors = true;
                $output .= $this->displayError($this->l('Please fill the Channel ID field'));
            }

            if ($userUpdatedValues['moneiData_UserID'] == null) {
                $hasErrors = true;
                $output .= $this->displayError($this->l('Please fill the User ID field'));
            }
            if ($userUpdatedValues['moneiData_Password'] == null) {
                $hasErrors = true;
                $output .= $this->displayError($this->l('Please fill the Password field'));
            }

            if (!$hasErrors) {
                $output .= $this->displayConfirmation($this->l('All settings updated'));
            }

            Configuration::updateValue($this->prefix . 'operationMode_testMode', $userUpdatedValues['operationMode_testMode']);
            Configuration::updateValue($this->prefix . 'acceptedPayment_visa', $userUpdatedValues['acceptedPayment_visa']);
            Configuration::updateValue($this->prefix . 'acceptedPayment_mastercard', $userUpdatedValues['acceptedPayment_mastercard']);
            Configuration::updateValue($this->prefix . 'acceptedPayment_maestro', $userUpdatedValues['acceptedPayment_maestro']);
            Configuration::updateValue($this->prefix . 'acceptedPayment_jcb', $userUpdatedValues['acceptedPayment_jcb']);
            Configuration::updateValue($this->prefix . 'moneiData_AppID', $userUpdatedValues['moneiData_AppID']);
            Configuration::updateValue($this->prefix . 'moneiData_ChannelID', $userUpdatedValues['moneiData_ChannelID']);
            Configuration::updateValue($this->prefix . 'moneiData_UserID', $userUpdatedValues['moneiData_UserID']);
            Configuration::updateValue($this->prefix . 'moneiData_Password', $userUpdatedValues['moneiData_Password']);
        }
        return $output . $this->displayForm();
    }

    public
    function displayForm()
    {
        $defaultValues = array(
            'operationMode_testMode' => Configuration::get($this->prefix . 'operationMode_testMode'),
            'acceptedPayment_visa' => Configuration::get($this->prefix . 'acceptedPayment_visa'),
            'acceptedPayment_mastercard' => Configuration::get($this->prefix . 'acceptedPayment_mastercard'),
            'acceptedPayment_maestro' => Configuration::get($this->prefix . 'acceptedPayment_maestro'),
            'acceptedPayment_jcb' => Configuration::get($this->prefix . 'acceptedPayment_jcb'),
            'moneiData_AppID' => Configuration::get($this->prefix . 'moneiData_AppID'),
            'moneiData_ChannelID' => Configuration::get($this->prefix . 'moneiData_ChannelID'),
            'moneiData_UserID' => Configuration::get($this->prefix . 'moneiData_UserID'),
            'moneiData_Password' => Configuration::get($this->prefix . 'moneiData_Password')
        );


        $this->context->smarty->assign(
            array(
                'token' => Tools::getAdminTokenLite('AdminModules'),
                'heading' => 'MONEI: ' . $this->l('Settings'),
                'defaultValues' => $defaultValues,
                'isSubmitted' => $this->isSubmitted
            )
        );

        return $this->display(__FILE__, 'views/templates/admin/config.tpl');
    }

    public function hookPayment($params)
    {
        $hasError = false;
        $errorMessage = "";
        $checkoutID = null;

        if (!$this->active) {
            $errorMessage = "An error ocurred while loading MONEI Payment Gateway Module, please contact support (MONEI Payment Gateway Module is not Active)" .
                $hasError = true;
        }


        $this->smarty->assign(array(
            'moneiPaymentURL' => Context::getContext()->link->getModuleLink('monei', 'payment'),
            'hasError' => $hasError,
            'errorMessage' => $errorMessage,
        ));

        return $this->display(__FILE__, 'payment.tpl');

    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active)
            return;

        $state = $params['objOrder']->getCurrentState();
        if (in_array($state, array(Configuration::get('PS_OS_PAYMENT'), Configuration::get('PS_OS_OUTOFSTOCK'), Configuration::get('PS_OS_OUTOFSTOCK_UNPAID')))) {
            $this->smarty->assign(array(
                'total_to_pay' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
                'status' => 'ok',
                'id_order' => $params['objOrder']->id
            ));
            if (isset($params['objOrder']->reference) && !empty($params['objOrder']->reference))
                $this->smarty->assign('reference', $params['objOrder']->reference);
        } else
            $this->smarty->assign('status', 'failed');

        return $this->display(__FILE__, 'payment_return.tpl');
    }


    public
    function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('controller') == 'AdminModules' && Tools::getValue('configure') == 'monei') {
            $this->context->controller->addCSS($this->_path . 'css/admin-style.css', 'all');
            $this->context->controller->addJquery();
            $this->context->controller->addJS($this->_path . 'js/admin-js.js');

        }
    }

    public function prepareCheckout($amount, $currency)
    {

        $userID = Configuration::get($this->prefix . 'moneiData_UserID');
        $password = Configuration::get($this->prefix . 'moneiData_Password');
        $channelID = Configuration::get($this->prefix . 'moneiData_ChannelID');

        $url = "https://test.monei-api.net/v1/checkouts";

        $data = http_build_query(array(
            "authentication.userId" => $userID,
            "authentication.password" => $password,
            "authentication.entityId" => $channelID,
            "amount" => $amount,
            "currency" => $currency,
            "paymentType" => "PA",
            "testMode" => "EXTERNAL"
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            )
        );
        $responseData = curl_exec($ch);
        if (curl_errno($ch)) {
            return curl_error($ch);
        }
        curl_close($ch);
        return $responseData;
    }

    public
    function install()
    {
        if (Shop::isFeatureActive())
            Shop::setContext(Shop::CONTEXT_ALL);

        $parentId = Tab::getIdFromClassName('AdminParentModules');

        $tab_controller_main = new Tab();
        $tab_controller_main->active = true;
        $tab_controller_main->class_name = "MoneiSettings";
        foreach (Language::getLanguages() as $lang)
            $tab_controller_main->name[$lang['id_lang']] = "MONEI";

        $tab_controller_main->id_parent = $parentId;
        $tab_controller_main->module = $this->name;
        $tab_controller_main->add();
        $tab_controller_main->move(Tab::getNewLastPosition(0));


        if (parent::install() && $this->registerHook('displayBackOfficeHeader') && $this->registerHook('payment') && $this->registerHook('paymentReturn'))
            return true;
        else
            return false;
    }

    public
    function uninstall()
    {

        $id_tab = Tab::getIdFromClassName('MoneiSettings');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            $tab->delete();
        }

        Configuration::updateValue($this->prefix . 'operationMode_testMode');
        Configuration::updateValue($this->prefix . 'acceptedPayment_visa');
        Configuration::updateValue($this->prefix . 'acceptedPayment_mastercard');
        Configuration::updateValue($this->prefix . 'acceptedPayment_maestro');
        Configuration::updateValue($this->prefix . 'acceptedPayment_jcb');
        Configuration::updateValue($this->prefix . 'moneiData_AppID');
        Configuration::updateValue($this->prefix . 'moneiData_ChannelID');
        Configuration::updateValue($this->prefix . 'moneiData_UserID');
        Configuration::updateValue($this->prefix . 'moneiData_Password');

        if (!parent::uninstall())
            return false;


        return true;
    }

    public
    function verifyAndGetValues(array $values)
    {
        $validValues = [];
        foreach ($values as $value) {
            $userInput = Tools::getValue($value);

            if ($userInput
                && !empty($userInput)
                && Validate::isGenericName($userInput)
            )
                $validValues[$value] = $userInput;
            else
                $validValues[$value] = null;

        }

        return $validValues;
    }

    public function getPaymentStatus($resourcePath)
    {
        $userID = Configuration::get($this->prefix . 'moneiData_UserID');
        $password = Configuration::get($this->prefix . 'moneiData_Password');
        $channelID = Configuration::get($this->prefix . 'moneiData_ChannelID');

        $url = "https://test.monei-api.net$resourcePath";
        $url .= "?authentication.userId=$userID";
        $url .= "&authentication.password=$password";
        $url .= "&authentication.entityId=$channelID";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseData = curl_exec($ch);
        if (curl_errno($ch)) {
            return curl_error($ch);
        }
        curl_close($ch);
        return $responseData;
    }

    public function getAllowedPaymentMethodsString()
    {
        $allowedMethods = Configuration::get($this->prefix . 'acceptedPayment_visa') != null ? "VISA" : "";
//        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_visa') !=  null ? " VISADEBIT" : "";
        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_mastercard') != null ? " MASTER" : "";
//        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_mastercard') !=  null ? " MASTERDEBIT" : "";
        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_maestro') != null ? " MAESTRO" : "";
        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_jcb') != null ? " JCB" : "";
        return $allowedMethods;
    }
}
