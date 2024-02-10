<?php


namespace Monei;

use Monei\Api\AppleApi;
use Monei\Api\PaymentsApi;

class MoneiClient
{
    public const VERSION = '1.0.0';
    /**
     * @var PaymentsApi
     */
    public $payments;
    /**
     * @var AppleApi
     */
    public $apple;
    protected $config;

    /**
     * @param string $api_key
     * @param Configuration|null $config
     * @return void
     */
    public function __construct(
        string        $api_key,
        Configuration $config = null
    )
    {
        if (!$config) {
            // Set default configuration
            $config = new Configuration();
            $this->config = $config->setUserAgent('MONEI/PrestaShop/' . self::VERSION);
            $this->config = $config->setHost('https://api.monei.com/v1');
        }
        $this->config = $config->setApiKey($api_key);
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
}
