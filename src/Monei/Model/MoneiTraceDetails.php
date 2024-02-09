<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiTraceDetails implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'ip' => 'ip',
        'country_code' => 'countryCode',
        'lang' => 'lang',
        'device_type' => 'deviceType',
        'device_model' => 'deviceModel',
        'browser' => 'browser',
        'browser_version' => 'browserVersion',
        'os' => 'os',
        'os_version' => 'osVersion',
        'source' => 'source',
        'source_version' => 'sourceVersion',
        'user_agent' => 'userAgent',
        'user_id' => 'userId',
        'user_email' => 'userEmail'
    ];

    private $attribute_type = [];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Get IP that created the payment
     * @return string
     */
    public function getIp(): string
    {
        return $this->container['ip'];
    }

    /**
     * Two letter country code
     * https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2
     * @return string
     */
    public function getCountryCode(): string
    {
        return $this->container['country_code'];
    }

    /**
     * Two letter language code
     * https://en.wikipedia.org/wiki/ISO_639-1
     * @return string
     */
    public function getLang(): string
    {
        return $this->container['lang'];
    }

    /**
     * Get Default that created the payment
     * desktop, mobile, smartTV, tablet
     * @return string
     */
    public function getDeviceType(): string
    {
        return $this->container['device_type'];
    }

    /**
     * Information about the device used for the browser session
     * @return string
     */
    public function getDeviceModel(): string
    {
        return $this->container['device_model'];
    }

    /**
     * The browser used in this browser session
     * @return string
     */
    public function getBrowser(): string
    {
        return $this->container['browser'];
    }

    /**
     * Browser version in this session
     * @return string
     */
    public function getBrowserVersion(): string
    {
        return $this->container['browser_version'];
    }

    /**
     * Operative System (Eg: iOS)
     * @return string
     */
    public function getOs(): string
    {
        return $this->container['os'];
    }

    /**
     * Operative System version (Eg: 14.0.0)
     * @return string
     */
    public function getOsVersion(): string
    {
        return $this->container['os_version'];
    }

    /**
     * The source component from where the operation was generated
     * @return string
     */
    public function getSource(): string
    {
        return $this->container['source'];
    }

    /**
     * The source component version from where the operation was generated
     * @return string
     */
    public function getSourceVersion(): string
    {
        return $this->container['source_version'];
    }

    /**
     * Full user agent string of the browser session
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->container['user_agent'];
    }

    /**
     * The ID of the user that started the operation
     * @return string
     */
    public function getUserId(): string
    {
        return $this->container['user_id'];
    }

    /**
     * The email of the user that started the operation
     * @return string
     */
    public function getUserEmail(): string
    {
        return $this->container['user_email'];
    }
}
