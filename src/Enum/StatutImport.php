<?php

namespace App\Enum;

/**
 * Issue d'un import de décisions du jury central.
 *
 * « Annulé » désigne un import entièrement remis en arrière parce que le taux
 * d'erreur dépassait le seuil : le fichier est réputé mauvais, aucune de ses
 * lignes n'a été appliquée (règle métier R6.6).
 */
enum StatutImport: string
{
    case EN_COURS = 'en_cours';
    case TERMINE  = 'termine';
    case ANNULE   = 'annule';

    public function libelle(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::TERMINE  => 'Terminé',
            self::ANNULE   => 'Annulé',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EN_COURS => 'amber',
            self::TERMINE  => 'green',
            self::ANNULE   => 'red',
        };
    }
}
