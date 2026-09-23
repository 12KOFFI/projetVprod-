<?php

namespace App\Repository;

use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Entity\Metier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CentreMetier>
 */
class CentreMetierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CentreMetier::class);
    }

    public function save(CentreMetier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Un métier n'est candidatable que s'il est explicitement ouvert dans le
     * centre choisi. Vérification serveur obligatoire à la soumission : le
     * filtrage du formulaire ne suffit pas (règle métier R3.2).
     */
    public function metierOuvertDansCentre(Centre $centre, Metier $metier): bool
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->andWhere('cm.centre = :centre')
            ->andWhere('cm.metier = :metier')
            ->setParameter('centre', $centre)
            ->setParameter('metier', $metier)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Métiers actifs ouverts dans un centre, pour peupler les listes déroulantes.
     *
     * @return Metier[]
     */
    public function findMetiersOuverts(Centre $centre): array
    {
        // La racine de la requête est Metier et non CentreMetier : DQL refuse de
        // sélectionner une entité jointe sans son alias racine, et c'est bien une
        // liste de métiers que les appelants attendent.
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('m')
            ->from(Metier::class, 'm')
            ->join(CentreMetier::class, 'cm', Join::WITH, 'cm.metier = m')
            ->andWhere('cm.centre = :centre')
            ->andWhere('m.statut = :actif')
            ->setParameter('centre', $centre)
            ->setParameter('actif', 'actif')
            ->orderBy('m.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function remove(CentreMetier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Offre de formation d'un centre (écran E2.6).
     *
     * @return CentreMetier[]
     */
    public function findOffreDuCentre(Centre $centre): array
    {
        return $this->createQueryBuilder('cm')
            ->addSelect('m', 'f')
            ->join('cm.metier', 'm')
            ->join('m.filiere', 'f')
            ->andWhere('cm.centre = :centre')
            ->setParameter('centre', $centre)
            ->orderBy('f.libelle', 'ASC')
            ->addOrderBy('m.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ligne d'offre existante pour un couple centre/métier : sert à faire
     * respecter l'unicité de R2.6 avant l'insertion, afin de rendre une erreur
     * métier plutôt qu'une violation de contrainte SQL.
     */
    public function findCouple(Centre $centre, Metier $metier): ?CentreMetier
    {
        return $this->findOneBy(['centre' => $centre, 'metier' => $metier]);
    }

    /**
     * Dépendance bloquante de R2.4 côté offre, et de R2.5 côté centre.
     */
    public function countParMetier(Metier $metier): int
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->andWhere('cm.metier = :metier')
            ->setParameter('metier', $metier)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countParCentre(Centre $centre): int
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->andWhere('cm.centre = :centre')
            ->setParameter('centre', $centre)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
