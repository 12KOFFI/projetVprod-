<?php

namespace App\Tests\Service\Candidature;

use App\Entity\Candidature;
use App\Entity\Paiement;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;

/**
 * Construit une candidature à partir de ses seules décisions métier, telles
 * que les écrit TransitionCandidature.
 */
final class FabriqueCandidature
{
    public static function avec(
        ?int $etu = null,
        ?int $rec = null,
        ?int $elig = null,
        ?int $resultat = null,
        ?int $admis = null,
        bool $fraisDossierRegles = false,
    ): Candidature {
        $candidature = (new Candidature())
            ->setEtuStatut($etu)
            ->setRecStatut($rec)
            ->setEligStatut($elig)
            ->setResultat($resultat)
            ->setAdmis($admis);

        if ($fraisDossierRegles) {
            self::ajouterPaiement($candidature, TypeFrais::DOSSIER, StatutPaiement::REUSSI);
        }

        return $candidature;
    }

    public static function ajouterPaiement(Candidature $candidature, TypeFrais $type, StatutPaiement $statut): Paiement
    {
        return (new Paiement())
            ->setType($type)
            ->setStatut($statut)
            ->setMontant('10000.00')
            ->setCandidature($candidature);
    }
}
