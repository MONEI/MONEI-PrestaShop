<?php
namespace PsMonei\Entity;

use Doctrine\ORM\Mapping as ORM;
use OpenAPI\Client\Model\Payment;

/**
 * @ORM\Entity
 * @ORM\Table(name="monei")
 */
class MoneiClass
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id_payment;

    /**
     * @ORM\Column(type="integer")
     */
    private $id_cart;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $id_order_prestashop;

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

    //     public function savePaymentResponse(Payment $moneiPaymentResponse)
//     {
//         if (property_exists($moneiPaymentResponse, 'id')) {
//             $this->id_payment = $moneiPaymentResponse->getId();
//         }
//         if (property_exists($moneiPaymentResponse, 'getOrderId')) {
//             $this->id_order_monei = $moneiPaymentResponse->getOrderId();

//             // Extracting cart ID from the formatted order ID
//             $this->id_cart = (int) substr($this->id_order_monei, 0, strpos($this->id_order_monei, 'm'));
//         }
//         if (property_exists($moneiPaymentResponse, 'amount')) {
//             $this->amount = $moneiPaymentResponse->getAmount();
//         }
//         if (property_exists($moneiPaymentResponse, 'currency')) {
//             $this->currency = $moneiPaymentResponse->getCurrency();
//         }
//         if (property_exists($moneiPaymentResponse, 'authorizationCode')) {
//             $this->authorization_code = $moneiPaymentResponse->getAuthorizationCode();
//         }
//         if (property_exists($moneiPaymentResponse, 'status')) {
//             $this->status = $moneiPaymentResponse->getStatus();
//         }

//         try {
//             // Assuming you have a method to persist the entity
//             $entityManager = // get your entity manager here
//             $entityManager->persist($this);
//             $entityManager->flush();
//         } catch (\Exception $e) {
//             PrestaShopLogger::addLog('MoneiClass::savePaymentResponse - Error saving payment response: ' . $e->getMessage(), PrestaShopLogger::LOG_LEVEL_ERROR);
//         }

//         return $this;
//     }

    public function getIdPayment(): ?int
    {
        return $this->id_payment;
    }

    public function setIdPayment(int $id_payment): self
    {
        $this->id_payment = $id_payment;
        return $this;
    }

    public function getIdCart(): ?int
    {
        return $this->id_cart;
    }

    public function setIdCart(int $id_cart): self
    {
        $this->id_cart = $id_cart;
        return $this;
    }

    public function getIdOrderPrestashop(): ?int
    {
        return $this->id_order_prestashop;
    }

    public function setIdOrderPrestashop(?int $id_order_prestashop): self
    {
        $this->id_order_prestashop = $id_order_prestashop;
        return $this;
    }

    public function getIdOrderMonei(): ?string
    {
        return $this->id_order_monei;
    }

    public function setIdOrderMonei(?string $id_order_monei): self
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

    public function setDateAdd(?\DateTime $date_add): self
    {
        $this->date_add = $date_add;
        return $this;
    }

    public function getDateUpd(): ?\DateTime
    {
        return $this->date_upd;
    }

    public function setDateUpd(?\DateTime $date_upd): self
    {
        $this->date_upd = $date_upd;
        return $this;
    }
}
