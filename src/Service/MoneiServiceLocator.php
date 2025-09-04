<?php

namespace PsMonei\Service;

use PsMonei\Entity\Monei2CustomerCard;
use PsMonei\Entity\Monei2History;
use PsMonei\Entity\Monei2Payment;
use PsMonei\Entity\Monei2Refund;
use PsMonei\Helper\PaymentMethodFormatter;
use PsMonei\Service\Monei\MoneiService;
use PsMonei\Service\Monei\StatusCodeHandler;
use PsMonei\Service\Order\OrderService;
use PsMonei\Service\Payment\PaymentOptionService;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Service Locator to replace PS8's service container for PS1.7 compatibility
 */
class MoneiServiceLocator
{
    private static $instances = [];

    /**
     * Get MoneiService instance
     */
    public static function getMoneiService()
    {
        if (!isset(self::$instances['monei_service'])) {
            self::$instances['monei_service'] = new MoneiService(
                \Context::getContext(),
                new Monei2Payment(),
                new Monei2CustomerCard(),
                new Monei2Refund(),
                new Monei2History()
            );
        }

        return self::$instances['monei_service'];
    }

    /**
     * Get OrderService instance
     */
    public static function getOrderService()
    {
        if (!isset(self::$instances['order_service'])) {
            $module = \Module::getInstanceByName('monei');
            self::$instances['order_service'] = new OrderService(
                $module,
                self::getMoneiService(),
                self::getPaymentMethodFormatter(),
                self::getLockService(),
                \Context::getContext()
            );
        }

        return self::$instances['order_service'];
    }

    /**
     * Get PaymentOptionService instance
     */
    public static function getPaymentOptionService()
    {
        if (!isset(self::$instances['payment_option_service'])) {
            self::$instances['payment_option_service'] = new PaymentOptionService(
                self::getMoneiService(),
                new Monei2CustomerCard(),
                \Configuration::class,  // Pass the Configuration class name for static calls
                \Context::getContext(),
                self::getPaymentMethodFormatter()
            );
        }

        return self::$instances['payment_option_service'];
    }

    /**
     * Get LockService instance
     */
    public static function getLockService()
    {
        if (!isset(self::$instances['lock_service'])) {
            self::$instances['lock_service'] = new LockService();
        }

        return self::$instances['lock_service'];
    }

    /**
     * Get PaymentMethodFormatter instance
     */
    public static function getPaymentMethodFormatter()
    {
        if (!isset(self::$instances['payment_method_formatter'])) {
            self::$instances['payment_method_formatter'] = new PaymentMethodFormatter();
        }

        return self::$instances['payment_method_formatter'];
    }

    /**
     * Get StatusCodeHandler instance
     */
    public static function getStatusCodeHandler()
    {
        if (!isset(self::$instances['status_code_handler'])) {
            $module = \Module::getInstanceByName('monei');
            self::$instances['status_code_handler'] = new StatusCodeHandler($module);
        }

        return self::$instances['status_code_handler'];
    }

    /**
     * Generic getter for backward compatibility with getService calls
     * Maps old service names to new service locator methods
     */
    public static function getService($serviceName)
    {
        // Remove 'monei.' prefix if present
        $serviceName = str_replace('monei.', '', $serviceName);

        switch ($serviceName) {
            case 'service.monei':
                return self::getMoneiService();
            case 'service.order':
                return self::getOrderService();
            case 'service.payment.option':
                return self::getPaymentOptionService();
            case 'service.lock':
                return self::getLockService();
            case 'helper.payment_method_formatter':
                return self::getPaymentMethodFormatter();
            case 'service.status_code_handler':
                return self::getStatusCodeHandler();
            default:
                throw new \Exception("Service '{$serviceName}' not found in MoneiServiceLocator");
        }
    }

    /**
     * Clear all service instances (useful for testing)
     */
    public static function clearInstances()
    {
        self::$instances = [];
    }
}
