<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiHistoryRepository")
 */
class Monei2History
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(name="id_history", type="integer", length=10)
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="PsMonei\Entity\Monei2Payment", inversedBy="history")
     * @ORM\JoinColumn(name="id_payment", referencedColumnName="id_payment", nullable=false)
     */
    private $payment;

    /**
     * @ORM\OneToOne(targetEntity="PsMonei\Entity\Monei2Refund", mappedBy="history")
     */
    private $refund;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=4, nullable=true)
     */
    private $status_code;

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

    public function setPayment(Monei2Payment $payment)
    {
        $this->payment = $payment;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusCode(): ?string
    {
        return $this->status_code ?? '';
    }

    public function setStatusCode(?string $status_code): self
    {
        $this->status_code = $status_code;

        return $this;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    public function getResponseDecoded(): ?array
    {
        return json_decode($this->response, true);
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

    public function getDateAddFormatted(): ?string
    {
        return $this->date_add->format('Y-m-d H:i:s');
    }

    public function setDateAdd(?\DateTime $date_add): self
    {
        $this->date_add = $date_add;

        return $this;
    }

    public function getRefund(): ?Monei2Refund
    {
        return $this->refund;
    }

    public function toArray()
    {
        return [
            'status' => $this->getStatus(),
            'statusCode' => $this->getStatusCode(),
            'response' => $this->getResponse(),
            'dateAdd' => $this->getDateAddFormatted(),
        ];
    }

    public function toArrayLegacy()
    {
        return [
            'status' => $this->getStatus(),
            'status_code' => $this->getStatusCode(),
            'response' => $this->getResponse(),
            'date_add' => $this->getDateAddFormatted(),
        ];
    }
}
