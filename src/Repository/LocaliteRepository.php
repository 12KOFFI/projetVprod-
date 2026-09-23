<?php

namespace App\Repository;

use App\Entity\DirectionRegionale;
use App\Entity\Localite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Localite>
 */
class LocaliteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Localite::class);
    }

    public function save(Localite $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Localite $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Liste de l'écran E2.2, filtrable par direction régionale.
     */
    public function queryListe(?DirectionRegionale $directionRegionale = null, ?string $recherche = null): Query
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('dr')
            ->join('l.directionRegionale', 'dr')
            ->orderBy('dr.libelle', 'ASC')
            ->addOrderBy('l.libelle', 'ASC');

        if ($directionRegionale !== null) {
            $qb->andWhere('l.directionRegionale = :dr')->setParameter('dr', $directionRegionale);
        }

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('l.libelle LIKE :recherche')
                ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * Dépendance bloquante de R2.2 : une direction régionale portant des
     * localités n'est pas supprimable.
     */
    public function countParDirectionRegionale(DirectionRegionale $directionRegionale): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.directionRegionale = :dr')
            ->setParameter('dr', $directionRegionale)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Peuplement dynamique F2.8 : localités d'une direction régionale.
     *
     * @return Localite[]
     */
    public function findParDirectionRegionale(DirectionRegionale $directionRegionale): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.directionRegionale = :dr')
            ->setParameter('dr', $directionRegionale)
            ->orderBy('l.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Localite[]
     */
    public function findToutesTriees(): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('dr')
            ->join('l.directionRegionale', 'dr')
            ->orderBy('dr.libelle', 'ASC')
            ->addOrderBy('l.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
