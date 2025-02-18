<?php
namespace PsMonei\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use PsMonei\Entity\MoCustomerCard;

class MoneiCustomerCardRepository extends EntityRepository
{
    /**
     * @param MoCustomerCard $moCustomerCard
     * @param bool $flush
     *
     * @return void
     */
    public function saveMoneiCustomerCard(MoCustomerCard $moCustomerCard, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->persist($moCustomerCard);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (ORMException $e) {
            throw new \Exception('Error saving monei customer card: ' . $e->getMessage());
        }
    }

    /**
     * @param MoCustomerCard $moCustomerCard
     * @param bool $flush
     *
     * @return void
     */
    public function removeMoneiCustomerCard(MoCustomerCard $moCustomerCard, bool $flush = true): void
    {
        try {
            $this->getEntityManager()->remove($moCustomerCard);
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
