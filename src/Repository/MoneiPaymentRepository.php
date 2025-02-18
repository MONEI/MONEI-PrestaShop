<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2Payment;

class MoneiPaymentRepository extends EntityRepository
{
    /**
     * @param Monei2Payment $Monei2Payment
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiPayment(Monei2Payment $Monei2Payment, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($Monei2Payment);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei payment: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2Payment $Monei2Payment
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiPayment(Monei2Payment $Monei2Payment, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($Monei2Payment);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei payment: ' . $e->getMessage());
        }
    }
}
