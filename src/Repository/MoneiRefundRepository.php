<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2Refund;

class MoneiRefundRepository extends EntityRepository
{
    /**
     * @param Monei2Refund $monei2Refund
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiRefund(Monei2Refund $monei2Refund, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($monei2Refund);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei refund: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2Refund $monei2Refund
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiRefund(Monei2Refund $monei2Refund, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($monei2Refund);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei refund: ' . $e->getMessage());
        }
    }
}
