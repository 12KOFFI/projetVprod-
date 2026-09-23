<?php

namespace App\Repository;

use App\Entity\Candidature;
use App\Entity\HistoriqueStatut;
use App\Enum\StatutCandidature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HistoriqueStatut>
 */
class HistoriqueStatutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HistoriqueStatut::class);
    }

    /**
     * Historique complet d'un dossier, du plus ancien au plus récent
     * (ordre d'affichage de la timeline « Suivi des étapes »).
     *
     * @return HistoriqueStatut[]
     */
    public function findPourCandidature(Candidature $candidature): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.candidature = :candidature')
            ->setParameter('candidature', $candidature)
            ->orderBy('h.creation', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * L'historique porte-t-il déjà l'arrivée du dossier à ce statut ?
     * Rend idempotente la journalisation d'une étape constatée (inscription).
     */
    public function aJournaliseArrivee(Candidature $candidature, StatutCandidature $statut): bool
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->andWhere('h.candidature = :candidature')
            ->andWhere('h.statutApres = :statut')
            ->andWhere('h.statutAvant IS NULL OR h.statutAvant != :statut')
            ->setParameter('candidature', $candidature)
            ->setParameter('statut', $statut->value)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findDernierPourCandidature(Candidature $candidature): ?HistoriqueStatut
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.candidature = :candidature')
            ->setParameter('candidature', $candidature)
            ->orderBy('h.creation', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
