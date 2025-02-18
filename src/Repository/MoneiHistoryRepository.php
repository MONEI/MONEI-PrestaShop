<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2History;

class MoneiHistoryRepository extends EntityRepository
{
    /**
     * @param Monei2History $monei2History
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiHistory(Monei2History $monei2History, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($monei2History);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei history: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2History $monei2History
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiHistory(Monei2History $monei2History, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($monei2History);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei history: ' . $e->getMessage());
        }
    }
}
