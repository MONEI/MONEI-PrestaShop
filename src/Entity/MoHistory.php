<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;
use OpenAPI\Client\Model\PaymentStatus;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiHistoryRepository")
 */
class MoHistory
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(name="id_history", type="integer", length=10)
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="PsMonei\Entity\MoPayment", inversedBy="paymentHistory")
     * @ORM\JoinColumn(name="id_payment", referencedColumnName="id_payment", nullable=false)
     */
    private $payment;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=4)
     */
    private $status_code;

    /**
     * @ORM\Column(type="boolean")
     */
    private $is_refund;

    /**
     * @ORM\Column(type="boolean")
     */
    private $is_callback;

    /**
     * @ORM\Column(type="string", length=4000, nullable=true)
     */
    private $response;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $date_add;

    public function __construct()
    {
        $this->date_add = new \DateTime();
    }

    // Getters and Setters for each property
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function setPayment(MoPayment $payment)
    {
        $this->payment = $payment;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus($status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusCode(): ?string
    {
        return $this->status_code;
    }

    public function setStatusCode(string $status_code): self
    {
        $this->status_code = $status_code;
        return $this;
    }

    public function isRefund(): ?bool
    {
        return $this->is_refund;
    }

    public function setIsRefund(bool $is_refund): self
    {
        $this->is_refund = $is_refund;
        return $this;
    }

    public function isCallback(): ?bool
    {
        return $this->is_callback;
    }

    public function setIsCallback(bool $is_callback): self
    {
        $this->is_callback = $is_callback;
        return $this;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    public function setResponse(?string $response): self
    {
        $this->response = $response;
        return $this;
    }

    public function getDateAdd(): ?\DateTime
    {
        return $this->date_add;
    }

    public function setDateAdd(?\DateTime $date_add): self
    {
        $this->date_add = $date_add;
        return $this;
    }
}