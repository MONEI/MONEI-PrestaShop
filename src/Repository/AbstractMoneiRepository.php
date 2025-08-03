<?php

namespace PsMonei\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Doctrine\ORM\EntityRepository;
use PsMonei\Exception\MoneiException;

abstract class AbstractMoneiRepository extends EntityRepository
{
    protected function saveEntity(object $entity, bool $flush = true, string $entityType = 'entity'): object
    {
        try {
            $this->getEntityManager()->persist($entity);
            if ($flush) {
                $this->getEntityManager()->flush();
            }

            return $entity;
        } catch (\Throwable $e) {
            throw new MoneiException("Error saving monei {$entityType}: " . $e->getMessage(), MoneiException::SAVE_ENTITY_ERROR, $e);
        }
    }

    protected function removeEntity(object $entity, bool $flush = true, string $entityType = 'entity'): void
    {
        try {
            $this->getEntityManager()->remove($entity);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        } catch (\Throwable $e) {
            throw new MoneiException("Error removing monei {$entityType}: " . $e->getMessage(), MoneiException::REMOVE_ENTITY_ERROR, $e);
        }
    }
}
