<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\MoHistory;

class MoneiHistoryRepository extends EntityRepository
{
    /**
     * @param MoHistory $moHistory
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiHistory(MoHistory $moHistory, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($moHistory);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei history: ' . $e->getMessage());
        }
    }

    /**
     * @param MoHistory $moHistory
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiHistory(MoHistory $moHistory, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($moHistory);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei history: ' . $e->getMessage());
        }
    }
}
