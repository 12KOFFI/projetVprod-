<?php

namespace App\Repository;

use App\Entity\Candidature;
use App\Entity\PreuveLivret;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PreuveLivret>
 */
class PreuveLivretRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreuveLivret::class);
    }

    /**
     * @return PreuveLivret[]
     */
    public function findPourCandidature(Candidature $candidature): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('u')
            ->leftJoin('p.deposePar', 'u')
            ->andWhere('p.candidature = :c')
            ->setParameter('c', $candidature)
            ->orderBy('p.creation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de preuves par dossier, en une requête.
     *
     * @param Candidature[] $candidatures
     *
     * @return array<int, int> id de candidature => nombre
     */
    public function compterParCandidature(array $candidatures): array
    {
        if ($candidatures === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.candidature) AS id, COUNT(p.id) AS n')
            ->andWhere('p.candidature IN (:c)')
            ->setParameter('c', $candidatures)
            ->groupBy('p.candidature')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn ($l) => ['id' => (int) $l['id'], 'n' => (int) $l['n']], $lignes), 'n', 'id');
    }
}
