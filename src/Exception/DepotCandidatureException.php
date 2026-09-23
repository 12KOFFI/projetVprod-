<?php

namespace App\Exception;

/**
 * Règle métier du dépôt de dossier non satisfaite (module M3).
 *
 * Couvre les situations où la demande est structurellement recevable mais
 * interdite par le métier : candidature déjà ouverte, métier fermé dans le
 * centre choisi, compte du personnel déposant pour lui-même.
 */
class DepotCandidatureException extends VaeException
{
    public static function candidatureDejaOuverte(): self
    {
        return new self(
            'Vous avez déjà un dossier de candidature en cours. '
            . "Un candidat ne peut suivre qu'une seule démarche VAE à la fois."
        );
    }

    public static function metierFermeDansCentre(string $metier, string $centre): self
    {
        return new self(sprintf(
            'Le métier « %s » n\'est pas ouvert au centre « %s ». '
            . 'Choisissez un métier proposé par ce centre.',
            $metier,
            $centre
        ));
    }

    public static function metierInactif(string $metier): self
    {
        return new self(sprintf(
            'Le métier « %s » n\'accueille plus de nouvelles candidatures.',
            $metier
        ));
    }

    public static function personnelNonAutorise(): self
    {
        return new self(
            "Un compte du personnel ne peut pas déposer de dossier de candidature. "
            . 'Utilisez un compte candidat.'
        );
    }

    public static function agentSansCentre(): self
    {
        return new self(
            "Votre compte n'est rattaché à aucun centre : l'inscription assistée est "
            . "impossible. Contactez l'administrateur."
        );
    }

    public static function numeroIndisponible(): self
    {
        return new self(
            "Le numéro d'inscription n'a pas pu être attribué en raison d'une "
            . 'affluence momentanée. Veuillez soumettre à nouveau votre dossier.'
        );
    }

    public static function sequenceAnnuelleEpuisee(int $annee): self
    {
        return new self(sprintf(
            "Le nombre maximal de dossiers pour l'année %d (999) a été atteint. "
            . "Contactez l'administrateur national.",
            $annee
        ));
    }

    public static function candidatIntrouvable(string $numero): self
    {
        return new self(sprintf(
            "Aucun dossier ne correspond au numéro « %s ».",
            $numero
        ));
    }
}
