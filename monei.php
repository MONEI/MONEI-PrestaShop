<?php

require_once dirname(__FILE__) . '/vendor/autoload.php';

use Monei\Model\PaymentPaymentMethod;
use Monei\Model\PaymentStatus;
use PsMonei\Entity\Monei2CustomerCard;
use PsMonei\Entity\Monei2Payment;
use PsMonei\Service\MoneiServiceLocator;

if (!defined('_PS_VERSION_')) {
    exit;
}
class Monei extends PaymentModule
{
    /**
     * MONEI JS SDK. v3 is what the express checkout components and the split
     * card fields require; v2 has neither.
     */
    const MONEI_JS_URL = 'https://js.monei.com/v3/monei.js';

    /**
     * Lowest PHP the MONEI PHP SDK supports, and therefore this module.
     */
    const MINIMUM_PHP_VERSION = '7.4';

    /**
     * Guards hookActionOrderStatusPostUpdate against re-entering itself.
     *
     * @var bool
     */
    private static $captureInProgress = false;

    protected $config_form = false;
    protected $paymentMethods;
    protected $moneiClient = false;
    protected static $admin_assets_loaded = false;

    // Payment module properties for restrictions
    public $currencies = true;

    const NAME = 'monei';
    const VERSION = '1.8.0';

    const LOG_SEVERITY_LEVELS = [
        'info' => 1,
        'error' => 2,
        'warning' => 3,
        'major' => 4,
    ];

    public function __construct()
    {
        $this->displayName = 'MONEI Payments';
        $this->name = 'monei';
        $this->tab = 'payments_gateways';
        $this->version = '1.8.0';
        $this->author = 'MONEI';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        // Currency support is enabled (currencies_mode defaults to 'checkbox' in parent)
        $this->currencies = true;

        $this->controllers = [
            'validation', 'confirmation', 'redirect', 'cards', 'errors', 'check', 'applepay', 'createPayment',
        ];

        parent::__construct();

        $this->description = $this->l('Accept Card, Apple Pay, Google Pay, Bizum, PayPal and many more payment methods in your store.');
    }

    /**
     * Get log severity level for PrestaShop compatibility
     *
     * @param string $level The log level (info, warning, error, major)
     *
     * @return int
     */
    public static function getLogLevel($level = 'info')
    {
        // Check if PrestaShop 1.7.13+ constants exist
        if (defined('PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE')) {
            switch ($level) {
                case 'info':
                    return constant('PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE');
                case 'warning':
                    return constant('PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING');
                case 'error':
                    return constant('PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR');
                case 'major':
                    return constant('PrestaShopLogger::LOG_SEVERITY_LEVEL_MAJOR');
            }
        }

        // Fallback for PrestaShop 1.7.13 - use numeric values
        $levels = [
            'info' => 1,
            'warning' => 2,
            'error' => 3,
            'major' => 4,
        ];

        return isset($levels[$level]) ? $levels[$level] : 1;
    }

    /**
     * Get Bootstrap version based on PrestaShop version
     *
     * @return int Bootstrap version (3 or 4)
     */
    public function getBootstrapVersion()
    {
        // PrestaShop 1.7.13 uses Bootstrap 3 in admin
        // PrestaShop 1.7.13+ uses Bootstrap 4 in admin
        // Note: All PrestaShop 1.7.x versions use Bootstrap 4 in the frontend (Classic theme)
        // but the admin panel transitioned from Bootstrap 3 to 4 in 1.7.13
        return version_compare(_PS_VERSION_, '1.7.13.0', '>=') ? 4 : 3;
    }

    /**
     * Get modal data attributes based on Bootstrap version
     *
     * @return array Modal attributes for toggle and dismiss
     */
    public function getModalAttributes()
    {
        // Both Bootstrap 3 and 4 in PrestaShop 1.7.x use data-* attributes
        // Bootstrap 5 (used in PrestaShop 8+) uses data-bs-* attributes
        // PrestaShop 1.7.x (including 1.7.13) uses Bootstrap 3/4 which use data-* attributes
        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            // PrestaShop 8+ uses Bootstrap 5
            return [
                'toggle' => 'data-bs-toggle',
                'dismiss' => 'data-bs-dismiss',
                'target' => 'data-bs-target',
            ];
        }

        // PrestaShop 1.7.x (Bootstrap 3/4) uses data-* attributes
        return [
            'toggle' => 'data-toggle',
            'dismiss' => 'data-dismiss',
            'target' => 'data-target',
        ];
    }

    /**
     * Check if a hook exists in current PrestaShop version
     *
     * @param string $hookName Hook name to check
     *
     * @return bool
     */
    public function isHookAvailable($hookName)
    {
        // Check if Hook class has the method to verify hook existence
        if (method_exists('Hook', 'getIdByName')) {
            return (bool) Hook::getIdByName($hookName);
        }

        // Fallback: Try to get hook ID directly from database
        $sql = 'SELECT id_hook FROM ' . _DB_PREFIX_ . 'hook WHERE name = \'' . pSQL($hookName) . '\'';

        return (bool) Db::getInstance()->getValue($sql);
    }

    /**
     * Check PHP version compatibility
     *
     * ⚠️ The floor is the MONEI PHP SDK's, not PrestaShop's. composer.json pins
     * the platform to 7.4 and monei/monei-php-sdk declares `php >=7.4`, so the
     * module cannot run below that whatever PrestaShop itself supports — 1.7.8
     * runs on 7.1 and up. This used to check 7.1.3, and only when PrestaShop was
     * 1.7.13 or newer, so a 1.7.8 store on PHP 7.2 passed the check and then
     * failed inside the SDK with no indication of why.
     *
     * @return array Array with 'compatible' bool and 'message' string
     */
    public function checkPHPCompatibility()
    {
        $phpVersion = PHP_VERSION;

        if (version_compare($phpVersion, self::MINIMUM_PHP_VERSION, '<')) {
            return [
                'compatible' => false,
                'message' => sprintf($this->l('MONEI requires PHP %s or higher. Current PHP version: %s'), self::MINIMUM_PHP_VERSION, $phpVersion),
            ];
        }

        return [
            'compatible' => true,
            'message' => '',
        ];
    }

    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');

            return false;
        }

        // Check PHP compatibility
        $phpCheck = $this->checkPHPCompatibility();
        if (!$phpCheck['compatible']) {
            $this->_errors[] = $phpCheck['message'];

            return false;
        }

        // General
        Configuration::updateValue('MONEI_TOKENIZE', false);
        Configuration::updateValue('MONEI_PRODUCTION_MODE', false);
        Configuration::updateValue('MONEI_SHOW_LOGO', true);
        Configuration::updateValue('MONEI_LOG_LEVEL', 3); // Default to ERROR only
        Configuration::updateValue('MONEI_API_KEY', '');
        Configuration::updateValue('MONEI_ACCOUNT_ID', '');
        Configuration::updateValue('MONEI_TEST_API_KEY', '');
        Configuration::updateValue('MONEI_TEST_ACCOUNT_ID', '');
        Configuration::updateValue('MONEI_EXPIRE_TIME', 600);
        // Gateways
        Configuration::updateValue('MONEI_ALLOW_CARD', true);
        Configuration::updateValue('MONEI_CARD_WITH_REDIRECT', false);
        Configuration::updateValue('MONEI_ALLOW_BIZUM', false);
        Configuration::updateValue('MONEI_BIZUM_WITH_REDIRECT', false);
        Configuration::updateValue('MONEI_ALLOW_APPLE', false);
        Configuration::updateValue('MONEI_ALLOW_GOOGLE', false);
        Configuration::updateValue('MONEI_ALLOW_PAYPAL', false);
        Configuration::updateValue('MONEI_PAYPAL_WITH_REDIRECT', false);
        Configuration::updateValue('MONEI_ALLOW_MULTIBANCO', false);
        Configuration::updateValue('MONEI_ALLOW_MBWAY', false);
        // Payment Action
        Configuration::updateValue('MONEI_PAYMENT_ACTION', 'sale');
        // Status - Use PrestaShop defaults (always available after PS installation)
        Configuration::updateValue('MONEI_STATUS_SUCCEEDED', Configuration::get('PS_OS_PAYMENT'));
        Configuration::updateValue('MONEI_STATUS_FAILED', Configuration::get('PS_OS_ERROR'));
        Configuration::updateValue('MONEI_STATUS_REFUNDED', Configuration::get('PS_OS_REFUND'));
        Configuration::updateValue('MONEI_STATUS_PARTIALLY_REFUNDED', Configuration::get('PS_OS_REFUND'));
        Configuration::updateValue('MONEI_STATUS_PENDING', Configuration::get('PS_OS_PREPARATION'));
        Configuration::updateValue('MONEI_STATUS_AUTHORIZED', 0);
        Configuration::updateValue('MONEI_SWITCH_REFUNDS', true);
        // Card layout. Split fields are the default; 'single' restores the
        // one-line CardInput.
        Configuration::updateValue('MONEI_CARD_LAYOUT', 'split');
        // Express checkout. Off by default: it changes the storefront, so a
        // merchant opts in.
        Configuration::updateValue('MONEI_EXPRESS_ENABLED', false);
        Configuration::updateValue('MONEI_EXPRESS_LOCATIONS', 'product,cart,checkout');
        Configuration::updateValue('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
        // Order states that trigger an automatic capture of a pre-authorization.
        // Empty means automatic capture is off.
        Configuration::updateValue('MONEI_CAPTURE_STATUS', '');
        // Styles
        Configuration::updateValue('MONEI_CARD_INPUT_STYLE', '{"base": {"height": "42px"}, "input": {"background": "none"}}');
        Configuration::updateValue('MONEI_BIZUM_STYLE', '{"height": "42"}');
        Configuration::updateValue('MONEI_PAYMENT_REQUEST_STYLE', '{"height": "42"}');
        Configuration::updateValue('MONEI_PAYPAL_STYLE', '{"height": "42"}');

        include dirname(__FILE__) . '/sql/install.php';

        $result = parent::install()
            && $this->installOrderState()
            && $this->installAdminTab('AdminMonei', 'MONEI')
            && $this->installAdminTab('AdminMoneiCapturePayment', 'MONEI Capture Payment')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('actionDeleteGDPRCustomer')
            && $this->registerHook('actionExportGDPRData')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('displayPaymentByBinaries')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('actionCustomerLogoutAfter')
            && $this->registerHook('moduleRoutes')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('actionGetAdminOrderButtons')
            // Capture a pre-authorization when an order reaches a configured
            // state. Registered unconditionally so it fires for every context
            // that moves an order, not only an admin click.
            && $this->registerHook('actionOrderStatusPostUpdate')
            // Express checkout surfaces. Hook placement verified against the
            // PrestaShop 1.7.8 classic theme:
            //   product  -> catalog/_partials/product-additional-info.tpl
            //   cart     -> checkout/_partials/cart-detailed-actions.tpl
            //   checkout -> checkout/_partials/steps/payment.tpl, above the
            //               payment options
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayExpressCheckout')
            && $this->registerHook('displayPaymentTop');

        // Copy Apple Pay domain verification file to .well-known directory
        if ($result) {
            $this->copyApplePayDomainVerificationFile();

            // Regenerate .htaccess to include the new route
            if (class_exists('Tools') && method_exists('Tools', 'generateHtaccess')) {
                Tools::generateHtaccess();
            }
        }

        return $result;
    }

    public static function getService($serviceName)
    {
        return MoneiServiceLocator::getService($serviceName);
    }

    /**
     * Log a message if it meets the configured minimum log level
     *
     * @param string $message
     * @param int $severity
     */
    public static function log($message, $severity = 1)
    {
        // Handle null or empty messages
        if (empty($message)) {
            return;
        }

        $minLogLevel = (int) Configuration::get('MONEI_LOG_LEVEL', 3); // Default to ERROR only

        // Treat 4 (NONE) as disabled
        if ($minLogLevel === 4) {
            return;
        }

        // Only log if the severity is at or above the configured minimum level
        // PrestaShop severity levels: 1=INFO, 2=WARNING, 3=ERROR, 4=MAJOR
        if ($severity >= $minLogLevel) {
            PrestaShopLogger::addLog($message, $severity);
        }
    }

    /**
     * Convenience method for debug/info logging
     */
    public static function logDebug($message)
    {
        self::log($message, 1); // INFO level
    }

    /**
     * Convenience method for warning logging
     */
    public static function logWarning($message)
    {
        self::log($message, 2); // WARNING level
    }

    /**
     * Convenience method for error logging
     */
    public static function logError($message)
    {
        self::log($message, 3); // ERROR level
    }

    public function getRepository($class)
    {
        // For backward compatibility, return the class itself for static method calls
        return $class;
    }

    public function getDbalConnection()
    {
        // Return Db instance for PS1.7 compatibility
        return Db::getInstance();
    }

    public function getLegacyContext()
    {
        return Context::getContext();
    }

    public function getLegacyConfiguration()
    {
        return Configuration::class;
    }

    public function getCacheClearerChain()
    {
        // PS1.7 doesn't have this service, clear cache manually
        Tools::clearCache();

        return null;
    }

    /**
     * Get order status translations
     *
     * @return array
     */
    public static function getOrderStatusTranslations()
    {
        return [
            'Awaiting payment' => [
                'en' => 'Awaiting payment',
                'es' => 'Esperando pago',
                'ca' => 'Esperant pagament',
                'fr' => 'En attente de paiement',
                'de' => 'Zahlung ausstehend',
                'it' => 'In attesa di pagamento',
                'pt' => 'Aguardando pagamento',
                'nl' => 'Wachten op betaling',
                'pl' => 'Oczekiwanie na płatność',
                'ru' => 'Ожидание платежа',
                'no' => 'Venter på betaling',
                'et' => 'Makse ootel',
                'fi' => 'Odottaa maksua',
                'lv' => 'Gaida maksājumu',
            ],
            'Payment accepted' => [
                'en' => 'Payment accepted',
                'es' => 'Pago aceptado',
                'ca' => 'Pagament acceptat',
                'fr' => 'Paiement accepté',
                'de' => 'Zahlung akzeptiert',
                'it' => 'Pagamento accettato',
                'pt' => 'Pagamento aceite',
                'nl' => 'Betaling geaccepteerd',
                'pl' => 'Płatność zaakceptowana',
                'ru' => 'Платеж принят',
                'no' => 'Betaling godtatt',
                'et' => 'Makse aktsepteeritud',
                'fi' => 'Maksu hyväksytty',
                'lv' => 'Maksājums pieņemts',
            ],
            'Payment error' => [
                'en' => 'Payment error',
                'es' => 'Error en el pago',
                'ca' => 'Error en el pagament',
                'fr' => 'Erreur de paiement',
                'de' => 'Zahlungsfehler',
                'it' => 'Errore di pagamento',
                'pt' => 'Erro de pagamento',
                'nl' => 'Betalingsfout',
                'pl' => 'Błąd płatności',
                'ru' => 'Ошибка платежа',
                'no' => 'Betalingsfeil',
                'et' => 'Makse viga',
                'fi' => 'Maksuvirhe',
                'lv' => 'Maksājuma kļūda',
            ],
            'Refunded' => [
                'en' => 'Refunded',
                'es' => 'Reembolsado',
                'ca' => 'Reemborsat',
                'fr' => 'Remboursé',
                'de' => 'Erstattet',
                'it' => 'Rimborsato',
                'pt' => 'Reembolsado',
                'nl' => 'Terugbetaald',
                'pl' => 'Zwrócono',
                'ru' => 'Возвращено',
                'no' => 'Refundert',
                'et' => 'Tagastatud',
                'fi' => 'Palautettu',
                'lv' => 'Atmaksāts',
            ],
            'Partially refunded' => [
                'en' => 'Partially refunded',
                'es' => 'Parcialmente reembolsado',
                'ca' => 'Parcialment reemborsat',
                'fr' => 'Partiellement remboursé',
                'de' => 'Teilweise erstattet',
                'it' => 'Parzialmente rimborsato',
                'pt' => 'Parcialmente reembolsado',
                'nl' => 'Gedeeltelijk terugbetaald',
                'pl' => 'Częściowo zwrócono',
                'ru' => 'Частично возвращено',
                'no' => 'Delvis refundert',
                'et' => 'Osaliselt tagastatud',
                'fi' => 'Osittain palautettu',
                'lv' => 'Daļēji atmaksāts',
            ],
            'Payment authorized' => [
                'en' => 'Payment authorized',
                'es' => 'Pago autorizado',
                'ca' => 'Pagament autoritzat',
                'fr' => 'Paiement autorisé',
                'de' => 'Zahlung autorisiert',
                'it' => 'Pagamento autorizzato',
                'pt' => 'Pagamento autorizado',
                'nl' => 'Betaling geautoriseerd',
                'pl' => 'Płatność autoryzowana',
                'ru' => 'Платеж авторизован',
                'no' => 'Betaling autorisert',
                'et' => 'Makse autoriseeritud',
                'fi' => 'Maksu valtuutettu',
                'lv' => 'Maksājums autorizēts',
            ],
        ];
    }

    /**
     * Get Spanish ISO codes (Spain only)
     *
     * @return array
     */
    public static function getSpanishIsoCodes()
    {
        return ['es'];
    }

    /**
     * Get translation for a specific status and language
     *
     * @param string $statusName
     * @param string $isoCode
     *
     * @return string
     */
    public static function getOrderStatusTranslation($statusName, $isoCode)
    {
        $translations = self::getOrderStatusTranslations();
        $isoCode = Tools::strtolower($isoCode);

        if (!isset($translations[$statusName])) {
            return $statusName;
        }

        // Return specific translation or default to English
        return $translations[$statusName][$isoCode] ?? $translations[$statusName]['en'] ?? $statusName;
    }

    /**
     * Find existing order state by name in default language
     *
     * @param string $name Name to search for in default language
     *
     * @return int|false Order state ID if found, false otherwise
     */
    private function findOrderStateByName($name)
    {
        try {
            $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

            // Get all translations for this status name
            $allTranslations = self::getOrderStatusTranslations();

            // Build query to search for any of the translated names
            $names = [];
            if (isset($allTranslations[$name])) {
                $names = array_values($allTranslations[$name]);
            } else {
                $names = [$name];
            }

            $sql = 'SELECT DISTINCT os.`id_order_state` 
                    FROM `' . _DB_PREFIX_ . 'order_state` os
                    LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl 
                        ON (os.`id_order_state` = osl.`id_order_state`)
                    WHERE osl.`name` IN (' . implode(',', array_map(function ($n) { return '\'' . pSQL($n) . '\''; }, $names)) . ')
                        AND os.`module_name` = \'' . pSQL($this->name) . '\'
                    ORDER BY os.`id_order_state` ASC
                    LIMIT 1';

            return Db::getInstance()->getValue($sql);
        } catch (Exception $e) {
            self::logError('MONEI - findOrderStateByName - Error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Create order state
     *
     * @return bool
     */
    private function installOrderState()
    {
        // Check for existing "Awaiting payment" state
        $existingPendingStateId = $this->findOrderStateByName('Awaiting payment');

        if ($existingPendingStateId) {
            Configuration::updateValue('MONEI_STATUS_PENDING', (int) $existingPendingStateId);
        } elseif ((int) Configuration::get('MONEI_STATUS_PENDING') === 0) {
            $order_state = new OrderState();
            $order_state->name = [];

            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = self::getOrderStatusTranslation('Awaiting payment', $language['iso_code']);
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
                $destination = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.gif';
                @copy($source, $destination);

                if (Shop::isFeatureActive()) {
                    $shops = Shop::getShops();
                    foreach ($shops as $shop) {
                        Configuration::updateValue(
                            'MONEI_STATUS_PENDING',
                            (int) $order_state->id,
                            false,
                            null,
                            (int) $shop['id_shop']
                        );
                    }
                } else {
                    Configuration::updateValue('MONEI_STATUS_PENDING', (int) $order_state->id);
                }
            } else {
                return false;
            }
        }

        // Install authorized order state
        $existingAuthorizedStateId = $this->findOrderStateByName('Payment authorized');

        if ($existingAuthorizedStateId) {
            Configuration::updateValue('MONEI_STATUS_AUTHORIZED', (int) $existingAuthorizedStateId);
        } else {
            $authorizedStateId = (int) Configuration::get('MONEI_STATUS_AUTHORIZED');
            if ($authorizedStateId === 0 || !Validate::isLoadedObject(new OrderState($authorizedStateId))) {
                $order_state = new OrderState();
                $order_state->name = [];

                foreach (Language::getLanguages() as $language) {
                    $order_state->name[$language['id_lang']] = self::getOrderStatusTranslation('Payment authorized', $language['iso_code']);
                }

                $order_state->send_email = false;
                $order_state->color = '#4169E1';
                $order_state->hidden = false;
                $order_state->delivery = false;
                $order_state->logable = false;
                $order_state->invoice = false;
                $order_state->module_name = $this->name;

                // For PrestaShop 8+ compatibility - ensure color is properly formatted
                if (property_exists($order_state, 'template')) {
                    $order_state->template = '';
                }

                if ($order_state->add()) {
                    $source = _PS_MODULE_DIR_ . $this->name . '/views/img/mini_monei.gif';
                    $destination = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.gif';
                    @copy($source, $destination);

                    if (Shop::isFeatureActive()) {
                        $shops = Shop::getShops();
                        foreach ($shops as $shop) {
                            Configuration::updateValue(
                                'MONEI_STATUS_AUTHORIZED',
                                (int) $order_state->id,
                                false,
                                null,
                                (int) $shop['id_shop']
                            );
                        }
                    } else {
                        Configuration::updateValue('MONEI_STATUS_AUTHORIZED', (int) $order_state->id);
                    }
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Installs a hidden Tab for AJAX calls
     *
     * @param mixed $class_name
     * @param mixed $tab_name
     *
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
        // Remove MONEI OrderStates
        $moneiOrderStates = [
            'MONEI_STATUS_PENDING',
            'MONEI_STATUS_AUTHORIZED',
        ];

        foreach ($moneiOrderStates as $stateConfig) {
            $stateId = Configuration::get($stateConfig);
            if ($stateId && Validate::isLoadedObject(new OrderState($stateId))) {
                // Check if some order has this state, then it shouldn't be deleted
                if (!$this->isMoneiStateUsed($stateId)) {
                    $order_state = new OrderState($stateId);
                    $order_state->delete();
                }
            }
        }

        // General
        Configuration::deleteByName('MONEI_TOKENIZE');
        Configuration::deleteByName('MONEI_PRODUCTION_MODE');
        Configuration::deleteByName('MONEI_SHOW_LOGO');
        Configuration::deleteByName('MONEI_LOG_LEVEL');
        Configuration::deleteByName('MONEI_API_KEY');
        Configuration::deleteByName('MONEI_ACCOUNT_ID');
        Configuration::deleteByName('MONEI_TEST_API_KEY');
        Configuration::deleteByName('MONEI_TEST_ACCOUNT_ID');
        Configuration::deleteByName('MONEI_CART_TO_ORDER');
        Configuration::deleteByName('MONEI_EXPIRE_TIME');
        // Gateways
        Configuration::deleteByName('MONEI_ALLOW_CARD');
        Configuration::deleteByName('MONEI_CARD_WITH_REDIRECT');
        Configuration::deleteByName('MONEI_ALLOW_BIZUM');
        Configuration::deleteByName('MONEI_BIZUM_WITH_REDIRECT');
        Configuration::deleteByName('MONEI_ALLOW_APPLE');
        Configuration::deleteByName('MONEI_ALLOW_GOOGLE');
        Configuration::deleteByName('MONEI_ALLOW_PAYPAL');
        Configuration::deleteByName('MONEI_PAYPAL_WITH_REDIRECT');
        Configuration::deleteByName('MONEI_ALLOW_MULTIBANCO');
        Configuration::deleteByName('MONEI_ALLOW_MBWAY');
        // Payment Action
        Configuration::deleteByName('MONEI_PAYMENT_ACTION');
        // Status
        Configuration::deleteByName('MONEI_STATUS_SUCCEEDED');
        Configuration::deleteByName('MONEI_STATUS_FAILED');
        Configuration::deleteByName('MONEI_SWITCH_REFUNDS');
        Configuration::deleteByName('MONEI_STATUS_REFUNDED');
        Configuration::deleteByName('MONEI_STATUS_PARTIALLY_REFUNDED');
        Configuration::deleteByName('MONEI_STATUS_PENDING');
        Configuration::deleteByName('MONEI_STATUS_AUTHORIZED');
        // Card layout and express checkout
        Configuration::deleteByName('MONEI_CARD_LAYOUT');
        Configuration::deleteByName('MONEI_EXPRESS_ENABLED');
        Configuration::deleteByName('MONEI_EXPRESS_LOCATIONS');
        Configuration::deleteByName('MONEI_EXPRESS_METHODS');
        // ⚠️ Holds order state ids, which are per install. Uninstalling deletes
        // the MONEI states and a reinstall reissues their ids, so a value kept
        // across that cycle would point at unrelated states.
        Configuration::deleteByName('MONEI_CAPTURE_STATUS');

        include dirname(__FILE__) . '/sql/uninstall.php';

        // Remove Apple Pay domain verification file
        $this->removeApplePayDomainVerificationFile();

        $result = parent::uninstall();

        // Regenerate .htaccess to remove the route
        if ($result && class_exists('Tools') && method_exists('Tools', 'generateHtaccess')) {
            Tools::generateHtaccess();
        }

        return $result;
    }

    /**
     * Reset module - ensures Apple Pay file is copied
     */
    public function reset()
    {
        $result = parent::reset();

        if ($result) {
            $this->copyApplePayDomainVerificationFile();

            // Regenerate .htaccess to ensure the route is included
            if (class_exists('Tools') && method_exists('Tools', 'generateHtaccess')) {
                Tools::generateHtaccess();
            }
        }

        return $result;
    }

    /**
     * Enable module - ensures Apple Pay file is copied
     */
    public function enable($force_all = false)
    {
        $result = parent::enable($force_all);

        if ($result) {
            $this->copyApplePayDomainVerificationFile();

            // Regenerate .htaccess to ensure the route is included
            if (class_exists('Tools') && method_exists('Tools', 'generateHtaccess')) {
                Tools::generateHtaccess();
            }
        }

        return $result;
    }

    /**
     * Checks if the MONEI OrderState is used by some order
     *
     * @return bool
     */
    private function isMoneiStateUsed($stateId = null)
    {
        if ($stateId === null) {
            $stateId = Configuration::get('MONEI_STATUS_PENDING');
        }

        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders WHERE current_state = ' . (int) $stateId;

        return Db::getInstance()->getValue($sql) > 0 ? true : false;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        // Add CSS and JS for module configuration page (only if not already loaded)
        if (!self::$admin_assets_loaded) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin/admin.css');
            $this->context->controller->addJS($this->_path . 'views/js/admin/admin.js');
            self::$admin_assets_loaded = true;
        }

        $message = '';

        /*
         * If values have been submitted in the form, process.
         */
        if ((bool) Tools::isSubmit('submitMoneiModule')) {
            $message = $this->postProcess(1);
        } elseif (Tools::isSubmit('submitMoneiModuleGateways')) {
            $message = $this->postProcess(2);
        } elseif (Tools::isSubmit('submitMoneiModuleStatus')) {
            $message = $this->postProcess(3);
        } elseif (Tools::isSubmit('submitMoneiModuleComponentStyle')) {
            $message = $this->postProcess(4);
        } elseif (Tools::isSubmit('submitMoneiModuleExpress')) {
            $message = $this->postProcess(5);
        }

        // Check Apple Pay domain verification status
        $applePayNotification = $this->checkApplePayDomainVerification();
        if ($applePayNotification) {
            $message = $applePayNotification . $message;
        }

        // Assign values
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'module_version' => $this->version,
            'module_name' => $this->name,
            'display_name' => $this->displayName,
            'helper_form_1' => $this->renderForm(),
            'helper_form_2' => $this->renderFormGateways(),
            'helper_form_3' => $this->renderFormStatus(),
            'helper_form_4' => $this->renderFormComponentStyle(),
            'helper_form_5' => $this->renderFormExpress(),
        ]);

        return $message . $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * Save form data.
     */
    protected function postProcess($which)
    {
        $section = '';
        $validatedValues = null;

        switch ($which) {
            case 1:
                $section = $this->l('General');
                $form_values = $this->getConfigFormValues();

                // Validate API credentials for the enabled environment
                $validationResult = $this->validateApiCredentials($form_values);
                if ($validationResult !== true) {
                    return $validationResult;
                }

                break;
            case 2:
                $section = $this->l('Payment Methods');
                $form_values = $this->getConfigFormGatewaysValues();

                // Validate payment methods against MONEI API
                $validatedValues = $this->validatePaymentMethods($form_values);

                // Override form values with validated ones
                $form_values = $validatedValues;

                break;
            case 3:
                $section = $this->l('Status');
                $form_values = $this->getConfigFormStatusValues();

                break;
            case 4:
                $section = $this->l('Component Style');
                $form_values = $this->getConfigFormComponentStyleValues();

                // Validate JSON styles
                $validationResult = $this->validateComponentStyleJson($form_values);
                if ($validationResult !== true) {
                    return $validationResult;
                }

                break;
            case 5:
                $section = $this->l('Express Checkout');
                $form_values = $this->getConfigFormExpressValues();

                break;
        }

        // Store previous Apple Pay state
        $previousApplePayState = Configuration::get('MONEI_ALLOW_APPLE');

        foreach (array_keys($form_values) as $key) {
            // For validated payment methods, use the validated value
            if ($which === 2 && isset($validatedValues) && array_key_exists($key, $validatedValues)) {
                $value = $validatedValues[$key];
                Configuration::updateValue($key, $value);
            } else {
                // ⚠️ A multiple select is declared as `NAME[]` so the form posts an
                // array, but PHP names that field `NAME`. Reading `NAME[]` finds
                // nothing and would then write an empty value to a bogus key, which
                // looks exactly like the setting refusing to save.
                $configKey = substr($key, -2) === '[]' ? substr($key, 0, -2) : $key;
                $value = Tools::getValue($configKey);

                // Stored as a comma separated list, which is what everything
                // reading these settings expects.
                if (is_array($value)) {
                    $value = implode(',', array_filter($value, 'strlen'));
                }

                Configuration::updateValue($configKey, $value);
            }
        }

        // Check if Apple Pay was just enabled
        $currentApplePayState = Configuration::get('MONEI_ALLOW_APPLE');

        // Register domain for Apple Pay if it's enabled (either just enabled or already was enabled)
        if ($currentApplePayState) {
            // First check if API keys are configured
            $apiKey = (bool) Configuration::get('MONEI_PRODUCTION_MODE')
                ? Configuration::get('MONEI_API_KEY')
                : Configuration::get('MONEI_TEST_API_KEY');

            if (!$apiKey) {
                if (!$previousApplePayState && $currentApplePayState) {
                    $this->warning[] = $this->l('Apple Pay enabled but cannot verify domain: Please configure your MONEI API keys first.');
                }
            } else {
                try {
                    // Ensure the domain verification file is accessible
                    $this->copyApplePayDomainVerificationFile();

                    // Register domain with MONEI
                    $moneiClient = self::getService('service.monei')->getMoneiClient();
                    if ($moneiClient) {
                        $domain = str_replace(['www.', 'https://', 'http://'], '', Tools::getShopDomainSsl(false, true));

                        // Create request object as expected by MONEI API
                        $registerRequest = new Monei\Model\RegisterApplePayDomainRequest();
                        $registerRequest->setDomainName($domain);

                        $result = $moneiClient->applePayDomain->register($registerRequest);

                        // Add success message if Apple Pay was just enabled
                        if (!$previousApplePayState && $currentApplePayState) {
                            // Mark Apple Pay as verified to prevent duplicate message
                            Configuration::updateValue('MONEI_APPLE_PAY_VERIFIED', true);
                            Configuration::updateValue('MONEI_APPLE_PAY_VERIFIED_DATE', date('Y-m-d H:i:s'));

                            $this->confirmations[] = $this->l('Apple Pay domain verified successfully.');
                        }
                    }
                } catch (Exception $e) {
                    // Don't show the API error, let checkApplePayDomainVerification handle it
                    // Just mark that verification failed
                    Configuration::updateValue('MONEI_APPLE_PAY_VERIFIED', false);
                }
            }
        }

        $output = '';

        // Display any warnings
        if (!empty($this->warning)) {
            foreach ($this->warning as $warning) {
                $output .= $this->displayWarning($warning);
            }
        }

        // Display any additional confirmations
        if (!empty($this->confirmations)) {
            foreach ($this->confirmations as $confirmation) {
                $output .= $this->displayConfirmation($confirmation);
            }
        }

        // Display main confirmation
        $output .= $this->displayConfirmation($this->l('Settings saved successfully.'));

        return $output;
    }

    /**
     * Validate API credentials for the enabled environment
     *
     * @param array $form_values
     *
     * @return bool|string true if valid, error message string if invalid
     */
    protected function validateApiCredentials($form_values)
    {
        $isProductionMode = Tools::getValue('MONEI_PRODUCTION_MODE');

        if ($isProductionMode === '1') {
            // Validate production credentials
            $accountId = Tools::getValue('MONEI_ACCOUNT_ID');
            $apiKey = Tools::getValue('MONEI_API_KEY');

            if (empty($accountId) || empty($apiKey)) {
                return $this->displayError($this->l('Production environment cannot be enabled without valid Account ID and API Key.'));
            }

            // Test API connection with production credentials
            if (!$this->testApiConnection($accountId, $apiKey, true)) {
                return $this->displayError($this->l('Invalid production credentials. Please check your Account ID and API Key.'));
            }
        } else {
            // Validate test credentials
            $testAccountId = Tools::getValue('MONEI_TEST_ACCOUNT_ID');
            $testApiKey = Tools::getValue('MONEI_TEST_API_KEY');

            if (empty($testAccountId) || empty($testApiKey)) {
                return $this->displayError($this->l('Test environment requires valid Test Account ID and Test API Key.'));
            }

            // Test API connection with test credentials
            if (!$this->testApiConnection($testAccountId, $testApiKey, false)) {
                return $this->displayError($this->l('Invalid test credentials. Please check your Test Account ID and Test API Key.'));
            }
        }

        return true;
    }

    /**
     * Test API connection with given credentials
     *
     * @param string $accountId
     * @param string $apiKey
     * @param bool $isProduction
     *
     * @return bool
     */
    protected function testApiConnection($accountId, $apiKey, $isProduction)
    {
        try {
            if (empty($accountId) || empty($apiKey)) {
                return false;
            }

            // Create temporary MONEI client with provided credentials
            $moneiClient = new Monei\MoneiClient($apiKey);
            $moneiClient->setUserAgent(PsMonei\Service\Monei\MoneiService::getUserAgent());

            // Test the connection by trying to get payment methods for the account
            $paymentMethods = $moneiClient->paymentMethods->get($accountId);

            // If we get any response without exception, credentials are valid
            return $paymentMethods !== null;
        } catch (Exception $e) {
            self::logWarning('MONEI - API credentials test failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Validate payment methods against MONEI API
     *
     * @param array $form_values Form values to validate
     *
     * @return array Modified form values with disabled unavailable methods
     */
    protected function validatePaymentMethods($form_values)
    {
        try {
            // Get available payment methods from MONEI API
            $moneiService = self::getService('service.monei');
            if (!$moneiService) {
                $this->warning[] = $this->l('Unable to access MONEI service.');

                return $form_values;
            }

            $availablePaymentMethods = $moneiService->getPaymentMethodsAllowed();

            // If API call fails, allow saving but show warning
            if (empty($availablePaymentMethods)) {
                $this->warning[] = $this->l('Unable to validate payment methods with MONEI API. Please ensure your API credentials are correct and the methods are configured in your MONEI dashboard.');

                return $form_values;
            }

            // Map form fields to MONEI payment method codes
            $paymentMethodMap = [
                'MONEI_ALLOW_CARD' => 'card',
                'MONEI_ALLOW_BIZUM' => 'bizum',
                'MONEI_ALLOW_APPLE' => 'applePay',
                'MONEI_ALLOW_GOOGLE' => 'googlePay',
                'MONEI_ALLOW_PAYPAL' => 'paypal',
                'MONEI_ALLOW_MULTIBANCO' => 'multibanco',
                'MONEI_ALLOW_MBWAY' => 'mbway',
            ];

            $unavailableMethods = [];

            // First, preserve all form values including redirect settings
            foreach (array_keys($form_values) as $configKey) {
                $form_values[$configKey] = Tools::getValue($configKey);
            }

            // Check each enabled payment method
            foreach ($paymentMethodMap as $configKey => $methodCode) {
                $isEnabled = Tools::getValue($configKey);
                $isAvailable = in_array($methodCode, $availablePaymentMethods);

                // Update form_values with the actual submitted value
                $form_values[$configKey] = $isEnabled;

                // If method is enabled but not available, disable it and add to warning
                if ($isEnabled && !$isAvailable) {
                    $methodName = $this->getPaymentMethodName($methodCode);
                    $unavailableMethods[] = $methodName;
                    // Override with disabled value
                    $form_values[$configKey] = 0;
                }
            }

            // If any methods are unavailable, show error
            if (!empty($unavailableMethods)) {
                $this->warning[] = sprintf(
                    $this->l('The following payment methods are not available in your MONEI account and have been disabled: %s. Please enable them in your MONEI dashboard first.'),
                    implode(', ', $unavailableMethods)
                );
            }

            // Return the potentially modified form values
            return $form_values;
        } catch (Exception $e) {
            // Log error and allow saving
            self::logWarning('MONEI - validatePaymentMethods - Error: ' . $e->getMessage());
            $this->warning[] = $this->l('Unable to validate payment methods. Please check your API credentials.');

            return $form_values;
        }
    }

    /**
     * Get human-readable payment method name
     *
     * @param string $methodCode
     *
     * @return string
     */
    protected function getPaymentMethodName($methodCode)
    {
        $names = [
            'card' => $this->l('Credit Card'),
            'bizum' => $this->l('Bizum'),
            'applePay' => $this->l('Apple Pay'),
            'googlePay' => $this->l('Google Pay'),
            'paypal' => $this->l('PayPal'),
            'multibanco' => $this->l('Multibanco'),
            'mbway' => $this->l('MB Way'),
        ];

        return isset($names[$methodCode]) ? $names[$methodCode] : $methodCode;
    }

    /**
     * Default configuration values for HelperForm
     */
    protected function getConfigFormValues()
    {
        return [
            'MONEI_TOKENIZE' => Configuration::get('MONEI_TOKENIZE', false),
            'MONEI_PRODUCTION_MODE' => Configuration::get('MONEI_PRODUCTION_MODE', false),
            'MONEI_SHOW_LOGO' => Configuration::get('MONEI_SHOW_LOGO', true),
            'MONEI_LOG_LEVEL' => Configuration::get('MONEI_LOG_LEVEL', 3),
            'MONEI_ACCOUNT_ID' => Configuration::get('MONEI_ACCOUNT_ID', ''),
            'MONEI_API_KEY' => Configuration::get('MONEI_API_KEY', ''),
            'MONEI_TEST_ACCOUNT_ID' => Configuration::get('MONEI_TEST_ACCOUNT_ID', ''),
            'MONEI_TEST_API_KEY' => Configuration::get('MONEI_TEST_API_KEY', ''),
            'MONEI_PAYMENT_ACTION' => Configuration::get('MONEI_PAYMENT_ACTION', 'sale'),
        ];
    }

    /**
     * Default gateways values for HelperForm
     */
    protected function getConfigFormGatewaysValues()
    {
        return [
            'MONEI_ALLOW_CARD' => Configuration::get('MONEI_ALLOW_CARD', true),
            'MONEI_CARD_WITH_REDIRECT' => Configuration::get('MONEI_CARD_WITH_REDIRECT', false),
            'MONEI_ALLOW_BIZUM' => Configuration::get('MONEI_ALLOW_BIZUM', false),
            'MONEI_BIZUM_WITH_REDIRECT' => Configuration::get('MONEI_BIZUM_WITH_REDIRECT', false),
            'MONEI_ALLOW_APPLE' => Configuration::get('MONEI_ALLOW_APPLE', false),
            'MONEI_ALLOW_GOOGLE' => Configuration::get('MONEI_ALLOW_GOOGLE', false),
            'MONEI_ALLOW_PAYPAL' => Configuration::get('MONEI_ALLOW_PAYPAL', false),
            'MONEI_PAYPAL_WITH_REDIRECT' => Configuration::get('MONEI_PAYPAL_WITH_REDIRECT', false),
            'MONEI_ALLOW_MULTIBANCO' => Configuration::get('MONEI_ALLOW_MULTIBANCO', false),
            'MONEI_ALLOW_MBWAY' => Configuration::get('MONEI_ALLOW_MBWAY', false),
        ];
    }

    /**
     * Default statuses values for HelperForm
     */
    protected function getConfigFormStatusValues()
    {
        return [
            'MONEI_STATUS_PENDING' => Configuration::get('MONEI_STATUS_PENDING', Configuration::get('PS_OS_WS_PAYMENT')),
            'MONEI_STATUS_SUCCEEDED' => Configuration::get('MONEI_STATUS_SUCCEEDED', Configuration::get('PS_OS_PAYMENT')),
            'MONEI_STATUS_FAILED' => Configuration::get('MONEI_STATUS_FAILED', Configuration::get('PS_OS_ERROR')),
            'MONEI_STATUS_AUTHORIZED' => Configuration::get('MONEI_STATUS_AUTHORIZED', 0),
            'MONEI_CAPTURE_STATUS[]' => $this->explodeConfigList('MONEI_CAPTURE_STATUS'),
            'MONEI_SWITCH_REFUNDS' => Configuration::get('MONEI_SWITCH_REFUNDS', false),
            'MONEI_STATUS_REFUNDED' => Configuration::get('MONEI_STATUS_REFUNDED', Configuration::get('PS_OS_REFUND')),
            'MONEI_STATUS_PARTIALLY_REFUNDED' => Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED', Configuration::get('PS_OS_REFUND')),
        ];
    }

    /**
     * Default styles values for HelperForm
     */
    protected function getConfigFormComponentStyleValues()
    {
        return [
            'MONEI_CARD_LAYOUT' => Configuration::get('MONEI_CARD_LAYOUT', 'split'),
            'MONEI_CARD_INPUT_STYLE' => Configuration::get('MONEI_CARD_INPUT_STYLE', '{"base": {"height": "42px"}, "input": {"background": "none"}}'),
            'MONEI_BIZUM_STYLE' => Configuration::get('MONEI_BIZUM_STYLE', '{"height": "42"}'),
            'MONEI_PAYMENT_REQUEST_STYLE' => Configuration::get('MONEI_PAYMENT_REQUEST_STYLE', '{"height": "42"}'),
            'MONEI_PAYPAL_STYLE' => Configuration::get('MONEI_PAYPAL_STYLE', '{"height": "42"}'),
        ];
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

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * Creates the structure of the general form
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Real environment'),
                        'name' => 'MONEI_PRODUCTION_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Set to OFF/DISABLED to use the test environment.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Your MONEI Account ID. Available at your MONEI dashboard.'),
                        'name' => 'MONEI_ACCOUNT_ID',
                        'label' => $this->l('Account ID'),
                        'placeholder' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                        'class' => 'monei-production-field',
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Your MONEI API Key. Available at your MONEI dashboard.'),
                        'name' => 'MONEI_API_KEY',
                        'label' => $this->l('API Key'),
                        'placeholder' => 'pk_live_7h3m4n1f3st0k3yf0r3x4mpl3purp0s3',
                        'class' => 'monei-production-field',
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Your MONEI Test Account ID. Available at your MONEI dashboard.'),
                        'name' => 'MONEI_TEST_ACCOUNT_ID',
                        'label' => $this->l('Test Account ID'),
                        'placeholder' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
                        'class' => 'monei-test-field',
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Your MONEI Test API Key. Available at your MONEI dashboard.'),
                        'name' => 'MONEI_TEST_API_KEY',
                        'label' => $this->l('Test API Key'),
                        'placeholder' => 'pk_test_d3m0t3stk3yf0rd3v3l0pm3ntus4g3',
                        'class' => 'monei-test-field',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow Credit Card Tokenization'),
                        'name' => 'MONEI_TOKENIZE',
                        'is_bool' => true,
                        'desc' => $this->l('Allow the customers to save their credit card information.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Log Level'),
                        'name' => 'MONEI_LOG_LEVEL',
                        'desc' => $this->l('Set minimum log level. Only messages at or above this level will be logged. WARNING: INFO level may impact performance.'),
                        'options' => [
                            'query' => [
                                [
                                    'id' => '1',
                                    'name' => $this->l('INFO - All messages (Debug mode)'),
                                ],
                                [
                                    'id' => '2',
                                    'name' => $this->l('WARNING - Warnings and errors only'),
                                ],
                                [
                                    'id' => '3',
                                    'name' => $this->l('ERROR - Errors only (Recommended)'),
                                ],
                                [
                                    'id' => '4',
                                    'name' => $this->l('NONE - Disable logging'),
                                ],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Payment Action'),
                        'name' => 'MONEI_PAYMENT_ACTION',
                        'desc' => $this->l('Choose payment flow: Immediate charge (sale) or Pre-authorization (auth). Pre-authorization is supported for: Card, Apple Pay, Google Pay, PayPal. MB WAY and Multibanco cannot be pre-authorized and are removed from your checkout entirely while Pre-authorization is selected.') . $this->getAuthHiddenMethodsWarning(),
                        'options' => [
                            'query' => [
                                [
                                    'id' => 'sale',
                                    'name' => $this->l('Sale (Immediate charge)'),
                                ],
                                [
                                    'id' => 'auth',
                                    'name' => $this->l('Authorization (Pre-authorization)'),
                                ],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Show MONEI logo'),
                        'name' => 'MONEI_SHOW_LOGO',
                        'is_bool' => true,
                        'desc' => $this->l('Shows the MONEI logo on the checkout step.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
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

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormGatewaysValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigFormGateways()]);
    }

    /**
     * Creates the structure of the gateways form
     */
    protected function getConfigFormGateways()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Payment methods'),
                    'icon' => 'icon-money',
                ],
                'input' => [
                    [
                        // Renders nothing unless the transaction type is currently
                        // hiding an enabled payment method. A merchant enabling
                        // MB WAY here would otherwise never learn that
                        // pre-authorization removes it again.
                        'type' => 'html',
                        'name' => 'MONEI_AUTH_HIDDEN_WARNING',
                        'html_content' => $this->getAuthHiddenMethodsWarning(),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow Credit Card'),
                        'name' => 'MONEI_ALLOW_CARD',
                        'is_bool' => true,
                        // 'desc' => $this->l('Allow payments with Credit Card.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activate Credit Card with Redirect'),
                        'name' => 'MONEI_CARD_WITH_REDIRECT',
                        'is_bool' => true,
                        'hint' => $this->l('It is recommended to enable redirection in cases where card payments do not function correctly.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow Bizum'),
                        'name' => 'MONEI_ALLOW_BIZUM',
                        'is_bool' => true,
                        // 'desc' => $this->l('Allow payments with Bizum.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activate Bizum with Redirect'),
                        'name' => 'MONEI_BIZUM_WITH_REDIRECT',
                        'is_bool' => true,
                        'hint' => $this->l('It is recommended to enable redirection in cases where Bizum payments do not function correctly.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow Apple Pay'),
                        'name' => 'MONEI_ALLOW_APPLE',
                        'is_bool' => true,
                        'desc' => $this->l('Allow payments with Apple Pay. Only displayed in Safari browser.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow Google Pay'),
                        'name' => 'MONEI_ALLOW_GOOGLE',
                        'is_bool' => true,
                        // 'desc' => $this->l('Allow payments with Google Pay.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow PayPal'),
                        'name' => 'MONEI_ALLOW_PAYPAL',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activate PayPal with Redirect'),
                        'name' => 'MONEI_PAYPAL_WITH_REDIRECT',
                        'is_bool' => true,
                        'hint' => $this->l('It is recommended to enable redirection in cases where PayPal payments do not function correctly.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow Multibanco'),
                        'name' => 'MONEI_ALLOW_MULTIBANCO',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow MBWay'),
                        'name' => 'MONEI_ALLOW_MBWAY',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
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

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormStatusValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigFormStatus()]);
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigFormStatus()
    {
        $order_statuses = OrderState::getOrderStates($this->context->language->id);

        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Order States'),
                    'icon' => 'icon-shopping-cart',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Status for pending payment'),
                        'name' => 'MONEI_STATUS_PENDING',
                        'required' => true,
                        'desc' => $this->l('You must select here the default status for a pending payment.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Status for succeeded payment'),
                        'name' => 'MONEI_STATUS_SUCCEEDED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for a completed payment.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Status for failed payment'),
                        'name' => 'MONEI_STATUS_FAILED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for a failed payment.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Status for authorized payment'),
                        'name' => 'MONEI_STATUS_AUTHORIZED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for an authorized (pre-authorized) payment that is not yet captured.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Capture automatically on'),
                        'name' => 'MONEI_CAPTURE_STATUS[]',
                        'multiple' => true,
                        'desc' => $this->l('Statuses that capture a pre-authorized payment automatically. Leave empty to capture only from the order page. Applies to any change of status, including one made by another module, a scheduled task or the API.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Change Status for Refunds'),
                        'name' => 'MONEI_SWITCH_REFUNDS',
                        'is_bool' => true,
                        'desc' => $this->l('Changes the order state to the ones below once a refund is done.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Status for refunded payment'),
                        'name' => 'MONEI_STATUS_REFUNDED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for a fully refunded payment.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Status for partially refunded'),
                        'name' => 'MONEI_STATUS_PARTIALLY_REFUNDED',
                        'required' => true,
                        'desc' => $this->l('You must select here the status for a partially refunded payment.'),
                        'options' => [
                            'query' => $order_statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
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

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormComponentStyleValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigFormComponentStyle()]);
    }

    protected function getConfigFormComponentStyle()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Component Style'),
                    'icon' => 'icon-paint-brush',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Card field layout'),
                        'name' => 'MONEI_CARD_LAYOUT',
                        'desc' => $this->l('Split shows separate fields for card number, expiry date and CVC. Single shows one combined field. Split is the default from 1.8.0 onward; choose Single to keep the previous appearance.'),
                        'options' => [
                            'query' => [
                                ['id' => 'split', 'name' => $this->l('Split fields (default)')],
                                ['id' => 'single', 'name' => $this->l('Single line')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Card input style'),
                        'name' => 'MONEI_CARD_INPUT_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the Card Input component. Documentation: ')
                            . '<a href="https://docs.monei.com/docs/monei-js/reference/#cardinput-style-object" target="_blank">MONEI Card Input Style</a>',
                        'cols' => 60,
                        'rows' => 3,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Bizum style'),
                        'name' => 'MONEI_BIZUM_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the Bizum component. Documentation: ')
                            . '<a href="https://docs.monei.com/docs/monei-js/reference/#bizum-options" target="_blank">MONEI Bizum Style</a>',
                        'cols' => 60,
                        'rows' => 3,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Payment Request style'),
                        'name' => 'MONEI_PAYMENT_REQUEST_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the Payment Request component. Documentation: ')
                            . '<a href="https://docs.monei.com/docs/monei-js/reference/#paymentrequest-options" target="_blank">MONEI Payment Request Style</a>',
                        'cols' => 60,
                        'rows' => 3,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('PayPal style'),
                        'name' => 'MONEI_PAYPAL_STYLE',
                        'desc' => $this->l('Configure in JSON format the style of the PayPal component. Documentation: ')
                            . '<a href="https://docs.monei.com/docs/monei-js/reference/#paypal-options" target="_blank">MONEI PayPal Style</a>',
                        'cols' => 60,
                        'rows' => 3,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    public function isMoneiAvailable($cart)
    {
        if (!$this->active) {
            return false;
        }
        if (!$this->checkCurrency($cart)) {
            return false;
        }

        try {
            self::getService('service.monei')->getMoneiClient();
        } catch (Exception $e) {
            self::logError('MONEI - Exception - monei.php - isMoneiAvailable: ' . $e->getMessage() . ' - ' . $e->getFile());

            return false;
        }

        return true;
    }

    /**
     * Get MONEI client instance
     *
     * @return Monei\MoneiClient
     *
     * @throws PsMonei\Exception\MoneiException
     */
    public function getMoneiClient()
    {
        return self::getService('service.monei')->getMoneiClient();
    }

    /**
     * Get all available payment methods
     *
     * @return array
     */
    private function getPaymentMethods()
    {
        if ($this->paymentMethods) {
            return;
        }

        $additionalInformation = '';
        if (Configuration::get('MONEI_SHOW_LOGO')) {
            $this->context->smarty->assign([
                'module_dir' => $this->_path,
            ]);
            $additionalInformation = $this->fetch('module:monei/views/templates/front/additional_info.tpl');
        }

        // DEMO MODE: Return default payment options if API fails
        $paymentOptions = [];

        try {
            $paymentOptionService = self::getService('service.payment.option');
            $paymentOptions = $paymentOptionService->getPaymentOptions();
        } catch (Exception $e) {
        }

        // If no payment options (API error or test mode), provide default card payment
        if (empty($paymentOptions)) {
            // Create a demo card payment option
            if (Configuration::get('MONEI_ALLOW_CARD')) {
                $paymentOptions[] = [
                    'name' => 'card',
                    'title' => $this->l('Credit/Debit Card'),
                    'enabled' => true,
                ];
            }
        }

        $transactionId = '';

        try {
            if (isset($paymentOptionService)) {
                $transactionId = $paymentOptionService->getTransactionId();
            }
        } catch (Exception $e) {
            // Use a demo transaction ID if service fails
            $transactionId = 'demo_' . time();
        }

        // Initialize payment methods array
        $paymentMethods = [];

        $paymentNames = [
            'bizum' => $this->l('Bizum'),
            'card' => $this->l('Credit Card'),
            'applePay' => $this->l('Apple Pay'),
            'googlePay' => $this->l('Google Pay'),
            'paypal' => $this->l('Paypal'),
            'multibanco' => $this->l('Multibanco'),
            'mbway' => $this->l('MB Way'),
        ];

        foreach ($paymentOptions as $paymentOption) {
            $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option->setModuleName($this->name . '-' . $paymentOption['name']);

            $testModeText = '';
            if (!(bool) Configuration::get('MONEI_PRODUCTION_MODE')) {
                $testModeText = ' (' . $this->l('Test Mode') . ')';
            }

            if (isset($paymentOption['title'])) {
                $option->setCallToActionText(
                    $paymentOption['title'] . $testModeText
                );
            } else {
                $baseTitle = $paymentNames[$paymentOption['name']];
                // Add custom title suffix if available (e.g., supported card brands)
                if (isset($paymentOption['customTitle'])) {
                    $baseTitle .= $paymentOption['customTitle'];
                }
                $option->setCallToActionText(
                    $baseTitle . $testModeText
                );
            }

            if (isset($paymentOption['additionalInformation'])) {
                $option->setAdditionalInformation($paymentOption['additionalInformation']);
            } else {
                if (!empty($additionalInformation)) {
                    $option->setAdditionalInformation($additionalInformation);
                }
            }

            if (isset($paymentOption['logo'])) {
                $option->setLogo($paymentOption['logo']);
            }

            if (isset($paymentOption['form'])) {
                $option->setForm($paymentOption['form']);
            }

            if (isset($paymentOption['action'])) {
                $option->setAction($paymentOption['action']);
            }

            if (isset($paymentOption['action'])) {
                $option->setAction($paymentOption['action']);
            } else {
                // Decode HTML entities as form actions should never be HTML-escaped
                $option->setAction(
                    html_entity_decode($this->context->link->getModuleLink($this->name, 'redirect', [
                        'method' => $paymentOption['name'],
                        'transaction_id' => $transactionId,
                    ]))
                );
            }

            if (isset($paymentOption['binary'])) {
                $option->setBinary($paymentOption['binary']);
            }

            $paymentMethods[] = $option;
        }

        $this->paymentMethods = $paymentMethods;

        return;
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
        // Check if cart parameter exists (it might not exist when called from admin payment preferences)
        if (!isset($params['cart']) || !$this->isMoneiAvailable($params['cart'])) {
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
        // Check if cart parameter exists before accessing it
        if (!isset($params['cart']) || !$this->isMoneiAvailable($params['cart'])) {
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

        $moneiService = self::getService('service.monei');
        $cartSummaryDetails = $this->context->cart->getSummaryDetails(null, true);

        if ($paymentMethodsToDisplay) {
            $this->context->smarty->assign([
                'paymentMethodsToDisplay' => $paymentMethodsToDisplay,
                'moneiAccountId' => (bool) Configuration::get('MONEI_PRODUCTION_MODE') ? Configuration::get('MONEI_ACCOUNT_ID') : Configuration::get('MONEI_TEST_ACCOUNT_ID'),
                'moneiAmount' => $moneiService->getCartAmount($cartSummaryDetails, $this->context->cart->id_currency),
                'moneiAmountFormatted' => Tools::displayPrice(
                    $moneiService->getCartAmount($cartSummaryDetails, $this->context->cart->id_currency, true),
                    $this->context->currency
                ),
                // URLs should never be HTML-escaped when used in JavaScript
                'moneiCreatePaymentUrlController' => html_entity_decode($this->context->link->getModuleLink('monei', 'createPayment')),
                'moneiToken' => Tools::getToken(false),
                'moneiCurrency' => $this->context->currency->iso_code,
                'moneiPaymentAction' => Configuration::get('MONEI_PAYMENT_ACTION', 'sale'),
            ]);

            return $this->fetch('module:monei/views/templates/hook/displayPaymentByBinaries.tpl');
        }
    }

    public function hookDisplayPaymentReturn($params)
    {
        $orderId = (int) $params['order']->id;
        $monei2PaymentEntity = Monei2Payment::findOneBy([
            'id_order' => $orderId,
            'status' => PaymentStatus::PENDING,
        ]);
        if (!$monei2PaymentEntity) {
            return;
        }

        $moneiService = self::getService('service.monei');
        $moneiPayment = $moneiService->getMoneiPayment($monei2PaymentEntity->getId());
        if ($moneiPayment
            && $moneiPayment->getPaymentMethod()->getMethod() === PaymentPaymentMethod::METHOD_MULTIBANCO
            && $moneiPayment->getStatus() === PaymentStatus::PENDING
        ) {
            return $this->fetch('module:monei/views/templates/hook/displayPaymentReturn.tpl');
        }
    }

    /**
     * Hook to display the refunds, when available
     */
    public function hookDisplayAdminOrder($params)
    {
        // Load required assets for jsonViewer (needed for PrestaShop 1.7.13 compatibility)
        $this->context->controller->addCSS($this->_path . 'views/css/jquery.json-viewer.css');
        $this->context->controller->addJS($this->_path . 'views/js/jquery.json-viewer.js');

        $orderId = (int) $params['id_order'];

        // Get ALL payments for this order (including failed and successful retries)
        $monei2PaymentEntities = Monei2Payment::findBy(['id_order' => $orderId], 'date_add DESC');
        if (empty($monei2PaymentEntities)) {
            return;
        }

        // Use the most recent payment for calculations (first in array when sorted DESC)
        $monei2PaymentEntity = $monei2PaymentEntities[0];

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

        // Get payment method formatter service
        $paymentMethodFormatter = self::getService('helper.payment_method_formatter');

        // Process history for ALL payments (including failed ones)
        foreach ($monei2PaymentEntities as $paymentEntity) {
            $paymentHistory = $paymentEntity->getHistoryList();
            if (!empty($paymentHistory)) {
                foreach ($paymentHistory as $history) {
                    $paymentHistoryLog = $history->toArrayLegacy();
                    $paymentHistoryLog['responseDecoded'] = $history->getResponseDecoded();
                    $paymentHistoryLog['responseB64'] = base64_encode($history->getResponse());
                    $paymentHistoryLog['payment_id'] = $paymentEntity->getId(); // Add payment ID for reference

                    // Extract payment method details from response
                    $response = $history->getResponseDecoded();
                    if ($response && isset($response['paymentMethod'])) {
                        // Flatten the payment method data structure like Magento does
                        $paymentInfo = $this->flattenPaymentMethodData($response['paymentMethod']);

                        // Add additional fields from the response
                        $paymentInfo['authorizationCode'] = $response['authorizationCode'] ?? null;

                        $paymentHistoryLog['paymentDetails'] = $paymentMethodFormatter->formatAdminPaymentDetails($paymentInfo);
                    }

                    $paymentHistoryLogs[] = $paymentHistoryLog;

                    $paymentRefund = $paymentEntity->getRefundByHistoryId($history->getId());
                    if ($paymentRefund) {
                        $paymentRefundLog = $paymentRefund->toArrayLegacy();
                        $paymentRefundLog['paymentHistory'] = $paymentHistoryLog;
                        $refundAmount = isset($paymentRefundLog['amount_in_decimal']) ? $paymentRefundLog['amount_in_decimal'] : 0;
                        $paymentRefundLog['amountFormatted'] = $this->formatPrice($refundAmount, $currency->iso_code);

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
        }

        // Check if payment is capturable (AUTHORIZED status and not captured)
        $isCapturable = $monei2PaymentEntity->getStatus() === 'AUTHORIZED' && !$monei2PaymentEntity->getIsCaptured();
        $authorizedAmount = $monei2PaymentEntity->getAmount() ?: 0;
        $authorizedAmountFormatted = $this->formatPrice($authorizedAmount / 100, $currency->iso_code);

        // Calculate captured and remaining amounts for partial capture
        $capturedAmount = 0;
        $remainingAmount = $authorizedAmount / 100; // Convert to currency units

        // Check if there have been any partial captures
        if ($monei2PaymentEntity->getIsCaptured() && $monei2PaymentEntity->getStatus() === 'SUCCEEDED') {
            // If payment is marked as captured and succeeded, it's fully captured
            $capturedAmount = $authorizedAmount;
            $remainingAmount = 0;
        }

        $capturedAmountFormatted = $this->formatPrice($capturedAmount / 100, $currency->iso_code);
        $remainingAmountFormatted = $this->formatPrice($remainingAmount, $currency->iso_code);

        // Generate capture controller link - decode HTML entities as URLs should not be escaped
        $captureLinkController = html_entity_decode($this->context->link->getAdminLink('AdminMoneiCapturePayment'));

        // Get modal attributes for Bootstrap compatibility
        $modalAttributes = $this->getModalAttributes();
        $bootstrapVersion = $this->getBootstrapVersion();

        $this->context->smarty->assign([
            'moneiPayment' => $monei2PaymentEntity->toArrayLegacy(),
            'isRefundable' => $monei2PaymentEntity->isRefundable(),
            'isCapturable' => $isCapturable,
            'modalToggle' => $modalAttributes['toggle'],
            'modalDismiss' => $modalAttributes['dismiss'],
            'modalTarget' => $modalAttributes['target'],
            'bootstrapVersion' => $bootstrapVersion,
            'authorizedAmount' => $authorizedAmount,
            'authorizedAmountFormatted' => $authorizedAmountFormatted,
            'capturedAmount' => $capturedAmount,
            'capturedAmountFormatted' => $capturedAmountFormatted,
            'remainingAmount' => $remainingAmount,
            'remainingAmountFormatted' => $remainingAmountFormatted,
            'captureLinkController' => $captureLinkController,
            'currencySign' => $currency->getSign('right'),
            'currencyCode' => $currency->iso_code,
            'locale' => $this->context->language->locale,
            'remainingAmountToRefund' => $monei2PaymentEntity->getRemainingAmountToRefund(),
            'totalRefundedAmount' => $monei2PaymentEntity->getRefundedAmount(),
            'totalRefundedAmountFormatted' => $this->formatPrice($monei2PaymentEntity->getRefundedAmount(true) ?: 0, $currency->iso_code),
            'paymentHistoryLogs' => $paymentHistoryLogs,
            'paymentRefundLogs' => $paymentRefundLogs,
            'orderId' => $orderId,
            'orderTotalPaid' => $order->getTotalPaid() * 100,
            'currencySymbol' => $currency->getSign('right'),
            'currencyIso' => $currency->iso_code,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/displayAdminOrder.tpl');
    }

    /**
     * Hook to display content on payment return
     */
    public function hookActionFrontControllerSetMedia()
    {
        if (!property_exists($this->context->controller, 'page_name')) {
            return;
        }

        $pageName = $this->getFrontPageName();

        // Express checkout lives on the product and cart pages too, so the SDK and
        // its client have to load there as well — but only when a merchant has
        // actually switched express on. Nothing is added to those pages otherwise.
        if (in_array($pageName, ['product', 'cart'], true) && $this->isExpressEnabledFor($pageName)) {
            $this->registerExpressAssets();

            return;
        }

        // Checkout
        if ($pageName == 'checkout') {
            $moneiSdkUrl = self::MONEI_JS_URL;
            $this->context->controller->registerJavascript(
                sha1($moneiSdkUrl),
                $moneiSdkUrl,
                [
                    'server' => 'remote',
                    'priority' => 50,
                    'attribute' => 'defer',
                ]
            );

            // Must load before front.js, which calls the init functions this file
            // declares. Both are deferred, so they execute in registration order
            // and both finish before DOMContentLoaded fires.
            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-payment',
                'modules/' . $this->name . '/views/js/front/payment.js',
                [
                    'priority' => 90,
                    'attribute' => 'defer',
                    'position' => 'bottom',
                ]
            );

            // Express renders above the payment options at checkout as well, so
            // its client has to load here too, not only on product and cart.
            if ($this->isExpressEnabledFor('checkout')) {
                $this->registerExpressAssets();
            }

            $this->context->controller->registerJavascript(
                'module-' . $this->name . '-front',
                'modules/' . $this->name . '/views/js/front/front.js',
                [
                    'priority' => 100,
                    'attribute' => 'defer',
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

            // Check if there's a MONEI error message to display
            if (!empty($this->context->cookie->monei_checkout_error)) {
                $moneiCheckoutError = $this->context->cookie->monei_checkout_error;

                // Use PrestaShop's native error display as primary method
                $this->context->controller->errors[] = $moneiCheckoutError;

                // Clear the error from cookie after reading
                unset($this->context->cookie->monei_checkout_error);
                $this->context->cookie->write();
            }

            // ⚠️ Published here, not from hookDisplayPaymentByBinaries, even though
            // that is the hook these values describe. PrestaShop collects the
            // js_def block before content hooks render, so an addJsDef call made
            // while rendering the payment step never reaches the page: the values
            // silently do not exist, payment.js initialises nothing, and the
            // checkout renders its payment options with no working component.
            $moneiJsDef = [
                'moneiAccountId' => (bool) Configuration::get('MONEI_PRODUCTION_MODE') ? Configuration::get('MONEI_ACCOUNT_ID') : Configuration::get('MONEI_TEST_ACCOUNT_ID'),
                'moneiCreatePaymentUrlController' => $this->context->link->getModuleLink('monei', 'createPayment'),
                'moneiToken' => Tools::getToken(false),
                'moneiCurrency' => $this->context->currency->iso_code,
                'moneiPaymentAction' => Configuration::get('MONEI_PAYMENT_ACTION', 'sale'),
                'moneiCardLayout' => Configuration::get('MONEI_CARD_LAYOUT') === 'single' ? 'single' : 'split',
            ];

            if (Validate::isLoadedObject($this->context->cart)) {
                $moneiJsDef['moneiAmount'] = self::getService('service.monei')->getCartAmount(
                    $this->context->cart->getSummaryDetails(null, true),
                    $this->context->cart->id_currency
                );
            }

            Media::addJsDef($moneiJsDef);

            Media::addJsDef([
                'moneiProcessing' => $this->l('Processing payment...'),
                'moneiProcessingPayment' => $this->l('Processing payment...'),
                'moneiCardHolderNameNotValid' => $this->l('Card holder name is not valid'),
                'moneiMsgRetry' => $this->l('Retry'),
                'moneiCardInputStyle' => json_decode(Configuration::get('MONEI_CARD_INPUT_STYLE')),
                'moneiBizumStyle' => json_decode(Configuration::get('MONEI_BIZUM_STYLE')),
                'moneiPaymentRequestStyle' => json_decode(Configuration::get('MONEI_PAYMENT_REQUEST_STYLE')),
                'moneiPayPalStyle' => json_decode(Configuration::get('MONEI_PAYPAL_STYLE')) ?: json_decode('{"height":"42"}'),
                'moneiErrorTitle' => $this->l('Payment Error'),
                'moneiPaymentCreationFailed' => $this->l('Payment creation failed'),
                'moneiPaymentProcessed' => $this->l('Payment processed'),
                'moneiErrorOccurred' => $this->l('An error occurred'),
                'moneiErrorOccurredWithPayPal' => $this->l('An error occurred with PayPal'),
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
                    'removingCard' => $this->l('Removing...'),
                    'successfullyRemovedCard' => $this->l('Card successfully removed'),
                    'errorRemovingCard' => $this->l('An error occurred while deleting the card.'),
                    'noSavedCards' => $this->l('You don\'t have any saved credit cards yet.'),
                    'unexpectedError' => $this->l('An unexpected error occurred.'),
                    'indexUrl' => $this->context->link->getPageLink('index'),
                ],
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
        $customerCards = Monei2CustomerCard::findBy(['id_customer' => $this->context->customer->id]);

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
                $customerCards = Monei2CustomerCard::findBy(['id_customer' => (int) $customer['id']]);
                if ($customerCards) {
                    foreach ($customerCards as $customerCard) {
                        $customerCard->delete();
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
                $customerCards = Monei2CustomerCard::findBy(['id_customer' => (int) $customer['id']]);
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

    public function hookModuleRoutes()
    {
        return [
            'module-monei-applepay' => [
                'controller' => 'applepay',
                'rule' => '.well-known/apple-developer-merchantid-domain-association',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'monei',
                    'controller' => 'applepay',
                ],
            ],
        ];
    }

    public function hookDisplayBackOfficeHeader()
    {
        // Add admin assets only if not already loaded
        if (!self::$admin_assets_loaded) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin/admin.css');
            $this->context->controller->addJS($this->_path . 'views/js/admin/admin.js');
            self::$admin_assets_loaded = true;
        }

        // Add additional JS/vars only for Orders controller
        if ($this->context->controller->controller_name === 'AdminOrders') {
            // Get refund reasons from MONEI SDK with translations
            $refundReasons = [];
            if (class_exists('\Monei\Model\PaymentRefundReason')) {
                $allowableValues = Monei\Model\PaymentRefundReason::getAllowableEnumValues();
                foreach ($allowableValues as $value) {
                    // Translate each refund reason
                    switch ($value) {
                        case 'requested_by_customer':
                            $label = $this->l('Requested by customer');

                            break;
                        case 'duplicated':
                            $label = $this->l('Duplicated');

                            break;
                        case 'fraudulent':
                            $label = $this->l('Fraudulent');

                            break;
                        default:
                            // Fallback: convert snake_case to human-readable format
                            $label = ucwords(str_replace('_', ' ', $value));

                            break;
                    }
                    $refundReasons[] = [
                        'value' => $value,
                        'label' => $label,
                    ];
                }
            }

            Media::addJsDef([
                'MoneiVars' => [
                    // Decode HTML entities as URLs should not be escaped in JavaScript
                    'adminMoneiControllerUrl' => html_entity_decode($this->context->link->getAdminLink('AdminMonei')),
                    'refundReasons' => $refundReasons,
                    'refundReasonTitle' => $this->l('MONEI refund reason'),
                ],
            ]);

            $this->context->controller->addCSS($this->_path . 'views/css/jquery.json-viewer.css');
            $this->context->controller->addJS($this->_path . 'views/js/jquery.json-viewer.js');
        }
    }

    /**
     * Process refund when a credit slip is created
     *
     * @param array $params
     *
     * @return void
     */
    public function hookActionOrderSlipAdd($params)
    {
        try {
            $order = $params['order'];
            $productList = $params['productList'];
            $qtyList = $params['qtyList'];

            // Get MONEI payment from repository
            $moneiPayment = Monei2Payment::findOneBy(['id_order' => $order->id]);
            if (!$moneiPayment) {
                return; // Not a MONEI order, skip
            }
            $paymentId = $moneiPayment->getId();

            // Get the order slip that was just created
            $orderSlips = OrderSlip::getOrdersSlip($order->id_customer, $order->id);
            $currentSlip = end($orderSlips); // Get the most recent one

            if (!$currentSlip) {
                return;
            }

            // Calculate refund amount from the order slip
            // Start with product refund amount
            $totalRefundAmount = $currentSlip['amount'];

            // Check for shipping refund in various POST parameters PrestaShop might send
            $shippingRefundAmount = 0;

            // Check if shipping refund is included in the order slip
            if (isset($currentSlip['shipping_cost_amount']) && $currentSlip['shipping_cost_amount'] > 0) {
                $shippingRefundAmount = $currentSlip['shipping_cost_amount'];
            }

            // Also check POST parameters for partial shipping refund (PrestaShop 1.7.2 - 1.7.13 compatibility)
            // These take precedence over order slip values if present
            if (Tools::getValue('partialRefundShippingCost') !== false) {
                $shippingRefundAmount = (float) Tools::getValue('partialRefundShippingCost');
            } elseif (Tools::getValue('cancel_product') && is_array(Tools::getValue('cancel_product'))) {
                $cancelProduct = Tools::getValue('cancel_product');

                if (isset($cancelProduct['shipping_amount'])) {
                    $shippingRefundAmount = (float) $cancelProduct['shipping_amount'];
                } elseif (isset($cancelProduct['shipping']) && $cancelProduct['shipping'] == 1) {
                    // Full shipping refund requested - get original shipping cost from order
                    $shippingRefundAmount = $order->total_shipping_tax_incl;
                }
            }

            // Add shipping refund to total if present
            if ($shippingRefundAmount > 0) {
                $totalRefundAmount += $shippingRefundAmount;
            }

            $refundAmount = (int) round($totalRefundAmount * 100); // Convert to cents

            // Get currency ISO code for logging
            $currency = new Currency((int) $order->id_currency);
            $currencyCode = $currency->iso_code;

            // Get refund reason from POST data or default to requested_by_customer
            $refundReason = Tools::getValue('monei_refund_reason', 'requested_by_customer');

            // Process the refund through MONEI
            $moneiService = self::getService('service.monei');
            $employeeId = $this->context->employee ? $this->context->employee->id : 0;

            $moneiService->createRefund((int) $order->id, $refundAmount, $employeeId, $refundReason);

            // Update order status if needed
            $orderService = self::getService('service.order');
            $orderService->updateOrderStateAfterRefund((int) $order->id);
        } catch (Exception $e) {
            // Log the error
            self::logError('MONEI - Failed to process refund on credit slip creation: ' . $e->getMessage());

            // Re-throw the exception to prevent credit slip creation
            // Include the actual error message for debugging
            throw new PrestaShopException(
                $this->l('Refund failed in MONEI payment gateway. Please try again or contact support.')
                . ' (' . $e->getMessage() . ')'
            );
        }
    }

    /**
     * Checks if the currency is one of the granted ones
     *
     * @param mixed $cart
     *
     * @return bool
     *
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
     * Formats number to Currency (price)
     *
     * @param mixed $price
     *
     * @return mixed
     *
     * @throws LocalizationException
     */
    private function formatPrice($price, $currencyIso)
    {
        // Ensure price is a valid number
        if ($price === null || $price === '') {
            $price = 0;
        }

        // PrestaShop 1.7.13 compatibility - use Tools::displayPrice instead of getContextLocale
        $currency = Currency::getCurrencyInstance(Currency::getIdByIsoCode($currencyIso));

        return Tools::displayPrice((float) $price, $currency);
    }

    /**
     * Validates JSON configuration for component styles
     *
     * @param array $form_values
     *
     * @return bool|string Returns true if valid, error message string if invalid
     */
    private function validateComponentStyleJson($form_values)
    {
        $styleConfigs = [
            'MONEI_CARD_INPUT_STYLE' => 'Card Input',
            'MONEI_BIZUM_STYLE' => 'Bizum',
            'MONEI_PAYMENT_REQUEST_STYLE' => 'Payment Request',
            'MONEI_PAYPAL_STYLE' => 'PayPal',
        ];

        foreach ($form_values as $key => $defaultValue) {
            $value = Tools::getValue($key);

            // Skip if field is not a style configuration
            if (!isset($styleConfigs[$key])) {
                continue;
            }

            $styleName = $styleConfigs[$key];

            // Allow empty values (they will use defaults)
            if (empty(trim($value))) {
                continue;
            }

            // Validate JSON syntax only
            json_decode($value);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                $errorMessage = $this->getJsonErrorMessage($jsonError);

                return $this->displayError(
                    sprintf(
                        $this->l('%s style configuration contains invalid JSON: %s'),
                        $styleName,
                        $errorMessage
                    )
                );
            }
        }

        return true;
    }

    /**
     * Get human-readable JSON error message
     *
     * @param int $errorCode
     *
     * @return string
     */
    private function getJsonErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case JSON_ERROR_NONE:
                return $this->l('No errors');
            case JSON_ERROR_DEPTH:
                return $this->l('Maximum stack depth exceeded');
            case JSON_ERROR_STATE_MISMATCH:
                return $this->l('Underflow or mode mismatch');
            case JSON_ERROR_CTRL_CHAR:
                return $this->l('Unexpected control character found');
            case JSON_ERROR_SYNTAX:
                return $this->l('Syntax error, malformed JSON');
            case JSON_ERROR_UTF8:
                return $this->l('Malformed UTF-8 characters');
            default:
                return $this->l('Unknown JSON error');
        }
    }

    /**
     * Copy Apple Pay domain verification file to .well-known directory
     *
     * @return bool
     */
    public function copyApplePayDomainVerificationFile()
    {
        $sourceFile = _PS_MODULE_DIR_ . $this->name . '/files/apple-developer-merchantid-domain-association';

        // Check for Bitnami Let's Encrypt installation first
        $letsEncryptDir = $this->getBitnamiLetsEncryptPath();
        if (is_dir($letsEncryptDir) && is_writable($letsEncryptDir)) {
            $destFile = $letsEncryptDir . '/apple-developer-merchantid-domain-association';
            if (file_exists($sourceFile)) {
                return @copy($sourceFile, $destFile);
            }
        }

        // Fallback to standard .well-known directory
        $wellKnownDir = _PS_ROOT_DIR_ . '/.well-known';
        $destFile = $wellKnownDir . '/apple-developer-merchantid-domain-association';

        // Create .well-known directory if it doesn't exist
        if (!is_dir($wellKnownDir)) {
            if (!@mkdir($wellKnownDir, 0755, true)) {
                return false;
            }
        }

        // Copy the file
        if (file_exists($sourceFile)) {
            return @copy($sourceFile, $destFile);
        }

        return false;
    }

    /**
     * Remove Apple Pay domain verification file
     *
     * @return bool
     */
    private function removeApplePayDomainVerificationFile()
    {
        $removed = true;

        // Remove from Bitnami Let's Encrypt directory if it exists
        $letsEncryptFile = $this->getBitnamiLetsEncryptPath() . '/apple-developer-merchantid-domain-association';
        if (file_exists($letsEncryptFile)) {
            $removed = @unlink($letsEncryptFile) & $removed;
        }

        // Remove from standard .well-known directory
        $file = _PS_ROOT_DIR_ . '/.well-known/apple-developer-merchantid-domain-association';
        if (file_exists($file)) {
            $removed = @unlink($file) & $removed;
        }

        return $removed;
    }

    /**
     * Check Apple Pay domain verification status and return notification HTML
     *
     * @return string|null
     */
    private function checkApplePayDomainVerification()
    {
        // Only check if Apple Pay is enabled
        if (!Configuration::get('MONEI_ALLOW_APPLE')) {
            return null;
        }

        $domain = Configuration::get('PS_SHOP_DOMAIN');
        $url = 'https://' . $domain . '/.well-known/apple-developer-merchantid-domain-association';

        // Check if file is accessible
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'follow_location' => 1,
            ],
            'ssl' => [
                // Disable SSL verification for domain verification check only
                // This is safe as we're checking our own domain's file accessibility
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $headers = @get_headers($url, true, $context);
        $httpCode = 0;

        if ($headers && isset($headers[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers[0], $matches);
            $httpCode = isset($matches[1]) ? (int) $matches[1] : 0;
        }

        if ($httpCode !== 200) {
            // Try to copy the file again
            $this->copyApplePayDomainVerificationFile();

            // Get diagnostic info
            $diagnosticInfo = $this->getApplePayDiagnosticInfo();

            // File is not accessible, show warning
            return $this->displayWarning(
                $this->l('Apple Pay domain verification file is not accessible.') . ' '
                . '<span style="color:#666;">(' . $this->l('HTTP Status:') . ' ' . ($httpCode ?: $this->l('No response')) . ')</span><br><br>'
                . '<strong>' . $this->l('To enable Apple Pay on your website, you need to:') . '</strong><br>'
                . '1. ' . $this->l('Make sure the file is accessible at:') . ' <a href="' . $url . '" target="_blank">' . $url . '</a><br>'
                . '2. ' . $this->l('If automatic setup failed, please follow these manual steps:') . '<br>'
                . '&nbsp;&nbsp;&nbsp;&nbsp;• ' . $this->l('Download the verification file from:') . ' <a href="https://assets.monei.com/apple-pay/apple-developer-merchantid-domain-association/" target="_blank">' . $this->l('MONEI Apple Pay Assets') . '</a><br>'
                . '&nbsp;&nbsp;&nbsp;&nbsp;• ' . $this->l('Upload it to your server at: /.well-known/apple-developer-merchantid-domain-association') . '<br>'
                . '&nbsp;&nbsp;&nbsp;&nbsp;• ' . $this->l('Ensure the file is accessible via HTTPS with a valid SSL certificate') . '<br><br>'
                . '<strong>' . $this->l('Common issues:') . '</strong><br>'
                . '• ' . $this->l('Let\'s Encrypt or other services may be using the .well-known directory') . '<br>'
                . '• ' . $this->l('File permissions may prevent access (should be 644)') . '<br>'
                . '• ' . $this->l('Web server configuration may block access to .well-known directory') . '<br><br>'
                . $this->l('For more information, visit:') . ' <a href="https://docs.monei.com/apis/rest/apple-pay-domain-register/" target="_blank">' . $this->l('MONEI Documentation') . '</a>'
                . $this->getServerSpecificInstructions()
                . $diagnosticInfo . '<br><br>'
                . '<a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name . '" class="btn btn-default">'
                . '<i class="icon-refresh"></i> ' . $this->l('Refresh to check again') . '</a>'
            );
        }

        // File is accessible, check if it was previously verified
        $wasVerified = Configuration::get('MONEI_APPLE_PAY_VERIFIED');

        // Save verification status
        Configuration::updateValue('MONEI_APPLE_PAY_VERIFIED', true);
        Configuration::updateValue('MONEI_APPLE_PAY_VERIFIED_DATE', date('Y-m-d H:i:s'));

        // Show success message only if it was previously not verified
        if (!$wasVerified) {
            return $this->displayConfirmation($this->l('Apple Pay domain verified successfully.'));
        }

        return null;
    }

    /**
     * Get server-specific instructions for Apple Pay domain verification
     *
     * @return string
     */
    private function getServerSpecificInstructions()
    {
        $instructions = '<br><br><strong>' . $this->l('Server-specific instructions:') . '</strong><br>';

        // Check for Nginx
        if (stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false) {
            $instructions .= $this->l('For Nginx, add this to your server configuration:') . '<br>'
                . '<pre style="background:#f5f5f5;padding:10px;margin:5px 0;">'
                . 'location ^~ /.well-known/apple-developer-merchantid-domain-association {' . "\n"
                . '    alias ' . _PS_MODULE_DIR_ . $this->name . '/files/apple-developer-merchantid-domain-association;' . "\n"
                . '    default_type text/plain;' . "\n"
                . '}'
                . '</pre>';
        }

        // Check for Apache
        elseif (function_exists('apache_get_version') || stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'apache') !== false) {
            $instructions .= $this->l('For Apache, ensure your .htaccess allows access to .well-known:') . '<br>'
                . '<pre style="background:#f5f5f5;padding:10px;margin:5px 0;">'
                . 'RewriteRule ^\.well-known/apple-developer-merchantid-domain-association$ - [L]'
                . '</pre>';
        }

        // Check for Bitnami
        if (is_dir('/opt/bitnami')) {
            $instructions .= '<br>' . $this->l('Bitnami detected: The file should be placed in:') . '<br>'
                . '<code>/opt/bitnami/apps/letsencrypt/.well-known/</code><br>'
                . $this->l('This is because Let\'s Encrypt redirects .well-known requests.');
        }

        return $instructions;
    }

    /**
     * Get diagnostic information for Apple Pay verification issues
     *
     * @return string
     */
    private function getApplePayDiagnosticInfo()
    {
        $info = '<br><br><details style="margin-top:10px;">'
                . '<summary style="cursor:pointer;font-weight:bold;">' . $this->l('Show diagnostic information') . '</summary>'
                . '<div style="background:#f5f5f5;padding:10px;margin-top:5px;font-family:monospace;font-size:12px;">';

        // Check file locations
        $locations = [
            $this->l('Module directory') => _PS_MODULE_DIR_ . $this->name . '/files/apple-developer-merchantid-domain-association',
            $this->l('PrestaShop .well-known') => _PS_ROOT_DIR_ . '/.well-known/apple-developer-merchantid-domain-association',
            $this->l('Let\'s Encrypt .well-known') => $this->getBitnamiLetsEncryptPath() . '/apple-developer-merchantid-domain-association',
        ];

        $info .= '<strong>' . $this->l('File locations checked:') . '</strong><br>';
        foreach ($locations as $name => $path) {
            $exists = file_exists($path);
            $readable = $exists ? is_readable($path) : false;
            $info .= $name . ': ' . ($exists ? '✓ ' . $this->l('exists') : '✗ ' . $this->l('not found'));
            if ($exists) {
                $info .= ' (' . ($readable ? $this->l('readable') : $this->l('not readable')) . ', ';
                $info .= $this->l('perms:') . ' ' . substr(sprintf('%o', fileperms($path)), -4) . ')';
            }
            $info .= '<br>';
        }

        // Server info
        $info .= '<br><strong>' . $this->l('Server information:') . '</strong><br>';
        $info .= $this->l('Server software:') . ' ' . ($_SERVER['SERVER_SOFTWARE'] ?? $this->l('Unknown')) . '<br>';
        $info .= $this->l('Document root:') . ' ' . $_SERVER['DOCUMENT_ROOT'] . '<br>';
        $info .= $this->l('PrestaShop root:') . ' ' . _PS_ROOT_DIR_ . '<br>';
        $info .= $this->l('SSL enabled:') . ' ' . (Configuration::get('PS_SSL_ENABLED') ? $this->l('Yes') : $this->l('No')) . '<br>';
        $info .= $this->l('Shop domain:') . ' ' . Configuration::get('PS_SHOP_DOMAIN') . '<br>';

        $info .= '</div></details>';

        return $info;
    }

    /**
     * Get Bitnami Let's Encrypt directory path
     *
     * @return string
     */
    private function getBitnamiLetsEncryptPath()
    {
        return '/opt/bitnami/apps/letsencrypt/.well-known';
    }

    /**
     * Flatten payment method data structure from MONEI API response
     * This follows the same approach as Magento to extract nested payment details
     *
     * @param array $paymentMethodData Nested payment method data from API
     *
     * @return array Flattened payment method data
     */
    private function flattenPaymentMethodData($paymentMethodData)
    {
        $result = [];

        foreach ($paymentMethodData as $key => $value) {
            if (!is_array($value)) {
                $result[$key] = $value;

                continue;
            }

            // Flatten nested arrays (like 'card', 'paypal', 'bizum', etc.)
            foreach ($value as $nestedKey => $nestedValue) {
                $result[$nestedKey] = $nestedValue;
            }
        }

        return $result;
    }

    /**
     * Hook to add capture payment button to order actions (PS 1.7.13+)
     *
     * @param array $params Hook parameters containing order information
     */
    public function hookActionGetAdminOrderButtons(array $params)
    {
        // This hook only exists in PrestaShop 1.7.13+
        // Check if the required classes exist
        if (!class_exists('PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButton')
            || !class_exists('PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButtonsCollection')) {
            return;
        }

        // Check if this is a MONEI order
        $orderId = (int) $params['id_order'];
        $order = new Order($orderId);

        if (!Validate::isLoadedObject($order) || $order->module !== 'monei') {
            return;
        }

        // Check if payment can be captured
        $monei2PaymentEntity = Monei2Payment::findOneBy(['id_order' => $orderId]);
        if (!$monei2PaymentEntity) {
            return;
        }

        // Check if payment is in AUTHORIZED status
        if ($monei2PaymentEntity->status !== 'AUTHORIZED') {
            return;
        }

        // Get the actions bar buttons collection
        /** @var PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButtonsCollection $bar */
        $bar = $params['actions_bar_buttons_collection'];

        // Calculate remaining amount
        $authorizedAmount = (float) $monei2PaymentEntity->amount / 100;
        $capturedAmount = (float) $monei2PaymentEntity->captured_amount / 100;
        $remainingAmount = $authorizedAmount - $capturedAmount;

        $currency = new Currency($order->id_currency);
        $currencySign = $currency->sign;

        // Get modal attributes for current Bootstrap version
        $modalAttrs = $this->getModalAttributes();

        // Add capture button that triggers the existing modal from hookDisplayAdminOrder
        $bar->add(
            new PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButton(
                'btn-action btn-primary monei-capture-action-btn',
                [
                    'href' => '#',
                    $modalAttrs['toggle'] => 'modal',
                    $modalAttrs['target'] => '#moneiCaptureModal',  // Use existing modal ID
                    'data-order-id' => $orderId,
                    'data-max-amount' => $remainingAmount,
                    'data-currency-sign' => $currencySign,
                    'title' => $this->l('Capture the authorized payment'),
                ],
                $this->l('Capture Payment')
            )
        );
    }

    /**
     * Record a failed automatic capture against the order.
     *
     * Uses the order's own note field. The module CLAUDE.md described a
     * monei2_admin_order_message table, but no such table or entity exists, so
     * there is nothing to write to but the order itself.
     *
     * @param Order $order Order to annotate
     * @param string $reason Failure reason
     */
    private function addOrderCaptureNote(Order $order, $reason)
    {
        try {
            $note = trim((string) $order->note);
            $line = '[MONEI] Automatic capture failed: ' . $reason;

            $order->note = $note === '' ? $line : $note . "\n" . $line;
            $order->update();
        } catch (Exception $e) {
            self::logError('[MONEI] Could not record the capture failure on the order: ' . $e->getMessage());
        }
    }

    /**
     * Expand a comma separated configuration value into an array.
     *
     * @param string $key Configuration key
     *
     * @return array
     */
    protected function explodeConfigList($key)
    {
        $raw = (string) Configuration::get($key);

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    /**
     * Warning shown when the configured transaction type removes payment methods.
     *
     * Selecting pre-authorization does not make MB WAY and Multibanco fall back to
     * an immediate charge: it takes them off the storefront completely. Merchants
     * were never told, so the first sign was a customer asking where a payment
     * method went. The warning is rendered on both the form that sets the
     * transaction type and the form that enables the methods, because a merchant
     * only ever visits one of them.
     *
     * @return string HTML warning, or an empty string when nothing is hidden
     */
    private function getAuthHiddenMethodsWarning()
    {
        $enabled = [];
        $labels = [
            'MONEI_ALLOW_MBWAY' => ['mbway', 'MB WAY'],
            'MONEI_ALLOW_MULTIBANCO' => ['multibanco', 'Multibanco'],
        ];

        foreach ($labels as $configKey => $method) {
            if (Configuration::get($configKey)) {
                $enabled[] = $method[0];
            }
        }

        $hidden = PsMonei\Service\Monei\PaymentMethodAvailability::hiddenBy(
            $enabled,
            (string) Configuration::get('MONEI_PAYMENT_ACTION', 'sale')
        );

        if (!$hidden) {
            return '';
        }

        $names = [];
        foreach ($labels as $method) {
            if (in_array($method[0], $hidden, true)) {
                $names[] = $method[1];
            }
        }

        return '<div class="alert alert-warning">'
            . $this->l('Pre-authorization is active, so these enabled payment methods are currently hidden from your checkout:')
            . ' <strong>' . implode(', ', $names) . '</strong>. '
            . $this->l('They cannot be pre-authorized. Switch Payment Action to Sale to offer them again.')
            . '</div>';
    }

    protected function getConfigFormExpress()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Express Checkout'),
                    'icon' => 'icon-bolt',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable Express Checkout'),
                        'name' => 'MONEI_EXPRESS_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Show Apple Pay, Google Pay and PayPal buttons that let a customer pay without going through the full checkout. Off by default, because it changes your storefront.'),
                        'values' => [
                            [
                                'id' => 'express_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'express_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Show on'),
                        'name' => 'MONEI_EXPRESS_LOCATIONS[]',
                        'multiple' => true,
                        'desc' => $this->l('Where the express buttons appear.'),
                        'options' => [
                            'query' => [
                                ['id' => 'product', 'name' => $this->l('Product page')],
                                ['id' => 'cart', 'name' => $this->l('Cart page')],
                                ['id' => 'checkout', 'name' => $this->l('Checkout page')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Payment methods'),
                        'name' => 'MONEI_EXPRESS_METHODS[]',
                        'multiple' => true,
                        'desc' => $this->l('A method only appears if it is also enabled under Payment methods and offered by your MONEI account.'),
                        'options' => [
                            'query' => [
                                ['id' => 'applePay', 'name' => 'Apple Pay'],
                                ['id' => 'googlePay', 'name' => 'Google Pay'],
                                ['id' => 'paypal', 'name' => 'PayPal'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    /**
     * Values bound to the express checkout form.
     *
     * @return array
     */
    protected function getConfigFormExpressValues()
    {
        return [
            'MONEI_EXPRESS_ENABLED' => Configuration::get('MONEI_EXPRESS_ENABLED', false),
            // Multiple selects post arrays, so the stored comma separated lists
            // are expanded back out for the form to preselect.
            'MONEI_EXPRESS_LOCATIONS[]' => $this->explodeConfigList('MONEI_EXPRESS_LOCATIONS'),
            'MONEI_EXPRESS_METHODS[]' => $this->explodeConfigList('MONEI_EXPRESS_METHODS'),
        ];
    }

    /**
     * Express payment methods that may render right now.
     *
     * A method has to be wanted for express, enabled as a payment method, and
     * offered by the MONEI account. Express settings widen nothing.
     *
     * @return string[]
     */
    public function getExpressMethods()
    {
        $allowed = [];
        $flags = [
            'applePay' => 'MONEI_ALLOW_APPLE',
            'googlePay' => 'MONEI_ALLOW_GOOGLE',
            'paypal' => 'MONEI_ALLOW_PAYPAL',
        ];

        foreach ($flags as $method => $configKey) {
            if (Configuration::get($configKey)) {
                $allowed[] = $method;
            }
        }

        try {
            $offered = self::getService('service.monei')->getPaymentMethodsAllowed();
        } catch (Exception $e) {
            Monei::logWarning('[MONEI] Could not read the account payment methods for express: ' . $e->getMessage());

            return [];
        }

        return PsMonei\Service\Express\ExpressMethodResolver::resolve(
            (string) Configuration::get('MONEI_EXPRESS_METHODS'),
            $allowed,
            is_array($offered) ? $offered : []
        );
    }

    /**
     * Which storefront page is being rendered.
     *
     * ⚠️ `page_name` is not populated yet when actionFrontControllerSetMedia fires
     * on a product or cart page — it is still an empty string, so any check against
     * it silently matches nothing and no asset is ever registered. `php_self` is
     * set earlier, and is what the express surfaces are keyed off. It spells the
     * checkout page "order", which is normalised here so the rest of the module can
     * keep using one vocabulary.
     *
     * @return string product, cart, checkout, or whatever the controller reports
     */
    private function getFrontPageName()
    {
        $controller = $this->context->controller;
        $pageName = (string) $controller->page_name;

        if ($pageName === '' && property_exists($controller, 'php_self')) {
            $pageName = (string) $controller->php_self;
        }

        return $pageName === 'order' ? 'checkout' : $pageName;
    }

    /**
     * Capture a pre-authorization when an order reaches a configured state.
     *
     * ⚠️ Registered unconditionally, for every request context. The equivalent
     * WooCommerce hook was wired for admin requests only, so any other path that
     * moves an order — a shipping module, an ERP sync, cron, the webservice API —
     * left the money authorized until it expired, with the order reading as paid
     * and nothing to explain it.
     *
     * ⚠️ This must not write the order state. The manual capture button in the
     * back office does (AdminMoneiCapturePaymentController), which is right for a
     * button but wrong here: from a status hook it would reset the state the
     * merchant just chose back to "Payment accepted", and re-enter this hook.
     *
     * @param array $params Hook parameters
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        // Re-entrancy guard. Anything this hook triggers that moves an order
        // would otherwise come straight back here.
        if (self::$captureInProgress) {
            return;
        }

        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $shouldCapture = PsMonei\Service\Monei\CaptureTrigger::shouldCapture(
            (string) $order->module,
            $this->name,
            (int) $params['newOrderStatus']->id,
            (string) Configuration::get('MONEI_CAPTURE_STATUS')
        );

        if (!$shouldCapture) {
            return;
        }

        self::$captureInProgress = true;

        try {
            // ⚠️ Read the amount with a plain query rather than through the
            // Doctrine repository. This hook has to work in every context that can
            // move an order — cron, an ERP sync, the webservice API, a CLI script —
            // and several of those bootstrap PrestaShop without the module's
            // service container, where getRepository() is null and this would be a
            // fatal in the middle of a merchant's order flow.
            $amount = (int) Db::getInstance()->getValue(
                'SELECT amount FROM ' . _DB_PREFIX_ . 'monei2_payment WHERE id_order = ' . (int) $order->id
            );

            if ($amount <= 0) {
                return;
            }

            // The authorized amount, not the order total. A merchant may have
            // edited the order since, and capturePayment rejects anything above
            // what was authorized.
            self::getService('service.monei')->capturePayment((int) $order->id, $amount);

            self::logDebug('[MONEI] Captured payment for order ' . (int) $order->id . ' on status change');
        } catch (Throwable $e) {
            // Never silent: a capture that did not happen is money that expires.
            self::logError(
                '[MONEI] Automatic capture failed for order ' . (int) $order->id . ': ' . $e->getMessage()
            );

            $this->addOrderCaptureNote($order, $e->getMessage());
        } finally {
            self::$captureInProgress = false;
        }
    }

    /**
     * Express buttons on the cart page, beside the checkout button.
     *
     * @return string
     */
    public function hookDisplayExpressCheckout()
    {
        return $this->renderExpressContainer('cart');
    }

    /**
     * Express buttons above the payment options at checkout.
     *
     * @return string
     */
    public function hookDisplayPaymentTop()
    {
        return $this->renderExpressContainer('checkout');
    }

    /**
     * Express buttons on the product page.
     *
     * @param array $params Hook parameters
     *
     * @return string
     */
    public function hookDisplayProductAdditionalInfo($params)
    {
        return $this->renderExpressContainer('product', isset($params['product']) ? $params['product'] : null);
    }

    /**
     * Is express checkout switched on for this surface?
     *
     * @param string $location product, cart or checkout
     *
     * @return bool
     */
    public function isExpressEnabledFor($location)
    {
        return PsMonei\Service\Express\ExpressMethodResolver::isLocationEnabled(
            (string) $location,
            (bool) Configuration::get('MONEI_EXPRESS_ENABLED'),
            (string) Configuration::get('MONEI_EXPRESS_LOCATIONS')
        );
    }

    /**
     * Load the SDK and the express client on a non checkout page.
     */
    private function registerExpressAssets()
    {
        $moneiSdkUrl = self::MONEI_JS_URL;

        $this->context->controller->registerJavascript(
            sha1($moneiSdkUrl),
            $moneiSdkUrl,
            [
                'server' => 'remote',
                'priority' => 50,
                'attribute' => 'defer',
            ]
        );

        $this->context->controller->registerJavascript(
            'module-' . $this->name . '-express',
            'modules/' . $this->name . '/views/js/front/express.js',
            [
                'priority' => 95,
                'attribute' => 'defer',
                'position' => 'bottom',
            ]
        );

        $this->context->controller->registerStylesheet(
            'module-' . $this->name . '-express',
            'modules/' . $this->name . '/views/css/front/express.css',
            [
                'priority' => 200,
                'media' => 'all',
            ]
        );

        // Published here rather than from the display hooks: PrestaShop collects
        // the js_def block before content hooks render.
        Media::addJsDef([
            'moneiExpress' => [
                'accountId' => (bool) Configuration::get('MONEI_PRODUCTION_MODE')
                    ? Configuration::get('MONEI_ACCOUNT_ID')
                    : Configuration::get('MONEI_TEST_ACCOUNT_ID'),
                'endpoint' => $this->context->link->getModuleLink('monei', 'express'),
                'token' => Tools::getToken(false),
                'currency' => $this->context->currency->iso_code,
                'methods' => $this->getExpressMethods(),
                'style' => json_decode(Configuration::get('MONEI_PAYMENT_REQUEST_STYLE')),
                'paypalStyle' => json_decode(Configuration::get('MONEI_PAYPAL_STYLE')),
                'errorGeneric' => $this->l('The payment could not be completed. Please try again.'),
            ],
        ]);
    }

    /**
     * Render the express container for a surface, or nothing.
     *
     * @param string $location product, cart or checkout
     * @param mixed|null $product Product being viewed, on the product page
     *
     * @return string
     */
    private function renderExpressContainer($location, $product = null)
    {
        if (!$this->isExpressEnabledFor($location)) {
            return '';
        }

        $methods = $this->getExpressMethods();

        if (!$methods) {
            return '';
        }

        $this->context->smarty->assign([
            'moneiExpressLocation' => $location,
            'moneiExpressMethods' => $methods,
            'moneiExpressProductId' => $product ? (int) $product['id_product'] : 0,
        ]);

        return $this->fetch('module:monei/views/templates/hook/expressCheckout.tpl');
    }

    protected function renderFormExpress()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoneiModuleExpress';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormExpressValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigFormExpress()]);
    }
}
