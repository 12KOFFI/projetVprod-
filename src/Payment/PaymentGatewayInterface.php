<?php

namespace App\Payment;

use App\Enum\MoyenPaiement;
use App\Payment\Dto\PaymentRequest;
use App\Payment\Dto\PaymentResponse;

/**
 * Contrat que doit remplir toute passerelle de paiement.
 *
 * La logique métier dépend de cette interface, jamais d'une implémentation :
 * brancher la vraie API Trésor Pay reviendra à écrire une seconde classe et à
 * changer l'alias déclaré dans services.yaml, sans toucher au domaine.
 */
interface PaymentGatewayInterface
{
    /**
     * Ouvre une transaction auprès de la passerelle.
     */
    public function initier(PaymentRequest $requete): PaymentResponse;

    /**
     * Interroge la passerelle sur l'état réel d'une transaction.
     *
     * C'est cette réponse qui fait foi, jamais ce que rapporte le navigateur
     * du candidat au retour (règle de sécurité S2).
     */
    public function verifier(string $referenceTransaction): PaymentResponse;

    public function annuler(string $referenceTransaction): PaymentResponse;

    public function rembourser(string $referenceTransaction, string $montant): PaymentResponse;

    public function supporte(MoyenPaiement $moyen): bool;

    /**
     * Identifiant technique de la passerelle, journalisé sur chaque
     * transaction pour savoir laquelle a traité quel règlement.
     */
    public function nom(): string;

    /**
     * Une passerelle de simulation le signale, afin que l'interface avertisse
     * clairement qu'aucun règlement réel n'a lieu.
     */
    public function estSimulation(): bool;
}
