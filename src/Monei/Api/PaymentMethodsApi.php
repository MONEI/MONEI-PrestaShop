<?php
namespace Monei\Api;

use Monei\ApiException;
use Monei\Configuration;
use Monei\Model\MoneiAccount;
use PsCurl\PsCurl as CurlCustom;

class PaymentMethodsApi
{
    public const ENDPOINT = '/payment-methods';
    protected $client;
    protected $config;

    /**
     * Creates a new PaymentApi client
     * @param Configuration $config
     * @return void
     */
    public function __construct(Configuration $config)
    {
        $this->config = $config;

        // Create the new client
        $this->client = new CurlCustom();
        $this->client->setUserAgent($this->config->getUserAgent());
        $this->client->setHeader('Authorization', $this->config->getApiKey());
        $this->client->setHeader('Content-Type', 'application/json');
    }

    /**
     * Get account information
     * @param string $accountId
     * @return array
     * @throws ApiException
     */
    public function getAccountInformation(): MoneiAccount
    {
        try {
            $this->client->get(
                $this->config->getHost() . self::ENDPOINT . '?accountId=' . $this->config->getAccountId()
            );
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            $response = $this->client->getResponse();
            return new MoneiAccount((array)$response);
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }

    /**
     * Get payment information
     * @param string $paymentId
     * @return array
     * @throws ApiException
     */
    public function getPaymentInformation(string $paymentId): MoneiAccount
    {
        try {
            $this->client->get(
                $this->config->getHost() . self::ENDPOINT . '?paymentId=' . $paymentId
            );
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            $response = $this->client->getResponse();
            return new MoneiAccount((array)$response);
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }
}
