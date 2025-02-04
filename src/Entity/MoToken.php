<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiTokenRepository")
 */
class MoToken
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id_token;

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
    private $threeds;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $threeds_version;

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

    public function __construct()
    {
        $this->date_add = new \DateTime();
    }

    public function getTokenId(): ?int
    {
        return $this->id_token;
    }

    public function getCustomerId(): ?int
    {
        return $this->id_customer;
    }

    public function setCustomerId(int $id_customer): self
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
        return $this->threeds;
    }

    public function setThreeDS(?bool $threeds): self
    {
        $this->threeds = $threeds;
        return $this;
    }

    public function getThreeDSVersion(): ?string
    {
        return $this->threeds_version;
    }

    public function setThreeDSVersion(?string $threeds_version): self
    {
        $this->threeds_version = $threeds_version;
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

    public function setDateAdd(?int $timestamp): self
    {
        $this->date_add = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;
        return $this;
    }
}