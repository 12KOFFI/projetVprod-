<?php

namespace App\Exception;

/**
 * Règle métier de l'étude ou de la recevabilité du dossier non satisfaite (module M4).
 */
class RecevabiliteException extends VaeException
{
    public static function resultatEtudeManquant(): self
    {
        return new self('Choisissez un résultat d\'étude (accepté ou refusé).');
    }

    public static function motifObligatoire(): self
    {
        return new self('Une décision défavorable doit être motivée.');
    }

    public static function resultatRecevabiliteManquant(): self
    {
        return new self('Choisissez un résultat de recevabilité (recevable ou non recevable).');
    }

    public static function etudeImpossible(string $numero): self
    {
        return new self(sprintf(
            "L'étude du dossier « %s » est déjà acceptée, ou le dossier n'est plus préinscrit : elle ne peut plus être saisie ici.",
            $numero
        ));
    }

    public static function recevabiliteImpossible(string $numero): self
    {
        return new self(sprintf(
            "Le dossier « %s » n'est pas encore inscrit (paiement des frais de dossier requis) : sa recevabilité ne peut pas encore être prononcée.",
            $numero
        ));
    }

    public static function etudeDejaRendue(string $numero): self
    {
        return new self(sprintf(
            "L'étude du dossier « %s » est déjà rendue : son conseiller ne peut plus changer.",
            $numero
        ));
    }
}
