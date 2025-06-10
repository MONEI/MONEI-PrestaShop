<?php
namespace PsMonei\Repository;

use PsMonei\Entity\Monei2Payment;

class MoneiPaymentRepository extends AbstractMoneiRepository
{
    /**
     * @param Monei2Payment $monei2Payment
     * @param bool $flush
     *
     * @return Monei2Payment
     */
    public function save(Monei2Payment $monei2Payment, bool $flush = true): Monei2Payment
    {
        return $this->saveEntity($monei2Payment, $flush, 'payment');
    }

    /**
     * @param Monei2Payment $monei2Payment
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2Payment $monei2Payment, bool $flush = true): void
    {
        $this->removeEntity($monei2Payment, $flush, 'payment');
    }
}
