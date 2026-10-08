<?php

namespace App\Referentiel;

/**
 * Numéros de téléphone au format international.
 *
 * Un numéro est enregistré complet, sans espace : « +2250707080910 ». Les
 * numéros ivoiriens gardent leurs dix chiffres ; ceux des autres pays en
 * comptent de 6 à 13 (règle simple, sans annuaire des formats nationaux).
 * Les anciens numéros enregistrés sur dix chiffres sans indicatif sont lus
 * comme ivoiriens.
 */
final class Telephone
{
    public const CHIFFRES_COTE_D_IVOIRE = 10;
    public const CHIFFRES_MIN = 6;
    public const CHIFFRES_MAX = 13;

    /**
     * Pays dont le 0 initial fait partie du numéro international. Ailleurs, le 0
     * composé dans le pays (« 06… » en France) tombe : « +33 6… ».
     */
    private const ZERO_CONSERVE = ['CI', 'IT'];

    /**
     * Saisie (pays + numéro local) => numéro enregistré, ou null si vide.
     *
     * @throws \InvalidArgumentException si le numéro n'a pas la bonne longueur
     */
    public static function normaliser(string $pays, ?string $saisie): ?string
    {
        $indicatif = Indicatifs::LISTE[$pays] ?? null;
        if ($indicatif === null) {
            throw new \InvalidArgumentException('Choisissez l\'indicatif du pays.');
        }

        $chiffres = preg_replace('/\D+/', '', (string) $saisie);
        if ($chiffres === '') {
            return null;
        }

        // Numéro collé avec son indicatif (« +225 07 08… », « 00225… ») : on le retire.
        $chiffres = preg_replace('/^(00)?' . $indicatif . '(?=\d{' . self::CHIFFRES_MIN . ',})/', '', $chiffres);
        if (!in_array($pays, self::ZERO_CONSERVE, true)) {
            $chiffres = preg_replace('/^0/', '', $chiffres);
        }

        if ($pays === 'CI') {
            if (strlen($chiffres) !== self::CHIFFRES_COTE_D_IVOIRE) {
                throw new \InvalidArgumentException(sprintf('Un numéro ivoirien compte exactement 10 chiffres (ici %d).', strlen($chiffres)));
            }
        } elseif (strlen($chiffres) < self::CHIFFRES_MIN || strlen($chiffres) > self::CHIFFRES_MAX) {
            throw new \InvalidArgumentException(sprintf('Le numéro doit compter entre %d et %d chiffres, sans l\'indicatif.', self::CHIFFRES_MIN, self::CHIFFRES_MAX));
        }

        return '+' . $indicatif . $chiffres;
    }

    /**
     * Numéro enregistré => [pays, numéro local]. Un ancien numéro de dix
     * chiffres sans indicatif est ivoirien.
     *
     * @return array{0: string, 1: string}
     */
    public static function decouper(?string $numero): array
    {
        $numero = trim((string) $numero);
        if ($numero === '') {
            return [Indicatifs::PAYS_PAR_DEFAUT, ''];
        }

        $chiffres = preg_replace('/\D+/', '', $numero);
        if (!str_starts_with($numero, '+')) {
            return [Indicatifs::PAYS_PAR_DEFAUT, $chiffres];
        }

        $pays = Indicatifs::paysDuNumero($chiffres);
        if ($pays === null) {
            return [Indicatifs::PAYS_PAR_DEFAUT, $chiffres];
        }

        return [$pays, substr($chiffres, strlen(Indicatifs::LISTE[$pays]))];
    }

    /** Affichage lisible : « +225 07 08 09 10 11 ». */
    public static function formater(?string $numero): string
    {
        if (trim((string) $numero) === '') {
            return '';
        }
        [$pays, $local] = self::decouper($numero);

        return '+' . Indicatifs::LISTE[$pays] . ' ' . self::grouper($local);
    }

    /** Chiffres groupés par deux depuis la fin : « 07 08 09 10 11 », « 6 12 34 56 78 ». */
    public static function grouper(string $chiffres): string
    {
        return strrev(trim(chunk_split(strrev($chiffres), 2, ' ')));
    }

    /**
     * Identifiant de connexion tapé par l'utilisateur => numéro enregistré,
     * ou null si ce n'est pas un numéro. Sans indicatif, un numéro de dix
     * chiffres est compris comme ivoirien : les comptes existants se
     * connectent comme avant.
     */
    public static function depuisIdentifiant(string $identifiant): ?string
    {
        $identifiant = trim($identifiant);
        if ($identifiant === '' || str_contains($identifiant, '@')) {
            return null;
        }

        $chiffres = preg_replace('/\D+/', '', $identifiant);
        if ($chiffres === '') {
            return null;
        }
        if (str_starts_with($identifiant, '+')) {
            return '+' . $chiffres;
        }
        if (str_starts_with($chiffres, '00')) {
            return '+' . substr($chiffres, 2);
        }
        if (strlen($chiffres) === self::CHIFFRES_COTE_D_IVOIRE) {
            return '+' . Indicatifs::LISTE['CI'] . $chiffres;
        }

        return '+' . $chiffres;
    }
}
