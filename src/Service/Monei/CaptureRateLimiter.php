<?php

namespace PsMonei\Service\Monei;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Simple rate limiter for capture attempts
 */
class CaptureRateLimiter
{
    const CACHE_KEY_PREFIX = 'monei_capture_rate_';
    const MAX_ATTEMPTS = 3;
    const WINDOW_SECONDS = 300; // 5 minutes

    /**
     * Check if capture attempt is allowed
     *
     * @param int $orderId
     *
     * @return bool
     */
    public function isAllowed(int $orderId): bool
    {
        $key = self::CACHE_KEY_PREFIX . $orderId;
        $attempts = $this->getAttempts($key);

        return $attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Record a capture attempt
     *
     * @param int $orderId
     */
    public function recordAttempt(int $orderId): void
    {
        $key = self::CACHE_KEY_PREFIX . $orderId;
        $attempts = $this->getAttempts($key);

        $this->setAttempts($key, $attempts + 1);
    }

    /**
     * Get remaining attempts
     *
     * @param int $orderId
     *
     * @return int
     */
    public function getRemainingAttempts(int $orderId): int
    {
        $key = self::CACHE_KEY_PREFIX . $orderId;
        $attempts = $this->getAttempts($key);

        return max(0, self::MAX_ATTEMPTS - $attempts);
    }

    /**
     * Get attempts from cache
     *
     * @param string $key
     *
     * @return int
     */
    private function getAttempts(string $key): int
    {
        // Using PrestaShop's cache system if available
        if (class_exists('Cache')) {
            $cached = \Cache::getInstance()->get($key);
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if ($data && isset($data['attempts'], $data['expires'])) {
                    if ($data['expires'] > time()) {
                        return (int) $data['attempts'];
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Set attempts in cache
     *
     * @param string $key
     * @param int $attempts
     */
    private function setAttempts(string $key, int $attempts): void
    {
        if (class_exists('Cache')) {
            $data = [
                'attempts' => $attempts,
                'expires' => time() + self::WINDOW_SECONDS,
            ];

            \Cache::getInstance()->set($key, json_encode($data));
        }
    }
}
