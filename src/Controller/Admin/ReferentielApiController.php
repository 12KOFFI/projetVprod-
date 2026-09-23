<?php

namespace App\Controller\Admin;

use App\Entity\Centre;
use App\Entity\DirectionRegionale;
use App\Entity\Localite;
use App\Entity\Metier;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\LocaliteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints de peuplement dynamique des listes déroulantes (F2.8).
 *
 * Chaque réponse est construite à partir de tableaux {id, libelle} : aucune
 * entité Doctrine n'est sérialisée, pour ne pas exposer de champ interne (S7).
 * L'accès est réservé à ROLE_ADMIN, l'ancien projet ayant exposé des endpoints
 * trop ouverts.
 */
#[Route('/admin/api', name: 'app_admin_api_')]
#[IsGranted('ROLE_ADMIN')]
class ReferentielApiController extends AbstractController
{
    public function __construct(
        private readonly LocaliteRepository $localiteRepository,
        private readonly CentreRepository $centreRepository,
        private readonly CentreMetierRepository $centreMetierRepository,
    ) {
    }

    #[Route('/directions-regionales/{id}/localites', name: 'localites', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function localites(DirectionRegionale $directionRegionale): JsonResponse
    {
        $localites = $this->localiteRepository->findParDirectionRegionale($directionRegionale);

        return $this->json(array_map(
            static fn (Localite $localite): array => [
                'id' => $localite->getId(),
                'libelle' => $localite->getLibelle(),
            ],
            $localites
        ));
    }

    #[Route('/localites/{id}/centres', name: 'centres', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function centres(Localite $localite): JsonResponse
    {
        $centres = $this->centreRepository->findParLocalite($localite);

        return $this->json(array_map(
            static fn (Centre $centre): array => [
                'id' => $centre->getId(),
                'libelle' => $centre->getNom(),
                'type' => $centre->getType(),
            ],
            $centres
        ));
    }

    /**
     * Métiers réellement ouverts dans un centre : la méthode de repository
     * écarte déjà les métiers inactifs (R2.7).
     */
    #[Route('/centres/{id}/metiers', name: 'metiers', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function metiers(Centre $centre): JsonResponse
    {
        $metiers = $this->centreMetierRepository->findMetiersOuverts($centre);

        return $this->json(array_map(
            static fn (Metier $metier): array => [
                'id' => $metier->getId(),
                'libelle' => $metier->getLibelle(),
                'filiere' => $metier->getFiliere()?->getLibelle(),
            ],
            $metiers
        ));
    }
}
