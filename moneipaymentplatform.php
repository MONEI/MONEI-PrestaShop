<?php
/**
 * moneipaymentplatform module main file.
 *
 * @author MONEI
 * @link https://monei.net/
 * @copyright Copyright &copy; 2018 https://monei.net/
 * @version 1.0.0
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

include_once dirname( __FILE__ ) . '/lib/utils.php';

class MoneiPaymentPlatform extends PaymentModule {
	public $test_mode;
	public $api_base_url;
	public $auth_params;

	public function __construct() {
		$this->name                   = 'moneipaymentplatform';
		$this->tab                    = 'payments_gateways';
		$this->version                = '1.0.0';
		$this->ps_versions_compliancy = array( 'min' => '1.7', 'max' => _PS_VERSION_ );
		$this->author                 = 'MONEI';
		$this->need_instance          = 1;
		$this->author_uri             = 'https://moneip.net/';
		$this->currencies             = true;
		$this->currencies_mode        = 'checkbox';
		$this->bootstrap              = true;
		$this->paymentUrl             = 'https://payments.monei.net/';
		$this->supportedBrands        = array(
			'AMEX'             => "American Express",
			'JCB'              => "JCB",
			'MAESTRO'          => "Maestro",
			'MASTER'           => "MasterCard",
			'MASTERDEBIT'      => "MasterCard Debit",
			'VISA'             => "Visa",
			'VISADEBIT'        => "Visa Debit",
			'VISAELECTRON'     => "Visa Electron",
			'PAYPAL'           => "PayPal",
			'BITCOIN'          => "Bitcoin",
			'ALIPAY'           => "Alipay",
			'DIRECTDEBIT_SEPA' => "SEPA Direct Debit"
		);
		$this->defaultConfig          = array(
			'brands'      => array( 'VISA', 'MASTER' ),
			'title'       => 'Pay with Credit Card',
			'description' => 'Pay via MONEI Payment Gateway.'
		);

		$config = $this->getConfig();

		if ( ! empty( $config['secret_token'] ) ) {
			$credentials        = json_decode( decode_token( $config['secret_token'] ) );
			$this->test_mode    = $credentials->t;
			$this->api_base_url = $this->test_mode ? "https://test.monei-api.net" : "https://monei-api.net";
			$this->auth_params  = array(
				'authentication.userId'   => $credentials->l,
				'authentication.password' => $credentials->p,
				'authentication.entityId' => $credentials->c,
			);
		}

		parent::__construct();

		$this->displayName      = $this->l( 'MONEI Payment Gateway' );
		$this->description      = $this->l( 'The easiest way to accept payments from your customers.' );
		$this->confirmUninstall = $this->l( 'Are you sure you want to uninstall?' );
	}

	public function getContent() {
		$output    = '';
		$hasErrors = false;
		if ( Tools::isSubmit( 'btnSubmit' ) ) {
			$config = $this->verifyAndGetValues( array(
				'secret_token',
				'brands',
				'title',
				'description'
			) );

			if ( $config['secret_token'] == null ) {
				$hasErrors = true;
				$output    .= $this->displayError( $this->l( 'Secret Token is required' ) );
			}
			if ( $config['brands'] == null ) {
				$hasErrors = true;
				$output    .= $this->displayError( $this->l( 'At least one payment method is required' ) );
			}
			if ( ! $hasErrors ) {
				$this->setConfig( $config );
				$output .= $this->displayConfirmation( $this->l( 'Settings have been updated' ) );
			}
		}

		return $output . $this->displayForm();
	}

	public function displayForm() {
		$this->context->smarty->assign(
			array(
				'token'           => Tools::getAdminTokenLite( 'AdminModules' ),
				'values'          => $this->getConfig(),
				'supportedBrands' => $this->supportedBrands
			)
		);

		return $this->display( __FILE__, 'views/templates/admin/config.tpl' );
	}


	public function hookPaymentOptions( $params ) {
		if ( ! $this->active ) {
			return;
		}
		$config    = $this->getConfig();
		$newOption = new PaymentOption();
		$newOption->setCallToActionText( $config['title'] )
		          ->setAction( $this->context->link->getModuleLink( $this->name, 'payment', array(), true ) )
		          ->setAdditionalInformation( $config['description'] );
		$payment_options = [ $newOption ];

		return $payment_options;
	}

	public function hookPaymentReturn( $params ) {
		if ( ! $this->active ) {
			return;
		}

		$state = $params['order']->getCurrentState();
		if ( in_array( $state,
			array(
				Configuration::get( 'PS_OS_PAYMENT' ),
				Configuration::get( 'PS_OS_OUTOFSTOCK' ),
				Configuration::get( 'PS_OS_OUTOFSTOCK_UNPAID' )
			) ) ) {
			$this->smarty->assign( array(
				'total_to_pay' => Tools::displayPrice( $params['total_to_pay'], $params['currencyObj'], false ),
				'status'       => 'ok',
				'id_order'     => $params['objOrder']->id
			) );
			if ( isset( $params['objOrder']->reference ) && ! empty( $params['objOrder']->reference ) ) {
				$this->smarty->assign( 'reference', $params['objOrder']->reference );
			}
		} else {
			$this->smarty->assign( 'status', 'failed' );
		}

		return $this->display( __FILE__, 'payment_return.tpl' );
	}


	public function hookDisplayBackOfficeHeader() {
		if ( Tools::getValue( 'controller' ) == 'AdminModules' &&
		     Tools::getValue( 'configure' ) == $this->name ) {
			$this->context->controller->addCSS( $this->_path . 'css/admin-style.css', 'all' );
			$this->context->controller->addCSS( $this->_path . 'assets/chosen.min.css' );
			$this->context->controller->addJquery();
			$this->context->controller->addJS( $this->_path . 'assets/chosen.jquery.min.js' );
			$this->context->controller->addJS( $this->_path . 'js/admin-js.js' );

		}
	}

	public function prepareCheckout() {
		$cart     = $this->context->cart;
		$currency = $this->context->currency->iso_code;
		$customer = $this->context->customer;
		$amount   = (float) $cart->getOrderTotal( true, Cart::BOTH );
		$order_id = $cart->id;
		$url      = $this->api_base_url . "/v1/checkouts";
		$params   = array_merge( $this->auth_params,
			array(
				'amount'                      => $amount,
				'currency'                    => $currency,
				'merchantInvoiceId'           => $order_id,
				'paymentType'                 => 'DB',
				'customer.merchantCustomerId' => $customer->id,
				'customer.email'              => $customer->email,
				'customer.givenName'          => $customer->firstname,
				'customer.surname'            => $customer->lastname
			) );
		$ch       = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $params ) );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$responseData = curl_exec( $ch );
		if ( curl_errno( $ch ) ) {
			return curl_error( $ch );
		}
		curl_close( $ch );

		return json_decode( $responseData, true );
	}

	public function install() {
		$parentId = Tab::getIdFromClassName( 'AdminParentModules' );

		$tab_controller_main             = new Tab();
		$tab_controller_main->active     = true;
		$tab_controller_main->class_name = "MoneiPaymentPlatformSettings";
		foreach ( Language::getLanguages() as $lang ) {
			$tab_controller_main->name[ $lang['id_lang'] ] = "MONEI Payment Gateway";
		}

		$tab_controller_main->id_parent = $parentId;
		$tab_controller_main->module    = $this->name;
		$tab_controller_main->add();
		$tab_controller_main->move( Tab::getNewLastPosition( 0 ) );

		$this->setConfig( $this->defaultConfig );

		if ( parent::install() &&
		     $this->registerHook( 'displayBackOfficeHeader' ) &&
		     $this->registerHook( 'paymentOptions' ) &&
		     $this->registerHook( 'paymentReturn' ) ) {
			return true;
		} else {
			return false;
		}
	}

	public function uninstall() {

		$id_tab = Tab::getIdFromClassName( 'MoneiPaymentPlatformSettings' );
		if ( $id_tab ) {
			$tab = new Tab( $id_tab );
			$tab->delete();
		}

		if ( ! parent::uninstall() || ! Configuration::deleteByName( $this->name ) ) {
			return false;
		}


		return true;
	}

	public function verifyAndGetValues(
		array $values
	) {
		$validValues = [];
		foreach ( $values as $value ) {
			$userInput = Tools::getValue( $value );

			if ( $userInput && ! empty( $userInput ) ) {
				$validValues[ $value ] = $userInput;
			} else {
				$validValues[ $value ] = null;
			}

		}

		return $validValues;
	}

	public function getPaymentStatus( $resourcePath ) {
		$url      = $this->api_base_url . $resourcePath . '?' . http_build_query( $this->auth_params);
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$responseData = curl_exec( $ch );
		if ( curl_errno( $ch ) ) {
			return curl_error( $ch );
		}
		curl_close( $ch );

		return $responseData;
	}

	public function getConfig() {
		return json_decode( Configuration::get( $this->name ), true );
	}

	private function setConfig( $config ) {
		Configuration::updateValue( $this->name, json_encode( $config ) );
	}
}
