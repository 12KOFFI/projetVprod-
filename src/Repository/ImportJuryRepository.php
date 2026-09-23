<?php

namespace App\Repository;

use App\Entity\ImportJury;
use App\Enum\TypeImportJury;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportJury>
 */
class ImportJuryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportJury::class);
    }

    public function save(ImportJury $import, bool $flush = false): void
    {
        $this->getEntityManager()->persist($import);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Historique des imports d'un type donné (F6.8).
     *
     * @return ImportJury[]
     */
    public function findParType(TypeImportJury $type, int $limite = 20): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.auteur', 'a')->addSelect('a')
            ->andWhere('i.type = :type')
            ->setParameter('type', $type->value)
            ->orderBy('i.creation', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
