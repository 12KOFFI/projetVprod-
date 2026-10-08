<?php

namespace App\Referentiel;

use App\Entity\Certification;

/**
 * Listes fermées du profil professionnel d'un dossier (situation, diplôme
 * académique, langue d'évaluation) et règle « expérience → diplôme visé ».
 *
 * Les codes sont ceux enregistrés en base ; les libellés, ceux affichés partout
 * (formulaires, fiches, impression). Une valeur AUTRE s'accompagne toujours
 * d'une précision saisie en clair.
 */
final class ProfilProfessionnel
{
    public const AUTRE = 'AUTRE';

    /** La VAE demande au moins 5 ans d'expérience (CQP) ; 7 ans ouvrent le CAP. */
    public const ANNEES_CQP = 5;
    public const ANNEES_CAP = 7;

    /** Le code ARTISAN A SON COMPTE est conservé : seuls les libellés ont changé. */
    public const SITUATIONS = [
        'ARTISAN A SON COMPTE' => 'Artisan',
        'SALARIE' => "Salarié d'une entreprise",
        self::AUTRE => 'Autre',
    ];

    public const DIPLOMES = [
        'CEPE' => 'CEPE',
        'BEPC' => 'BEPC',
        'BAC' => 'BAC',
        'SUPERIEUR' => 'Supérieur au BAC',
        self::AUTRE => 'Autre',
    ];

    public const LANGUES = [
        'FRANCAIS' => 'Français',
        'LANGUE LOCALE' => 'Langue locale',
        self::AUTRE => 'Autre',
    ];

    /**
     * Choix d'un ChoiceType (libellé => code).
     *
     * @param array<string, string> $liste
     *
     * @return array<string, string>
     */
    public static function choix(array $liste): array
    {
        return array_flip($liste);
    }

    /**
     * Libellé d'affichage : « Autre : <précision> » pour une valeur AUTRE, le
     * code lui-même s'il n'appartient plus à la liste.
     *
     * @param array<string, string> $liste
     */
    public static function libelle(array $liste, ?string $code, ?string $precision = null): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $precision = trim((string) $precision);

        if ($code === self::AUTRE && $precision !== '') {
            return 'Autre : ' . $precision;
        }

        return $liste[$code] ?? $code;
    }

    /**
     * Type de certification accessible pour une durée d'expérience : CQP de 5 à
     * 6 ans, CAP à partir de 7 ans, aucun en dessous de 5 ans.
     */
    public static function typeCertificationPour(?int $annees): ?string
    {
        return match (true) {
            $annees === null, $annees < self::ANNEES_CQP => null,
            $annees < self::ANNEES_CAP => Certification::TYPE_CQP,
            default => Certification::TYPE_CAP,
        };
    }

    /**
     * Type de diplôme proposé compte tenu de l'offre du couple (centre,
     * métier) : un candidat de 7 ans ou plus vise le CAP, mais se rabat sur le
     * CQP quand le centre ne prépare aucun CAP pour ce métier.
     *
     * @param string[] $typesOfferts types des certifications proposées
     */
    public static function typeRetenu(?int $annees, array $typesOfferts): ?string
    {
        $type = self::typeCertificationPour($annees);

        if ($type === Certification::TYPE_CAP && !in_array(Certification::TYPE_CAP, $typesOfferts, true)) {
            return Certification::TYPE_CQP;
        }

        return $type;
    }
}
