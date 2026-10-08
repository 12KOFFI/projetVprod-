<?php

namespace App\Payment;

/**
 * Identifiants du fournisseur de paiement actif, quel qu'il soit (Trésor Pay,
 * Orange Money, Wave…).
 *
 * Les variables d'environnement sont volontairement neutres (PAIEMENT_*) :
 * changer de fournisseur ne change ni leurs noms ni le code qui les lit.
 * Chaque passerelle réelle reçoit cet objet et y prend ce dont elle a besoin.
 * Les valeurs viennent de .env.local, jamais du dépôt.
 */
final class ConfigurationPasserelle
{
    public function __construct(
        /** Adresse de base de l'API du fournisseur (https obligatoire). */
        public readonly string $urlApi,
        /** Identifiant marchand de la DAIP chez le fournisseur. */
        public readonly string $identifiantMarchand,
        /** Clé ou jeton d'API (secret). */
        public readonly string $cleApi,
        /** Secret partagé de signature des notifications (secret). */
        public readonly string $secretNotification,
        /** Adresse publique de notification, déclarée chez le fournisseur. */
        public readonly string $urlNotification,
    ) {
    }

    /**
     * Noms des variables obligatoires restées vides.
     *
     * @return string[]
     */
    public function manquants(): array
    {
        return array_keys(array_filter([
            'PAIEMENT_API_URL' => $this->urlApi,
            'PAIEMENT_MARCHAND_ID' => $this->identifiantMarchand,
            'PAIEMENT_API_CLE' => $this->cleApi,
        ], static fn (string $valeur) => trim($valeur) === ''));
    }

    public function estChiffree(): bool
    {
        return str_starts_with($this->urlApi, 'https://');
    }

    /** Hôte de l'API, seul domaine vers lequel le candidat peut être redirigé. */
    public function hote(): ?string
    {
        return parse_url($this->urlApi, \PHP_URL_HOST) ?: null;
    }
}
