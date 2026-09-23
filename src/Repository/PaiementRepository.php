<?php

namespace App\Repository;

use App\Entity\Candidature;
use App\Entity\Paiement;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paiement>
 */
class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiement::class);
    }

    public function save(Paiement $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Un paiement de ce type a-t-il été réglé pour ce dossier ?
     *
     * Utilisé comme garde d'accès (l'évaluation devant jury exige le paiement
     * des frais d'examen) : on compte, on n'hydrate pas de collection.
     */
    public function existeReussi(Candidature $candidature, TypeFrais $type): bool
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.candidature = :candidature')
            ->andWhere('p.typeFrais = :type')
            ->andWhere('p.statutPaiement = :statut')
            ->setParameter('candidature', $candidature)
            ->setParameter('type', $type->value)
            ->setParameter('statut', StatutPaiement::REUSSI->value)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findParTypePourCandidature(Candidature $candidature, TypeFrais $type): ?Paiement
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.candidature = :candidature')
            ->andWhere('p.typeFrais = :type')
            ->setParameter('candidature', $candidature)
            ->setParameter('type', $type->value)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Liste des règlements pour le suivi administratif (écran E5.6).
     */
    public function queryListeAdmin(
        ?string $recherche = null,
        ?TypeFrais $type = null,
        ?StatutPaiement $statut = null,
    ): \Doctrine\ORM\Query {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.candidature', 'c')->addSelect('c')
            // Le candidat propriétaire du dossier est porté par Candidature::$user.
            // L'association « candidat » a disparu avec Version20260917100000.
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->orderBy('p.creation', 'DESC');

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('c.numero LIKE :recherche OR p.referencePaiement LIKE :recherche OR cand.nom LIKE :recherche OR cand.prenoms LIKE :recherche')
               ->setParameter('recherche', '%' . $recherche . '%');
        }

        if ($type !== null) {
            $qb->andWhere('p.typeFrais = :type')->setParameter('type', $type->value);
        }

        if ($statut !== null) {
            $qb->andWhere('p.statutPaiement = :statut')->setParameter('statut', $statut->value);
        }

        return $qb->getQuery();
    }

    /**
     * Montants encaissés et effectifs par type de frais.
     *
     * L'agrégation est faite par la base : compter en PHP supposerait
     * d'hydrater tous les règlements (point d'attention de la partie D).
     *
     * @return array<string, array{effectif: int, montant: string}>
     */
    public function totauxParType(): array
    {
        $lignes = $this->createQueryBuilder('p')
            ->select('p.typeFrais AS type, COUNT(p.id) AS effectif, SUM(p.montant) AS montant')
            ->andWhere('p.statutPaiement = :reussi')
            ->setParameter('reussi', StatutPaiement::REUSSI->value)
            ->groupBy('p.typeFrais')
            ->getQuery()
            ->getScalarResult();

        $totaux = [];

        foreach ($lignes as $ligne) {
            $totaux[(string) $ligne['type']] = [
                'effectif' => (int) $ligne['effectif'],
                'montant' => (string) ($ligne['montant'] ?? '0'),
            ];
        }

        return $totaux;
    }

    /**
     * @return Paiement[]
     */
    public function findPourCandidature(Candidature $candidature): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.candidature = :candidature')
            ->setParameter('candidature', $candidature)
            ->orderBy('p.creation', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
