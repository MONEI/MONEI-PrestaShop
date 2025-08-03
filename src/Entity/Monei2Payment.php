<?php

namespace PsMonei\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 *
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiPaymentRepository")
 */
class Monei2Payment
{
    /**
     * @ORM\Id
     *
     * @ORM\Column(name="id_payment", type="string", length=50)
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     */
    private $id_cart;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $id_order;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $id_order_monei;

    /**
     * @ORM\Column(type="integer")
     */
    private $amount;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $refunded_amount;

    /**
     * @ORM\Column(type="string", length=3)
     */
    private $currency;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $authorization_code;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $status;

    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $is_captured = false;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $status_code;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $date_add;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $date_upd;

    /**
     * @ORM\OneToMany(targetEntity="PsMonei\Entity\Monei2History", cascade={"persist", "remove"}, mappedBy="payment")
     */
    private $historyList;

    /**
     * @ORM\OneToMany(targetEntity="PsMonei\Entity\Monei2Refund", cascade={"persist", "remove"}, mappedBy="payment")
     */
    private $refundList;

    public function __construct()
    {
        $this->historyList = new ArrayCollection();
        $this->refundList = new ArrayCollection();

        $this->setDateAdd(time());
        $this->setDateUpd(time());
    }

    public function isRefundable(): bool
    {
        $amount = $this->getAmount();
        if ($amount === null) {
            return false;
        }

        $refundedAmount = $this->getRefundedAmount() ?? 0;
        if ($refundedAmount < $amount) {
            return true;
        }

        return false;
    }

    public function getRemainingAmountToRefund(): int
    {
        $amount = $this->getAmount() ?? 0;
        $refundedAmount = $this->getRefundedAmount() ?? 0;

        return $amount - $refundedAmount;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getCartId(): ?int
    {
        return $this->id_cart;
    }

    public function setCartId(int $id_cart): self
    {
        $this->id_cart = $id_cart;

        return $this;
    }

    public function getOrderId(): ?int
    {
        return $this->id_order;
    }

    public function setOrderId(?int $id_order): self
    {
        $this->id_order = $id_order;

        return $this;
    }

    public function getOrderMoneiId(): ?string
    {
        return $this->id_order_monei;
    }

    public function setOrderMoneiId(?string $id_order_monei): self
    {
        $this->id_order_monei = $id_order_monei;

        return $this;
    }

    /**
     * @return int|float|null Returns int when $inDecimal is false, float when true, null if amount not set
     */
    public function getAmount(bool $inDecimal = false)
    {
        if ($this->amount === null) {
            return null;
        }

        return $inDecimal ? $this->amount / 100 : $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRefundedAmount(bool $inDecimal = false): ?float
    {
        return $inDecimal ? $this->refunded_amount / 100 : $this->refunded_amount;
    }

    public function setRefundedAmount(?int $refunded_amount): self
    {
        $this->refunded_amount = $refunded_amount;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getAuthorizationCode(): ?string
    {
        return $this->authorization_code;
    }

    public function setAuthorizationCode(?string $authorization_code): self
    {
        $this->authorization_code = $authorization_code;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getIsCaptured(): bool
    {
        return $this->is_captured;
    }

    public function setIsCaptured(bool $is_captured): self
    {
        $this->is_captured = $is_captured;

        return $this;
    }

    public function getStatusCode(): ?string
    {
        return $this->status_code;
    }

    public function setStatusCode(?string $status_code): self
    {
        $this->status_code = $status_code;

        return $this;
    }

    public function getDateAdd(): ?\DateTime
    {
        return $this->date_add;
    }

    public function getDateAddFormatted(): ?string
    {
        return $this->date_add ? $this->date_add->format('Y-m-d H:i:s') : null;
    }

    public function setDateAdd(?int $timestamp): self
    {
        $this->date_add = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;

        return $this;
    }

    public function getDateUpd(): ?\DateTime
    {
        return $this->date_upd;
    }

    public function getDateUpdFormatted(): ?string
    {
        return $this->date_upd ? $this->date_upd->format('Y-m-d H:i:s') : null;
    }

    public function setDateUpd(?int $timestamp): self
    {
        $this->date_upd = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;

        return $this;
    }

    /**
     * @return Collection<int, Monei2History>
     */
    public function getHistoryList(): Collection
    {
        return $this->historyList;
    }

    public function addHistory(Monei2History $paymentHistory)
    {
        $paymentHistory->setPayment($this);
        $this->historyList->add($paymentHistory);
    }

    /**
     * @return Collection<int, Monei2Refund>
     */
    public function getRefundList(): Collection
    {
        return $this->refundList;
    }

    public function getRefundByHistoryId(int $historyId): ?Monei2Refund
    {
        foreach ($this->refundList as $refund) {
            if ($refund->getHistory()->getId() === $historyId) {
                return $refund;
            }
        }

        return null;
    }

    public function addRefund(Monei2Refund $paymentRefund)
    {
        $paymentRefund->setPayment($this);
        $this->refundList->add($paymentRefund);
    }

    public function toArray()
    {
        return [
            'id' => $this->getId(),
            'cartId' => $this->getCartId(),
            'orderId' => $this->getOrderId(),
            'orderMoneiId' => $this->getOrderMoneiId(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'authorizationCode' => $this->getAuthorizationCode(),
            'status' => $this->getStatus(),
            'dateAdd' => $this->getDateAddFormatted(),
            'dateUpd' => $this->getDateUpdFormatted(),
        ];
    }

    public function toArrayLegacy()
    {
        return [
            'id_payment' => $this->getId(),
            'id_cart' => $this->getCartId(),
            'id_order' => $this->getOrderId(),
            'id_order_monei' => $this->getOrderMoneiId(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'authorization_code' => $this->getAuthorizationCode(),
            'status' => $this->getStatus(),
            'date_add' => $this->getDateAddFormatted(),
            'date_upd' => $this->getDateUpdFormatted(),
        ];
    }
}
