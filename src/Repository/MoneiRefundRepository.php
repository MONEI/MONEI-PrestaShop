<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\MoRefund;

class MoneiRefundRepository extends EntityRepository
{
    /**
     * @param MoRefund $moRefund
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiRefund(MoRefund $moRefund, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($moRefund);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei refund: ' . $e->getMessage());
        }
    }

    /**
     * @param MoRefund $moRefund
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiRefund(MoRefund $moRefund, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($moRefund);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei refund: ' . $e->getMessage());
        }
    }
}
