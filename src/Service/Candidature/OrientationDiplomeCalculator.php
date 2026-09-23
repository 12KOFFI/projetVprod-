<?php

namespace App\Service\Candidature;

/**
 * Détermine le diplôme visé à partir de l'expérience professionnelle déclarée.
 *
 * Règle métier :
 *   - au moins 5 ans d'expérience -> CQP
 *   - au moins 7 ans d'expérience -> CAP
 *
 * En dessous de 5 ans, le candidat est tout de même orienté vers le CQP : ce
 * palier plancher reste la référence par défaut plutôt que de bloquer le
 * dossier.
 */
class OrientationDiplomeCalculator
{
    public const CQP = 'CQP';
    public const CAP = 'CAP';

    private const SEUIL_CAP = 7;

    public function calculer(int $anneesExperience): string
    {
        return $anneesExperience >= self::SEUIL_CAP ? self::CAP : self::CQP;
    }
}
