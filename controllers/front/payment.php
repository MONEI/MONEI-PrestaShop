<?php

class MoneiPaymentPlatformPaymentModuleFrontController extends ModuleFrontController {
	public $ssl = true;
	public $display_column_left = false;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		parent::initContent();

		$cart = $this->context->cart;

		$hasError     = false;
		$errorMessage = "";
		$checkoutID   = null;


		if ( ! $this->module->active ) {
			$errorMessage =
				"An error occurred while loading the Monei Platform Payment Module, please contact support (Monei Payment Plaftform Module not Active)" .
				$hasError = true;
		}

		if ( ! isset( $cart ) || $cart == null && ! is_a( $cart, "Cart" ) ) {
			$errorMessage =
				"An error occurred while loading the Monei Platform Payment Module, please contact support (There is a problem with your Cart)" .
				$hasError = true;

		}

		if ( ! $hasError ) {
			$checkout = $this->module->prepareCheckout( $cart );
			if ( ! isset( $checkout['id'] ) ) {
				$hasError = true;

				if ( isset( $checkout->result ) ) {
					//CREATE AN ENTRY IN THE DATABASE
					$desc = $checkout->result->description;
					if ( isset( $desc ) && ! empty( $desc ) ) {
						$errorMessage = $desc;
					} else {
						$errorMessage = "MONEI Payment Gateway: An error occurred while processing your request";
					}
				} else {
					$errorMessage = "MONEI Payment Gateway: An error occurred while processing your request";
				}
			} else {
				$checkoutID = $checkout['id'];
			}
		}

		$isRedirectedWithError  = false;
		$redirectedErrorMessage = '';
		if ( isset( $this->context->cookie->monei_redirect_error ) && $this->context->cookie->monei_redirect_error ) {
			$isRedirectedWithError                       = true;
			$redirectedErrorMessage                      = $this->context->cookie->monei_redirect_error;
			$this->context->cookie->monei_redirect_error = false;
		}

		$this->context->smarty->assign( array(
			'checkoutID'             => $checkoutID,
			'returnURL'              => Tools::getShopDomainSsl( true, true ) .
			                            __PS_BASE_URI__ .
			                            'modules/' .
			                            $this->module->name .
			                            '/',
			'hasError'               => $hasError,
			'errorMessage'           => $errorMessage,
			'nbProducts'             => $cart->nbProducts(),
			'allowedPaymentMethods'  => $this->module->getAllowedPaymentMethodsString(),
			'total'                  => $cart->getOrderTotal( true, Cart::BOTH ),
			'isRedirectedWithError'  => $isRedirectedWithError,
			'redirectedErrorMessage' => $redirectedErrorMessage,
			'apiHost'                => $this->getApiHost()

		) );

		$this->setTemplate( 'payment_execution.tpl' );
	}

	private
	function getApiHost() {
		$testMode = Configuration::get( 'mpp_operationMode_testMode' );
		if ( $testMode != null ) {
			return "test.moneipayments-api.net";
		} else {
			return "moneipayments-api.net";
		}
	}
}

