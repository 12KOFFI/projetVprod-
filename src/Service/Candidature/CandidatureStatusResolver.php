<?php

namespace App\Service\Candidature;

use App\Entity\Candidature;
use App\Entity\Paiement;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutPaiement;
use App\Enum\StatutRecevabilite;
use App\Enum\TypeFrais;

/**
 * Unique source de vérité du statut global d'une candidature.
 *
 * Le statut global n'est stocké nulle part : il se déduit des décisions
 * métier, chacune portée par sa propre colonne, et du règlement des frais de
 * dossier. La dernière étape décidée l'emporte :
 *
 *   admis → resultat → eligStatut → recStatut → etuStatut + paiement → PREINSCRIT
 *
 * resolve() et expressionDql() énoncent la MÊME règle, l'une en PHP, l'autre
 * pour les filtres, comptages et regroupements faits en base. Toute
 * modification de l'une doit être reportée dans l'autre : le test
 * CandidatureStatusResolverDqlTest vérifie leur concordance.
 *
 * Lecture seule : ce service ne modifie jamais la candidature.
 */
class CandidatureStatusResolver
{
    /** Convention des colonnes de décision : 1 = décision négative, 2 = positive. */
    public const DECISION_NEGATIVE = 1;
    public const DECISION_POSITIVE = 2;

    private static int $sousRequetes = 0;

    public function resolve(Candidature $candidature): StatutCandidature
    {
        return $this->selonDecision($candidature->getAdmis(), StatutCandidature::NON_ADMIS_DEFINITIF, StatutCandidature::ADMIS_DEFINITIF)
            ?? $this->selonDecision($candidature->getResultat(), StatutCandidature::NON_ADMISSIBLE, StatutCandidature::ADMISSIBLE)
            ?? $this->selonDecision($candidature->getEligStatut(), StatutCandidature::NON_ELIGIBLE, StatutCandidature::ELIGIBLE)
            ?? $this->selonRecevabilite($candidature->getRecStatut())
            ?? $this->selonEtudeEtPaiement($candidature);
    }

    /**
     * Préinscrit dont l'étude reste à rendre (jamais étudiée, ou refusée puis
     * à réétudier) : seul cas où le dossier reste modifiable par son auteur.
     */
    public function estEnAttenteEtude(Candidature $candidature): bool
    {
        return $this->resolve($candidature) === StatutCandidature::PREINSCRIT
            && $candidature->getEtuStatut() !== StatutEtude::ACCEPTE->value;
    }

    /**
     * Préinscrit dont l'étude est acceptée : seul le règlement des frais de
     * dossier reste attendu pour passer à INSCRIT.
     */
    public function estAccepteeEnAttentePaiement(Candidature $candidature): bool
    {
        return $this->resolve($candidature) === StatutCandidature::PREINSCRIT
            && $candidature->getEtuStatut() === StatutEtude::ACCEPTE->value;
    }

    /**
     * Même règle que resolve(), exprimée en DQL : une expression entière égale
     * à la valeur de StatutCandidature, utilisable dans un WHERE, un SELECT ou
     * un GROUP BY (via un alias de résultat).
     */
    public function expressionDql(string $alias): string
    {
        return $this->caseDql($alias, static fn (StatutCandidature $statut): int => $statut->value);
    }

    /**
     * Condition DQL « le statut calculé appartient (ou non) à cet ensemble ».
     *
     * Doctrine n'accepte pas IN derrière une expression CASE : la même règle
     * est donc construite avec, pour chaque branche, 1 si son statut est dans
     * l'ensemble et 0 sinon. Une seule évaluation, sans répéter la règle.
     *
     * @param list<StatutCandidature> $statuts
     */
    public function conditionDql(string $alias, array $statuts, bool $exclure = false): string
    {
        return $this->caseDql(
            $alias,
            static fn (StatutCandidature $statut): int => (int) (in_array($statut, $statuts, true) !== $exclure)
        ) . ' = 1';
    }

    /**
     * @param callable(StatutCandidature): int $valeur valeur produite par chaque branche
     */
    private function caseDql(string $alias, callable $valeur): string
    {
        $p = 'statut_p' . ++self::$sousRequetes;

        $fraisDossierRegles = sprintf(
            "EXISTS (SELECT %1\$s.id FROM %2\$s %1\$s WHERE %1\$s.candidature = %3\$s AND %1\$s.typeFrais = '%4\$s' AND %1\$s.statutPaiement = '%5\$s')",
            $p,
            Paiement::class,
            $alias,
            TypeFrais::DOSSIER->value,
            StatutPaiement::REUSSI->value,
        );

        $cas = [
            ["$alias.admis", self::DECISION_POSITIVE, StatutCandidature::ADMIS_DEFINITIF],
            ["$alias.admis", self::DECISION_NEGATIVE, StatutCandidature::NON_ADMIS_DEFINITIF],
            ["$alias.resultat", self::DECISION_POSITIVE, StatutCandidature::ADMISSIBLE],
            ["$alias.resultat", self::DECISION_NEGATIVE, StatutCandidature::NON_ADMISSIBLE],
            ["$alias.eligStatut", self::DECISION_POSITIVE, StatutCandidature::ELIGIBLE],
            ["$alias.eligStatut", self::DECISION_NEGATIVE, StatutCandidature::NON_ELIGIBLE],
            ["$alias.recStatut", StatutRecevabilite::RECEVABLE->value, StatutCandidature::DOSSIER_RECEVABLE],
            ["$alias.recStatut", StatutRecevabilite::NON_RECEVABLE->value, StatutCandidature::DOSSIER_NON_RECEVABLE],
        ];

        $branches = array_map(
            static fn (array $c): string => sprintf('WHEN %s = %d THEN %d', $c[0], $c[1], $valeur($c[2])),
            $cas
        );

        $branches[] = sprintf(
            'WHEN %s.etuStatut = %d AND %s THEN %d',
            $alias,
            StatutEtude::ACCEPTE->value,
            $fraisDossierRegles,
            $valeur(StatutCandidature::INSCRIT)
        );

        return sprintf('(CASE %s ELSE %d END)', implode(' ', $branches), $valeur(StatutCandidature::PREINSCRIT));
    }

    private function selonDecision(?int $decision, StatutCandidature $negatif, StatutCandidature $positif): ?StatutCandidature
    {
        return match ($decision) {
            self::DECISION_POSITIVE => $positif,
            self::DECISION_NEGATIVE => $negatif,
            default => null,
        };
    }

    /** « En attente » (0) n'est pas une décision : l'étape n'est pas franchie. */
    private function selonRecevabilite(?int $recStatut): ?StatutCandidature
    {
        return match ($recStatut) {
            StatutRecevabilite::RECEVABLE->value => StatutCandidature::DOSSIER_RECEVABLE,
            StatutRecevabilite::NON_RECEVABLE->value => StatutCandidature::DOSSIER_NON_RECEVABLE,
            default => null,
        };
    }

    private function selonEtudeEtPaiement(Candidature $candidature): StatutCandidature
    {
        return $candidature->getEtuStatut() === StatutEtude::ACCEPTE->value && $this->fraisDossierRegles($candidature)
            ? StatutCandidature::INSCRIT
            : StatutCandidature::PREINSCRIT;
    }

    /**
     * Même règle que PaiementRepository::existeReussi() pour les frais de
     * dossier : un règlement de ce type au statut « réussi ».
     */
    private function fraisDossierRegles(Candidature $candidature): bool
    {
        foreach ($candidature->getPaiements() as $paiement) {
            if ($paiement->getType() === TypeFrais::DOSSIER && $paiement->estReussi()) {
                return true;
            }
        }

        return false;
    }
}
