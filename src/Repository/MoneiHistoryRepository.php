<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2History;
use PsMonei\Exception\MoneiException;
class MoneiHistoryRepository extends EntityRepository
{
    /**
     * @param Monei2History $monei2History
     * @param bool $flush
     *
     * @return void
     */
    public function save(Monei2History $monei2History, bool $flush = true): Monei2History
    {
        try {
            $this->getEntityManager()->persist($monei2History);
            if ($flush) {
                $this->getEntityManager()->flush();
            }

            return $monei2History;
        } catch (ORMException $e) {
            throw new MoneiException('Error saving monei history: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2History $monei2History
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2History $monei2History, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($monei2History);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new MoneiException('Error removing monei history: ' . $e->getMessage());
        }
    }
}
