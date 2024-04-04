<?php


namespace Monei;

/**
 * Configuration class
 * @package Monei
 */
class Configuration
{
    protected $api_key = null;
    protected $account_id = null;
    protected $host = null;
    protected $user_agent = null;
    protected $debug = false;

    /**
     * Gets the API key
     * @return string|null
     */
    public function getApiKey(): ?string
    {
        return $this->api_key;
    }

    /**
     * Set an API Key
     * @param string $key
     * @return configuration
     */
    public function setApiKey(string $key): self
    {
        $this->api_key = $key;
        return $this;
    }

    /**
     * Get the account ID
     * @return null|string
     */
    public function getAccountId(): ?string
    {
        return $this->account_id;
    }

    /**
     * Set an account ID
     * @param string $account_id
     * @return configuration
     */
    public function setAccountId(string $account_id): self
    {
        $this->account_id = $account_id;
        return $this;
    }

    /**
     * Get hostname
     * @return null|string
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * Set a hostname to connect
     * @param string $host
     * @return configuration
     */
    public function setHost(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    /**
     * Gets the API client User-Agent
     * @return string|null
     */
    public function getUserAgent(): ?string
    {
        return $this->user_agent;
    }

    /**
     * Set the API client User-Agent
     * @param string $user_agent
     * @return configuration
     */
    public function setUserAgent(string $user_agent): self
    {
        $this->user_agent = $user_agent;
        return $this;
    }

    /**
     * Gets the current state for debug mode
     * @return bool
     */
    public function getDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Sets Debug mode
     * @param bool $debug
     * @return configuration
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }
}
