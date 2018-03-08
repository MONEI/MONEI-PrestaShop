<?php

class MoneiPaymentPlatformPaymentModuleFrontController extends ModuleFrontController {
	public $ssl = true;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		parent::initContent();
		$cart = $this->context->cart;
		if ( $cart->id_customer == 0 ||
		     $cart->id_address_delivery == 0 ||
		     $cart->id_address_invoice == 0 ||
		     ! $this->module->active ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}


		$authorized = false;
		foreach ( Module::getPaymentModules() as $module ) {
			if ( $module['name'] == 'moneipaymentplatform' ) {
				$authorized = true;
				break;
			}
		}
		if ( ! $authorized ) {
			die( $this->module->l( 'This payment method is not available.', 'validation' ) );
		}

		$customer = new Customer( $cart->id_customer );
		if ( ! Validate::isLoadedObject( $customer ) ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}

		$config   = $this->module->getConfig();
		$checkout = $this->module->prepareCheckout( $cart );
		if ( ! isset( $checkout['id'] ) ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}
		$brands        = implode( ' ', $config['brands'] );
		$return_url    = $this->context->link->getModuleLink( $this->module->name, 'validation', array(), true );
		$paymentConfig = array(
			'checkoutId'  => $checkout['id'],
			'brands'      => $brands,
			'redirectUrl' => $return_url,
			'test'        => $this->module->test_mode
		);

		Tools::redirect( $this->module->paymentUrl . '?' . http_build_query( $paymentConfig ) );
	}
}

