<?php
/**
 * universalpay module main file.
 *
 * @author    Microapss
 * @link http://microapps.com/
 * @copyright Copyright &copy; 2016 http://microapps.com/
 * @version 0.0.5
 */

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

class MoneiPaymentPlatform extends PaymentModule {
	public function __construct() {
		$this->name                          = 'moneipaymentplatform';
		$this->tab                           = 'payments_gateways';
		$this->version                       = '1.0.0';
		$this->author                        = 'MONEI';
		$this->need_instance                 = 1;
		$this->ps_versions_compliancy['min'] = '1.6.0';
		$this->author_uri                    = 'https://monei.net/';
		$this->prefix                        = "monei_";
		$this->currencies                    = true;
		$this->currencies_mode               = 'checkbox';
		$this->bootstrap                     = true;
		$this->isSubmitted                   = false;
		parent::__construct();

		$this->displayName      = $this->l( 'MONEI Payment Gateway' );
		$this->description      = $this->l( 'The easiest way to accept payments from your customers.' );
		$this->confirmUninstall = $this->l( 'Are you sure you want to uninstall?' );
		$this->supportedBrands  = array(
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
	}

	public function getContent() {
		$output    = '';
		$hasErrors = false;
		if ( Tools::isSubmit( 'submit' . $this->name ) ) {
			$this->isSubmitted = true;
			$settings          = $this->verifyAndGetValues( array(
				'secret_token',
				'brands',
				'descriptor',
				'submit_text',
				'show_cardholder',
				'primary_color'
			) );


			if ( $settings['secret_token'] == null ) {
				$hasErrors = true;
				$output    .= $this->displayError( $this->l( 'Secret Token is required' ) );
			}
			if ( $settings['brands'] == null ) {
				$hasErrors = true;
				$output    .= $this->displayError( $this->l( 'At least one payment method is required' ) );
			}
			if ( ! $hasErrors ) {
				$output .= $this->displayConfirmation( $this->l( 'All settings updated' ) );
			}

			Configuration::updateValue( $this->prefix . 'settings', json_encode($settings) );
		}

		return $output . $this->displayForm();
	}

	public function displayForm() {
		$settings = json_decode(Configuration::get( $this->prefix . 'settings' ));

		$this->context->smarty->assign(
			array(
				'token'           => Tools::getAdminTokenLite( 'AdminModules' ),
				'heading'         => $this->l( 'Settings' ),
				'values'          => $settings,
				'supportedBrands' => $this->supportedBrands,
				'isSubmitted'     => $this->isSubmitted
			)
		);

		return $this->display( __FILE__, 'views/templates/admin/config.tpl' );
	}

	public function hookPayment( $params ) {
		$hasError     = false;
		$errorMessage = "";
		$checkoutID   = null;

		if ( ! $this->active ) {
			$errorMessage = "An error ocurred while loading the Monei Platform Payment Module, please contact support (Monei Payment Plaftform Module not Active)" .
			                $hasError = true;
		}


		$this->smarty->assign( array(
			'moneiPaymentURL' => Context::getContext()->link->getModuleLink( 'moneipaymentplatform', 'payment' ),
			'hasError'        => $hasError,
			'errorMessage'    => $errorMessage,
		) );

		return $this->display( __FILE__, 'payment.tpl' );

	}

	public function hookPaymentReturn( $params ) {
		if ( ! $this->active ) {
			return;
		}

		$state = $params['objOrder']->getCurrentState();
		if ( in_array( $state, array(
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


	public
	function hookDisplayBackOfficeHeader() {
		if ( Tools::getValue( 'controller' ) == 'AdminModules' && Tools::getValue( 'configure' ) == 'moneipaymentplatform' ) {
			$this->context->controller->addCSS( $this->_path . 'css/admin-style.css', 'all' );
			$this->context->controller->addCSS( $this->_path . 'assets/chosen.min.css' );
			$this->context->controller->addJquery();
			$this->context->controller->addJS( $this->_path . 'assets/chosen.jquery.min.js' );
			$this->context->controller->addJS( $this->_path . 'js/admin-js.js' );

		}
	}

	public function prepareCheckout( $amount, $currency ) {

		$userID          = Configuration::get( $this->prefix . 'moneiData_UserID' );
		$password        = Configuration::get( $this->prefix . 'moneiData_Password' );
		$channelID       = Configuration::get( $this->prefix . 'moneiData_ChannelID' );
		$apiHost         = $this->getApiHost();
		$formattedAmount = number_format( $amount, 2, '.', '' );
		$customerEmail   = $this->context->customer->email;
		$firstName       = $this->context->customer->firstname;
		$lastName        = $this->context->customer->lastname;

		$url  = "https://" . $apiHost . "/v1/checkouts";
		$data = "authentication.userId=$userID" .
		        "&authentication.password=$password" .
		        "&authentication.entityId=$channelID" .
		        "&customer.email=$customerEmail" .
		        "&customer.givenName=$firstName" .
		        "&customer.surname=$lastName" .
		        "&amount=$formattedAmount" .
		        "&currency=$currency" .
		        "&paymentType=DB";
		$ch   = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$responseData = curl_exec( $ch );
		if ( curl_errno( $ch ) ) {
			return curl_error( $ch );
		}
		curl_close( $ch );

		return $responseData;
	}

	private
	function getApiHost() {
		$testMode = Configuration::get( $this->prefix . 'operationMode_testMode' );
		if ( $testMode != null ) {
			return "test.monei-api.net";
		} else {
			return "monei-api.net";
		}
	}

	public
	function install() {
		if ( Shop::isFeatureActive() ) {
			Shop::setContext( Shop::CONTEXT_ALL );
		}

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


		if ( parent::install() && $this->registerHook( 'displayBackOfficeHeader' ) && $this->registerHook( 'payment' ) && $this->registerHook( 'paymentReturn' ) ) {
			return true;
		} else {
			return false;
		}
	}

	public
	function uninstall() {

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

	public
	function verifyAndGetValues(
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
		$userID    = Configuration::get( $this->prefix . 'moneiData_UserID' );
		$password  = Configuration::get( $this->prefix . 'moneiData_Password' );
		$channelID = Configuration::get( $this->prefix . 'moneiData_ChannelID' );
		$testMode  = Configuration::get( $this->prefix . 'operationMode_testMode' );
		$apiHost   = $this->getApiHost( $testMode );

		$url = "https://" . $apiHost . "$resourcePath";
		$url .= "?authentication.userId=$userID";
		$url .= "&authentication.password=$password";
		$url .= "&authentication.entityId=$channelID";

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

	public function getAllowedPaymentMethodsString() {
		$allowedMethods = Configuration::get( $this->prefix . 'acceptedPayment_visa' ) != null ? "VISA" : "";
//        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_visa') !=  null ? " VISADEBIT" : "";
		$allowedMethods .= Configuration::get( $this->prefix . 'acceptedPayment_mastercard' ) != null ? " MASTER" : "";
//        $allowedMethods .= Configuration::get($this->prefix . 'acceptedPayment_mastercard') !=  null ? " MASTERDEBIT" : "";
		$allowedMethods .= Configuration::get( $this->prefix . 'acceptedPayment_maestro' ) != null ? " MAESTRO" : "";
		$allowedMethods .= Configuration::get( $this->prefix . 'acceptedPayment_jcb' ) != null ? " JCB" : "";

		return $allowedMethods;
	}
}
