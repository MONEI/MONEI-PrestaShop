<?php
namespace PsMonei\Repository;

use PsMonei\Entity\Monei2History;

class MoneiHistoryRepository extends AbstractMoneiRepository
{
    /**
     * @param Monei2History $monei2History
     * @param bool $flush
     *
     * @return Monei2History
     */
    public function save(Monei2History $monei2History, bool $flush = true): Monei2History
    {
        return $this->saveEntity($monei2History, $flush, 'history');
    }

    /**
     * @param Monei2History $monei2History
     * @param bool $flush
     *
     * @return void
     */
    public function remove(Monei2History $monei2History, bool $flush = true): void
    {
        $this->removeEntity($monei2History, $flush, 'history');
    }
}
