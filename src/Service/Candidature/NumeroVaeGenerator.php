<?php

namespace App\Service\Candidature;

use App\Exception\DepotCandidatureException;
use Doctrine\DBAL\Connection;

/**
 * Attribue les numéros d'inscription au format court VAE{année sur 2}{séquence
 * sur 3}, ex. VAE26001.
 *
 * Les dossiers créés avant l'introduction de ce format court portent l'ancien
 * format VAE-{année sur 4}-{séquence sur 6} ; FORMAT_HERITE reste accepté en
 * lecture (recherche, validation d'une saisie) mais n'est plus jamais produit.
 *
 * La séquence est dérivée du plus grand numéro déjà attribué à une candidature
 * pour l'année, et non de l'identifiant technique. L'appelant
 * (CandidatureService) sérialise le calcul et l'insertion sous verrou, et
 * l'index UNIQUE de candidature.numero reste le dernier garde-fou (règle R3.5).
 */
class NumeroVaeGenerator
{
    public const PREFIXE = 'VAE';

    /** Séquence annuelle maximale représentable sur 3 chiffres. */
    public const SEQUENCE_MAX = 999;

    /** Format actuel, produit pour tout nouveau dépôt : VAE{YY}{NNN}. */
    public const FORMAT_COURT = '/^VAE\d{2}\d{3}$/';

    /** Ancien format, conservé pour les dossiers déjà en base : VAE-YYYY-NNNNNN. */
    public const FORMAT_HERITE = '/^VAE-\d{4}-\d{6}$/';

    public function __construct(
        private readonly Connection $connexion,
    ) {
    }

    /**
     * Numéro suivant pour l'année considérée, au format court.
     */
    public function suivant(?\DateTimeInterface $date = null): string
    {
        $annee = (int) ($date ?? new \DateTimeImmutable())->format('Y');

        return $this->composer($annee, $this->derniereSequence($annee) + 1);
    }

    public function composer(int $annee, int $sequence): string
    {
        if ($sequence > self::SEQUENCE_MAX) {
            throw DepotCandidatureException::sequenceAnnuelleEpuisee($annee);
        }

        return sprintf('%s%02d%03d', self::PREFIXE, $annee % 100, $sequence);
    }

    /**
     * Accepte aussi bien un numéro au format court (nouveaux dossiers) qu'à
     * l'ancien format hérité (dossiers créés avant ce format).
     */
    public static function estValide(string $numero): bool
    {
        $numero = trim($numero);

        return preg_match(self::FORMAT_COURT, $numero) === 1
            || preg_match(self::FORMAT_HERITE, $numero) === 1;
    }

    /**
     * Normalise une saisie utilisateur : espaces superflus, casse, et préfixe
     * omis lorsque le candidat ne recopie que la partie numérique.
     */
    public static function normaliser(string $saisie): string
    {
        $saisie = strtoupper(trim($saisie));

        return preg_replace('/\s+/', '', $saisie) ?? $saisie;
    }

    /**
     * Plus grande séquence déjà attribuée pour l'année, au format court
     * uniquement : le LIKE seul ne suffit pas à exclure les numéros hérités
     * (VAE-YYYY-NNNNNN), d'où la contrainte de longueur exacte en complément.
     */
    private function derniereSequence(int $annee): int
    {
        $dernier = $this->connexion->fetchOne(
            'SELECT numero FROM candidature WHERE numero LIKE ? AND LENGTH(numero) = 8 ORDER BY numero DESC LIMIT 1',
            [sprintf('%s%02d%%', self::PREFIXE, $annee % 100)]
        );

        return $dernier === false ? 0 : (int) substr((string) $dernier, -3);
    }
}
