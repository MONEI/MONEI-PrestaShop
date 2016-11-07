<?php

class MoneiPaymentPlatformPaymentModuleFrontController extends ModuleFrontController
{
	public $ssl = true;
	public $display_column_left = false;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		parent::initContent();

		$cart = $this->context->cart;


		$hasError = false;
		$errorMessage = "";
		$checkoutID = null;


		if (!$this->module->active) {
			$errorMessage = "An error ocurred while loading the Monei Platform Payment Module, please contact support (Monei Payment Plaftform Module not Active)" .
					$hasError = true;
		}

		if (!isset($cart) || $cart == null && !is_a($cart, "Cart")) {
			$errorMessage = "An error ocurred while loading the Monei Platform Payment Module, please contact support (There is a problem with your Cart)" .
					$hasError = true;

		}


		if (Configuration::get($this->module->prefix . 'acceptedPayment_visa') == null && Configuration::get($this->module->prefix . 'acceptedPayment_mastercard') == null)
			if (Configuration::get($this->module->prefix . 'acceptedPayment_maestro') == null && Configuration::get($this->module->prefix . 'acceptedPayment_jcb') == null) {
				$errorMessage = "An error ocurred while loading the Monei Platform Payment Module, No payment methods allowed" .
						$hasError = true;

			}

		if (!$hasError) {

			$cartOjbect = $cart;
			$currency = Currency::getCurrency($cartOjbect->id_currency);
			$checkout = $this->module->prepareCheckout($cartOjbect->getOrderTotal(true), $currency['iso_code']);
			$checkoutObj = json_decode($checkout);
			if (!isset($checkoutObj->id)) {
				$hasError = true;

				if (isset($checkoutObj->result)) {
					//CREATE AN ENTRY IN THE DATABASE
					if (isset($checkoutObj->result->description) && !empty($checkoutObj->result->description))
						$errorMessage = $checkoutObj->result->description;
					else {
						$errorMessage = "Monei Servers: An error occurred while processing your request";
					}
				} else {
					$errorMessage = "Monei Servers: An error occurred while processing your request";
				}
			} else {
				$checkoutID = $checkoutObj->id;
			}
		}

		$isRedirectedWithError =  false;
		$redirectedErrorMessage = '';
		if(isset($this->context->cookie->monei_redirect_error) && $this->context->cookie->monei_redirect_error){
			$isRedirectedWithError = true;
			$redirectedErrorMessage =  $this->context->cookie->monei_redirect_error;
			$this->context->cookie->monei_redirect_error = false;
		}

		$this->context->smarty->assign(array(
				'checkoutID' => $checkoutID,
				'returnURL' =>  Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/',
				'hasError' => $hasError,
				'errorMessage' => $errorMessage,
				'nbProducts' => $cart->nbProducts(),
				'allowedPaymentMethods' => $this->module->getAllowedPaymentMethodsString(),
				'total' => $cart->getOrderTotal(true, Cart::BOTH),
				'isRedirectedWithError' =>$isRedirectedWithError,
				'redirectedErrorMessage' => $redirectedErrorMessage

		));



		$this->setTemplate('payment_execution.tpl');
	}
}
