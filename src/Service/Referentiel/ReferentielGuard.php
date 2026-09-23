<?php

namespace App\Service\Referentiel;

use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Entity\DirectionRegionale;
use App\Entity\Filiere;
use App\Entity\Localite;
use App\Entity\Metier;
use App\Entity\User;
use App\Exception\SuppressionInterditeException;
use App\Repository\CandidatureRepository;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\LocaliteRepository;
use App\Repository\MetierRepository;
use App\Repository\UserRepository;

/**
 * Point d'entrée unique des suppressions protégées du référentiel (W2.1).
 *
 * Chaque méthode compte les dépendances de l'élément visé et lève une
 * SuppressionInterditeException énumérant les blocages : les contrôleurs
 * n'implémentent aucune de ces règles eux-mêmes (D.2).
 */
class ReferentielGuard
{
    public function __construct(
        private readonly LocaliteRepository $localiteRepository,
        private readonly CentreRepository $centreRepository,
        private readonly MetierRepository $metierRepository,
        private readonly CentreMetierRepository $centreMetierRepository,
        private readonly CandidatureRepository $candidatureRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * R2.2 : une direction régionale portant des localités reste en place.
     */
    public function verifierSuppressionDirectionRegionale(DirectionRegionale $directionRegionale): void
    {
        $this->bloquerSiDependances(
            sprintf('la direction régionale « %s »', (string) $directionRegionale->getLibelle()),
            ['localité(s)' => $this->localiteRepository->countParDirectionRegionale($directionRegionale)]
        );
    }

    /**
     * R2.1 : une localité portant des centres reste en place.
     */
    public function verifierSuppressionLocalite(Localite $localite): void
    {
        $this->bloquerSiDependances(
            sprintf('la localité « %s »', (string) $localite->getLibelle()),
            ['centre(s)' => $this->centreRepository->countParLocalite($localite)]
        );
    }

    /**
     * R2.3 : une filière portant des métiers reste en place.
     */
    public function verifierSuppressionFiliere(Filiere $filiere): void
    {
        $this->bloquerSiDependances(
            sprintf('la filière « %s »', (string) $filiere->getLibelle()),
            ['métier(s)' => $this->metierRepository->countParFiliere($filiere)]
        );
    }

    /**
     * R2.4 : un métier référencé par une offre, une candidature ou un
     * accompagnateur ne se supprime pas ; le passage au statut « inactif » est
     * la voie normale.
     */
    public function verifierSuppressionMetier(Metier $metier): void
    {
        $this->bloquerSiDependances(
            sprintf('le métier « %s »', (string) $metier->getLibelle()),
            [
                'offre(s) de centre'  => $this->centreMetierRepository->countParMetier($metier),
                'candidature(s)'      => $this->candidatureRepository->countParMetier($metier),
                'accompagnateur(s)'   => $this->userRepository->countParMetier($metier),
            ],
            'Passez plutôt ce métier au statut « inactif » : il disparaîtra des '
            . 'formulaires de candidature tout en restant lisible dans les dossiers existants.'
        );
    }

    /**
     * R2.5 : un centre portant des candidatures ou du personnel reste en place.
     */
    public function verifierSuppressionCentre(Centre $centre): void
    {
        $this->bloquerSiDependances(
            sprintf('le centre « %s »', (string) $centre->getNom()),
            [
                'candidature(s)'      => $this->candidatureRepository->countParCentre($centre),
                'compte(s)'           => $this->userRepository->countParCentre($centre),
                'métier(s) au catalogue' => $this->centreMetierRepository->countParCentre($centre),
            ]
        );
    }

    /**
     * Une ligne d'offre est supprimable sauf si des dossiers ont déjà été
     * déposés sur ce couple centre/métier.
     */
    public function verifierSuppressionCentreMetier(CentreMetier $centreMetier): void
    {
        $centre = $centreMetier->getCentre();
        $metier = $centreMetier->getMetier();

        if ($centre === null || $metier === null) {
            return;
        }

        $nombre = $this->candidatureRepository->countParCoupleCentreMetier($centre, $metier);

        if ($nombre > 0) {
            throw SuppressionInterditeException::avecMotif(sprintf(
                'Suppression impossible : %d candidature(s) ont déjà été déposées sur '
                . '« %s » dans ce centre. Ramenez le nombre de places à 0 pour fermer l\'offre.',
                $nombre,
                (string) $metier->getLibelle()
            ));
        }
    }

    /**
     * R2.8 : un administrateur ne supprime jamais son propre compte, et un
     * compte ayant traité des dossiers est désactivé plutôt que supprimé.
     */
    public function verifierSuppressionUtilisateur(User $utilisateur, ?User $auteur): void
    {
        if ($auteur !== null && $auteur->getId() === $utilisateur->getId()) {
            throw SuppressionInterditeException::avecMotif(
                'Vous ne pouvez pas supprimer votre propre compte.'
            );
        }

        $nombre = $this->candidatureRepository->countParUtilisateur($utilisateur);

        if ($nombre > 0) {
            throw SuppressionInterditeException::avecMotif(sprintf(
                'Suppression impossible : ce compte est rattaché à %d dossier(s) de '
                . 'candidature. Désactivez-le pour lui retirer l\'accès sans perdre la traçabilité.',
                $nombre
            ));
        }
    }

    /**
     * @param array<string, int> $blocages
     */
    private function bloquerSiDependances(string $element, array $blocages, ?string $conseil = null): void
    {
        $bloquants = array_filter($blocages, static fn (int $nombre): bool => $nombre > 0);

        if ($bloquants === []) {
            return;
        }

        $exception = SuppressionInterditeException::pour($element, $bloquants);

        if ($conseil === null) {
            throw $exception;
        }

        throw SuppressionInterditeException::avecMotif($exception->getMessage() . ' ' . $conseil);
    }
}
