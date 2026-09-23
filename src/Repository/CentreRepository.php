<?php

namespace App\Repository;

use App\Entity\Centre;
use App\Entity\DirectionRegionale;
use App\Entity\Localite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Centre>
 */
class CentreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Centre::class);
    }

    public function save(Centre $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Centre $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Liste de l'écran E2.5, filtrable par direction régionale, localité et type.
     */
    public function queryListe(
        ?DirectionRegionale $directionRegionale = null,
        ?Localite $localite = null,
        ?string $type = null,
        ?string $recherche = null,
    ): Query {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('l', 'dr')
            ->join('c.localite', 'l')
            ->join('l.directionRegionale', 'dr')
            ->orderBy('c.nom', 'ASC');

        if ($directionRegionale !== null) {
            $qb->andWhere('l.directionRegionale = :dr')->setParameter('dr', $directionRegionale);
        }

        if ($localite !== null) {
            $qb->andWhere('c.localite = :localite')->setParameter('localite', $localite);
        }

        if ($type !== null && $type !== '') {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('c.nom LIKE :recherche')
                ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * Dépendance bloquante de R2.1 : une localité portant des centres n'est
     * pas supprimable.
     */
    public function countParLocalite(Localite $localite): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.localite = :localite')
            ->setParameter('localite', $localite)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Peuplement dynamique F2.8 : centres d'une localité.
     *
     * @return Centre[]
     */
    public function findParLocalite(Localite $localite): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.localite = :localite')
            ->setParameter('localite', $localite)
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Centre[]
     */
    public function findTousTries(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('l')
            ->join('c.localite', 'l')
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
