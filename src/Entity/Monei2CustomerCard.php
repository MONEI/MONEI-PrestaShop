<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="PsMonei\Repository\MoneiCustomerCardRepository")
 */
class Monei2CustomerCard
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(name="id_customer_card", type="integer", length=11)
     */
    private $id;

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

    public function getId(): ?int
    {
        return $this->id;
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
        return $this->brand !== null ? strtoupper($this->brand) : null;
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

    public function getLastFourWithMask(): string
    {
        return '**** **** **** ' . $this->last_four;
    }

    public function setLastFour(string $last_four): self
    {
        $this->last_four = $last_four;

        return $this;
    }

    public function getExpiration(): int
    {
        return $this->expiration;
    }

    public function getExpirationFormatted(): string
    {
        return date('m/y', $this->expiration);
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

    public function getDateAddFormatted(): ?string
    {
        return $this->date_add ? $this->date_add->format('Y-m-d H:i:s') : null;
    }

    public function setDateAdd(?int $timestamp): self
    {
        $this->date_add = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : null;

        return $this;
    }

    public function toArrayLegacy(): array
    {
        return [
            'id_customer_card' => $this->id,
            'id_customer' => $this->id_customer,
            'brand' => $this->brand,
            'country' => $this->country,
            'last_four' => $this->last_four,
            'last_four_with_mask' => $this->getLastFourWithMask(),
            'expiration' => $this->expiration,
            'tokenized' => $this->tokenized,
            'date_add' => $this->getDateAddFormatted(),
        ];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customerId' => $this->id_customer,
            'brand' => $this->brand,
            'country' => $this->country,
            'lastFour' => $this->last_four,
            'lastFourWithMask' => $this->getLastFourWithMask(),
            'expiration' => $this->expiration,
            'tokenized' => $this->tokenized,
            'dateAdd' => $this->getDateAddFormatted(),
        ];
    }
}
