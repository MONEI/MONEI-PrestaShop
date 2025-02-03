<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;
use OpenAPI\Client\Model\Payment;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiPaymentRepository")
 */
class MoPayment
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=50)
     */
    private $id_payment;

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



    public function getPaymentId(): string
    {
        return $this->id_payment;
    }

    public function setPaymentId(string $id_payment): self
    {
        $this->id_payment = $id_payment;
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
}
