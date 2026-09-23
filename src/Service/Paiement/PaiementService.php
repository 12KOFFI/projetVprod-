<?php

namespace App\Service\Paiement;

use App\Entity\Candidature;
use App\Entity\Paiement;
use App\Entity\TransactionPaiement;
use App\Entity\User;
use App\Enum\MoyenPaiement;
use App\Enum\StatutCandidature;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use App\Event\PaiementReussiEvent;
use App\Exception\PaiementException;
use App\Exception\PasserelleIndisponibleException;
use App\Payment\Dto\PaymentRequest;
use App\Payment\Dto\PaymentResponse;
use App\Payment\PaymentGatewayInterface;
use App\Repository\PaiementRepository;
use App\Repository\TransactionPaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestration métier des trois règlements du parcours VAE (module M5).
 *
 * Le service décide de ce qui est payable et de ce qui est enregistré ; la
 * passerelle ne fait qu'exécuter. Aucune transition de statut n'est appliquée
 * ici : elle découle de PaiementReussiEvent, écouté par PaiementSubscriber
 * (règle métier R5.7).
 */
class PaiementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaiementRepository $paiementRepository,
        private readonly TransactionPaiementRepository $transactionRepository,
        private readonly PaymentGatewayInterface $passerelle,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly CandidatureStatusResolver $resolver,
        private readonly array $tarifs,
    ) {
    }

    /**
     * État des trois frais pour un dossier, dans l'ordre du parcours.
     *
     * Chaque ligne indique si le règlement est débloqué : l'écran s'appuie
     * dessus pour l'affichage, mais la garde qui compte reste celle appliquée à
     * l'initiation (règle métier R5.4).
     *
     * @return list<array{type: TypeFrais, paiement: ?Paiement, montant: string, debloque: bool, regle: bool, motif: ?string}>
     */
    public function tableauDeBord(Candidature $candidature): array
    {
        $lignes = [];

        foreach (TypeFrais::cases() as $type) {
            $paiement = $this->paiementRepository->findParTypePourCandidature($candidature, $type);
            $regle = $this->paiementRepository->existeReussi($candidature, $type);

            $lignes[] = [
                'type' => $type,
                'paiement' => $paiement,
                'montant' => $this->tarif($type),
                'debloque' => !$regle && $this->estDebloque($candidature, $type),
                'regle' => $regle,
                'motif' => $regle ? null : $this->motifDeBlocage($candidature, $type),
            ];
        }

        return $lignes;
    }

    /**
     * Un règlement n'est ouvert que si l'étape correspondante est atteinte.
     */
    public function estDebloque(Candidature $candidature, TypeFrais $type): bool
    {
        $statut = $this->resolver->resolve($candidature);

        return match ($type) {
            // Étude acceptée, dossier encore préinscrit : le paiement confirmé
            // le fera passer à INSCRIT (PaiementSubscriber).
            TypeFrais::DOSSIER => $this->resolver->estAccepteeEnAttentePaiement($candidature),
            TypeFrais::ACCOMPAGNEMENT => $statut === StatutCandidature::ELIGIBLE,
            TypeFrais::EXAMEN => $statut === StatutCandidature::ELIGIBLE,
        };
    }

    /**
     * Ouvre un règlement auprès de la passerelle.
     *
     * @throws PaiementException si le règlement n'est pas ouvert ou déjà réglé
     */
    public function initier(
        Candidature $candidature,
        TypeFrais $type,
        MoyenPaiement $moyen,
        User $candidat,
        ?string $urlRetour = null,
    ): Paiement {
        // Ces deux gardes sont la raison d'être du service : ni le template ni
        // le formulaire ne peuvent en dispenser (règles R5.4 et R5.5).
        if ($this->paiementRepository->existeReussi($candidature, $type)) {
            throw PaiementException::dejaRegle($type);
        }

        if (!$this->estDebloque($candidature, $type)) {
            throw PaiementException::nonDebloque($type);
        }

        if (!$this->passerelle->supporte($moyen)) {
            throw PaiementException::moyenNonSupporte($moyen->libelle());
        }

        $paiement = $this->obtenirOuCreer($candidature, $type, $candidat);
        $paiement->setMoyenPaiement($moyen);
        $paiement->setDateInitiation(new \DateTime());
        $paiement->incrementerTentatives();

        // La référence est propre à la tentative : deux essais sur un même
        // frais doivent rester distinguables dans le journal.
        $reference = $this->referenceTentative($candidature, $type, $paiement->getTentatives());
        $paiement->setReferencePaiement($reference);

        $this->entityManager->flush();

        $requete = new PaymentRequest(
            reference: $reference,
            // Le montant vient du barème, jamais de la requête du client (V5.1).
            montant: $this->tarif($type),
            moyenPaiement: $moyen,
            description: sprintf('%s — dossier %s', $type->libelle(), (string) $candidature->getNumero()),
            candidatNom: $candidat->getNomComplet(),
            candidatContact: $candidat->getContact(),
            urlRetour: $urlRetour,
            metadonnees: [
                'candidature' => $candidature->getNumero(),
                'type_frais' => $type->value,
            ],
        );

        try {
            $reponse = $this->passerelle->initier($requete);
        } catch (\Throwable $erreur) {
            $this->logger->error('Passerelle de paiement injoignable', [
                'reference' => $reference,
                'exception' => $erreur->getMessage(),
            ]);

            throw PasserelleIndisponibleException::injoignable();
        }

        $this->enregistrerTransaction($paiement, $reponse, $moyen);
        $this->appliquerReponse($paiement, $reponse);

        return $paiement;
    }

    /**
     * Confirme un règlement auprès de la passerelle, seule source de vérité.
     *
     * Le retour du navigateur ne prouve rien : l'état est systématiquement
     * revérifié auprès du fournisseur avant d'être entériné (S2).
     */
    public function confirmer(Paiement $paiement): Paiement
    {
        // Idempotence : un retour rejoué ne doit ni redéclencher l'événement,
        // ni dupliquer la transition de statut (R5.7 et V5.3).
        if ($paiement->estReussi()) {
            return $paiement;
        }

        $reference = (string) $paiement->getReferencePaiement();

        try {
            $reponse = $this->passerelle->verifier($reference);
        } catch (\Throwable $erreur) {
            $this->logger->error('Vérification de paiement impossible', [
                'reference' => $reference,
                'exception' => $erreur->getMessage(),
            ]);

            throw PasserelleIndisponibleException::injoignable();
        }

        $this->enregistrerTransaction($paiement, $reponse, $paiement->getMoyenPaiement());
        $this->appliquerReponse($paiement, $reponse);

        return $paiement;
    }

    /**
     * Le candidat a quitté la page de règlement : la tentative est abandonnée,
     * mais le frais reste payable (règle W5.3).
     */
    public function annuler(Paiement $paiement): Paiement
    {
        if ($paiement->estReussi()) {
            return $paiement;
        }

        $paiement->setStatut(StatutPaiement::EN_ATTENTE);

        $this->journaliser(
            $paiement,
            StatutPaiement::ANNULE,
            $this->referenceTechnique($paiement, 'annulation'),
            ['operation' => 'annulation_candidat']
        );

        $this->entityManager->flush();

        return $paiement;
    }

    /**
     * Rembourse un règlement abouti.
     *
     * Le remboursement n'annule pas l'avancement déjà acquis : il crée une
     * transaction inverse et marque le règlement, sans transition de retour
     * (règle métier R5.8).
     */
    public function rembourser(Paiement $paiement, ?User $auteur = null): Paiement
    {
        if (!$paiement->estReussi()) {
            throw PaiementException::remboursementImpossible();
        }

        $reponse = $this->passerelle->rembourser(
            (string) $paiement->getReferencePaiement(),
            (string) $paiement->getMontant()
        );

        $this->enregistrerTransaction($paiement, $reponse, $paiement->getMoyenPaiement());

        $paiement->setStatut(StatutPaiement::REMBOURSE);

        if ($auteur !== null) {
            $paiement->setUser($auteur);
        }

        $this->entityManager->flush();

        $this->logger->warning('Paiement remboursé', [
            'reference' => $paiement->getReferencePaiement(),
            'candidature' => $paiement->getCandidature()?->getNumero(),
            'auteur' => $auteur?->getUserIdentifier(),
        ]);

        return $paiement;
    }

    public function tarif(TypeFrais $type): string
    {
        $montant = $this->tarifs[$type->value] ?? null;

        if ($montant === null) {
            throw PaiementException::tarifAbsent($type);
        }

        return (string) $montant;
    }

    public function passerelleEstSimulee(): bool
    {
        return $this->passerelle->estSimulation();
    }

    /**
     * Raison pour laquelle un frais n'est pas encore réglable, à afficher au
     * candidat pour qu'il sache ce qu'il attend.
     */
    private function motifDeBlocage(Candidature $candidature, TypeFrais $type): ?string
    {
        if ($this->estDebloque($candidature, $type)) {
            return null;
        }

        return match ($type) {
            TypeFrais::DOSSIER => "Disponible dès que le conseiller VAE a accepté votre dossier.",
            TypeFrais::ACCOMPAGNEMENT => 'Disponible après la décision d\'éligibilité du jury central.',
            TypeFrais::EXAMEN => 'Disponible après la décision d\'éligibilité du jury central.',
        };
    }

    private function obtenirOuCreer(Candidature $candidature, TypeFrais $type, User $candidat): Paiement
    {
        $paiement = $this->paiementRepository->findParTypePourCandidature($candidature, $type);

        if ($paiement === null) {
            $paiement = new Paiement();
            $paiement->setCandidature($candidature);
            $paiement->setType($type);
            $paiement->setStatut(StatutPaiement::EN_ATTENTE);
            $paiement->setUser($candidat);

            $this->entityManager->persist($paiement);
        }

        // Le tarif est relu à chaque tentative : un barème modifié entre-temps
        // s'applique tant que le frais n'est pas réglé.
        $paiement->setMontant($this->tarif($type));

        return $paiement;
    }

    /**
     * Reporte l'état renvoyé par la passerelle sur le règlement, et signale le
     * succès au reste de l'application.
     */
    private function appliquerReponse(Paiement $paiement, PaymentResponse $reponse): void
    {
        if ($reponse->identifiantExterne !== null) {
            $paiement->setIdentifiantExterne($reponse->identifiantExterne);
        }

        $dejaReussi = $paiement->estReussi();
        $paiement->setStatut($reponse->statut);

        if ($reponse->estReussi()) {
            $paiement->setDatePaiement(new \DateTime());
        }

        $this->entityManager->flush();

        // L'événement n'est émis qu'au passage effectif à « réussi » : sans
        // cette garde, un webhook rejoué relancerait la transition de statut.
        if ($reponse->estReussi() && !$dejaReussi) {
            $this->logger->info('Paiement abouti', [
                'reference' => $paiement->getReferencePaiement(),
                'type' => $paiement->getType()->value,
                'candidature' => $paiement->getCandidature()?->getNumero(),
            ]);

            $this->dispatcher->dispatch(new PaiementReussiEvent($paiement));
        }
    }

    private function enregistrerTransaction(
        Paiement $paiement,
        PaymentResponse $reponse,
        ?MoyenPaiement $moyen,
    ): TransactionPaiement {
        return $this->journaliser(
            $paiement,
            $reponse->statut,
            $this->referenceUnique($reponse->referenceTransaction),
            $reponse->reponseBrute,
            $reponse->identifiantExterne,
            $reponse->codeErreur,
            $reponse->messageErreur,
            $moyen,
        );
    }

    /**
     * @param array<string, mixed> $reponseBrute
     */
    private function journaliser(
        Paiement $paiement,
        StatutPaiement $statut,
        string $reference,
        array $reponseBrute,
        ?string $identifiantExterne = null,
        ?string $codeErreur = null,
        ?string $messageErreur = null,
        ?MoyenPaiement $moyen = null,
    ): TransactionPaiement {
        $transaction = (new TransactionPaiement())
            ->setPaiement($paiement)
            ->setReference($reference)
            ->setIdentifiantExterne($identifiantExterne)
            ->setMontant((string) $paiement->getMontant())
            ->setStatut($statut)
            ->setMoyenPaiement(($moyen ?? $paiement->getMoyenPaiement())?->value)
            ->setPasserelle($this->passerelle->nom())
            ->setCodeErreur($codeErreur)
            ->setMessageErreur($messageErreur)
            ->setReponseFournisseur($reponseBrute)
            ->setAdresseIp($this->requestStack->getCurrentRequest()?->getClientIp());

        $this->entityManager->persist($transaction);

        return $transaction;
    }

    private function referenceTentative(Candidature $candidature, TypeFrais $type, int $tentative): string
    {
        return sprintf(
            '%s-%s-%02d',
            (string) $candidature->getNumero(),
            strtoupper(substr($type->value, 0, 3)),
            $tentative
        );
    }

    /**
     * La référence d'une transaction est unique en base : plusieurs lignes
     * peuvent découler d'une même référence de règlement (initiation, puis
     * confirmation), on la suffixe donc.
     */
    private function referenceUnique(string $base): string
    {
        return substr($base . '-' . strtoupper(bin2hex(random_bytes(3))), 0, 100);
    }

    private function referenceTechnique(Paiement $paiement, string $operation): string
    {
        return substr(
            sprintf('%s-%s-%s', (string) $paiement->getReferencePaiement(), $operation, bin2hex(random_bytes(2))),
            0,
            100
        );
    }
}
