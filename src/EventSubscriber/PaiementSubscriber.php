<?php

namespace App\EventSubscriber;

use App\Enum\StatutCandidature;
use App\Event\PaiementReussiEvent;
use App\Service\Candidature\TransitionCandidature;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Journalise l'avancement qu'emporte un règlement abouti.
 *
 * Le règlement ne modifie aucune décision : l'inscription se lit dans le
 * couple (étude acceptée, frais de dossier réglés) calculé par
 * CandidatureStatusResolver. Ce subscriber se contente de la tracer dans
 * l'historique (règle métier R5.7).
 */
class PaiementSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TransitionCandidature $transition,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PaiementReussiEvent::class => 'onPaiementReussi',
        ];
    }

    public function onPaiementReussi(PaiementReussiEvent $evenement): void
    {
        $candidature = $evenement->getCandidature();
        $type = $evenement->getType();

        // Les frais d'accompagnement et d'examen lèvent une garde de paiement
        // mais ne font franchir aucune étape à eux seuls.
        if ($type->statutApresPaiement() !== StatutCandidature::INSCRIT) {
            return;
        }

        $journalisee = $this->transition->constaterInscription(
            $candidature,
            // Auteur nul : l'inscription découle du règlement, non d'un agent.
            null,
            sprintf('Règlement des %s', mb_strtolower($type->libelle()))
        );

        if (!$journalisee) {
            // Webhook rejoué ou dossier déjà au-delà de l'inscription : ce n'est
            // pas une anomalie métier, mais cela mérite une trace.
            $this->logger->notice('Inscription consécutive au paiement déjà journalisée ou non atteinte', [
                'candidature' => $candidature->getNumero(),
            ]);
        }
    }
}
