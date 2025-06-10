<?php
namespace PsMonei\Repository;

use PsMonei\Entity\Monei2CustomerCard;

class MoneiCustomerCardRepository extends AbstractMoneiRepository
{
    /**
     * @param Monei2CustomerCard $monei2CustomerCard
     * @param bool $flush
     *
     * @return Monei2CustomerCard
     */
    public function save(Monei2CustomerCard $monei2CustomerCard, bool $flush = true): Monei2CustomerCard
    {
        return $this->saveEntity($monei2CustomerCard, $flush, 'customer card');
    }

    /**
     * @param Monei2CustomerCard $monei2CustomerCard
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2CustomerCard $monei2CustomerCard, bool $flush = true): void
    {
        $this->removeEntity($monei2CustomerCard, $flush, 'customer card');
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
