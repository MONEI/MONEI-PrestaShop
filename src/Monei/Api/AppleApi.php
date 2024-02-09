<?php


namespace Monei\Api;

use Monei\ApiException;
use Monei\Configuration;
use PsCurl\PsCurl as CurlCustom;

class AppleApi
{
    public const ENDPOINT = '/apple-pay/domains';
    protected $client;
    protected $config;

    /**
     * Creates a new AppleApi client
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
     * Registers custom domain for Apple Pay
     * @param string $domain
     * @return AppleApi
     * @throws ApiException
     */
    public function register(string $domain): AppleApi
    {
        try {
            if ($this->checkDomainAssociation()) {
                $this->client->post(
                    $this->config->getHost() . self::ENDPOINT,
                    [
                        'domainName' => $domain,
                    ]
                );
            } else {
                throw new ApiException(
                    'Unable to copy required files to .well-known directory',
                    500
                );
            }
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }

        if ((int)$this->client->getHttpStatusCode() === 200) {
            return $this;
        } else {
            throw new ApiException(
                property_exists($this->client->getResponse(), 'message') ?
                    $this->client->getResponse()->message : $this->client->getErrorMessage(),
                (int)$this->client->getHttpStatusCode()
            );
        }
    }

    /**
     * Checks if apple files are already copied to .well-known directory
     * @return bool
     */
    private function checkDomainAssociation(): bool
    {
        if (file_exists(_PS_ROOT_DIR_ . '/.well-known/apple-developer-merchantid-domain-association')) {
            return true;
        } else {
            return $this->copyDomainAssociation();
        }
    }

    /**
     * Copies required files to .well-known directory
     * @return bool
     */
    private function copyDomainAssociation(): bool
    {
        try {
            if (!file_exists(_PS_ROOT_DIR_ . '/.well-known')) {
                $create_dir = mkdir(_PS_ROOT_DIR_ . '/.well-known');
                if (!$create_dir) {
                    return false;
                }
            }

            if (!copy(
                _PS_MODULE_DIR_ . '/monei/files/apple-developer-merchantid-domain-association',
                _PS_ROOT_DIR_ . '/.well-known/apple-developer-merchantid-domain-association'
            )) {
                return false;
            }

            return true;
        } catch (\Exception $ex) {
            return false;
        }
    }
}
