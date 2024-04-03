<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiPayment implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'id' => 'id',
        'amount' => 'amount',
        'currency' => 'currency',
        'order_id' => 'orderId',
        'description' => 'description',
        'account_id' => 'accountId',
        'authorization_code' => 'authorizationCode',
        'livemode' => 'livemode',
        'status' => 'status',
        'status_code' => 'statusCode',
        'status_message' => 'statusMessage',
        'customer' => 'customer',
        'shop' => 'shop',
        'billing_details' => 'billingDetails',
        'shipping_details' => 'shippingDetails',
        'refunded_amount' => 'refundedAmount',
        'last_refund_amount' => 'lastRefundAmount',
        'last_refund_reason' => 'lastRefundReason',
        'cancellation_reason' => 'cancellationReason',
        'session_details' => 'sessionDetails',
        'trace_details' => 'traceDetails',
        'payment_token' => 'paymentToken',
        'payment_method' => 'paymentMethod',
        'sequence' => 'sequence',
        'sequence_id' => 'sequenceId',
        'point_of_sale_id' => 'pointOfSaleId',
        'next_action' => 'nextAction',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
        // Not in response
        'session_id' => 'sessionId',
        'allowed_payment_methods' => 'allowedPaymentMethods',
        'complete_url' => 'completeUrl',
        'callback_url' => 'callbackUrl',
        'fail_url' => 'failUrl',
        'cancel_url' => 'cancelUrl',
        'generate_payment_token' => 'generatePaymentToken',
        'expire_at' => 'expireAt',
    ];

    private $attribute_type = [
        //'status' => '\Monei\Model\PaymentStatus',
        'customer' => '\Monei\Model\MoneiCustomer',
        'shop' => '\Monei\Model\MoneiShop',
        'trace_details' => '\Monei\Model\MoneiTraceDetails',
        'billing_details' => '\Monei\Model\MoneiBillingDetails',
        'shipping_details' => '\Monei\Model\MoneiShippingDetails',
        'next_action' => '\Monei\Model\MoneiNextAction',
        'payment_method' => '\Monei\Model\MoneiPaymentMethod'
        /*'last_refund_reason' => '\Monei\Model\PaymentLastRefundReason',
        'cancellation_reason' => '\Monei\Model\PaymentCancellationReason',
        'session_details' => '\Monei\Model\PaymentSessionDetails',
        'sequence' => '\Monei\Model\PaymentSequence',
        'next_action' => '\Monei\Model\PaymentNextAction'
        */
    ];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
        $this->withPaymentToken(false);
    }

    /**
     * Sets if we must generate a tokenized payment
     * @param bool $with_token
     * @return MoneiPayment
     */
    public function withPaymentToken(bool $with_token = false): self
    {
        $this->container['generate_payment_token'] = $with_token;
        return $this;
    }

    /**
     * Gets the current Payment ID
     * @return null|string
     */
    public function getId(): ?string
    {
        return $this->getContainerValue('id') ?? null;
    }

    /**
     * Gets the current Session ID
     * @param string $session_id
     * @return MoneiPayment
     */
    public function setSessionId(string $session_id): self
    {
        $this->container['session_id'] = $session_id;
        return $this;
    }

    /**
     * Get the livemode
     * @return bool
     */
    public function getLiveMode(): bool
    {
        return (bool)$this->container['live_mode'];
    }

    /**
     * Sets Live/Demo mode
     * @param bool $live
     * @return MoneiPayment
     */
    public function setLiveMode(bool $live = false): self
    {
        $this->container['live_mode'] = $live;
        return $this;
    }

    /**
     * Get Amount for the payment
     * @return int
     */
    public function getAmount(): int
    {
        return (int)$this->container['amount'];
    }

    /**
     * Sets the amount for the payment
     * @param int $amount
     * @return MoneiPayment
     */
    public function setAmount(int $amount): self
    {
        $this->container['amount'] = (int)$amount;
        return $this;
    }

    /**
     * Get Currency for the payment (ISO CODE)
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->container['currency'];
    }

    /**
     * Sets de ISO code for the currency
     * @param string $currency
     * @return MoneiPayment
     */
    public function setCurrency(string $currency): self
    {
        $this->container['currency'] = $currency;
        return $this;
    }

    /**
     * Get Order ID for the payment
     * @return string
     */
    public function getOrderId(): string
    {
        return $this->container['order_id'];
    }

    /**
     * Sets an order ID
     * @param string $order_id
     * @return MoneiPayment
     */
    public function setOrderId(string $order_id): self
    {
        $this->container['order_id'] = $order_id;
        return $this;
    }

    /**
     * Get description for the payment
     * @return string
     */
    public function getDescription(): string
    {
        return $this->container['description'];
    }

    /**
     * Sets description for the payment
     * @param string $description
     * @return MoneiPayment
     */
    public function setDescription(string $description): self
    {
        $this->container['description'] = $description;
        return $this;
    }

    /**
     * Get the account for the payment
     * @return string
     */
    public function getAccountId(): string
    {
        return $this->container['account_id'];
    }

    /**
     * Get the authorization code for the payment
     * @return null|string
     */
    public function getAuthorizationCode(): ?string
    {
        return $this->container['authorization_code'];
    }

    /**
     * Get payment status
     * @return null|string
     */
    public function getStatus(): ?string
    {
        return array_key_exists('status', $this->container) ? $this->container['status'] : null;
    }

    /**
     * Payment status code
     * @return string
     */
    public function getStatusCode(): ?string
    {
        return $this->getContainerValue('status_code') ?? null;
    }

    /**
     * Human readable status message
     * @return string
     */
    public function getStatusMessage(): ?string
    {
        return $this->getContainerValue('status_message') ?? null;
    }

    /**
     * Get the customer Object
     * @return MoneiCustomer
     */
    public function getCustomer(): MoneiCustomer
    {
        return $this->container['customer'];
    }

    /**
     * Sets the customer Information
     * @param MoneiCustomer $customer
     * @return MoneiPayment
     */
    public function setCustomer(MoneiCustomer $customer): self
    {
        $this->container['customer'] = $customer;
        return $this;
    }

    /**
     * Gets Shop instance
     * @return MoneiShop
     */
    public function getShop(): MoneiShop
    {
        return $this->container['shop'];
    }

    /**
     * Sets the shop information
     * @param MoneiShop $shop
     * @return MoneiPayment
     */
    public function setShop(MoneiShop $shop): self
    {
        $this->container['shop'] = $shop;
        return $this;
    }

    /**
     * Gets billing details
     * @return MoneiBillingDetails
     */
    public function getBillingDetails(): MoneiBillingDetails
    {
        return $this->container['billing_details'];
    }

    /**
     * Sets the billing details object
     * @param MoneiBillingDetails $billing
     * @return MoneiPayment
     */
    public function setBillingDetails(MoneiBillingDetails $billing): self
    {
        $this->container['billing_details'] = $billing;
        return $this;
    }

    /**
     * Get shipping details object
     * @return MoneiShippingDetails
     */
    public function getShippingDetails(): MoneiShippingDetails
    {
        return $this->container['shipping_details'];
    }

    /**
     * Sets the billing detail object
     * @param MoneiShippingDetails $shipping
     * @return MoneiPayment
     */
    public function setShippingDetails(MoneiShippingDetails $shipping): self
    {
        $this->container['shipping_details'] = $shipping;
        return $this;
    }

    /**
     * Gets trace details
     * @return MoneiTraceDetails
     */
    public function getTraceDetails(): MoneiTraceDetails
    {
        return $this->container['trace_details'];
    }

    /**
     * Gets tokenizen card
     * @return string
     */
    public function getPaymentToken(): ?string
    {
        return $this->getContainerValue('payment_token') ?? null;
    }

    /**
     * Sets the URL to redirect if payments goes OK
     * @param string $complete_url
     * @return MoneiPayment
     */
    public function setCompleteUrl(string $complete_url): self
    {
        $this->container['complete_url'] = $complete_url;
        return $this;
    }

    /**
     * Sets the callback URL to notify the payment status
     * @param string $callback_url
     * @return MoneiPayment
     */
    public function setCallbackUrl(string $callback_url): self
    {
        $this->container['callback_url'] = $callback_url;
        return $this;
    }

    /**
     * Sets the URL to redirect if payment goes wrong
     * @param string $fail_url
     * @return MoneiPayment
     */
    public function setFailUrl(string $fail_url): self
    {
        $this->container['fail_url'] = $fail_url;
        return $this;
    }

    /**
     * Sets the URL to redirect if payment is cancelled by the customer
     * @param string $cancel_url
     * @return MoneiPayment
     */
    public function setCancelUrl(string $cancel_url): self
    {
        $this->container['cancel_url'] = $cancel_url;
        return $this;
    }

    /**
     * Configure the allowed payment methods, must be one of:
     * card, bizum, applePay, googlePay, clickToPay, paypal, cofifis
     * @param array $payment_methods
     * @return MoneiPayment
     */
    public function setAllowedPaymentMethods(array $payment_methods): self
    {
        /** TODO, CHECK ENUMS */
        $this->container['allowed_payment_methods'] = $payment_methods;
        return $this;
    }

    public function getAllowedPaymentMethods(): array
    {
        return $this->getContainerValue('allowed_payment_methods') ?? [];
    }

    public function isPaymentMethodAllowed(string $paymentMethod): bool
    {
        if (is_array($this->getContainerValue('allowed_payment_methods'))) {
            return in_array($paymentMethod, $this->getContainerValue('allowed_payment_methods'));
        }

        return false;
    }

    /**
     * Gets the next action to take for the payment
     * @return MoneiNextAction
     */
    public function getNextAction(): MoneiNextAction
    {
        return $this->container['next_action'];
    }

    /**
     * Gets the creation date UNIX epoch
     * @return int
     */
    public function getCreatedAt(): int
    {
        return (int)$this->container['created_at'];
    }

    /**
     * Gets the update date UNIX epoch
     * @return int
     */
    public function getUpdatedAt(): int
    {
        return (int)$this->container['updated_at'];
    }

    /**
     * Gets the total refunded for this order
     * @return int
     */
    public function getRefundedAmount(): int
    {
        return (int)$this->getContainerValue('refunded_amount') ?? 0;
    }

    /**
     * Gets the enum reason for the refund
     * @return string
     */
    public function getLastRefundReason(): string
    {
        return $this->getContainerValue('last_refund_reason') ?? '';
    }

    /**
     * Gets the amount for the last refund
     * @return int
     */
    public function getLastRefundAmount(): int
    {
        return (int)$this->getContainerValue('last_refund_amount') ?? 0;
    }

    /**
     * Get the current status for payment tokenization
     * @return bool
     */
    public function getGeneratePaymentToken(): bool
    {
        return $this->getContainerValue('generate_payment_token') ?? false;
    }

    /**
     * Sets the payment tokenization status
     * @param bool $generate_payment_token
     * @return MoneiPayment
     */
    public function setGeneratePaymentToken(bool $generate_payment_token): self
    {
        $this->container['generate_payment_token'] = $generate_payment_token;
        return $this;
    }

    /**
     * gets PaymentMethod information (including Card)
     * @return MoneiPaymentMethod|null
     */
    public function getPaymentMethod(): ?MoneiPaymentMethod
    {
        return $this->getContainerValue('payment_method') ?? null;
    }

    /**
     * Sets a payment token to use
     * @param string $payment_token
     * @return MoneiPayment
     */
    public function setPaymentToken(string $payment_token): self
    {
        $this->container['payment_token'] = $payment_token;
        return $this;
    }

    /**
     * Gets the expiration time for a payment
     * @return int
     */
    public function getExpireAt(): int
    {
        return (int)$this->container['expire_at'];
    }

    /**
     * Sets the expiration time for a payment
     * @param int $expiration_time
     * @return MoneiPayment
     */
    public function setExpireAt(int $expiration_time): self
    {
        $this->container['expire_at'] = $expiration_time;
        return $this;
    }
}
