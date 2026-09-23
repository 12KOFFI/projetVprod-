<?php

namespace App\Repository;

use App\Entity\Centre;
use App\Entity\Certification;
use App\Entity\Metier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Certification>
 */
class CertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Certification::class);
    }

    public function save(Certification $certification, bool $flush = false): void
    {
        $this->getEntityManager()->persist($certification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Certification $certification, bool $flush = false): void
    {
        $this->getEntityManager()->remove($certification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByLibelle(string $libelle): ?Certification
    {
        return $this->findOneBy(['libelle' => $libelle]);
    }

    /**
     * Certifications réellement préparées pour un couple (centre, métier).
     *
     * C'est la seule source du menu « Diplôme visé » au dépôt d'un dossier :
     * proposer les certifications du métier sans tenir compte du centre
     * laisserait choisir un diplôme que l'établissement ne prépare pas.
     *
     * @return Certification[]
     */
    public function findOffertesPour(Centre $centre, Metier $metier): array
    {
        return $this->createQueryBuilder('cert')
            ->join('App\Entity\CentreMetier', 'cm', 'WITH', 'cert MEMBER OF cm.certifications')
            ->andWhere('cm.centre = :centre')
            ->andWhere('cm.metier = :metier')
            ->andWhere('cert.actif = true')
            ->setParameter('centre', $centre)
            ->setParameter('metier', $metier)
            ->orderBy('cert.type', 'ASC')
            ->addOrderBy('cert.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Certifications d'un métier, pour l'écran d'administration de l'offre :
     * l'administrateur ne coche que des certifications cohérentes avec le
     * métier du couple qu'il édite.
     *
     * @return Certification[]
     */
    public function findParMetier(Metier $metier, bool $actifSeulement = true): array
    {
        $qb = $this->createQueryBuilder('cert')
            ->andWhere('cert.metier = :metier')
            ->setParameter('metier', $metier)
            ->orderBy('cert.type', 'ASC')
            ->addOrderBy('cert.libelle', 'ASC');

        if ($actifSeulement) {
            $qb->andWhere('cert.actif = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Liste paginée du référentiel, filtrable par métier, type et texte.
     */
    public function queryListe(?Metier $metier = null, ?string $type = null, ?string $recherche = null): Query
    {
        $qb = $this->createQueryBuilder('cert')
            ->leftJoin('cert.metier', 'm')->addSelect('m')
            ->orderBy('m.libelle', 'ASC')
            ->addOrderBy('cert.type', 'ASC')
            ->addOrderBy('cert.libelle', 'ASC');

        if ($metier !== null) {
            $qb->andWhere('cert.metier = :metier')->setParameter('metier', $metier);
        }

        if ($type !== null && $type !== '') {
            $qb->andWhere('cert.type = :type')->setParameter('type', $type);
        }

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('cert.libelle LIKE :recherche')
               ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * Nombre de couples (centre, métier) proposant cette certification :
     * garde-fou avant suppression, sur le modèle de ReferentielGuard.
     */
    public function compterOffres(Certification $certification): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(cm.id) FROM App\Entity\CentreMetier cm
                 WHERE :certification MEMBER OF cm.certifications'
            )
            ->setParameter('certification', $certification)
            ->getSingleScalarResult();
    }
}
