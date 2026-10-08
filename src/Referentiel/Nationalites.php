<?php

namespace App\Referentiel;

use App\Entity\User;

use function Symfony\Component\String\u;

/**
 * Nationalités proposées à l'inscription, au format « Ivoirienne · Côte d'Ivoire ».
 *
 * Cinquante nationalités, choisies parmi les plus présentes en Côte d'Ivoire :
 * la CEDEAO, le reste de l'Afrique, puis les pays d'Asie, d'Europe et
 * d'Amérique les plus représentés. « Autre nationalité » couvre les autres
 * cas. Symfony (Intl) ne fournit que les noms de pays, pas l'adjectif de
 * nationalité : la liste est donc tenue ici, au féminin (« nationalité
 * ivoirienne »).
 *
 * La valeur enregistrée est le nom du pays en majuscules sans accents, forme
 * déjà en base pour la Côte d'Ivoire (User::NATIONALITE_IVOIRIENNE).
 */
final class Nationalites
{
    public const AUTRE = 'AUTRE';

    /** Code ISO 3166-1 alpha-2 => [nationalité, pays]. */
    public const LISTE = [
        // CEDEAO
        'CI' => ['Ivoirienne', "Côte d'Ivoire"],
        'BJ' => ['Béninoise', 'Bénin'],
        'BF' => ['Burkinabè', 'Burkina Faso'],
        'CV' => ['Cap-verdienne', 'Cap-Vert'],
        'GM' => ['Gambienne', 'Gambie'],
        'GH' => ['Ghanéenne', 'Ghana'],
        'GN' => ['Guinéenne', 'Guinée'],
        'GW' => ['Bissau-guinéenne', 'Guinée-Bissau'],
        'LR' => ['Libérienne', 'Liberia'],
        'ML' => ['Malienne', 'Mali'],
        'NE' => ['Nigérienne', 'Niger'],
        'NG' => ['Nigériane', 'Nigeria'],
        'SN' => ['Sénégalaise', 'Sénégal'],
        'SL' => ['Sierra-léonaise', 'Sierra Leone'],
        'TG' => ['Togolaise', 'Togo'],
        // Reste de l'Afrique
        'MR' => ['Mauritanienne', 'Mauritanie'],
        'CM' => ['Camerounaise', 'Cameroun'],
        'TD' => ['Tchadienne', 'Tchad'],
        'CF' => ['Centrafricaine', 'Centrafrique'],
        'GA' => ['Gabonaise', 'Gabon'],
        'CG' => ['Congolaise', 'Congo'],
        'CD' => ['Congolaise', 'Congo (République démocratique)'],
        'GQ' => ['Équato-guinéenne', 'Guinée équatoriale'],
        'RW' => ['Rwandaise', 'Rwanda'],
        'BI' => ['Burundaise', 'Burundi'],
        'AO' => ['Angolaise', 'Angola'],
        'KE' => ['Kényane', 'Kenya'],
        'ET' => ['Éthiopienne', 'Éthiopie'],
        'ZA' => ['Sud-africaine', 'Afrique du Sud'],
        'MG' => ['Malgache', 'Madagascar'],
        'MA' => ['Marocaine', 'Maroc'],
        'DZ' => ['Algérienne', 'Algérie'],
        'TN' => ['Tunisienne', 'Tunisie'],
        'EG' => ['Égyptienne', 'Égypte'],
        'LY' => ['Libyenne', 'Libye'],
        // Asie et Moyen-Orient
        'LB' => ['Libanaise', 'Liban'],
        'SY' => ['Syrienne', 'Syrie'],
        'CN' => ['Chinoise', 'Chine'],
        'IN' => ['Indienne', 'Inde'],
        'TR' => ['Turque', 'Turquie'],
        // Europe
        'FR' => ['Française', 'France'],
        'BE' => ['Belge', 'Belgique'],
        'CH' => ['Suisse', 'Suisse'],
        'ES' => ['Espagnole', 'Espagne'],
        'IT' => ['Italienne', 'Italie'],
        'DE' => ['Allemande', 'Allemagne'],
        'GB' => ['Britannique', 'Royaume-Uni'],
        // Amérique
        'US' => ['Américaine', 'États-Unis'],
        'CA' => ['Canadienne', 'Canada'],
        'HT' => ['Haïtienne', 'Haïti'],
    ];

    /**
     * Choix pour un ChoiceType : libellé « Nationalité · Pays » => valeur
     * enregistrée. La Côte d'Ivoire en tête, les autres par ordre alphabétique,
     * « Autre nationalité » en dernier.
     *
     * @return array<string, string>
     */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::LISTE as $code => [$nationalite, $pays]) {
            if ($code !== 'CI') {
                $choix[$nationalite . ' · ' . $pays] = self::valeur($code);
            }
        }
        uksort($choix, static fn (string $a, string $b): int => strcmp(
            u($a)->ascii()->lower()->toString(),
            u($b)->ascii()->lower()->toString(),
        ));

        return ['Ivoirienne · Côte d\'Ivoire' => User::NATIONALITE_IVOIRIENNE]
            + $choix
            + ['Autre nationalité' => self::AUTRE];
    }

    /** Valeur enregistrée pour un code pays. */
    public static function valeur(string $code): string
    {
        if ($code === 'CI') {
            return User::NATIONALITE_IVOIRIENNE;
        }

        return u(self::LISTE[$code][1])->ascii()->upper()->toString();
    }
}
