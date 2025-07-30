<?php

namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 *
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiRefundRepository")
 */
class Monei2Refund
{
    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue
     *
     * @ORM\Column(name="id_refund", type="integer", length=10)
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="PsMonei\Entity\Monei2Payment", inversedBy="refund")
     *
     * @ORM\JoinColumn(name="id_payment", referencedColumnName="id_payment", nullable=false)
     */
    private $payment;

    /**
     * @ORM\OneToOne(targetEntity="PsMonei\Entity\Monei2History", inversedBy="refund")
     *
     * @ORM\JoinColumn(name="id_history", referencedColumnName="id_history", nullable=false)
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

    public function setPayment(Monei2Payment $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    public function getHistory()
    {
        return $this->history;
    }

    public function setHistory(Monei2History $history): self
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

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getAmount(bool $inDecimal = false): ?float
    {
        return $inDecimal ? $this->amount / 100 : $this->amount;
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

    public function getDateAddFormatted(): ?string
    {
        return $this->date_add ? $this->date_add->format('Y-m-d H:i:s') : null;
    }

    public function setDateAdd(?\DateTimeInterface $date_add): self
    {
        $this->date_add = $date_add;

        return $this;
    }

    public function toArray()
    {
        return [
            'idEmployee' => $this->getEmployeeId(),
            'reason' => $this->getReason(),
            'amount' => $this->getAmount(),
            'amountInDecimal' => $this->getAmount(true),
            'dateAdd' => $this->getDateAddFormatted(),
        ];
    }

    public function toArrayLegacy()
    {
        return [
            'id_employee' => $this->getEmployeeId(),
            'reason' => $this->getReason(),
            'amount' => $this->getAmount(),
            'amount_in_decimal' => $this->getAmount(true),
            'date_add' => $this->getDateAddFormatted(),
        ];
    }
}
