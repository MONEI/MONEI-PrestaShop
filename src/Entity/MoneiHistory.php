<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="monei_history")
 */
class MoneiHistory
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id_monei_history;

    /**
     * @ORM\Column(type="integer")
     */
    private $id_monei;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private $status;

    /**
     * @ORM\Column(type="integer")
     */
    private $id_monei_code;

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

    // Getters and Setters for each property
    public function getIdMoneiHistory(): ?int
    {
        return $this->id_monei_history;
    }

    public function getIdMonei(): ?int
    {
        return $this->id_monei;
    }

    public function setIdMonei(int $id_monei): self
    {
        $this->id_monei = $id_monei;
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

    public function getIdMoneiCode(): ?int
    {
        return $this->id_monei_code;
    }

    public function setIdMoneiCode(int $id_monei_code): self
    {
        $this->id_monei_code = $id_monei_code;
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