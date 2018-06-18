<?php

include_once dirname( __FILE__ ) . '/utils.php';

class ApiHandler {
	private $testMode;
	private $apiBaseUrl;
	private $paymentUrl;
	private $authParams;

	public static $successCodes = array(
		'000.000.000',
		'000.000.100',
		'000.100.110',
		'000.100.111',
		'000.100.112',
		'000.300.000'
	);

	public function __construct( $token ) {
		$credentials      = json_decode( decode_token( $token ) );
        if (isset($credentials->t)) {
            $this->testMode = $credentials->t;
        } else {
            $this->testMode = false;
        }
		$this->apiBaseUrl = $this->testMode ? "https://test.monei-api.net" : "https://monei-api.net";
		$this->paymentUrl = 'https://payments.monei.net/';
		$this->authParams = array(
			'authentication.userId'   => $credentials->l,
			'authentication.password' => $credentials->p,
			'authentication.entityId' => $credentials->c,
		);
	}

	public function prepareCheckout( $checkoutParams ) {
		$url    = $this->apiBaseUrl . "/v1/checkouts";
		$params = array_merge( $this->authParams, $checkoutParams );
		$ch     = curl_init();
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

		return json_decode( $responseData );
	}

	public function getTransactionStatus( $resourcePath ) {
		$url = $this->apiBaseUrl . $resourcePath . '?' . http_build_query( $this->authParams );
		$ch  = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$responseData = curl_exec( $ch );
		if ( curl_errno( $ch ) ) {
			return curl_error( $ch );
		}
		curl_close( $ch );

		return json_decode( $responseData );
	}

	public function getPaymentUrl( $paymentParams ) {
		$apiParams = array();
		if ($this->testMode) $apiParams['test'] = 'true';
		$params = array_merge($paymentParams, $apiParams);
		return $this->paymentUrl . '?' . http_build_query( $params );
	}

	public function isTransactionSuccessful( $response ) {
		return in_array( $response->result->code, self::$successCodes );
	}
}