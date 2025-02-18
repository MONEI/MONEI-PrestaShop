<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\Monei2CustomerCard;

class MoneiCustomerCardRepository extends EntityRepository
{
    /**
     * @param Monei2CustomerCard $monei2CustomerCard
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiCustomerCard(Monei2CustomerCard $monei2CustomerCard, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($monei2CustomerCard);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei customer card: ' . $e->getMessage());
        }
    }

    /**
     * @param Monei2CustomerCard $monei2CustomerCard
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiCustomerCard(Monei2CustomerCard $monei2CustomerCard, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($monei2CustomerCard);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error removing monei customer card: ' . $e->getMessage());
        }
    }

    public function getActiveCustomerCards(int $customerId): array
    {
        $currentDate = new \DateTime();
        return $this->createQueryBuilder('c')
            ->where('c.id_customer = :customerId')
            ->andWhere('c.expiration > :currentDate')
            ->setParameter('customerId', $customerId)
            ->setParameter('currentDate', $currentDate->getTimestamp())
            ->getQuery()
            ->getResult();
    }
}
