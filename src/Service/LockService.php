<?php

namespace PsMonei\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Service for managing database locks to prevent concurrent processing
 * Uses MySQL's GET_LOCK/RELEASE_LOCK functions
 */
class LockService
{
    /**
     * Default lock timeout in seconds
     */
    private const DEFAULT_TIMEOUT = 10;

    /**
     * Lock name prefix to avoid conflicts
     */
    private const LOCK_PREFIX = 'monei_';

    /**
     * Acquire a named lock
     *
     * @param string $lockName The name of the lock
     * @param int $timeout Timeout in seconds (default: 10)
     *
     * @return bool True if lock acquired, false otherwise
     */
    public function acquireLock(string $lockName, int $timeout = self::DEFAULT_TIMEOUT): bool
    {
        $fullLockName = self::LOCK_PREFIX . $lockName;

        try {
            $sql = "SELECT GET_LOCK('" . pSQL($fullLockName) . "', " . (int) $timeout . ') AS lock_result';
            $result = \Db::getInstance()->getValue($sql);

            // GET_LOCK returns 1 if lock acquired, 0 if timeout, NULL if error
            return $result === '1' || $result === 1;
        } catch (\PrestaShopException $e) {
            \PrestaShopLogger::addLog(
                'MONEI - LockService - Failed to acquire lock: ' . $e->getMessage(),
                \Monei::getLogLevel('error')
            );

            return false;
        }
    }

    /**
     * Release a named lock
     *
     * @param string $lockName The name of the lock
     *
     * @return bool True if lock released, false otherwise
     */
    public function releaseLock(string $lockName): bool
    {
        $fullLockName = self::LOCK_PREFIX . $lockName;

        try {
            $sql = "SELECT RELEASE_LOCK('" . pSQL($fullLockName) . "') AS release_result";
            $result = \Db::getInstance()->getValue($sql);

            // RELEASE_LOCK returns 1 if lock released, 0 if lock not held, NULL if lock doesn't exist
            return $result === '1' || $result === 1;
        } catch (\PrestaShopException $e) {
            \PrestaShopLogger::addLog(
                'MONEI - LockService - Failed to release lock: ' . $e->getMessage(),
                \Monei::getLogLevel('error')
            );

            return false;
        }
    }

    /**
     * Check if a lock is free
     *
     * @param string $lockName The name of the lock
     *
     * @return bool True if lock is free, false if held
     */
    public function isLockFree(string $lockName): bool
    {
        $fullLockName = self::LOCK_PREFIX . $lockName;

        try {
            $sql = "SELECT IS_FREE_LOCK('" . pSQL($fullLockName) . "') AS is_free";
            $result = \Db::getInstance()->getValue($sql);

            // IS_FREE_LOCK returns 1 if free, 0 if held, NULL if error
            return $result === '1' || $result === 1;
        } catch (\PrestaShopException $e) {
            \PrestaShopLogger::addLog(
                'MONEI - LockService - Failed to check lock status: ' . $e->getMessage(),
                \Monei::getLogLevel('warning')
            );

            return true; // Assume free on error to avoid blocking
        }
    }

    /**
     * Execute a callback with lock protection
     *
     * @param string $lockName The name of the lock
     * @param callable $callback The callback to execute
     * @param int $timeout Lock timeout in seconds
     *
     * @return mixed The result of the callback
     *
     * @throws \PrestaShopException If lock cannot be acquired
     */
    public function executeWithLock(string $lockName, callable $callback, int $timeout = self::DEFAULT_TIMEOUT)
    {
        if (!$this->acquireLock($lockName, $timeout)) {
            throw new \PrestaShopException('Unable to acquire lock: ' . $lockName);
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Wait for a lock to become available
     *
     * @param string $lockName The name of the lock
     * @param int $maxWaitTime Maximum wait time in seconds
     * @param int $checkInterval Check interval in milliseconds
     *
     * @return bool True if lock became available, false if timeout
     */
    public function waitForLock(string $lockName, int $maxWaitTime = 30, int $checkInterval = 100): bool
    {
        $startTime = time();

        while (time() - $startTime < $maxWaitTime) {
            if ($this->isLockFree($lockName)) {
                return true;
            }

            usleep($checkInterval * 1000); // Convert milliseconds to microseconds
        }

        return false;
    }
}
