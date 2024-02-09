<?php


namespace Monei\Api;

use PsCurl\PsCurl as CurlCustom;
use Monei\ApiException;
use Monei\Configuration;
use Monei\Model\MoneiPayment;
use Monei\Model\MoneiRefundPayment;

class PaymentsApi
{
    public const ENDPOINT = '/payments';
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
     * Creates a new payment
     * @param MoneiPayment $payment
     * @return MoneiPayment
     * @throws ApiException
     */
    public function createPayment(MoneiPayment $payment): MoneiPayment
    {
        try {
            $this->client->post(
                $this->config->getHost() . self::ENDPOINT,
                json_encode($payment->toAPI())
            );
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            $response = $this->client->getResponse();
            return new MoneiPayment((array)$response);
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }

    /**
     * Gets an existent Payment information
     * @param string $id
     * @return MoneiPayment
     * @throws ApiException
     */
    public function getPayment(string $id): MoneiPayment
    {
        try {
            $this->client->get(
                $this->config->getHost() . self::ENDPOINT . '/' . $id
            );
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            $response = $this->client->getResponse();
            return new MoneiPayment((array)$response);
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }

    /**
     * Refunds an existent Payment
     * @param MoneiRefundPayment $refund
     * @return MoneiPayment
     * @throws ApiException
     */
    public function refundPayment(MoneiRefundPayment $refund): MoneiPayment
    {
        try {
            $this->client->post(
                $this->config->getHost() . self::ENDPOINT . '/' . $refund->getId() . '/refund',
                json_encode($refund->toAPI())
            );
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            $response = $this->client->getResponse();
            return new MoneiPayment((array)$response);
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }

    /**
     * Cancel a blocked Payment
     * @param string $id
     * @return MoneiPayment
     * @throws ApiException
     */
    public function cancelPayment(
        string $id,
        string $cancellationReason = null
    ): MoneiPayment
    {
        $cancellationReason = $cancellationReason ?? 'Cancelled by merchant'; // PS Validator requires this

        try {
            $this->client->post(
                $this->config->getHost() . self::ENDPOINT . '/' . $id . '/cancel',
                [
                    'id' => $id
                ]
            );
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            $response = $this->client->getResponse();
            return new MoneiPayment((array)$response);
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }
}
