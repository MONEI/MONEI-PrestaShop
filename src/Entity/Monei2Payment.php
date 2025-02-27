<?php
namespace PsMonei\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiPaymentRepository")
 */
class Monei2Payment
{
    /**
     * @ORM\Id
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
     * @ORM\Column(type="integer")
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
        if ($this->getRefundedAmount() < $this->getAmount()) {
            return true;
        }

        return false;
    }

    public function getRemainingAmountToRefund(): int
    {
        return $this->getAmount() - $this->getRefundedAmount();
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

    public function getAmount(bool $inDecimal = false): ?int
    {
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

    public function getDateAdd(): ?\DateTime
    {
        return $this->date_add;
    }

    public function getDateAddFormatted(): ?string
    {
        return $this->date_add->format('Y-m-d H:i:s');
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
        return $this->date_upd->format('Y-m-d H:i:s');
    }

    public function setDateUpd(?int $timestamp): self
    {
        $this->date_upd = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;

        return $this;
    }

    public function getHistoryList()
    {
        return $this->historyList;
    }

    public function addHistory(Monei2History $paymentHistory)
    {
        $paymentHistory->setPayment($this);
        $this->historyList->add($paymentHistory);
    }

    public function getRefundList()
    {
        return $this->refundList;
    }

    public function getRefundByHistoryId($historyId)
    {
        foreach ($this->refundList as $refund) {
            if ($refund->getHistory()->getId() === (int) $historyId) {
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
