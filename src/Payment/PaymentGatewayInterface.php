<?php

namespace App\Payment;

use App\Enum\MoyenPaiement;
use App\Payment\Dto\PaymentRequest;
use App\Payment\Dto\PaymentResponse;

/**
 * Contrat que doit remplir toute passerelle de paiement.
 *
 * La logique métier dépend de cette interface, jamais d'une implémentation.
 *
 * AJOUTER UN FOURNISSEUR (Orange Money, Wave, MTN…) :
 *   1. créer une classe dans src/Payment/Gateway/ qui implémente cette
 *      interface et porte l'attribut #[AutoconfigureTag(SelecteurPasserelle::TAG)] ;
 *   2. lui donner une clé unique (cle()) — par exemple « wave » ;
 *   3. dans .env.local : PAIEMENT_PASSERELLE=wave et les variables PAIEMENT_*.
 * Aucun autre fichier ne change : le sélecteur la découvre tout seul.
 */
interface PaymentGatewayInterface
{
    /**
     * Clé de la passerelle, valeur attendue dans PAIEMENT_PASSERELLE
     * (« simulation », « tresor_pay », « wave »…).
     */
    public static function cle(): string;

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

    /**
     * Authentifie une notification serveur à serveur du fournisseur et en
     * renvoie la référence de transaction ; null si elle n'est pas
     * authentique. Chaque fournisseur lit SON en-tête de signature parmi
     * $entetes (noms en minuscules). L'appelant ne se fie jamais au statut
     * transmis : il revérifie par verifier().
     *
     * @param array<string, string> $entetes
     */
    public function lireNotification(string $corps, array $entetes): ?string;

    /**
     * Hôte de la page de paiement du fournisseur, seule destination de
     * redirection admise ; null pour une passerelle sans page externe.
     */
    public function hoteAutorise(): ?string;
}
