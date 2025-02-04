<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\MoToken;

class MoneiTokenRepository extends EntityRepository
{
    /**
     * @param MoToken $moToken
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiToken(MoToken $moToken, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($moToken);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei payment: ' . $e->getMessage());
        }
    }

    /**
     * @param MoToken $moToken
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiToken(MoToken $moToken, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($moToken);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei token: ' . $e->getMessage());
        }
    }
}
