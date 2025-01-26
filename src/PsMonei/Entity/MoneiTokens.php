<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="monei_tokens")
 */
class MoneiTokens
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id_monei_tokens;

    /**
     * @ORM\Column(type="integer")
     */
    private $id_customer;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $brand;

    /**
     * @ORM\Column(type="string", length=4, nullable=true)
     */
    private $country;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private $last_four;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $threeDS;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $threeDS_version;

    /**
     * @ORM\Column(type="integer")
     */
    private $expiration;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $tokenized;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $date_add;

    // Getters and Setters for each property
    public function getIdMoneiTokens(): ?int
    {
        return $this->id_monei_tokens;
    }

    public function getIdCustomer(): ?int
    {
        return $this->id_customer;
    }

    public function setIdCustomer(int $id_customer): self
    {
        $this->id_customer = $id_customer;
        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): self
    {
        $this->brand = $brand;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getLastFour(): string
    {
        return $this->last_four;
    }

    public function setLastFour(string $last_four): self
    {
        $this->last_four = $last_four;
        return $this;
    }

    public function getThreeDS(): ?bool
    {
        return $this->threeDS;
    }

    public function setThreeDS(?bool $threeDS): self
    {
        $this->threeDS = $threeDS;
        return $this;
    }

    public function getThreeDSVersion(): ?string
    {
        return $this->threeDS_version;
    }

    public function setThreeDSVersion(?string $threeDS_version): self
    {
        $this->threeDS_version = $threeDS_version;
        return $this;
    }

    public function getExpiration(): int
    {
        return $this->expiration;
    }

    public function setExpiration(int $expiration): self
    {
        $this->expiration = $expiration;
        return $this;
    }

    public function getTokenized(): string
    {
        return $this->tokenized;
    }

    public function setTokenized(string $tokenized): self
    {
        $this->tokenized = $tokenized;
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