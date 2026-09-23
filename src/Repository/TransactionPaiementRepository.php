<?php

namespace App\Repository;

use App\Entity\Paiement;
use App\Entity\TransactionPaiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransactionPaiement>
 */
class TransactionPaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransactionPaiement::class);
    }

    public function save(TransactionPaiement $transaction, bool $flush = false): void
    {
        $this->getEntityManager()->persist($transaction);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return TransactionPaiement[]
     */
    public function findPourPaiement(Paiement $paiement): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.paiement = :paiement')
            ->setParameter('paiement', $paiement)
            ->orderBy('t.creation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retrouve une transaction par l'identifiant attribué par le fournisseur.
     *
     * Clé de l'idempotence du callback : un webhook rejoué doit retomber sur la
     * transaction déjà enregistrée plutôt que d'en créer une seconde.
     */
    public function findOneParIdentifiantExterne(string $identifiant): ?TransactionPaiement
    {
        return $this->findOneBy(['identifiantExterne' => $identifiant]);
    }

    public function findOneParReference(string $reference): ?TransactionPaiement
    {
        return $this->findOneBy(['reference' => $reference]);
    }
}
