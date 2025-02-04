<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiPaymentRepository")
 */
class MoPayment
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
     * @ORM\OneToMany(targetEntity="PsMonei\Entity\MoHistory", cascade={"persist", "remove"}, mappedBy="payment")
     */
    private $paymentHistory;

    /**
     * @ORM\OneToMany(targetEntity="PsMonei\Entity\MoRefund", cascade={"persist", "remove"}, mappedBy="payment")
     */
    private $paymentRefund;

    public function __construct()
    {
        $this->paymentHistory = new ArrayCollection();
        $this->paymentRefund = new ArrayCollection();
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

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;
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

    public function setDateAdd(?int $timestamp): self
    {
        $this->date_add = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;
        return $this;
    }

    public function getDateUpd(): ?\DateTime
    {
        return $this->date_upd;
    }

    public function setDateUpd(?int $timestamp): self
    {
        $this->date_upd = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;
        return $this;
    }

    public function getHistory()
    {
        return $this->paymentHistory;
    }

    public function getHistoryFormatted()
    {
        dump($this->paymentHistory);die;
        $historyList = $this->paymentHistory;
        if (!$historyList) {
            return [];
        }

        $historyListFormatted = [];

        foreach ($historyList as $history) {
            $historyListFormatted[] = json_encode($history);
        }
        return $historyListFormatted;
    }

    public function addHistory(MoHistory $paymentHistory)
    {
        $paymentHistory->setPayment($this);
        $this->paymentHistory->add($paymentHistory);
    }

    public function getRefunds()
    {
        return $this->paymentRefund;
    }

    public function getRefundsFormatted()
    {
        $refundList = $this->paymentRefund;
        if (!$refundList) {
            return [];
        }

        $refundListFormatted = [];
        foreach ($refundList as $refund) {
            $refundListFormatted[] = json_encode($refund);
        }
        return $refundListFormatted;
    }

    public function addRefund(MoRefund $paymentRefund)
    {
        $paymentRefund->setPayment($this);
        $this->paymentRefund->add($paymentRefund);
    }
}
