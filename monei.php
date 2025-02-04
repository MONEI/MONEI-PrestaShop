<?php

require_once dirname(__FILE__).'/vendor/autoload.php';

use PsMonei\MoneiPaymentMethods;

use Monei\ApiException;
use Monei\MoneiClient;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use PsMonei\Entity\MoCustomerCard;
use PsMonei\Entity\MoPayment;
use Symfony\Polyfill\Mbstring\Mbstring;

if (!defined('_PS_VERSION_')) {
    exit;
}
class Monei extends PaymentModule
{
    protected $config_form = false;
    protected $paymentMethods;

    public const LOG_SEVERITY_LEVELS = [
        'info' => 1,
        'error' => 2,
        'warning' => 3,
        'major' => 4,
    ];

    /**
     * Payment methods
     */
    public const MONEI_PAYMENT_METHOD_CARD = 'card';
    public const MONEI_PAYMENT_METHOD_BIZUM = 'bizum';
    public const MONEI_PAYMENT_METHOD_APPLE = 'applePay';
    public const MONEI_PAYMENT_METHOD_GOOGLE = 'googlePay';
    public const MONEI_PAYMENT_METHOD_CLICKTOPAY = 'clickToPay';
    public const MONEI_PAYMENT_METHOD_PAYPAL = 'paypal';
    public const MONEI_PAYMENT_METHOD_COFIDIS = 'cofidis';
    public const MONEI_PAYMENT_METHOD_KLARNA = 'klarna';
    public const MONEI_PAYMENT_METHOD_MULTIBANCO = 'multibanco';
    public const MONEI_PAYMENT_METHOD_MBWAY = 'mbway';

    /**
     * Payment statuses
     */
    public const MONEI_STATUS_SUCCEEDED = 'SUCCEEDED';
    public const MONEI_STATUS_PENDING = 'PENDING';
    public const MONEI_STATUS_FAILED = 'FAILED';
    public const MONEI_STATUS_CANCELED = 'CANCELED';
    public const MONEI_STATUS_REFUNDED = 'REFUNDED';
    public const MONEI_STATUS_PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const MONEI_STATUS_AUTHORIZED = 'AUTHORIZED';
    public const MONEI_STATUS_EXPIRED = 'EXPIRED';
    public const MONEI_STATUS_UPDATED = 'UPDATED';
    public const MONEI_STATUS_PAID_OUT = 'PAID_OUT';
    public const MONEI_STATUS_PENDING_PROCESSING = 'PENDING_PROCESSING';

    const NAME = 'monei';
    const VERSION = '2.0.0';

    private static $serviceContainer;
    private static $serviceList;

    public function __construct()
    {
        $this->displayName = 'MONEI Payments';
        $this->name = 'monei';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'MONEI';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        $this->controllers = [
            'validation', 'confirmation', 'redirect', 'cards', 'errors', 'check'
        ];

        parent::__construct();

        $this->description = $this->l('Accept Card, Apple Pay, Google Pay, Bizum, PayPal and many more payment methods in your store.');
    }

    public function getMoneiClient()
    {
        $apiKey = Configuration::get('MONEI_API_KEY');

        if (!$apiKey) {
            return false;
        }

        try {
            return new MoneiClient($apiKey);
        } catch (ApiException $e) {
            return false;
        }
    }

    // public function getMoneiAccount()
    // {
    //     try {
    //         $moneiClient = $this->getMoneiClient();
    //         if (!$moneiClient) {
    //             return false;
    //         }

    //         return $moneiClient->paymentMethods->getAccountInformation();
    //     } catch (ApiException $e) {
    //         return false;
    //     }
    // }

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
            $this->registerHook('paymentOptions') &&
            $this->registerHook('actionCustomerLogoutAfter');
    }

    public static function getService($serviceName)
    {
        $serviceName = self::NAME . '.' . $serviceName;

        if (is_null(self::$serviceContainer)) {
            $localPath = _PS_MODULE_DIR_ . self::NAME . '/';

            self::$serviceContainer = new \PrestaShop\ModuleLibServiceContainer\DependencyInjection\ServiceContainer(
                self::NAME . str_replace('.', '', self::VERSION),
                $localPath
            );
        }

        if (isset(self::$serviceList[$serviceName])) {
            return self::$serviceList[$serviceName];
        }

        self::$serviceList[$serviceName] = self::$serviceContainer->getService($serviceName);

        return self::$serviceList[$serviceName];
    }

    public function getRepository($class)
    {
        return $this->get('doctrine.orm.entity_manager')->getRepository($class);
    }

    public function getDbalConnection()
    {
        return $this->get('doctrine.dbal.default_connection');
    }

    public function getLegacyContext()
    {
        return $this->get('prestashop.adapter.legacy.context');
    }

    public function getLegacyConfiguration()
    {
        return $this->get('prestashop.adapter.legacy.configuration');
    }

    public function getCacheClearerChain()
    {
        return $this->get('prestashop.core.cache.clearer.cache_clearer_chain');
    }

    public function getModuleLink(string $controller, array $params = [])
    {
        return $this->context->link->getModuleLink($this->name, $controller, $params);
    }

    public static function getPaymentMethodsAllowed()
    {
        return [
            self::CARD,
            self::BIZUM,
            self::APPLE,
            self::GOOGLE,
            self::CLICKTOPAY,
            self::PAYPAL,
            self::COFIDIS,
            self::KLARNA,
            self::MULTIBANCO,
            self::MBWAY,
        ];
    }

    public static function getPaymentStatusesAllowed()
    {
        return [
            self::SUCCEEDED,
            self::PENDING,
            self::FAILED,
            self::CANCELED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
            self::AUTHORIZED,
            self::EXPIRED,
            self::UPDATED,
            self::PAID_OUT,
            self::PENDING_PROCESSING,
        ];
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
        $moneiClient = $this->getMoneiClient();
        if ($moneiClient && (bool) Configuration::get('MONEI_PRODUCTION_MODE')) {
            try {
                $domain = str_replace(['www.', 'https://', 'http://'], '', Tools::getShopDomainSsl(false, true));
                $moneiClient->apple->register($domain);
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

    public function isMoneiAvailable($cart)
    {
        if (!$this->active) {
            return false;
        }
        if (!$this->checkCurrency($cart)) {
            return false;
        }

        $moneiClient = $this->getMoneiClient();
        if (!$moneiClient) {
            return false;
        }

        // $moneiAccount = $this->getMoneiAccount();
        // if (!$moneiAccount) {
        //     return false;
        // }

        return true;
    }

    /**
     * Get all available payment methods
     * @return array
     */
    private function getPaymentMethods()
    {
        if ($this->paymentMethods) {
            return;
        }

        $cart = $this->context->cart;

        $moneiPaymentMethod = false;

        $moneiAccount = false;
        // $moneiAccount = $this->getMoneiAccount();
        // if ($moneiAccount) {
        //     $moneiPaymentMethod = $moneiAccount->getPaymentMethodsAllowed();
        // }

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
        if (Configuration::get('MONEI_ALLOW_CARD') && (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::CARD, $currencyIsoCode))) {
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
                    'isCustomerLogged' => Validate::isLoadedObject($this->context->customer) ? true : false,
                ]);

                $paymentOptionList['card']['additionalInformation'] = $this->fetch('module:monei/views/templates/front/onsite_card.tpl');
                $paymentOptionList['card']['binary'] = true;
            }

            // Get current customer cards (not expired ones)
            $activeCustomerCards = $this->getRepository(MoCustomerCard::class)->getActiveCustomerCards($this->context->cart->id_customer);
            if ($activeCustomerCards) {
                foreach ($activeCustomerCards as $customerCard) {
                    $callToActionText = $this->l('Saved Card');
                    $callToActionText .= ': ' . $customerCard->getBrand() . ' ' . $customerCard->getLastFourWithMask();
                    $callToActionText .= ' (' . $customerCard->getExpirationFormatted() . ')';

                    $redirectUrl = $this->context->link->getModuleLink($this->name, 'redirect', [
                        'method' => 'tokenized_card',
                        'transaction_id' => $transactionId,
                        'id_monei_card' => $customerCard->getId(),
                    ]);

                    $paymentOptionList['card-' . $customerCard->getId()] = [
                        'method' => 'tokenized_card',
                        'callToActionText' => $callToActionText,
                        'additionalInformation' => $template,
                        'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments/' . strtolower($customerCard->getBrand()) . '.svg'),
                        'action' => $redirectUrl,
                    ];
                }
            }
        }

        // Bizum
        if (Configuration::get('MONEI_ALLOW_BIZUM') &&
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::BIZUM, $currencyIsoCode, $countryIsoCode))
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
            $this->isSafariBrowser() &&
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::APPLE, $currencyIsoCode))
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
            !$this->isSafariBrowser() &&
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::GOOGLE, $currencyIsoCode))
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
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::CLICKTOPAY, $currencyIsoCode))
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
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::PAYPAL, $currencyIsoCode))
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
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::COFIDIS, $currencyIsoCode, $countryIsoCode))
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
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::KLARNA, $currencyIsoCode, $countryIsoCode))
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
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::MULTIBANCO, $currencyIsoCode, $countryIsoCode))
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
            (!$moneiPaymentMethod || $moneiPaymentMethod->isPaymentMethodAllowed(MoneiPaymentMethods::MBWAY, $currencyIsoCode, $countryIsoCode))
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
                if ($moneiAccount && !$moneiAccount->isLiveMode()) {
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
                        ])
                    );
                }
            }

            if (isset($paymentOption['binary'])) {
                $option->setBinary($paymentOption['binary']);
            }

            $paymentMethods[] = $option;
        }

        $this->paymentMethods = $paymentMethods;
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
        if (!$this->paymentMethods) {
            return;
        }

        return $this->paymentMethods;
    }

    public function hookDisplayPaymentByBinaries($params)
    {
        if (!$this->isMoneiAvailable($params['cart'])) {
            return;
        }

        $paymentMethodsToDisplay = [];

        $this->getPaymentMethods();
        if (!$this->paymentMethods) {
            return;
        }

        foreach ($this->paymentMethods as $paymentOption) {
            if ($paymentOption->isBinary()) {
                $paymentMethodsToDisplay[] = $paymentOption->getModuleName();
            }
        }

        $moneiService = $this->getService('service.monei');
        $cartSummaryDetails = $this->context->cart->getSummaryDetails(null, true);

        if ($paymentMethodsToDisplay) {
            $this->context->smarty->assign([
                'paymentMethodsToDisplay' => $paymentMethodsToDisplay,
                'moneiAccountId' => Configuration::get('MONEI_ACCOUNT_ID'),
                'moneiAmount' => $moneiService->getCartAmount($cartSummaryDetails, $this->context->cart->id_currency),
                'moneiAmountFormatted' => Tools::displayPrice($moneiService->getCartAmount($cartSummaryDetails, $this->context->cart->id_currency, true)),
                'moneiCreatePaymentUrlController' => $this->context->link->getModuleLink('monei', 'createPayment'),
                'moneiToken' => Tools::getToken(false),
                'moneiCurrency' => $this->context->currency->iso_code,
            ]);

            return $this->fetch('module:monei/views/templates/hook/displayPaymentByBinaries.tpl');
        }
    }

    /**
     * Hook to display the refunds, when available
     */
    public function hookDisplayAdminOrder($params)
    {
        $orderId = (int) $params['id_order'];

        $moPaymentEntity = $this->getRepository(MoPayment::class)->findOneBy(['id_order' => $orderId]);
        if (!$moPaymentEntity) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $currency = new Currency($order->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            return;
        }

        $paymentHistoryLogs = [];
        $paymentRefundLogs = [];

        $paymentHistory = $moPaymentEntity->getHistoryList();
        if (!$paymentHistory->isEmpty()) {
            foreach ($paymentHistory as $history) {
                $paymentHistoryLog = $history->toArrayLegacy();
                $paymentHistoryLog['responseDecoded'] = $history->getResponseDecoded();
                $paymentHistoryLog['responseB64'] = Mbstring::mb_convert_encoding($history->getResponse(), 'BASE64');

                $paymentHistoryLogs[] = $paymentHistoryLog;

                $paymentRefund = $moPaymentEntity->getRefundByHistoryId($history->getId());
                if ($paymentRefund) {
                    $paymentRefundLog = $paymentRefund->toArrayLegacy();
                    $paymentRefundLog['paymentHistory'] = $paymentHistoryLog;
                    $paymentRefundLog['amountFormatted'] = $this->formatPrice($paymentRefundLog['amount_in_decimal'], $currency->iso_code);

                    $employeeEmail = '';
                    if ($paymentRefundLog['id_employee']) {
                        $employee = new Employee($paymentRefundLog['id_employee']);
                        $employeeEmail = $employee->email;
                    }

                    $paymentRefundLog['employeeEmail'] = $employeeEmail;

                    $paymentRefundLogs[] = $paymentRefundLog;
                }
            }
        }

        $this->context->smarty->assign([
            'moneiPayment' => $moPaymentEntity->toArrayLegacy(),
            'isRefundable' => $moPaymentEntity->isRefundable(),
            'remainingAmountToRefund' => $moPaymentEntity->getRemainingAmountToRefund(),
            'totalRefundedAmount' => $moPaymentEntity->getRefundedAmount(),
            'totalRefundedAmountFormatted' => $this->formatPrice($moPaymentEntity->getRefundedAmount(true), $currency->iso_code),
            'paymentHistoryLogs' => $paymentHistoryLogs,
            'paymentRefundLogs' => $paymentRefundLogs,
            'orderId' => $orderId,
            'orderTotalPaid' => $order->getTotalPaid() * 100,
            'currencySymbol' => $currency->getSign('right'),
            'currencyIso' => $currency->iso_code,
            'sweetalert2' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11',
        ]);

        return $this->display(__FILE__, 'views/templates/hook/displayAdminOrder.tpl');
    }

    /**
     * Hook to load SweetAlerts on payment return
     */
    public function hookActionFrontControllerSetMedia()
    {
        if (!property_exists($this->context->controller, 'page_name')) {
            return;
        }

        $pageName = $this->context->controller->page_name;

        if ($pageName == 'module-monei-customerCards' || $pageName == 'checkout') {
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
        }

        // Checkout
        if ($pageName == 'checkout') {
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

            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-front',
                'modules/' . $this->name . '/views/js/front/front.js',
                [
                    'priority' => 300,
                    'attribute' => 'async',
                    'position' => 'bottom',
                ]
            );

            $this->context->controller->registerStylesheet(
                'module-' . $this->name . '-checkout-page',
                'modules/' . $this->name . '/views/css/front/checkout_page.css',
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
        if ($pageName == 'module-monei-customerCards') {
            Media::addJsDef([
                'MoneiVars' => [
                    'titleRemoveCard' => $this->l('Remove card'),
                    'textRemoveCard' => $this->l('Are you sure you want to remove this card?'),
                    'cancelRemoveCard' => $this->l('Cancel'),
                    'confirmRemoveCard' => $this->l('Confirm'),
                    'successfullyRemovedCard' => $this->l('Card successfully removed'),
                    'indexUrl' => $this->context->link->getPageLink('index')
                ]
            ]);

            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-customerCards',
                'modules/' . $this->name . '/views/js/front/customerCards.js',
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
        $customerCards = $this->getRepository(MoCustomerCard::class)->findBy(['id_customer' => $this->context->customer->id]);

        $isWarehouseInstalled = Module::isEnabled('iqitelementor');

        if ($customerCards) {
            $this->context->smarty->assign('isWarehouseInstalled', $isWarehouseInstalled);

            return $this->display(__FILE__, 'views/templates/hook/displayCustomerAccount.tpl');
        }
    }

    /**
     * GDPR Compliance Hooks
     */
    public function hookActionDeleteGDPRCustomer($customer)
    {
        if (!empty($customer['id'])) {
            try {
                $customerCards = $this->getRepository(MoCustomerCard::class)->findBy(['id_customer' => (int) $customer['id']]);
                if ($customerCards) {
                    foreach ($customerCards as $customerCard) {
                        $this->getRepository(MoCustomerCard::class)->removeMoneiCustomerCard($customerCard);
                    }
                }

                return json_encode(true);
            } catch (Exception $e) {
                return json_encode($this->l('MONEI Official: Unable to delete customer tokenized cards from database'));
            }
        }
    }

    public function hookActionExportGDPRData($customer)
    {
        if (!empty($customer['id'])) {
            try {
                $customerCards = $this->getRepository(MoCustomerCard::class)->findBy(['id_customer' => (int) $customer['id']]);
                if ($customerCards) {
                    $customerCardsArray = [];
                    foreach ($customerCards as $customerCard) {
                        $customerCardsArray[] = $customerCard->toArrayLegacy();
                    }

                    return json_encode($customerCardsArray);
                }
            } catch (Exception $e) {
                return json_encode($this->l('MONEI Official: Unable to export customer tokenized cards from database'));
            }
        }
    }

    public function hookActionCustomerLogoutAfter()
    {
        unset($this->context->cookie->monei_error);
    }

    /**
     * Hook for JSON Viewer
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') === $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin/admin.css');
        }

        // Only for Orders controller, we dont need to load JS/CSS everywhere
        if ($this->context->controller->controller_name !== 'AdminOrders') {
            return;
        }

        Media::addJsDef([
            'MoneiVars' => [
                'titleRefund' => $this->l('Refund'),
                'textRefund' => $this->l('Are you sure you want to refund this order?'),
                'confirmRefund' => $this->l('Yes, make the refund'),
                'cancelRefund' => $this->l('Cancel'),
                'adminMoneiControllerUrl' => $this->context->link->getAdminLink('AdminMonei'),
            ],
        ]);

        $this->context->controller->addCSS($this->_path . 'views/css/jquery.json-viewer.css');

        $this->context->controller->addJS($this->_path . 'views/js/jquery.json-viewer.js');
        $this->context->controller->addJS($this->_path . 'views/js/admin/admin.js');
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
     * Formats number to Currency (price)
     * @param mixed $price
     * @return mixed
     * @throws LocalizationException
     */
    private function formatPrice($price, $currencyIso)
    {
        $locale = Tools::getContextLocale($this->context);
        return $locale->formatPrice($price, $currencyIso);
    }

    /**
     * Detects if Safari is the current browser.
     * @return bool
     */
    public function isSafariBrowser()
    {
        $userBrowser = Tools::getUserBrowser();
        if (strpos($userBrowser, 'Safari') !== false) {
            return true;
        }
        return false;
    }
}
