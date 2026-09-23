<?php

namespace App\Service\Candidature;

use App\Entity\Candidature;
use App\Entity\HistoriqueStatut;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Event\CandidatureStatutChangeEvent;
use App\Exception\TransitionInterditeException;
use App\Repository\HistoriqueStatutRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Point d'entrée UNIQUE de toute décision qui fait avancer une candidature.
 *
 * Ce service ne stocke ni ne calcule le statut global : il lit l'état courant
 * auprès de CandidatureStatusResolver, vérifie que la décision est permise à
 * ce stade, enregistre la décision dans SA colonne métier, puis journalise.
 *
 *   recevabilité          → recStatut
 *   éligibilité           → eligStatut
 *   admissibilité         → resultat
 *   admission définitive  → admis
 *
 * L'inscription n'est pas une décision : elle découle du règlement des frais
 * de dossier, et constaterInscription() se contente de la journaliser.
 */
class TransitionCandidature
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly CandidatureStatusResolver $resolver,
        private readonly HistoriqueStatutRepository $historiques,
    ) {
    }

    /**
     * Enregistre une décision et journalise l'opération.
     *
     * @param bool $flush Mettre à false pour intégrer la décision dans une
     *                    transaction plus large (import par lot, par exemple).
     *
     * @throws TransitionInterditeException si la décision n'est pas permise
     */
    public function appliquer(
        Candidature $candidature,
        StatutCandidature $cible,
        ?User $auteur = null,
        ?string $motif = null,
        ?string $commentaire = null,
        bool $flush = true,
    ): Candidature {
        $actuel = $this->resolver->resolve($candidature);

        if ($actuel === $cible) {
            // Idempotence : réappliquer la même décision ne produit rien.
            return $candidature;
        }

        if (!$this->peutAppliquer($candidature, $cible)) {
            throw TransitionInterditeException::entre($actuel, $cible);
        }

        $this->enregistrerDecision($candidature, $cible);

        // Garde-fou : la décision enregistrée doit produire exactement l'étape
        // visée, faute de quoi deux colonnes de décision se contrediraient.
        $apres = $this->resolver->resolve($candidature);
        if ($apres !== $cible) {
            throw new \LogicException(sprintf(
                'La décision « %s » mène le dossier %s à « %s ».',
                $cible->libelle(),
                (string) $candidature->getNumero(),
                $apres->libelle()
            ));
        }

        $this->journaliser($candidature, $actuel, $cible, $auteur, $motif, $commentaire, $flush);

        $this->logger->info('Décision de candidature enregistrée', [
            'candidature' => $candidature->getNumero(),
            'de' => $actuel->name,
            'vers' => $cible->name,
            'auteur' => $auteur?->getUserIdentifier(),
            'motif' => $motif,
        ]);

        return $candidature;
    }

    /**
     * La décision visée est-elle permise à l'étape actuelle du dossier ?
     *
     * L'inscription n'en fait pas partie : elle ne se décide pas, elle
     * s'obtient par le règlement des frais de dossier.
     */
    public function peutAppliquer(Candidature $candidature, StatutCandidature $cible): bool
    {
        return $cible !== StatutCandidature::INSCRIT
            && $this->resolver->resolve($candidature)->peutAllerVers($cible);
    }

    /**
     * Journalise l'inscription d'un dossier dont les frais sont réglés.
     *
     * Aucune colonne n'est écrite : l'inscription se lit dans le couple
     * (étude acceptée, frais de dossier réglés). Rejouée, la méthode ne
     * produit rien.
     *
     * @return bool true si l'inscription vient d'être journalisée
     */
    public function constaterInscription(Candidature $candidature, ?User $auteur = null, ?string $motif = null): bool
    {
        if ($this->resolver->resolve($candidature) !== StatutCandidature::INSCRIT) {
            return false;
        }

        if ($this->historiques->aJournaliseArrivee($candidature, StatutCandidature::INSCRIT)) {
            return false;
        }

        $this->journaliser(
            $candidature,
            StatutCandidature::PREINSCRIT,
            StatutCandidature::INSCRIT,
            $auteur,
            $motif,
            null,
            true
        );

        return true;
    }

    /**
     * Journalise une décision qui ne modifie pas le statut du dossier.
     *
     * L'étude du dossier est dans ce cas, qu'elle soit acceptée ou refusée :
     * le dossier reste PREINSCRIT jusqu'au règlement des frais de dossier.
     */
    public function journaliserDecision(
        Candidature $candidature,
        ?User $auteur,
        string $motif,
        ?string $commentaire = null,
    ): void {
        $statut = $this->resolver->resolve($candidature);

        $this->entityManager->persist($this->ligneHistorique($candidature, $statut, $statut, $auteur, $motif, $commentaire));
        $this->entityManager->flush();
    }

    /**
     * Journalise l'entrée d'un dossier dans le parcours (règle R1.3).
     */
    public function journaliserDepot(Candidature $candidature, ?User $auteur = null, ?string $motif = null): void
    {
        $statut = $this->resolver->resolve($candidature);

        $this->entityManager->persist(
            $this->ligneHistorique($candidature, null, $statut, $auteur, $motif ?? 'Dépôt du dossier de candidature', null)
        );
        $this->entityManager->flush();

        $this->dispatcher->dispatch(new CandidatureStatutChangeEvent($candidature, null, $statut, $auteur, $motif));
    }

    /**
     * Correspondance unique entre une décision et sa colonne métier.
     */
    private function enregistrerDecision(Candidature $candidature, StatutCandidature $cible): void
    {
        $positif = CandidatureStatusResolver::DECISION_POSITIVE;
        $negatif = CandidatureStatusResolver::DECISION_NEGATIVE;

        match ($cible) {
            StatutCandidature::DOSSIER_RECEVABLE => $candidature->setRecStatut($positif),
            StatutCandidature::DOSSIER_NON_RECEVABLE => $candidature->setRecStatut($negatif),
            StatutCandidature::ELIGIBLE => $candidature->setEligStatut($positif),
            StatutCandidature::NON_ELIGIBLE => $candidature->setEligStatut($negatif),
            StatutCandidature::ADMISSIBLE => $candidature->setResultat($positif),
            StatutCandidature::NON_ADMISSIBLE => $candidature->setResultat($negatif),
            StatutCandidature::ADMIS_DEFINITIF => $candidature->setAdmis($positif),
            StatutCandidature::NON_ADMIS_DEFINITIF => $candidature->setAdmis($negatif),
            StatutCandidature::PREINSCRIT,
            StatutCandidature::INSCRIT => throw new \LogicException(sprintf(
                '« %s » n\'est pas une décision enregistrable.',
                $cible->libelle()
            )),
        };
    }

    private function journaliser(
        Candidature $candidature,
        StatutCandidature $avant,
        StatutCandidature $apres,
        ?User $auteur,
        ?string $motif,
        ?string $commentaire,
        bool $flush,
    ): void {
        $this->entityManager->persist($this->ligneHistorique($candidature, $avant, $apres, $auteur, $motif, $commentaire));

        if ($flush) {
            $this->entityManager->flush();
        }

        $this->dispatcher->dispatch(new CandidatureStatutChangeEvent($candidature, $avant, $apres, $auteur, $motif));
    }

    private function ligneHistorique(
        Candidature $candidature,
        ?StatutCandidature $avant,
        StatutCandidature $apres,
        ?User $auteur,
        ?string $motif,
        ?string $commentaire,
    ): HistoriqueStatut {
        return (new HistoriqueStatut())
            ->setCandidature($candidature)
            ->setStatutAvant($avant)
            ->setStatutApres($apres)
            ->setAuteur($auteur)
            ->setMotif($motif)
            ->setCommentaire($commentaire)
            ->setAdresseIp($this->requestStack->getCurrentRequest()?->getClientIp());
    }
}
