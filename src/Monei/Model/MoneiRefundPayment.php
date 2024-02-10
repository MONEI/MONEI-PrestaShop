<?php


namespace Monei\Model;

use Monei\ApiException;
use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiRefundPayment implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'id' => 'id',
        'amount' => 'amount',
        'refund_reason' => 'refundReason'
    ];

    private $attribute_type = [];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Gets the current Payment ID
     * @return null|string
     */
    public function getId(): ?string
    {
        return array_key_exists('id', $this->container) ? $this->container['id'] : null;
    }

    /**
     * Sets the current Payment ID
     * @param string $id
     * @return MoneiRefundPayment
     */
    public function setId(string $id): self
    {
        $this->container['id'] = $id;
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
     * Gets the refund reason
     * @return string
     */
    public function getRefundReason(): string
    {
        return $this->container['refund_reason'];
    }

    /**
     * Sets the refund reason
     * @param string $refund_reason
     * @return MoneiRefundPayment
     * @throws ApiException
     */
    public function setRefundReason(string $refund_reason): self
    {
        // Check if $refund_reason is one of the allowed values
        if (!in_array($refund_reason, MoneiRefundReason::getAllowableEnumValues())) {
            throw new ApiException(
                sprintf(
                    "Invalid value for 'refund_reason', must be one of '%s'",
                    implode("', '", MoneiRefundReason::getAllowableEnumValues())
                ),
                500
            );
        }

        $this->container['refund_reason'] = $refund_reason;
        return $this;
    }
}
