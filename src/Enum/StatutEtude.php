<?php

namespace App\Enum;

/**
 * Résultat de l'étude du dossier par le conseiller VAE (1re analyse).
 * Référence : specifications_vae.txt, étape 2 et section 5.3.
 *
 * Ce n'est PAS un statut officiel de candidature : aucune valeur ne déclenche
 * de transition. Le dossier reste PREINSCRIT quel que soit le résultat ; une
 * étude ACCEPTE ouvre le paiement des frais de dossier, dont la confirmation
 * fait passer le dossier à INSCRIT. Une étude REFUSE laisse le dossier
 * modifiable et resoumissible à une nouvelle étude.
 *
 * Les valeurs entières reprennent la convention déjà utilisée par les colonnes
 * de statut existantes de la table candidature (0 = attente, 1 = négatif, 2 = positif).
 */
enum StatutEtude: int
{
    case EN_ATTENTE = 0;
    case REFUSE     = 1;
    case ACCEPTE    = 2;

    public function libelle(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::REFUSE      => 'Refusé',
            self::ACCEPTE     => 'Accepté',
        };
    }

    /**
     * Libellé de l'étape « Étude de dossier » dans le parcours du candidat.
     */
    public function libelleParcours(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'Étude en cours',
            self::ACCEPTE     => 'Dossier accepté',
            self::REFUSE      => 'Dossier refusé',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'slate',
            self::REFUSE      => 'red',
            self::ACCEPTE     => 'green',
        };
    }

    /**
     * Un refus doit être motivé (règle métier R4.4).
     */
    public function exigeCommentaire(): bool
    {
        return $this === self::REFUSE;
    }
}
