<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2Payment;
use PsMonei\Exception\MoneiException;
class MoneiPaymentRepository extends EntityRepository
{
    /**
     * @param Monei2Payment $monei2Payment
     * @param bool $flush
     *
     * @return void
     */
    public function save(Monei2Payment $monei2Payment, bool $flush = true): Monei2Payment
    {
        try {
            $this->getEntityManager()->persist($monei2Payment);
            if ($flush) {
                $this->getEntityManager()->flush();
            }

            return $monei2Payment;
        } catch (ORMException $e) {
            throw new MoneiException('Error saving monei payment: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2Payment $monei2Payment
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2Payment $monei2Payment, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($monei2Payment);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new MoneiException('Error removing monei payment: ' . $e->getMessage());
        }
    }
}
