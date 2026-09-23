<?php

namespace App\Enum;

/**
 * Résultat de la décision de recevabilité rendue par le conseiller VAE,
 * une fois le dossier INSCRIT (paiement des frais de dossier confirmé).
 *
 * Ce n'est PAS le statut global de la candidature : c'est la décision portée
 * par Candidature::$recStatut. RecevabiliteService la fait enregistrer par
 * TransitionCandidature (INSCRIT → DOSSIER_RECEVABLE ou
 * DOSSIER_NON_RECEVABLE), et CandidatureStatusResolver en déduit le statut.
 *
 * Les valeurs reprennent la convention des colonnes de statut existantes
 * (0 = attente, 1 = négatif, 2 = positif).
 */
enum StatutRecevabilite: int
{
    case EN_ATTENTE   = 0;
    case NON_RECEVABLE = 1;
    case RECEVABLE     = 2;

    public function libelle(): string
    {
        return match ($this) {
            self::EN_ATTENTE    => 'En attente',
            self::NON_RECEVABLE => 'Non recevable',
            self::RECEVABLE     => 'Recevable',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EN_ATTENTE    => 'slate',
            self::NON_RECEVABLE => 'red',
            self::RECEVABLE     => 'green',
        };
    }

    /**
     * Statut officiel déclenché par ce résultat, appelé explicitement par
     * RecevabiliteService — jamais automatiquement.
     */
    public function statutOfficiel(): StatutCandidature
    {
        return match ($this) {
            self::RECEVABLE     => StatutCandidature::DOSSIER_RECEVABLE,
            self::NON_RECEVABLE => StatutCandidature::DOSSIER_NON_RECEVABLE,
            self::EN_ATTENTE    => throw new \LogicException('EN_ATTENTE ne déclenche aucune transition.'),
        };
    }
}
