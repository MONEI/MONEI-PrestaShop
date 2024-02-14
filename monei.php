<?php

// Load libraries
require_once dirname(__FILE__) . '/vendor/autoload.php';

use Monei\CoreClasses\Monei as MoneiClass;
use Monei\CoreClasses\MoneiCard;
use Monei\CoreHelpers\PsTools;
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

    public function __construct()
    {
        $this->displayName = $this->l('MONEI Official');
        $this->description = $this->l('Accept Card, Bizum, PayPal and many more payment methods in your store.');
        $this->name = 'monei';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.4';
        $this->author = 'MONEI';
        $this->need_instance = 0;
        $this->controllers = [
            'validation', 'confirmation', 'redirect', 'cards', 'errors', 'check'
        ];

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->module_key = '4d41d86eaadd516c0c922aca9abce7a3';

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
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
        Configuration::updateValue('MONEI_CART_TO_ORDER', false);
        Configuration::updateValue('MONEI_EXPIRE_TIME', 600);
        Configuration::updateValue('MONEI_SHOW_ALL', true);
        // Gateways
        Configuration::updateValue('MONEI_ALLOW_CARD', true);
        Configuration::updateValue('MONEI_ALLOW_BIZUM', false);
        Configuration::updateValue('MONEI_ALLOW_APPLE', false);
        Configuration::updateValue('MONEI_ALLOW_GOOGLE', false);
        Configuration::updateValue('MONEI_ALLOW_CLICKTOPAY', false);
        Configuration::updateValue('MONEI_ALLOW_PAYPAL', false);
        Configuration::updateValue('MONEI_ALLOW_COFIDIS', false);
        Configuration::updateValue('MONEI_ALLOW_KLARNA', false);
        Configuration::updateValue('MONEI_ALLOW_MULTIBANCO', false);
        // Status
        Configuration::updateValue('MONEI_STATUS_SUCCEEDED', Configuration::get('PS_OS_PAYMENT'));
        Configuration::updateValue('MONEI_STATUS_FAILED', Configuration::get('PS_OS_ERROR'));
        Configuration::updateValue('MONEI_SWITCH_REFUNDS', false);
        Configuration::updateValue('MONEI_STATUS_REFUNDED', Configuration::get('PS_OS_REFUND'));
        Configuration::updateValue('MONEI_STATUS_PARTIALLY_REFUNDED', Configuration::get('PS_OS_REFUND'));
        Configuration::updateValue('MONEI_STATUS_PENDING', Configuration::get('PS_OS_PREPARATION'));

        include(dirname(__FILE__) . '/sql/install.php');

        return parent::install() &&
            $this->installOrderState() &&
            $this->installAdminTab('AdminMonei', 'MONEI') &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('displayCustomerAccount') &&
            $this->registerHook('registerGDPRConsent') &&
            $this->registerHook('actionDeleteGDPRCustomer') &&
            $this->registerHook('actionExportGDPRData') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('displayAdminOrder') &&
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
        Configuration::deleteByName('MONEI_CART_TO_ORDER');
        Configuration::deleteByName('MONEI_EXPIRE_TIME');
        Configuration::deleteByName('MONEI_SHOW_ALL');
        // Gateways
        Configuration::deleteByName('MONEI_ALLOW_CARD');
        Configuration::deleteByName('MONEI_ALLOW_BIZUM');
        Configuration::deleteByName('MONEI_ALLOW_APPLE');
        Configuration::deleteByName('MONEI_ALLOW_GOOGLE');
        Configuration::deleteByName('MONEI_ALLOW_CLICKTOPAY');
        Configuration::deleteByName('MONEI_ALLOW_PAYPAL');
        Configuration::deleteByName('MONEI_ALLOW_COFIDIS');
        Configuration::deleteByName('MONEI_ALLOW_KLARNA');
        Configuration::deleteByName('MONEI_ALLOW_MULTIBANCO');
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
        }

        // Assign values
        $this->context->smarty->assign(array(
            'module_dir' => $this->_path,
            'module_version' => $this->version,
            'module_name' => $this->name,
            'display_name' => $this->displayName,
            'helper_form_1' => $this->renderForm(),
            'helper_form_2' => $this->renderFormGateways(),
            'helper_form_3' => $this->renderFormStatus()
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
        }

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
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
            'MONEI_CART_TO_ORDER' => Configuration::get('MONEI_CART_TO_ORDER', true),
            'MONEI_SHOW_ALL' => Configuration::get('MONEI_SHOW_ALL', true),
        );
    }

    /**
     * Default gateways values for HelperForm
     */
    protected function getConfigFormGatewaysValues()
    {
        return array(
            'MONEI_ALLOW_CARD' => Configuration::get('MONEI_ALLOW_CARD', true),
            'MONEI_ALLOW_BIZUM' => Configuration::get('MONEI_ALLOW_BIZUM', false),
            'MONEI_ALLOW_APPLE' => Configuration::get('MONEI_ALLOW_APPLE', false),
            'MONEI_ALLOW_GOOGLE' => Configuration::get('MONEI_ALLOW_GOOGLE', false),
            'MONEI_ALLOW_CLICKTOPAY' => Configuration::get('MONEI_ALLOW_CLICKTOPAY', false),
            'MONEI_ALLOW_PAYPAL' => Configuration::get('MONEI_ALLOW_PAYPAL', false),
            'MONEI_ALLOW_COFIDIS' => Configuration::get('MONEI_ALLOW_COFIDIS', false),
            'MONEI_ALLOW_KLARNA' => Configuration::get('MONEI_ALLOW_KLARNA', false),
            'MONEI_ALLOW_MULTIBANCO' => Configuration::get('MONEI_ALLOW_MULTIBANCO', false),
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
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show all payment methods'),
                        'name' => 'MONEI_SHOW_ALL',
                        'is_bool' => true,
                        'desc' => $this->l('Shows all payment methods in MONEI instead of only the selected one.'),
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
                        'desc' => $this->l('Allow payments with Credit Card.'),
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
                        'label' => $this->l('Allow Bizum'),
                        'name' => 'MONEI_ALLOW_BIZUM',
                        'is_bool' => true,
                        'desc' => $this->l('Allow payments with Bizum.'),
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
                        'desc' => $this->l('Allow payments with Google Pay.'),
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
                        'label' => $this->l('Allow ClickToPay'),
                        'name' => 'MONEI_ALLOW_CLICKTOPAY',
                        'is_bool' => true,
                        'desc' => $this->l('Allow payments with ClickToPay.'),
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
                        'desc' => $this->l('Allow payments with PayPal.'),
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
                        'desc' => $this->l('Allow payments with COFIDIS.'),
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
                        'desc' => $this->l('Allow payments with Klarna.'),
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
                        'desc' => $this->l('Allow payments with Multibanco.'),
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

    /**
     * Everything from now is related to HelperForms
     */

    /**
     * Hook for JSON Viewer
     */
    public function hookBackOfficeHeader()
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
        $this->context->controller->addJS($this->_path . 'views/js/moneiback.min.js');
        $this->context->controller->addJS($this->_path . 'views/js/jquery.json-viewer.js');
    }

    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookPaymentReturn($params)
    {
        if ($this->active == false) {
            return;
        }

        $order = $params['objOrder'];

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR')) {
            $this->smarty->assign('status', 'ok');
        }

        $this->smarty->assign(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
        ));

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
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
        if (!$this->active) {
            return [];
        }
        if (!$this->checkCurrency($params['cart'])) {
            return [];
        }
        if (!Configuration::get('MONEI_API_KEY')) {
            return [];
        }

        return $this->getPaymentMethods(
            (int)$params['cart']->id,
            (int)$params['cart']->id_customer
        );
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
        $currencies_module = $this->getCurrency($cart->id_currency);
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
     * Get all available payment methods
     * @return array
     */
    private function getPaymentMethods($id_cart, $id_customer)
    {
        $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $transaction_id = $crypto->hash($id_cart . $id_customer, _COOKIE_KEY_);

        // For every payment method configured
        $payment_methods = [];
        $template = '';

        if (Configuration::get('MONEI_SHOW_LOGO')) {
            $this->context->smarty->assign([
                'module_dir' => $this->_path
            ]);
            $template = $this->fetch('module:monei/views/templates/front/additional_info.tpl');
        }

        // Credit Card
        if (Configuration::get('MONEI_ALLOW_CARD')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'card',
                'transaction_id' => $transaction_id,
                'monei_tokenize_card' => 0
            ]);

            $template_tokenize = '';
            if (Configuration::get('MONEI_TOKENIZE')) {
                $template_tokenize = $this->fetch('module:monei/views/templates/front/tokenize.tpl');
            }

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('Credit Card'))
                ->setAdditionalInformation($template . $template_tokenize)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }


        // Get current customer cards (not expired ones)
        $customer_cards = MoneiCard::getStaticCustomerCards($id_customer, false);
        if ($customer_cards) {
            foreach ($customer_cards as $card) {
                $credit_card = new MoneiCard($card['id_monei_tokens']);
                $card_number = '**** **** **** ' . $credit_card->last_four;
                $card_brand = Tools::strtoupper($credit_card->brand);
                $card_expiration = $credit_card->unixEpochToExpirationDate();

                $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                    'method' => 'card',
                    'transaction_id' => $transaction_id,
                    'id_monei_card' => $credit_card->id,
                    'monei_tokenize_card' => 0
                ]);

                $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
                $option
                    ->setCallToActionText($this->l('Saved Card') . ': ' . $card_brand . ' ' . $card_number . ' ('
                        . $card_expiration . ')')
                    ->setAdditionalInformation($template)
                    ->setAction($link_create_payment);
                $payment_methods[] = $option;
            }
        }

        // Bizum
        if (Configuration::get('MONEI_ALLOW_BIZUM')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'bizum',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('Bizum'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // Apple
        if (Configuration::get('MONEI_ALLOW_APPLE') && PsTools::isSafariBrowser()) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'applePay',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('Apple Pay'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // Google
        if (Configuration::get('MONEI_ALLOW_GOOGLE')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'googlePay',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('Google Pay'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // ClickToPay
        if (Configuration::get('MONEI_ALLOW_CLICKTOPAY')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'clickToPay',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('ClickToPay'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // PayPal
        if (Configuration::get('MONEI_ALLOW_PAYPAL')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'paypal',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('PayPal'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // COFIDIS
        if (Configuration::get('MONEI_ALLOW_COFIDIS')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'cofidis',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('COFIDIS'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // Klarna
        if (Configuration::get('MONEI_ALLOW_KLARNA')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'klarna',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('Klarna'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        // Multibanco
        if (Configuration::get('MONEI_ALLOW_MULTIBANCO')) {
            $link_create_payment = $this->context->link->getModuleLink($this->name, 'redirect', [
                'method' => 'multibanco',
                'transaction_id' => $transaction_id
            ]);

            $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option
                ->setCallToActionText($this->l('Multibanco'))
                ->setAdditionalInformation($template)
                ->setAction($link_create_payment);
            $payment_methods[] = $option;
        }

        return $payment_methods;
    }

    /**
     * Hook to display the refunds, when available
     */
    public function hookDisplayAdminOrder($params)
    {
        $id_order = (int)$params['id_order'];
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
                'status' => $monei->status,
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
            $json_array = $this->vJSON($json_clean);

            if ($json_array) {
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
                    default:
                        $badge = 'info';
                        break;
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
        if (version_compare(_PS_VERSION_, '1.7.6', '>=')) {
            $context = Context::getContext();
            $locale = Tools::getContextLocale($context);
            return $locale->formatPrice($price, $iso_currency);
        } else {
            return Tools::displayPrice($price);
        }
    }

    /**
     * Hook to load SweetAlerts on payment return
     */
    public function hookActionFrontControllerSetMedia()
    {
        $allowed_page_names = ['module-monei-confirmation', 'module-monei-errors'];

        // Load only the variables and files into confirmation controller
        if (
            property_exists($this->context->controller, 'page_name')
            && in_array($this->context->controller->page_name, $allowed_page_names)
        ) {
            $msg_mon1_ok = $this->l('👍 Payment completed');
            $msg_mon2_ok = $this->l('Your order was successfully processed using MONEI');
            $type_icon_ok = 'success';

            $msg_mon1_ko = $this->l('❌ Payment failed');
            $msg_mon2_ko = $this->l('Something went wrong with your payment');
            $type_icon_ko = 'error';

            Media::addJsDef([
                'conf_msg_mon1_ok' => $msg_mon1_ok,
                'conf_msg_mon2_ok' => $msg_mon2_ok,
                'conf_mon_icon_ok' => $type_icon_ok,
                'conf_msg_mon1_ko' => $msg_mon1_ko,
                'conf_msg_mon2_ko' => $msg_mon2_ko,
                'conf_mon_icon_ko' => $type_icon_ko,
                'monei_index_url' => $this->context->link->getPageLink('index')
            ]);

            // Swal2
            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-sweet',
                'modules/' . $this->name . '/views/js/sweetalert.min.js',
                [
                    'priority' => 200,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );

            // MONEI Front JS
            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-checker',
                'modules/' . $this->name . '/views/js/checker.min.js',
                [
                    'priority' => 300,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );

            // MONEI Front CSS
            $this->context->controller->registerStylesheet(
                'module-' . $this->name . '-mfront',
                'modules/' . $this->name . '/views/css/moneifront.min.css',
                [
                    'priority' => 200,
                    'media' => 'all',
                    'position' => 'bottom',
                ]
            );
        }

        // Checkout
        if (
            property_exists($this->context->controller, 'page_name')
            && $this->context->controller->page_name == 'checkout'
        ) {
            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-tokenize',
                'modules/' . $this->name . '/views/js/tokenize.min.js',
                [
                    'priority' => 300,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );
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
                'modules/' . $this->name . '/views/js/cards.min.js',
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
}
