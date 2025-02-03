<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\MoPayment;

class MoneiPaymentRepository extends EntityRepository
{
    /**
     * @param MoPayment $MoPayment
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiPayment(MoPayment $MoPayment, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($MoPayment);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei payment: ' . $e->getMessage());
        }
    }

    /**
     * @param MoPayment $MoPayment
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiPayment(MoPayment $MoPayment, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($MoPayment);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei payment: ' . $e->getMessage());
        }
    }
}
