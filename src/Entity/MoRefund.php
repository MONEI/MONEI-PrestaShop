<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiRefundRepository")
 */
class MoRefund
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(name="id_refund", type="integer", length=10)
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="PsMonei\Entity\MoPayment", inversedBy="paymentRefund")
     * @ORM\JoinColumn(name="id_payment", referencedColumnName="id_payment", nullable=false)
     */
    private $payment;

    /**
     * @ORM\ManyToOne(targetEntity="PsMonei\Entity\MoHistory", inversedBy="paymentRefund")
     * @ORM\JoinColumn(name="id_history", referencedColumnName="id", nullable=false)
     */
    private $history;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $id_employee;

    /**
     * @ORM\Column(type="string", length=50, options={"default": "requested_by_customer"})
     */
    private $reason;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $amount;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $date_add;

    public function __construct()
    {
        $this->date_add = new \DateTime();
    }

    // Getters and Setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function setPayment(MoPayment $payment): self
    {
        $this->payment = $payment;
        return $this;
    }

    public function getHistory()
    {
        return $this->history;
    }

    public function setHistory(MoHistory $history): self
    {
        $this->history = $history;
        return $this;
    }

    public function getEmployeeId(): ?int
    {
        return $this->id_employee;
    }

    public function setEmployeeId(?int $id_employee): self
    {
        $this->id_employee = $id_employee;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getDateAdd(): ?\DateTimeInterface
    {
        return $this->date_add;
    }

    public function setDateAdd(?\DateTimeInterface $date_add): self
    {
        $this->date_add = $date_add;
        return $this;
    }
}
