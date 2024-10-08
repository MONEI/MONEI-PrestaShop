<?php
require_once dirname(__FILE__) . '/vendor/autoload.php';

use Monei\CoreClasses\Monei as MoneiClass;
use Monei\CoreClasses\MoneiCard;
use Monei\CoreHelpers\PsTools;
use Monei\ApiException;
use Monei\CoreHelpers\PsOrderHelper;
use Monei\Model\MoneiBillingDetails;
use Monei\Model\MoneiCustomer;
use Monei\Model\MoneiPayment;
use Monei\Model\MoneiPaymentMethods;
use Monei\Model\MoneiPaymentStatus;
use Monei\MoneiClient;
use Monei\Traits\ValidationHelpers;

use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use Symfony\Polyfill\Mbstring\Mbstring;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monei extends PaymentModule
{
    use ValidationHelpers;

    protected $config_form = false;
    protected $moneiClient;
    protected $moneiPaymentId;
    protected $paymentMethods;

    public function __construct()
    {
        $this->displayName = 'MONEI Payments';
        $this->name = 'monei';
        $this->tab = 'payments_gateways';
        $this->version = '1.4.3';
        $this->author = 'MONEI';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        $this->controllers = [
            'validation', 'confirmation', 'redirect', 'cards', 'errors', 'check'
        ];

        parent::__construct();

        $this->description = $this->l('Accept Card, Apple Pay, Google Pay, Bizum, PayPal and many more payment methods in your store.');

        $apiKey = Configuration::get('MONEI_API_KEY');
        $accountId = Configuration::get('MONEI_ACCOUNT_ID');
        if (!$apiKey || !$accountId) {
            $this->warning = $this->l('Api Key or Account ID is not set.');
        } else {
            $this->moneiClient = new MoneiClient(
                $apiKey,
                $accountId
            );
        }
    }

    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        // General
        Configuration::updateValue('MONEI_TOKENIZE', false);
        Configuration::updateValue('MONEI_PRODUCTION_MODE', false);
        Configuration::updateValue('MONEI_SHOW_LOGO', true);
        Configuration::updateValue('MONEI_API_KEY', '');
        Configuration::updateValue('MONEI_ACCOUNT_ID', '');
        Configuration::updateValue('MONEI_CART_TO_ORDER', false);
        Configuration::updateValue('MONEI_EXPIRE_TIME', 600);
        // Gateways
        Configuration::updateValue('MONEI_ALLOW_CARD', true);
        Configuration::updateValue('MONEI_CARD_WITH_REDIRECT', false);
        Configuration::updateValue('MONEI_ALLOW_BIZUM', false);
        Configuration::updateValue('MONEI_BIZUM_WITH_REDIRECT', false);
        Configuration::updateValue('MONEI_ALLOW_APPLE', false);
        Configuration::updateValue('MONEI_ALLOW_GOOGLE', false);
        Configuration::updateValue('MONEI_ALLOW_CLICKTOPAY', false);
        Configuration::updateValue('MONEI_ALLOW_PAYPAL', false);
        Configuration::updateValue('MONEI_ALLOW_COFIDIS', false);
        Configuration::updateValue('MONEI_ALLOW_KLARNA', false);
        Configuration::updateValue('MONEI_ALLOW_MULTIBANCO', false);
        Configuration::updateValue('MONEI_ALLOW_MBWAY', false);
        // Status
        Configuration::updateValue('MONEI_STATUS_SUCCEEDED', Configuration::get('PS_OS_PAYMENT'));
        Configuration::updateValue('MONEI_STATUS_FAILED', Configuration::get('PS_OS_ERROR'));
        Configuration::updateValue('MONEI_STATUS_REFUNDED', Configuration::get('PS_OS_REFUND'));
        Configuration::updateValue('MONEI_STATUS_PARTIALLY_REFUNDED', Configuration::get('PS_OS_REFUND'));
        Configuration::updateValue('MONEI_STATUS_PENDING', Configuration::get('PS_OS_PREPARATION'));
        Configuration::updateValue('MONEI_SWITCH_REFUNDS', false);
        // Styles
        Configuration::updateValue('MONEI_CARD_INPUT_STYLE', '{"base": {"height": "42px"}}');
        Configuration::updateValue('MONEI_BIZUM_STYLE', '{"height": "42"}');
        Configuration::updateValue('MONEI_PAYMENT_REQUEST_STYLE', '{"height": "42"}');

        include(dirname(__FILE__) . '/sql/install.php');

        return parent::install() &&
            $this->installOrderState() &&
            $this->installAdminTab('AdminMonei', 'MONEI') &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('displayCustomerAccount') &&
            $this->registerHook('actionDeleteGDPRCustomer') &&
            $this->registerHook('actionExportGDPRData') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('displayAdminOrder') &&
            $this->registerHook('displayPaymentByBinaries') &&
            $this->registerHook('paymentOptions');
    }

    /**
     * Create order state
     * @return boolean
     */
    private function installOrderState()
    {
        if ((int)Configuration::get('MONEI_STATUS_PENDING') === 0) {
            $order_state = new OrderState();
            $order_state->name = [];
            $spanish_isos = ['es', 'mx', 'co', 'pe', 'ar', 'cl', 've', 'py', 'uy', 'bo', 've', 'ag', 'cb'];

            foreach (Language::getLanguages() as $language) {
                if (Tools::strtolower($language['iso_code']) == 'fr') {
                    $order_state->name[$language['id_lang']] = 'MONEI - En attente de paiement';
                } elseif (in_array(Tools::strtolower($language['iso_code']), $spanish_isos)) {
                    $order_state->name[$language['id_lang']] = 'MONEI - Pendiente de pago';
                } else {
                    $order_state->name[$language['id_lang']] = 'MONEI - Awaiting for payment';
                }
            }

            $order_state->send_email = false;
            $order_state->color = '#8961A5';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            $order_state->module_name = $this->name;

            if ($order_state->add()) {
                $source = _PS_MODULE_DIR_ . $this->name . '/views/img/mini_monei.gif';
                $destination = _PS_ROOT_DIR_ . '/img/os/' . (int)$order_state->id . '.gif';
                @copy($source, $destination);

                if (Shop::isFeatureActive()) {
                    $shops = Shop::getShops();
                    foreach ($shops as $shop) {
                        Configuration::updateValue(
                            'MONEI_STATUS_PENDING',
                            (int)$order_state->id,
                            false,
                            null,
                            (int)$shop['id_shop']
                        );
                    }
                } else {
                    Configuration::updateValue('MONEI_STATUS_PENDING', (int)$order_state->id);
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Installs a hidden Tab for AJAX calls
     * @param mixed $class_name
     * @param mixed $tab_name
     * @return bool
     */
    private function installAdminTab($class_name, $tab_name)
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $class_name;
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tab_name;
        }

        $tab->id_parent = -1;
        $tab->module = $this->name;
        return $tab->add();
    }

    public function uninstall()
    {
        // We need to remove the MONEI OrderState
        if (
            !Configuration::get('MONEI_STATUS_PENDING')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MONEI_STATUS_PENDING')))
        ) {
            // Check if some order has this state, then it shouldnt be deleted
            if (!$this->isMoneiStateUsed()) {
                $order_state = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
                $order_state->delete();
                Configuration::deleteByName('MONEI_STATUS_PENDING');
            }
        }

        // General
        Configuration::deleteByName('MONEI_TOKENIZE');
        Configuration::deleteByName('MONEI_PRODUCTION_MODE');
        Configuration::deleteByName('MONEI_SHOW_LOGO');
        Configuration::deleteByName('MONEI_API_KEY');
        Configuration::deleteByName('MONEI_ACCOUNT_ID');
        Configuration::deleteByName('MONEI_CART_TO_ORDER');
        Configuration::deleteByName('MONEI_EXPIRE_TIME');
        // Gateways
        Configuration::deleteByName('MONEI_ALLOW_CARD');
        Configuration::deleteByName('MONEI_CARD_WITH_REDIRECT');
        Configuration::deleteByName('MONEI_ALLOW_BIZUM');
        Configuration::deleteByName('MONEI_BIZUM_WITH_REDIRECT');
        Configuration::deleteByName('MONEI_ALLOW_APPLE');
        Configuration::deleteByName('MONEI_ALLOW_GOOGLE');
        Configuration::deleteByName('MONEI_ALLOW_CLICKTOPAY');
        Configuration::deleteByName('MONEI_ALLOW_PAYPAL');
        Configuration::deleteByName('MONEI_ALLOW_COFIDIS');
        Configuration::deleteByName('MONEI_ALLOW_KLARNA');
        Configuration::deleteByName('MONEI_ALLOW_MULTIBANCO');
        Configuration::deleteByName('MONEI_ALLOW_MBWAY');
        // Status
        Configuration::deleteByName('MONEI_STATUS_SUCCEEDED');
        Configuration::deleteByName('MONEI_STATUS_FAILED');
        Configuration::deleteByName('MONEI_SWITCH_REFUNDS');
        Configuration::deleteByName('MONEI_STATUS_REFUNDED');
        Configuration::deleteByName('MONEI_STATUS_PARTIALLY_REFUNDED');
        Configuration::deleteByName('MONEI_STATUS_PENDING');

        include(dirname(__FILE__) . '/sql/uninstall.php');

        return parent::uninstall();
    }

    /**
     * Checks if the MONEI OrderState is used by some order
     * @return bool
     */
    private function isMoneiStateUsed()
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_state WHERE id_order_state = '
            . (int)Configuration::get('MONEI_STATUS_PENDING');
        return Db::getInstance()->getValue($sql) > 0 ? true : false;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $message = '';

        /**
         * If values have been submitted in the form, process.
         */
        if ((bool)Tools::isSubmit('submitMoneiModule')) {
            $message = $this->postProcess(1);
        } elseif (Tools::isSubmit('submitMoneiModuleGateways')) {
            $message = $this->postProcess(2);
        } elseif (Tools::isSubmit('submitMoneiModuleStatus')) {
            $message = $this->postProcess(3);
        } elseif (Tools::isSubmit('submitMoneiModuleComponentStyle')) {
            $message = $this->postProcess(4);
        }

        // Assign values
        $this->context->smarty->assign(array(
            'module_dir' => $this->_path,
            'module_version' => $this->version,
            'module_name' => $this->name,
            'display_name' => $this->displayName,
            'helper_form_1' => $this->renderForm(),
            'helper_form_2' => $this->renderFormGateways(),
            'helper_form_3' => $this->renderFormStatus(),
            'helper_form_4' => $this->renderFormComponentStyle(),
        ));

        return $message . $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * Save form data.
     */
    protected function postProcess($which)
    {
        $section = '';
        switch ($which) {
            case 1:
                $section = $this->l('General');
                $form_values = $this->getConfigFormValues();
                break;
            case 2:
                $section = $this->l('Payment Methods');
                $form_values = $this->getConfigFormGatewaysValues();
                break;
            case 3:
                $section = $this->l('Status');
                $form_values = $this->getConfigFormStatusValues();
                break;
            case 4:
                $section = $this->l('Component Style');
                $form_values = $this->getConfigFormComponentStyleValues();

                // Validate JSON styles
                foreach ($form_values as $key => $value) {
                    $value = Tools::getValue($key);

                    if (!json_decode($value)) {
                        $formattedKey = ucwords(str_replace('_', ' ', str_replace('_STYLE', '', $key)));

                        return $this->displayWarning($this->l('The style of ') . $formattedKey . $this->l(' is not a valid JSON.'));
                    }
                }

                break;
        }

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        // Register domain for Apple Pay only in production mode
        if ($this->moneiClient && (bool) Configuration::get('MONEI_PRODUCTION_MODE')) {
            try {
                $domain = str_replace(['www.', 'https://', 'http://'], '', Tools::getShopDomainSsl(false, true));
                $this->moneiClient->apple->register($domain);
            } catch (\Exception $e) {
                $this->warning[] = $e->getMessage();
            }
        }

        return $this->displayConfirmation($section . ' ' . $this->l('options saved sucessfully.'));
    }

    /**
     * Default configuration values for HelperForm
     */
    protected function getConfigFormValues()
    {
        return array(
            'MONEI_TOKENIZE' => Configuration::get('MONEI_TOKENIZE', false),
            'MONEI_PRODUCTION_MODE' => Configuration::get('MONEI_PRODUCTION_MODE', false),
            'MONEI_SHOW_LOGO' => Configuration::get('MONEI_SHOW_LOGO', true),
            'MONEI_API_KEY' => Configuration::get('MONEI_API_KEY', ''),
            'MONEI_ACCOUNT_ID' => Configuration::get('MONEI_ACCOUNT_ID', ''),
            'MONEI_CART_TO_ORDER' => Configuration::get('MONEI_CART_TO_ORDER', true),
        );
    }

    /**
     * Default gateways values for HelperForm
     */
    protected function getConfigFormGatewaysValues()
    {
        return array(
            'MONEI_ALLOW_CARD' => Configuration::get('MONEI_ALLOW_CARD', true),
            'MONEI_CARD_WITH_REDIRECT' => Configuration::get('MONEI_CARD_WITH_REDIRECT', false),
            'MONEI_ALLOW_BIZUM' => Configuration::get('MONEI_ALLOW_BIZUM', false),
            'MONEI_BIZUM_WITH_REDIRECT' => Configuration::get('MONEI_BIZUM_WITH_REDIRECT', false),
            'MONEI_ALLOW_APPLE' => Configuration::get('MONEI_ALLOW_APPLE', false),
            'MONEI_ALLOW_GOOGLE' => Configuration::get('MONEI_ALLOW_GOOGLE', false),
            'MONEI_ALLOW_CLICKTOPAY' => Configuration::get('MONEI_ALLOW_CLICKTOPAY', false),
            'MONEI_ALLOW_PAYPAL' => Configuration::get('MONEI_ALLOW_PAYPAL', false),
            'MONEI_ALLOW_COFIDIS' => Configuration::get('MONEI_ALLOW_COFIDIS', false),
            'MONEI_ALLOW_KLARNA' => Configuration::get('MONEI_ALLOW_KLARNA', false),
            'MONEI_ALLOW_MULTIBANCO' => Configuration::get('MONEI_ALLOW_MULTIBANCO', false),
            'MONEI_ALLOW_MBWAY' => Configuration::get('MONEI_ALLOW_MBWAY', false),
        );
    }

    /**
     * Default statuses values for HelperForm
     */
    protected function getConfigFormStatusValues()
    {
        return array(
            'MONEI_STATUS_PENDING' =>
                Configuration::get('MONEI_STATUS_PENDING', Configuration::get('PS_OS_WS_PAYMENT')),
            'MONEI_STATUS_SUCCEEDED' =>
                Configuration::get('MONEI_STATUS_SUCCEEDED', Configuration::get('PS_OS_PAYMENT')),
            'MONEI_STATUS_FAILED' =>
                Configuration::get('MONEI_STATUS_FAILED', Configuration::get('PS_OS_ERROR')),
            'MONEI_SWITCH_REFUNDS' => Configuration::get('MONEI_SWITCH_REFUNDS', false),
            'MONEI_STATUS_REFUNDED' =>
                Configuration::get('MONEI_STATUS_REFUNDED', Configuration::get('PS_OS_REFUND')),
            'MONEI_STATUS_PARTIALLY_REFUNDED' =>
                Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED', Configuration::get('PS_OS_REFUND'))
        );
    }

    /**
     * Default styles values for HelperForm
     */
    protected function getConfigFormComponentStyleValues()
    {
        return array(
            'MONEI_CARD_INPUT_STYLE' => Configuration::get('MONEI_CARD_INPUT_STYLE', '{"base": {"height": "42px"}}'),
            'MONEI_BIZUM_STYLE' => Configuration::get('MONEI_BIZUM_STYLE', '{"height": "42"}'),
            'MONEI_PAYMENT_REQUEST_STYLE' => Configuration::get('MONEI_PAYMENT_REQUEST_STYLE', '{"height": "42"}'),
        );
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoneiModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Creates the structure of the general form
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Your MONEI API Key. Available at your MONEI dashboard.'),
                        'name' => 'MONEI_API_KEY',
                        'label' => $this->l('API Key'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Your MONEI Account ID. Available at your MONEI dashboard.'),
                        'name' => 'MONEI_ACCOUNT_ID',
                        'label' => $this->l('Account ID'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Real environment'),
                        'name' => 'MONEI_PRODUCTION_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Set to OFF/DISABLED to set the test environment.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Credit Card Tokenization'),
                        'name' => 'MONEI_TOKENIZE',
                        'is_bool' => true,
                        'desc' => $this->l('Allow the customers to save their credit card information.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Cart to order'),
                        'name' => 'MONEI_CART_TO_ORDER',
                        'is_bool' => true,
                        'desc' => $this->l('Convert the customer cart into an order before the payment.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show MONEI logo'),
                        'name' => 'MONEI_SHOW_LOGO',
                        'is_bool' => true,
                        'desc' => $this->l('Shows the MONEI logo on checkout step.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderFormGateways()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoneiModuleGateways';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormGatewaysValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigFormGateways()));
    }

    /**
     * Creates the structure of the gateways form
     */
    protected function getConfigFormGateways()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Payment methods'),
                    'icon' => 'icon-money',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Credit Card'),
                        'name' => 'MONEI_ALLOW_CARD',
                        'is_bool' => true,
                        // 'desc' => $this->l('Allow payments with Credit Card.'),
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Activate Credit Card with Redirect'),
                        'name' => 'MONEI_CARD_WITH_REDIRECT',
                        'is_bool' => true,
                        'hint' => $this->l('It is recommended to enable redirection in cases where card payments do not function correctly.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Bizum'),
                        'name' => 'MONEI_ALLOW_BIZUM',
                        'is_bool' => true,
                        // 'desc' => $this->l('Allow payments with Bizum.'),
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Activate Bizum with Redirect'),
                        'name' => 'MONEI_BIZUM_WITH_REDIRECT',
                        'is_bool' => true,
                        'hint' => $this->l('It is recommended to enable redirection in cases where bizum payment do not function correctly.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Apple Pay'),
                        'name' => 'MONEI_ALLOW_APPLE',
                        'is_bool' => true,
                        'desc' => $this->l('Allow payments with Apple Pay. Only displayed in Safari browser.'),
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Google Pay'),
                        'name' => 'MONEI_ALLOW_GOOGLE',
                        'is_bool' => true,
                        // 'desc' => $this->l('Allow payments with Google Pay.'),
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Click To Pay'),
                        'name' => 'MONEI_ALLOW_CLICKTOPAY',
                        'is_bool' => true,
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow PayPal'),
                        'name' => 'MONEI_ALLOW_PAYPAL',
                        'is_bool' => true,
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow COFIDIS'),
                        'name' => 'MONEI_ALLOW_COFIDIS',
                        'is_bool' => true,
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Klarna'),
                        'name' => 'MONEI_ALLOW_KLARNA',
                        'is_bool' => true,
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Multibanco'),
                        'name' => 'MONEI_ALLOW_MULTIBANCO',
                        'is_bool' => true,
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow MBWay'),
                        'name' => 'MONEI_ALLOW_MBWAY',
                        'is_bool' => true,
                        'hint' => $this->l('The payment must be active and configured on your MONEI Dashboard.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderFormStatus()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoneiModuleStatus';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormStatusValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigFormStatus()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigFormStatus()
    {
        $order_statuses = OrderState::getOrderStates($this->context->language->id);

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Order States'),
                    'icon' => 'icon-shopping-cart',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status for pending payment'),
                        'name' => 'MONEI_STATUS_PENDING',
                        'required' => true,
                        'desc' => $this->l('You must select here the default status for a pending payment.'),
                        'options' => array(
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status for succeeded payment'),
                        'name' => 'MONEI_STATUS_SUCCEEDED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for a completed payment.'),
                        'options' => array(
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status for failed payment'),
                        'name' => 'MONEI_STATUS_FAILED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for a failed payment.'),
                        'options' => array(
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Change Status for Refunds'),
                        'name' => 'MONEI_SWITCH_REFUNDS',
                        'is_bool' => true,
                        'desc' => $this->l('Changes the order state to below ones once a refund is done.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status for refunded payment'),
                        'name' => 'MONEI_STATUS_REFUNDED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for fully refunded payment.'),
                        'options' => array(
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status for partially refunded'),
                        'name' => 'MONEI_STATUS_PARTIALLY_REFUNDED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for partially refunded payment.'),
                        'options' => array(
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ),
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function renderFormComponentStyle()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoneiModuleComponentStyle';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormComponentStyleValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigFormComponentStyle()));
    }

    protected function getConfigFormComponentStyle()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Component Style'),
                    'icon' => 'icon-paint-brush',
                ),
                'input' => array(
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Card input style'),
                        'name' => 'MONEI_CARD_INPUT_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the Card Input component. Documentation: ') .
                            '<a href="https://docs.monei.com/docs/monei-js/reference/#cardinput-style-object" target="_blank">MONEI Card Input Style</a>',
                        'cols' => 60,
                        'rows' => 10,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Bizum style'),
                        'name' => 'MONEI_BIZUM_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the Bizum component. Documentation: ') .
                            '<a href="https://docs.monei.com/docs/monei-js/reference/#bizum-options" target="_blank">MONEI Bizum Style</a>',
                        'cols' => 60,
                        'rows' => 10,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Payment Request style'),
                        'name' => 'MONEI_PAYMENT_REQUEST_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the Payment Request component. Documentation: ') .
                            '<a href="https://docs.monei.com/docs/monei-js/reference/#paymentrequest-options" target="_blank">MONEI Payment Request Style</a>',
                        'cols' => 60,
                        'rows' => 10,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    public function getCartAmount()
    {
        $cart = $this->context->cart;

        $cartSummaryDetails = $cart->getSummaryDetails(null, true);
        $totalShippingTaxExc = $cartSummaryDetails['total_shipping_tax_exc'];
        $subTotal = $cartSummaryDetails['total_price_without_tax'] - $cartSummaryDetails['total_shipping_tax_exc'];
        $totalTax = $cartSummaryDetails['total_tax'];

        $currency = new Currency($cart->id_currency);
        $currencyDecimals = is_array($currency) ? (int) $currency['decimals'] : (int) $currency->decimals;
        $decimals = $currencyDecimals * _PS_PRICE_DISPLAY_PRECISION_; // _PS_PRICE_DISPLAY_PRECISION_ deprec 1.7.7 TODO

        $total_price = Tools::ps_round($totalShippingTaxExc + $subTotal + $totalTax, $decimals);

        return (int) number_format($total_price, 2, '', '');
    }

    public function getCustomerData($returnMoneiCustomerObject = false)
    {
        $customer = $this->context->customer;

        if (!Validate::isLoadedObject($customer)) {
            return $this->createGuestCustomerData($returnMoneiCustomerObject);
        }

        $customer->email = str_replace(':', '', $customer->email);
        $addressInvoice = new Address((int) $this->context->cart->id_address_invoice);

        $customerData = [
            'name' => $customer->firstname . ' ' . $customer->lastname,
            'email' => $customer->email,
            'phone' => $addressInvoice->phone_mobile ?: $addressInvoice->phone
        ];

        return $returnMoneiCustomerObject ? new MoneiCustomer($customerData) : $customerData;
    }

    private function createGuestCustomerData($returnMoneiCustomerObject)
    {
        $customerData = [
            'name' => 'Guest',
            'email' => 'guest@temp.com',
            'phone' => '000000000'
        ];

        return $returnMoneiCustomerObject ? new MoneiCustomer($customerData) : $customerData;
    }

    public function getAddressData($addressId, $returnMoneiBillingObject = false)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address)) {
            return $this->createGuestBillingData($returnMoneiBillingObject);
        }

        $customer = $this->context->customer;
        $customerEmail = Validate::isLoadedObject($customer) ? $customer->email : 'guest@temp.com';

        $state = new State((int) $address->id_state, (int) $this->context->language->id);
        $stateName = $state->name ?: '';

        $country = new Country($address->id_country, (int) $this->context->language->id);

        $billingData = [
            'name' => "{$address->firstname} {$address->lastname}",
            'email' => $customerEmail,
            'phone' => $address->phone_mobile ?: $address->phone,
            'company' => $address->company,
            'address' => [
                'line1' => $address->address1,
                'line2' => $address->address2,
                'zip' => $address->postcode,
                'city' => $address->city,
                'state' => $stateName,
                'country' => $country->iso_code
            ]
        ];

        return $returnMoneiBillingObject ? new MoneiBillingDetails($billingData) : $billingData;
    }

    private function createGuestBillingData($returnMoneiBillingObject)
    {
        $billingData = [
            'name' => 'Guest',
            'email' => 'guest@temp.com',
            'phone' => '000000000',
            'company' => '',
            'address' => [
                'line1' => '.',
                'line2' => '',
                'zip' => '00000',
                'city' => '.',
                'state' => '.',
                'country' => 'ES'
            ],
        ];

        return $returnMoneiBillingObject ? new MoneiBillingDetails($billingData) : $billingData;
    }

    /**
     * Remove the MONEI payment cookie by cart amount
     */
    public function removeMoneiPaymentCookie()
    {
        foreach ($this->context->cookie->getAll() as $key => $value) {
            if (strpos($key, 'monei_payment_') === 0) {
                unset($this->context->cookie->$key);
            }
        }
    }

    public function createPayment(bool $tokenizeCard = false, int $moneiCardId = 0)
    {
        $cartAmount = $this->getCartAmount();
        if (empty($cartAmount)) {
            return false;
        }

        // Check if the payment already exists in the cookie by cart amount
        $moneiPaymentId = $this->context->cookie->{'monei_payment_' . $cartAmount};
        if (!$tokenizeCard && !$moneiCardId && !empty($moneiPaymentId)) {
            $moneiPayment = $this->moneiClient->payments->getPayment($moneiPaymentId);
            if ($moneiPayment) {
                return $moneiPayment;
            }
        }

        $cart = $this->context->cart;
        $link = $this->context->link;
        $currency = new Currency($cart->id_currency);

        $orderId = str_pad($cart->id . 'm' . time() % 1000, 12, '0', STR_PAD_LEFT); // Redsys/Bizum Style

        $moneiPayment = new MoneiPayment();
        $moneiPayment
            ->setOrderId($orderId)
            ->setAmount($this->getCartAmount())
            ->setCurrency($currency->iso_code)
            ->setCustomer($this->getCustomerData(true))
            ->setBillingDetails($this->getAddressData((int) $cart->id_address_invoice, true))
            ->setShippingDetails($this->getAddressData((int) $cart->id_address_delivery, true))
            ->setCompleteUrl(
                $link->getModuleLink($this->name, 'confirmation', [
                    'success' => 1,
                    'cart_id' => $cart->id,
                    'order_id' => $orderId
                ])
            )
            ->setFailUrl(
                $link->getModuleLink($this->name, 'confirmation', [
                    'success' => 0,
                    'cart_id' => $cart->id,
                    'order_id' => $orderId
                ])
            )
            ->setCallbackUrl(
                $link->getModuleLink($this->name, 'validation')
            )
            ->setCancelUrl(
                $link->getPageLink('order', null, null, 'step=3')
            );

        $payment_methods = [];

        // Check for available payment methods
        if (!Configuration::get('MONEI_ALLOW_ALL')) {
            if (Tools::isSubmit('method')) {
                $param_method = Tools::getValue('method', 'card');
                $payment_methods[] = in_array($param_method, MoneiPaymentMethods::getAllowableEnumValues()) ?
                    $param_method : 'card'; // Fallback card
            } else {
                if (Configuration::get('MONEI_ALLOW_CARD')) {
                    $payment_methods[] = 'card';
                }
                if (Configuration::get('MONEI_ALLOW_BIZUM')) {
                    $payment_methods[] = 'bizum';
                }
                if (Configuration::get('MONEI_ALLOW_APPLE')) {
                    $payment_methods[] = 'applePay';
                }
                if (Configuration::get('MONEI_ALLOW_GOOGLE')) {
                    $payment_methods[] = 'googlePay';
                }
                if (Configuration::get('MONEI_ALLOW_CLICKTOPAY')) {
                    $payment_methods[] = 'clickToPay';
                }
                if (Configuration::get('MONEI_ALLOW_PAYPAL')) {
                    $payment_methods[] = 'paypal';
                }
                if (Configuration::get('MONEI_ALLOW_COFIDIS')) {
                    $payment_methods[] = 'cofidis';
                }
                if (Configuration::get('MONEI_ALLOW_KLARNA')) {
                    $payment_methods[] = 'klarna';
                }
                if (Configuration::get('MONEI_ALLOW_MULTIBANCO')) {
                    $payment_methods[] = 'multibanco';
                }
                if (Configuration::get('MONEI_ALLOW_MBWAY')) {
                    $payment_methods[] = 'mbway';
                }
            }
        }

        $moneiPayment->setAllowedPaymentMethods($payment_methods);
        if ($tokenizeCard) {
            $moneiPayment->setGeneratePaymentToken(true);
        } else if ($moneiCardId) {
            $belongsToCustomer = MoneiCard::belongsToCustomer(
                $moneiCardId,
                $this->context->customer->id
            );

            if ($belongsToCustomer) {
                $tokenizedCard = new MoneiCard($moneiCardId);

                $moneiPayment->setPaymentToken($tokenizedCard->tokenized);
                $moneiPayment->setGeneratePaymentToken(false);
            }
        }

        try {
            // Save the information before sending it to the API
            PsOrderHelper::saveTransaction($moneiPayment, true);

            $moneiPaymentResponse = $this->moneiClient->payments->createPayment($moneiPayment);

            // Only save the payment id if dont tokenize the card or the card id is not set
            if (!$tokenizeCard && !$moneiCardId) {
                $this->context->cookie->{'monei_payment_' . $cartAmount} = $moneiPaymentResponse->getId();
            }

            return $moneiPaymentResponse;
        } catch (Exception $ex) {
            PrestaShopLogger::addLog(
                'MONEI - Exception - monei.php - createPayment: ' . $ex->getMessage() . ' - ' . $ex->getFile(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }
    }

    public function createOrUpdateOrder($moneiPaymentId, $redirectToConfirmationPage = false)
    {
        if (is_object($moneiPaymentId)) {
            $moneiPayment = $moneiPaymentId;
        } else {
            $moneiPayment = $this->moneiClient->payments->getPayment($moneiPaymentId);
        }

        $moneiOrderId = $moneiPayment->getOrderId();
        $moneiId = (int) MoneiClass::getIdByInternalOrder($moneiOrderId);

        // Check Monei
        $monei = new MoneiClass($moneiId);
        if (!Validate::isLoadedObject($monei)) {
            throw new ApiException('Monei identifier not found');
        }

        // Check Cart
        $cartId = (int) $monei->id_cart;
        $cartIdResponse = is_array(explode('m', $moneiOrderId)) ? (int)explode('m', $moneiOrderId)[0] : false;
        if ($cartId !== $cartIdResponse) {
            throw new ApiException('cartId from response and internal registry doesnt match: CartId: ' . $cartId . ' - CartIdResponse: ' . $cartIdResponse);
        }

        // Check Currencies
        if ($monei->currency !== $moneiPayment->getCurrency()) {
            throw new ApiException('Currency from response and internal registry doesnt match: Currency: ' . $monei->currency . ' - CurrencyResponse: ' . $moneiPayment->getCurrency());
        }

        $cart = new Cart($cartId);

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            throw new ApiException('Customer #' . $cart->id_customer . ' not valid');
        }

        // Save the authorization code
        if ($moneiPayment->getStatus() === MoneiPaymentStatus::SUCCEEDED) {
            $monei->authorization_code = $moneiPayment->getAuthorizationCode();
            $monei->save();
        }

        $cartAmountResponse = $moneiPayment->getAmount();

        $orderStateId = (int) Configuration::get('MONEI_STATUS_FAILED');
        $message = '';
        $failed = true;
        $is_refund = false;

        if (in_array($moneiPayment->getStatus(), [MoneiPaymentStatus::REFUNDED, MoneiPaymentStatus::PARTIALLY_REFUNDED])) {
            $orderStateId = (int) Configuration::get('MONEI_STATUS_REFUNDED');
            $failed = false;
            $is_refund = true;
        } elseif ($moneiPayment->getStatus() === MoneiPaymentStatus::PENDING) {
            $orderStateId = (int) Configuration::get('MONEI_STATUS_PENDING');
            $failed = false;
        } elseif ($moneiPayment->getStatus() === MoneiPaymentStatus::SUCCEEDED) {
            $orderStateId = (int) Configuration::get('MONEI_STATUS_SUCCEEDED');
            $failed = false;
        }

        // Check if the order already exists
        $orderByCart = Order::getByCartId($cartId);

        // Check if the order should be created
        $should_create_order = true;
        if (Validate::isLoadedObject($orderByCart)) {
            $should_create_order = false;

            // Check if the order is from the same payment method
            if ($orderByCart->module !== $this->name) {
                $message = 'Order (' . $orderByCart->id . ') already exists with a different payment method.';
                PrestaShopLogger::addLog(
                    'MONEI - monei:createOrUpdateOrder - ' . $message,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                );

                return;
            }

            $orderState = new OrderState($orderStateId);
            if (Validate::isLoadedObject($orderState)) {
                $orderStateIdsPending = [
                    Configuration::get('MONEI_STATUS_PENDING'),
                ];

                // Only if the order is in a pending state, the status can be updated.
                if (in_array((int) $orderByCart->current_state, $orderStateIdsPending)) {
                    $orderByCart->setCurrentState($orderStateId); // Change order status to paid/failed

                    // Update transaction_id in order_payment
                    $orderPayment = $orderByCart->getOrderPaymentCollection();
                    if (count($orderPayment) > 0) {
                        $orderPayment[0]->transaction_id = $moneiPayment->getId();
                        $orderPayment[0]->save();
                    }
                }
            }
        } elseif (true === $failed && !Configuration::get('MONEI_CART_TO_ORDER')) {
            $should_create_order = false;
        }

        $orderId = 0;

        // Create the order
        if ($should_create_order) {
            // Set a LOCK for slow servers
            $is_locked_info = MoneiClass::getLockInformation($moneiId);

            if ($is_locked_info['locked'] == 0) {
                Db::getInstance()->update(
                    'monei',
                    [
                        'locked' => 1,
                        'locked_at' => time(),
                    ],
                    'id_monei = ' . (int)$moneiId
                );
            } elseif ($is_locked_info['locked'] == 1 && $is_locked_info['locked_at'] < (time() - 60)) {
                $should_create_order = false;
                $message = 'Slow server detected, order in creation process';

                PrestaShopLogger::addLog(
                    'MONEI - monei:createOrUpdateOrder - ' . $message,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                );
            } elseif ($is_locked_info['locked'] == 1 && $is_locked_info['locked_at'] > (time() - 60)) {
                $message = 'Slow server detected, previous order creation process timed out';

                Db::getInstance()->update(
                    'monei',
                    [
                        'locked_at' => time(),
                    ],
                    'id_monei = ' . (int)$moneiId
                );

                PrestaShopLogger::addLog(
                    'MONEI - monei:createOrUpdateOrder - ' . $message,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                );
            }

            if ($should_create_order) {
                $this->validateOrder(
                    $cartId,
                    $orderStateId,
                    $cartAmountResponse / 100,
                    'MONEI ' . $moneiPayment->getPaymentMethod()->getMethod(),
                    $message,
                    ['transaction_id' => $moneiPayment->getId()],
                    $cart->id_currency,
                    false,
                    $customer->secure_key
                );

                // Check id_order and save it
                $orderId = (int) Order::getIdByCartId($cartId);
                if ($orderId) {
                    $monei->id_order = $orderId;
                    $monei->save();
                }
            }
        }

        // remove monei payment id from cookie
        $this->removeMoneiPaymentCookie();

        // Save log (required from API for tokenization)
        if (!PsOrderHelper::saveTransaction($moneiPayment, false, $is_refund, true, $failed)) {
            throw new ApiException('Unable to save transaction information');
        }

        if ($orderId) {
            if ($redirectToConfirmationPage) {
                Tools::redirect(
                    'index.php?controller=order-confirmation' .
                    '&id_cart=' . $cart->id .
                    '&id_module=' . $this->id .
                    '&id_order=' . $this->currentOrder .
                    '&key=' . $customer->secure_key
                );
            } else {
                echo 'OK';
            }
        } else {
            throw new ApiException($moneiPayment->getStatusCode() . ' - ' . $moneiPayment->getStatusMessage());
        }
    }

    public function isMoneiAvailable($cart)
    {
        if (!$this->active) {
            return false;
        }
        if (!$this->checkCurrency($cart)) {
            return false;
        }
        if (!$this->moneiClient) {
            return false;
        }

        return true;
    }

    /**
     * Hook for JSON Viewer
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') === $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/moneiback.css');
        }

        // Only for Orders controller, we dont need to load JS/CSS everywhere
        if ($this->context->controller->controller_name !== 'AdminOrders') {
            return;
        }

        // jQuery is already included by default on 1.7.7 or higher
        if (version_compare(_PS_VERSION_, '1.7.7', '<')) {
            if (method_exists($this->context->controller, 'addJquery')) {
                $this->context->controller->addJquery();
            }
        }

        // CSS
        $this->context->controller->addCSS($this->_path . 'views/css/jquery.json-viewer.css');
        // JS
        $this->context->controller->addJS($this->_path . 'views/js/sweetalert.min.js');
        $this->context->controller->addJS($this->_path . 'views/js/moneiback.js');
        $this->context->controller->addJS($this->_path . 'views/js/jquery.json-viewer.js');
    }

    /**
     * Return payment options available for PS 1.7+
     *
     * @param array Hook parameters
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->isMoneiAvailable($params['cart'])) {
            return;
        }

        $this->getPaymentMethods();
        if (!$this->paymentMethods || !$this->moneiPaymentId) {
            return;
        }

        return $this->paymentMethods;
    }

    /**
     * Get all available payment methods
     * @return array
     */
    private function getPaymentMethods()
    {
        if ($this->paymentMethods && $this->moneiPaymentId) {
            return;
        }

        $cart = $this->context->cart;

        $moneiPayment = $this->createPayment();
        if (!$moneiPayment) {
            return;
        }

        $moneiPaymentId = $moneiPayment->getId();

        $moneiAccount = $this->moneiClient->getMoneiAccount();

        $moneiPaymentMethod = $moneiAccount->getPaymentInformation($moneiPaymentId)->getPaymentMethodsAllowed();

        $template = '';
        if (Configuration::get('MONEI_SHOW_LOGO')) {
            $this->context->smarty->assign([
                'module_dir' => $this->_path
            ]);
            $template = $this->fetch('module:monei/views/templates/front/additional_info.tpl');
        }

        $paymentMethods = [];
        $paymentOptionList = [];

        $countryIsoCode = $this->context->country->iso_code;
        $currencyIsoCode = $this->context->currency->iso_code;
        $addressInvoice = new Address($cart->id_address_invoice);
        if (Validate::isLoadedObject($addressInvoice)) {
            $countryInvoice = new Country($addressInvoice->id_country);
            $countryIsoCode = $countryInvoice->iso_code;
        }

        $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $transactionId = $crypto->hash(
            $cart->id . $cart->id_customer, _COOKIE_KEY_
        );

        // Credit Card
        if (Configuration::get('MONEI_ALLOW_CARD') && $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::CARD, $currencyIsoCode)) {
            $paymentOptionList['card'] = [
                'method' => 'card',
                'callToActionText' => $this->l('Credit Card'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/cards.svg'),
            ];

            if (Configuration::get('MONEI_CARD_WITH_REDIRECT')) {
                $redirectUrl = $this->context->link->getModuleLink($this->name, 'redirect', [
                    'method' => 'card',
                    'transaction_id' => $transactionId,
                ]);

                if (Configuration::get('MONEI_TOKENIZE')) {
                    $this->context->smarty->assign('link_create_payment', $redirectUrl);

                    $paymentOptionList['card']['form'] = $this->fetch('module:monei/views/templates/hook/paymentOptions.tpl');
                } else {
                    $paymentOptionList['card']['action'] = $redirectUrl;
                }
            } else {
                $this->context->smarty->assign([
                    'moneiCardHolderName' => $moneiPayment->getBillingDetails()->getName(),
                ]);

                $paymentOptionList['card']['additionalInformation'] = $this->fetch('module:monei/views/templates/front/onsite_card.tpl');
                $paymentOptionList['card']['binary'] = true;
            }

            // Get current customer cards (not expired ones)
            $customer_cards = MoneiCard::getStaticCustomerCards($this->context->cart->id_customer, false);
            if ($customer_cards) {
                foreach ($customer_cards as $card) {
                    $credit_card = new MoneiCard($card['id_monei_tokens']);
                    $card_number = '**** **** **** ' . $credit_card->last_four;
                    $card_brand = Tools::strtoupper($credit_card->brand);
                    $card_expiration = $credit_card->unixEpochToExpirationDate();

                    $redirectUrl = $this->context->link->getModuleLink($this->name, 'redirect', [
                        'method' => 'tokenized_card',
                        'transaction_id' => $transactionId,
                        'id_monei_card' => $credit_card->id,
                    ]);

                    $paymentOptionList['card-' . (int) $card['id_monei_tokens']] = [
                        'method' => 'tokenized_card',
                        'callToActionText' => $this->l('Saved Card') . ': ' . $card_brand . ' ' . $card_number . ' (' . $card_expiration . ')',
                        'additionalInformation' => $template,
                        'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/' . strtolower($card_brand) . '.svg'),
                        'action' => $redirectUrl,
                    ];
                }
            }
        }

        // Bizum
        if (Configuration::get('MONEI_ALLOW_BIZUM') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::BIZUM, $currencyIsoCode, $countryIsoCode)
        ) {
            $paymentOptionList['bizum'] = [
                'method' => 'bizum',
                'callToActionText' => $this->l('Bizum'),
                'additionalInformation' => Configuration::get('MONEI_BIZUM_WITH_REDIRECT') ? $template : '',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/bizum.svg'),
                'binary' => Configuration::get('MONEI_BIZUM_WITH_REDIRECT') ? false : true,
            ];
        }

        // Apple
        if (Configuration::get('MONEI_ALLOW_APPLE') &&
            PsTools::isSafariBrowser() &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::APPLE, $currencyIsoCode)
        ) {
            $paymentOptionList['applePay'] = [
                'method' => 'applePay',
                'callToActionText' => $this->l('Apple Pay'),
                'additionalInformation' => '',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/apple-pay.svg'),
                'binary' => true,
            ];
        }

        // Google
        if (Configuration::get('MONEI_ALLOW_GOOGLE') &&
            !PsTools::isSafariBrowser() &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::GOOGLE, $currencyIsoCode)
        ) {
            $paymentOptionList['googlePay'] = [
                'method' => 'googlePay',
                'callToActionText' => $this->l('Google Pay'),
                'additionalInformation' => '',
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/google-pay.svg'),
                'binary' => true,
            ];
        }

        // ClickToPay
        if (Configuration::get('MONEI_ALLOW_CLICKTOPAY') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::CLICKTOPAY, $currencyIsoCode)
        ) {
            $paymentOptionList['clickToPay'] = [
                'method' => 'clickToPay',
                'callToActionText' => $this->l('Click To Pay'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/click-to-pay.svg'),
            ];
        }

        // PayPal
        if (Configuration::get('MONEI_ALLOW_PAYPAL') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::PAYPAL, $currencyIsoCode)
        ) {
            $paymentOptionList['paypal'] = [
                'method' => 'paypal',
                'callToActionText' => $this->l('Paypal'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/paypal.svg'),
            ];
        }

        // COFIDIS
        if (Configuration::get('MONEI_ALLOW_COFIDIS') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::COFIDIS, $currencyIsoCode, $countryIsoCode)
        ) {
            $paymentOptionList['cofidis'] = [
                'method' => 'cofidis',
                'callToActionText' => $this->l('COFIDIS'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/cofidis.svg'),
            ];
        }

        // Klarna
        if (Configuration::get('MONEI_ALLOW_KLARNA') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::KLARNA, $currencyIsoCode, $countryIsoCode)
        ) {
            $paymentOptionList['klarna'] = [
                'method' => 'klarna',
                'callToActionText' => $this->l('Klarna'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/klarna.svg'),
            ];
        }

        // Multibanco
        if (Configuration::get('MONEI_ALLOW_MULTIBANCO') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::MULTIBANCO, $currencyIsoCode, $countryIsoCode)
        ) {
            $paymentOptionList['multibanco'] = [
                'method' => 'multibanco',
                'callToActionText' => $this->l('Multibanco'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/multibanco.svg'),
            ];
        }

        // MBWay
        if (Configuration::get('MONEI_ALLOW_MBWAY') &&
            $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::MBWAY, $currencyIsoCode, $countryIsoCode)
        ) {
            $paymentOptionList['mbway'] = [
                'method' => 'mbway',
                'callToActionText' => $this->l('MB Way'),
                'additionalInformation' => $template,
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/mbway.svg'),
            ];
        }

        foreach ($paymentOptionList as $paymentOption) {
            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option->setModuleName($this->name . '-' . $paymentOption['method']);

            if (isset($paymentOption['callToActionText'])) {
                $testModeText = '';
                if (!$moneiAccount->getAccountInformation()->isLiveMode()) {
                    $testModeText = ' (' . $this->l('Test Mode') . ')';
                }

                $option->setCallToActionText(
                    $paymentOption['callToActionText'] . $testModeText
                );
            }
            if (isset($paymentOption['additionalInformation'])) {
                $option->setAdditionalInformation($paymentOption['additionalInformation']);
            }
            if (isset($paymentOption['logo'])) {
                $option->setLogo($paymentOption['logo']);
            }

            if (isset($paymentOption['form'])) {
                $option->setForm($paymentOption['form']);
            }

            if (isset($paymentOption['action'])) {
                $option->setAction($paymentOption['action']);
            } else {
                $redirection = true;
                if ($redirection) {
                    $option->setAction(
                        $this->context->link->getModuleLink($this->name, 'redirect', [
                            'method' => $paymentOption['method'],
                            'transaction_id' => $transactionId,
                            'monei_payment_id' => $moneiPaymentId,
                        ])
                    );
                }
            }

            if (isset($paymentOption['binary'])) {
                $option->setBinary($paymentOption['binary']);
            }

            $paymentMethods[] = $option;
        }

        $this->moneiPaymentId = $moneiPaymentId;
        $this->paymentMethods = $paymentMethods;
    }

    public function hookDisplayPaymentByBinaries($params)
    {
        if (!$this->isMoneiAvailable($params['cart'])) {
            return;
        }

        $paymentMethodsToDisplay = [];

        $this->getPaymentMethods();
        if (!$this->paymentMethods || !$this->moneiPaymentId) {
            return;
        }

        foreach ($this->paymentMethods as $paymentOption) {
            if ($paymentOption->isBinary()) {
                $paymentMethodsToDisplay[] = $paymentOption->getModuleName();
            }
        }

        if ($paymentMethodsToDisplay) {
            $this->context->smarty->assign([
                'paymentMethodsToDisplay' => $paymentMethodsToDisplay,
                'moneiPaymentId' => $this->moneiPaymentId,
                'moneiAmount' => Tools::displayPrice($this->context->cart->getOrderTotal()),
                'customerData' => $this->getCustomerData(),
                'billingData' => $this->getAddressData((int) $this->context->cart->id_address_invoice),
                'shippingData' => $this->getAddressData((int) $this->context->cart->id_address_delivery),
            ]);

            return $this->fetch('module:monei/views/templates/hook/displayPaymentByBinaries.tpl');
        }
    }

    /**
     * Hook to display the refunds, when available
     */
    public function hookDisplayAdminOrder($params)
    {
        $id_order = (int) $params['id_order'];
        $history_logs = [];

        $id_monei = MoneiClass::getIdByIdOrder($id_order);
        if (!$id_monei) {
            return;
        }

        $monei = new MoneiClass($id_monei);
        $history_logs = $this->formatHistoryLogs($monei->getHistory());
        $refund_logs = $this->formatHistoryLogs($monei->getRefundHistory(), true);
        $order = new Order($id_order);
        $total_order = $order->getTotalPaid() * 100;
        $amount_refunded = $monei->getTotalRefunded($id_monei);

        $is_refundable = false;
        if ($amount_refunded <= $total_order) {
            $is_refundable = true;
        }

        $currency = new Currency($order->id_currency);
        $this->context->smarty->assign(
            [
                'admin_monei_token' => Tools::getAdminTokenLite('AdminMonei'),
                'id_order' => $id_order,
                'refund_logs' => $refund_logs,
                'history_logs' => $history_logs,
                'id_order_monei' => $monei->id_order_monei,
                'id_order_internal' => $monei->id_order_internal,
                'authorization_code' => $monei->authorization_code,
                //'status' => $monei->status,
                'max_amount' => ($monei->amount - $amount_refunded) / 100,
                'amount_paid' => $total_order,
                'amount_refunded' => $amount_refunded,
                'amount_refunded_formatted' => $this->formatPrice(
                    $amount_refunded / 100,
                    MoneiClass::getISOCurrencyByIdOrder($id_order)
                ),
                'is_refundable' => $is_refundable,
                'currency_symbol' => $currency->getSign('right'),
                'currency_iso' => $currency->iso_code
            ]
        );
        $template = 'order177';
        if (version_compare(_PS_VERSION_, '1.7.7', '<')) {
            $template = 'order17';
        }

        return $this->display(__FILE__, 'views/templates/admin/' . $template . '.tpl');
    }

    /**
     * Hook to load SweetAlerts on payment return
     */
    public function hookActionFrontControllerSetMedia()
    {
        // Checkout
        if (
            property_exists($this->context->controller, 'page_name')
            && $this->context->controller->page_name == 'checkout'
        ) {
            $moneiv2 = 'https://js.monei.com/v2/monei.js';
            $this->context->controller->registerJavascript(
                sha1($moneiv2),
                $moneiv2,
                [
                    'server' => 'remote',
                    'priority' => 50,
                    'attribute' => 'defer',
                ]
            );

            $sweetalert2 = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
            $this->context->controller->registerJavascript(
                sha1($sweetalert2),
                $sweetalert2,
                [
                    'server' => 'remote',
                    'priority' => 50,
                    'attribute' => 'defer',
                ]
            );

            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-front',
                'modules/' . $this->name . '/views/js/front.js',
                [
                    'priority' => 300,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );

            $this->context->controller->registerStylesheet(
                'module-' . $this->name . '-checkout-page',
                'modules/' . $this->name . '/views/css/checkout_page.css',
                [
                    'priority' => 200,
                    'media' => 'all',
                    'position' => 'bottom',
                ]
            );

            Media::addJsDef([
                'moneiProcessing' => $this->l('Processing payment...'),
                'moneiCardHolderNameNotValid' => $this->l('Card holder name is not valid'),
                'moneiMsgRetry' => $this->l('Retry'),
                'moneiCardInputStyle' => json_decode(Configuration::get('MONEI_CARD_INPUT_STYLE')),
                'moneiBizumStyle' => json_decode(Configuration::get('MONEI_BIZUM_STYLE')),
                'moneiPaymentRequestStyle' => json_decode(Configuration::get('MONEI_PAYMENT_REQUEST_STYLE')),
            ]);
        }

        // Card manager
        if (
            property_exists($this->context->controller, 'page_name')
            && $this->context->controller->page_name == 'module-monei-cards'
        ) {
            $msg_title_remove_card = $this->l('Remove card');
            $msg_text_remove_card = $this->l('Are you sure you want to remove this card?');
            $btn_cancel_remove_card = $this->l('Cancel');
            $btn_confirm_remove_card = $this->l('Confirm');
            $monei_successfully_removed_card = $this->l('Card successfully removed');

            Media::addJsDef([
                'monei_title_remove_card' => $msg_title_remove_card,
                'monei_text_remove_card' => $msg_text_remove_card,
                'monei_cancel_remove_card' => $btn_cancel_remove_card,
                'monei_confirm_remove_card' => $btn_confirm_remove_card,
                'monei_successfully_removed_card' => $monei_successfully_removed_card,
                'monei_index_url' => $this->context->link->getPageLink('index')
            ]);

            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-sweet',
                'modules/' . $this->name . '/views/js/sweetalert.min.js',
                [
                    'priority' => 300,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );

            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-cards',
                'modules/' . $this->name . '/views/js/cards.js',
                [
                    'priority' => 300,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );
        }
    }

    public function hookDisplayCustomerAccount()
    {
        $nb_cards = MoneiCard::getNbCards($this->context->customer->id);
        $is_warehouse = Module::isEnabled('iqitelementor');

        if ($nb_cards > 0) {
            $this->context->smarty->assign(
                [
                    'is_warehouse' => $is_warehouse
                ]
            );

            return $this->display(__FILE__, 'views/templates/hook/customer_account.tpl');
        }
    }

    /**
     * GDPR Compliance Hooks
     */
    public function hookActionDeleteGDPRCustomer($customer)
    {
        if (!empty($customer['id'])) {
            $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'monei_tokens` WHERE `id_customer` = ' . (int)$customer['id'];
            if (Db::getInstance()->execute($sql)) {
                return json_encode(true);
            }
            return json_encode($this->l('MONEI Official: Unable to delete customer tokenized cards from database'));
        }
    }

    public function hookActionExportGDPRData($customer)
    {
        if (!empty($customer['id'])) {
            $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'monei_tokens` WHERE `id_customer` = ' . (int)$customer['id'];
            if ($res = Db::getInstance()->execute($sql)) {
                return json_encode($res);
            }
            return json_encode($this->l('MONEI Official: Unable to export customer tokenized cards from database'));
        }
    }

    /**
     * Checks if the currency is one of the granted ones
     * @param mixed $cart
     * @return bool
     * @throws PrestaShopException
     * @throws PrestaShopDatabaseException
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency();
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get formatted logs for Smarty templates
     * @param mixed $history_logs
     * @param bool $are_refunds
     * @return array
     */
    private function formatHistoryLogs($history_logs, $are_refunds = false)
    {
        $logs = [];
        if (!$history_logs) {
            return $logs;
        }

        foreach ($history_logs as $history) {
            // Instanciamos MoneiPayment
            $json_clean = trim(str_replace('\"', '"', $history['response']), '"');
            $json_clean = trim(str_replace('\\"', '"', $json_clean), '"');
            $json_array = $this->vJSON($json_clean);

            if ($json_array) {
                $badge = 'info';
                if (isset($json_array['status'])) {
                    switch ($json_array['status']) {
                        case 'FAILED':
                            $badge = 'danger';
                            break;
                        case 'PENDING':
                            $badge = 'warning';
                            break;
                        case 'REFUNDED':
                        case 'PARTIALLY_REFUNDED':
                        case 'SUCCESS':
                            $badge = 'success';
                            break;
                    }
                }

                if ($are_refunds) {
                    $id_order = (int)MoneiClass::getIdOrderByIdMonei($history['id_monei']);
                    $iso_currency = MoneiClass::getISOCurrencyByIdOrder($id_order);
                    $details = MoneiClass::getRefundDetailByIdMoneiHistory($history['id_monei_history']);
                    $employee = new Employee($details['id_employee']);
                    $amount = $details['amount'];
                    $json_array['amount'] = $this->formatPrice($amount / 100, $iso_currency);
                    $json_array['employee'] = $employee->email;
                }

                $json_array['date_add'] = $history['date_add'];
                $json_array['b64'] = Mbstring::mb_convert_encoding(json_encode($json_array), 'BASE64');
                $json_array['badge'] = $badge;
                $json_array['is_callback'] = $history['is_callback'];
                $logs[] = $json_array;
            }
        }

        return $logs;
    }

    /**
     * Formats number to Currency (price)
     * @param mixed $price
     * @return mixed
     * @throws LocalizationException
     */
    private function formatPrice($price, $iso_currency)
    {
        if (version_compare(_PS_VERSION_, '1.7.7', '>=')) {
            $context = Context::getContext();
            $locale = Tools::getContextLocale($context);
            return $locale->formatPrice($price, $iso_currency);
        } else {
            return Tools::displayPrice($price);
        }
    }
}
