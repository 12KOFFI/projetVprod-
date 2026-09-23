<?php

namespace App\Enum;

/**
 * Les trois paiements du parcours VAE.
 * Référence : specifications_vae.txt, section 4 « Récapitulatif des 3 paiements ».
 *
 * Les valeurs correspondent aux valeurs déjà stockées dans paiement.type_frais.
 */
enum TypeFrais: string
{
    case DOSSIER        = 'dossier';
    case ACCOMPAGNEMENT = 'accompagnement';
    case EXAMEN         = 'examen';

    public function libelle(): string
    {
        return match ($this) {
            self::DOSSIER        => 'Frais de dossier',
            self::ACCOMPAGNEMENT => 'Frais d\'accompagnement',
            self::EXAMEN         => 'Frais d\'examen',
        };
    }

    /**
     * Rang du paiement dans le parcours (1er, 2e, 3e).
     */
    public function ordre(): int
    {
        return match ($this) {
            self::DOSSIER        => 1,
            self::ACCOMPAGNEMENT => 2,
            self::EXAMEN         => 3,
        };
    }

    /**
     * Statut atteint automatiquement lorsque ce paiement est réussi (et
     * confirmé — PaiementSubscriber ne réagit qu'à un règlement abouti),
     * ou null si le paiement ne provoque pas de transition à lui seul.
     */
    public function statutApresPaiement(): ?StatutCandidature
    {
        return match ($this) {
            self::DOSSIER        => StatutCandidature::INSCRIT,
            self::ACCOMPAGNEMENT => null,
            self::EXAMEN         => null,
        };
    }
}
