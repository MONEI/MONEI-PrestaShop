<?php

namespace PsMonei\Service\Monei;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\MoneiClient;
use PsMonei\Exception\MoneiException;

/**
 * Abstract base class for MONEI API services
 *
 * Provides common functionality for all MONEI API service classes,
 * reducing boilerplate code and standardizing error handling.
 */
abstract class AbstractApiService
{
    /**
     * Get MONEI client instance
     *
     * @param bool|null $forceMode Optional: true for production, false for test, null for configured mode
     * @return MoneiClient
     * @throws MoneiException
     */
    protected function getMoneiClient(?bool $forceMode = null)
    {
        $isLiveMode = $forceMode ?? (bool) \Configuration::get('MONEI_PRODUCTION_MODE');
        $apiKey = $isLiveMode 
            ? \Configuration::get('MONEI_API_KEY') 
            : \Configuration::get('MONEI_TEST_API_KEY');

        if (empty($apiKey)) {
            throw new MoneiException('API key is not configured', MoneiException::API_KEY_NOT_CONFIGURED);
        }

        $client = new MoneiClient($apiKey);
        $client->setUserAgent('MONEI/PrestaShop/' . _PS_VERSION_);
        
        return $client;
    }

    /**
     * Execute API call with standardized error handling and logging
     *
     * @param string $operation Name of the operation (for logging)
     * @param callable $apiCall Callable that performs the API call
     * @param array $logContext Additional context data for logging
     * @return mixed Response from the API call
     * @throws MoneiException
     */
    protected function executeApiCall(string $operation, callable $apiCall, array $logContext = [])
    {
        $startTime = microtime(true);
        
        // Log the API request
        \PrestaShopLogger::addLog(
            sprintf('[%s] API Request - Context: %s', $operation, json_encode($logContext)),
            \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        try {
            $result = $apiCall();
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log successful response
            \PrestaShopLogger::addLog(
                sprintf('[%s] API Response - Success (%.2fms)', $operation, $executionTime),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
            
            return $result;
        } catch (\Monei\ApiException $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log API error with details
            \PrestaShopLogger::addLog(
                sprintf(
                    '[%s] API Error - Code: %s, Message: %s, Execution time: %.2fms, Context: %s',
                    $operation,
                    $e->getCode(),
                    $e->getMessage(),
                    $executionTime,
                    json_encode($logContext)
                ),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
            
            throw new MoneiException(
                sprintf('MONEI API error: %s', $e->getMessage()),
                MoneiException::CAPTURE_FAILED
            );
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log unexpected error
            \PrestaShopLogger::addLog(
                sprintf(
                    '[%s] Unexpected Error - Type: %s, Message: %s, Execution time: %.2fms, Context: %s',
                    $operation,
                    get_class($e),
                    $e->getMessage(),
                    $executionTime,
                    json_encode($logContext)
                ),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
            
            throw new MoneiException(
                sprintf('Unexpected error in %s: %s', $operation, $e->getMessage()),
                MoneiException::CAPTURE_FAILED
            );
        }
    }

    /**
     * Validate required parameters
     *
     * @param array $data Data to validate
     * @param array $requiredParams List of required parameters
     * @throws MoneiException If validation fails
     */
    protected function validateParams(array $data, array $requiredParams): void
    {
        foreach ($requiredParams as $param) {
            if (!isset($data[$param]) || $data[$param] === '' || ($data[$param] !== 0 && $data[$param] !== false && empty($data[$param]))) {
                throw new MoneiException(
                    sprintf('Required parameter "%s" is missing or empty', $param),
                    MoneiException::PAYMENT_REQUEST_NOT_VALID
                );
            }
        }
    }

    /**
     * Sanitize error message for logging
     *
     * @param string $message Error message to sanitize
     * @return string Sanitized message
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Remove sensitive data patterns
        $patterns = [
            '/Bearer\s+[A-Za-z0-9\-._~\+\/]+=*/i' => 'Bearer [REDACTED]',
            '/pk_[a-zA-Z0-9]{32}/' => 'pk_[REDACTED]',
            '/sk_[a-zA-Z0-9]{32}/' => 'sk_[REDACTED]',
            '/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i' => '[UUID_REDACTED]',
        ];
        
        return preg_replace(array_keys($patterns), array_values($patterns), $message);
    }
}