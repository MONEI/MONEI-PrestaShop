<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2Refund;
use PsMonei\Exception\MoneiException;
class MoneiRefundRepository extends EntityRepository
{
    /**
     * @param Monei2Refund $monei2Refund
     * @param bool $flush
     *
     * @return void
     */
    public function save(Monei2Refund $monei2Refund, bool $flush = true): Monei2Refund
    {
        try {
            $this->getEntityManager()->persist($monei2Refund);
            if ($flush) {
                $this->getEntityManager()->flush();
            }

            return $monei2Refund;
        } catch (ORMException $e) {
            throw new MoneiException('Error saving monei refund: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2Refund $monei2Refund
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2Refund $monei2Refund, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($monei2Refund);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new MoneiException('Error removing monei refund: ' . $e->getMessage());
        }
    }
}
