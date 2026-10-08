<?php

namespace App\Referentiel;

use function Symfony\Component\String\u;

/**
 * Indicatifs téléphoniques proposés dans les champs de numéro : les mêmes
 * cinquante pays que les nationalités (Nationalites::LISTE), Côte d'Ivoire en
 * tête. Le choix porte sur le pays (code ISO) et non sur l'indicatif, parce que
 * deux pays peuvent le partager (+1 pour les États-Unis et le Canada).
 */
final class Indicatifs
{
    public const PAYS_PAR_DEFAUT = 'CI';

    /** Code ISO 3166-1 alpha-2 => indicatif, sans le « + ». */
    public const LISTE = [
        'CI' => '225', 'BJ' => '229', 'BF' => '226', 'CV' => '238', 'GM' => '220',
        'GH' => '233', 'GN' => '224', 'GW' => '245', 'LR' => '231', 'ML' => '223',
        'NE' => '227', 'NG' => '234', 'SN' => '221', 'SL' => '232', 'TG' => '228',
        'MR' => '222', 'CM' => '237', 'TD' => '235', 'CF' => '236', 'GA' => '241',
        'CG' => '242', 'CD' => '243', 'GQ' => '240', 'RW' => '250', 'BI' => '257',
        'AO' => '244', 'KE' => '254', 'ET' => '251', 'ZA' => '27', 'MG' => '261',
        'MA' => '212', 'DZ' => '213', 'TN' => '216', 'EG' => '20', 'LY' => '218',
        'LB' => '961', 'SY' => '963', 'CN' => '86', 'IN' => '91', 'TR' => '90',
        'FR' => '33', 'BE' => '32', 'CH' => '41', 'ES' => '34', 'IT' => '39',
        'DE' => '49', 'GB' => '44', 'US' => '1', 'CA' => '1', 'HT' => '509',
    ];

    /**
     * Pays retenu quand un indicatif est partagé, pour relire un numéro
     * enregistré (« +1… » est présenté comme américain).
     */
    private const PAYS_PREFERE = ['1' => 'US'];

    /**
     * Choix pour un ChoiceType : « Côte d'Ivoire (+225) » => code pays.
     * Côte d'Ivoire en tête, les autres par nom de pays.
     *
     * @return array<string, string>
     */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::LISTE as $code => $indicatif) {
            if ($code !== self::PAYS_PAR_DEFAUT) {
                $choix[self::libelle($code)] = $code;
            }
        }
        uksort($choix, static fn (string $a, string $b): int => strcmp(
            u($a)->ascii()->lower()->toString(),
            u($b)->ascii()->lower()->toString(),
        ));

        return [self::libelle(self::PAYS_PAR_DEFAUT) => self::PAYS_PAR_DEFAUT] + $choix;
    }

    public static function libelle(string $code): string
    {
        return Nationalites::LISTE[$code][1] . ' (+' . self::LISTE[$code] . ')';
    }

    /** Pays d'un indicatif (le plus long qui préfixe le numéro), ou null. */
    public static function paysDuNumero(string $chiffres): ?string
    {
        for ($longueur = 3; $longueur >= 1; --$longueur) {
            $indicatif = substr($chiffres, 0, $longueur);
            if (isset(self::PAYS_PREFERE[$indicatif])) {
                return self::PAYS_PREFERE[$indicatif];
            }
            $code = array_search($indicatif, self::LISTE, true);
            if ($code !== false) {
                return $code;
            }
        }

        return null;
    }
}
