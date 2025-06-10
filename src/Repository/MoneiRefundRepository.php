<?php
namespace PsMonei\Repository;

use PsMonei\Entity\Monei2Refund;

class MoneiRefundRepository extends AbstractMoneiRepository
{
    /**
     * @param Monei2Refund $monei2Refund
     * @param bool $flush
     *
     * @return Monei2Refund
     */
    public function save(Monei2Refund $monei2Refund, bool $flush = true): Monei2Refund
    {
        return $this->saveEntity($monei2Refund, $flush, 'refund');
    }

    /**
     * @param Monei2Refund $monei2Refund
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2Refund $monei2Refund, bool $flush = true): void
    {
        $this->removeEntity($monei2Refund, $flush, 'refund');
    }
}
