<?php


namespace Monei;

use Monei\Api\AppleApi;
use Monei\Api\PaymentMethodsApi;
use Monei\Api\PaymentsApi;

class MoneiClient
{
    public const VERSION = '1.4.6';
    /**
     * @var PaymentsApi
     */
    public $payments;
    /**
     * @var AppleApi
     */
    public $apple;
    /**
     * @var MoneiAccount
     */
    public $account;

    protected $config;

    /**
     * @param string $api_key
     * @param Configuration|null $config
     * @return void
     */
    public function __construct(
        string $api_key,
        string $account_id,
        Configuration $config = null
    )
    {
        if (!$config) {
            // Set default configuration
            $config = new Configuration();
            $this->config = $config->setUserAgent('MONEI/PrestaShop/' . self::VERSION);
            $this->config = $config->setHost('https://api.monei.com/v1');
        }
        $config->setApiKey($api_key);
        $config->setAccountId($account_id);

        $this->config = $config;
        $this->payments = new PaymentsApi($this->config);
        $this->apple = new AppleApi($this->config);
    }

    /**
     * Returns configuration instance
     * @return Configuration
     */
    public function getConfig(): Configuration
    {
        return $this->config;
    }

    /**
     * @param string    $body
     * @param string    $signature
     * @return object
     */
    public function verifySignature($body, $signature)
    {
        $parts = array_reduce(explode(',', $signature), function ($result, $part) {
            [$key, $value] = explode('=', $part);
            $result[$key] = $value;
            return $result;
        }, []);

        $hmac = hash_hmac('SHA256', $parts['t'] . '.' . $body, $this->config->getApiKey('Authorization'));

        if ($hmac !== $parts['v1']) {
            throw new ApiException('[401] Signature verification failed', 401);
        }

        return json_decode($body);
    }

    public function getMoneiAccount()
    {
        return new PaymentMethodsApi($this->config);
    }
}
