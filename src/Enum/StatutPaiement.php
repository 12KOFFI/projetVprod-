<?php

namespace App\Enum;

/**
 * Cycle de vie d'un paiement, aligné sur les états qu'une passerelle réelle
 * (Trésor Pay) est capable de renvoyer.
 *
 * La valeur historique 'paye' présente en base est migrée vers 'reussi'.
 */
enum StatutPaiement: string
{
    case EN_ATTENTE = 'en_attente';
    case REUSSI     = 'reussi';
    case ECHOUE     = 'echoue';
    case ANNULE     = 'annule';
    case REMBOURSE  = 'rembourse';

    public function libelle(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::REUSSI     => 'Payé',
            self::ECHOUE     => 'Échoué',
            self::ANNULE     => 'Annulé',
            self::REMBOURSE  => 'Remboursé',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'amber',
            self::REUSSI     => 'green',
            self::ECHOUE     => 'red',
            self::ANNULE     => 'slate',
            self::REMBOURSE  => 'purple',
        };
    }

    /**
     * Un paiement dans cet état peut-il encore être retenté par le candidat ?
     */
    public function estRejouable(): bool
    {
        return in_array($this, [self::EN_ATTENTE, self::ECHOUE, self::ANNULE], true);
    }
}
