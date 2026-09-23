<?php

namespace App\Repository;

use App\Entity\Filiere;
use App\Entity\Metier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Metier>
 */
class MetierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Metier::class);
    }

    public function save(Metier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Metier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Liste de l'écran E2.4, filtrable par filière et par statut.
     */
    public function queryListe(?Filiere $filiere = null, ?string $statut = null, ?string $recherche = null): Query
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('f')
            ->join('m.filiere', 'f')
            ->orderBy('f.libelle', 'ASC')
            ->addOrderBy('m.libelle', 'ASC');

        if ($filiere !== null) {
            $qb->andWhere('m.filiere = :filiere')->setParameter('filiere', $filiere);
        }

        if ($statut !== null && $statut !== '') {
            $qb->andWhere('m.statut = :statut')->setParameter('statut', $statut);
        }

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('m.libelle LIKE :recherche')
                ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * Dépendance bloquante de R2.3.
     */
    public function countParFiliere(Filiere $filiere): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.filiere = :filiere')
            ->setParameter('filiere', $filiere)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Métiers actifs uniquement : un métier inactif n'est plus proposé dans les
     * formulaires de candidature mais reste lisible dans les dossiers déjà
     * déposés (règle métier R2.7).
     *
     * @return Metier[]
     */
    public function findActifsTries(): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('f')
            ->join('m.filiere', 'f')
            ->andWhere('m.statut = :actif')
            ->setParameter('actif', 'actif')
            ->orderBy('f.libelle', 'ASC')
            ->addOrderBy('m.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Metier[]
     */
    public function findTousTries(): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('f')
            ->join('m.filiere', 'f')
            ->orderBy('f.libelle', 'ASC')
            ->addOrderBy('m.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
