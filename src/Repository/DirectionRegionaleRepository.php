<?php

namespace App\Repository;

use App\Entity\DirectionRegionale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DirectionRegionale>
 */
class DirectionRegionaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DirectionRegionale::class);
    }

    public function save(DirectionRegionale $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DirectionRegionale $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Requête de liste de l'écran E2.1 : recherche texte et tri, non exécutée
     * afin que le contrôleur puisse la confier directement au paginateur.
     */
    public function queryListe(?string $recherche = null): Query
    {
        $qb = $this->createQueryBuilder('dr')->orderBy('dr.libelle', 'ASC');

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('dr.libelle LIKE :recherche')
                ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * @return DirectionRegionale[]
     */
    public function findToutesTriees(): array
    {
        return $this->createQueryBuilder('dr')
            ->orderBy('dr.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
