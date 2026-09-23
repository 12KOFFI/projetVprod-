<?php

namespace App\Enum;

/**
 * Les deux décisions rendues par le jury central et importées par fichier.
 *
 * Chaque type porte tout ce qui le distingue : intitulé de la feuille, colonnes
 * attendues, statut pré-requis de la candidature et statuts résultants. Ajouter
 * un troisième import reviendrait à compléter cette énumération, sans toucher
 * au service qui la consomme.
 */
enum TypeImportJury: string
{
    case ELIGIBILITE = 'eligibilite';
    case ADMISSION   = 'admission';

    public function libelle(): string
    {
        return match ($this) {
            self::ELIGIBILITE => 'Éligibilité',
            self::ADMISSION   => 'Admission définitive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ELIGIBILITE => 'Décision du jury central sur l\'éligibilité des candidats dont le dossier a été déclaré recevable.',
            self::ADMISSION   => 'Décision du jury central sur l\'admission définitive des candidats admissibles.',
        };
    }

    /** Nom de la feuille attendue dans le classeur. */
    public function feuille(): string
    {
        return $this->value;
    }

    /**
     * En-têtes attendus, dans l'ordre des colonnes (validation V6.2).
     *
     * @return list<string>
     */
    public function colonnes(): array
    {
        return match ($this) {
            self::ELIGIBILITE => ['NUMERO_VAE', 'NOM', 'PRENOMS', 'DECISION', 'DATE_JURY', 'OBSERVATION'],
            self::ADMISSION   => ['NUMERO_VAE', 'NOM', 'PRENOMS', 'DECISION', 'DATE_JURY', 'MENTION', 'OBSERVATION'],
        };
    }

    /**
     * Seules ces colonnes doivent être renseignées pour qu'une ligne soit
     * traitable ; les autres sont indicatives ou facultatives.
     *
     * NOM et PRENOMS en font partie : ils sont le seul contrôle capable de
     * détecter une faute de frappe sur le numéro VAE, qui désignerait sinon
     * un autre dossier réel sans que rien ne le signale.
     *
     * @return list<string>
     */
    public function colonnesObligatoires(): array
    {
        return ['NUMERO_VAE', 'NOM', 'PRENOMS', 'DECISION'];
    }

    /**
     * Statut que la candidature doit avoir pour être traitée par cet import
     * (règles métier R6.1 et R6.2).
     */
    public function statutRequis(): StatutCandidature
    {
        return match ($this) {
            self::ELIGIBILITE => StatutCandidature::DOSSIER_RECEVABLE,
            self::ADMISSION   => StatutCandidature::ADMISSIBLE,
        };
    }

    /**
     * Décisions acceptées dans la colonne DECISION, et statut visé par chacune.
     *
     * @return array<string, StatutCandidature>
     */
    public function decisions(): array
    {
        return match ($this) {
            self::ELIGIBILITE => [
                'ELIGIBLE'     => StatutCandidature::ELIGIBLE,
                'NON_ELIGIBLE' => StatutCandidature::NON_ELIGIBLE,
            ],
            self::ADMISSION => [
                'ADMIS'     => StatutCandidature::ADMIS_DEFINITIF,
                'NON_ADMIS' => StatutCandidature::NON_ADMIS_DEFINITIF,
            ],
        };
    }

    /**
     * Statuts signalant qu'une décision a déjà été rendue : une seconde ligne
     * portant sur le même dossier est ignorée, pas appliquée (règle R6.8).
     *
     * @return list<StatutCandidature>
     */
    public function statutsDejaDecides(): array
    {
        return array_values($this->decisions());
    }

    public function icone(): string
    {
        return match ($this) {
            self::ELIGIBILITE => 'user-check',
            self::ADMISSION   => 'award',
        };
    }
}
