<?php

namespace App\Repository;

use App\Entity\Filiere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Filiere>
 */
class FiliereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Filiere::class);
    }

    public function save(Filiere $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Filiere $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Liste de l'écran E2.3.
     */
    public function queryListe(?string $recherche = null): Query
    {
        $qb = $this->createQueryBuilder('f')->orderBy('f.libelle', 'ASC');

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('f.libelle LIKE :recherche')
                ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * @return Filiere[]
     */
    public function findToutesTriees(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
