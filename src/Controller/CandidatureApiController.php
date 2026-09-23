<?php

namespace App\Controller;

use App\Entity\Centre;
use App\Entity\Certification;
use App\Entity\Metier;
use App\Repository\CentreMetierRepository;
use App\Repository\CertificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Peuplement en cascade du formulaire de dépôt : le centre détermine les
 * métiers ouverts (F3.11), et le couple (centre, métier) les certifications
 * réellement préparées.
 *
 * L'endpoint équivalent du module M2 est réservé à ROLE_ADMIN : il ne peut donc
 * pas servir le formulaire du candidat. Celui-ci est ouvert à tout utilisateur
 * connecté et n'expose que l'offre publique d'un centre — identifiant et
 * libellé, aucune entité sérialisée (règle S7).
 */
#[Route('/api/candidature', name: 'app_candidature_api_')]
#[IsGranted('ROLE_USER')]
class CandidatureApiController extends AbstractController
{
    #[Route('/centres/{id}/metiers', name: 'metiers', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function metiers(Centre $centre, CentreMetierRepository $centreMetierRepository): JsonResponse
    {
        return $this->json(array_map(
            static fn (Metier $metier): array => [
                'id' => $metier->getId(),
                'libelle' => $metier->getLibelle(),
                'filiere' => $metier->getFiliere()?->getLibelle(),
            ],
            $centreMetierRepository->findMetiersOuverts($centre)
        ));
    }

    /**
     * Certifications préparées par un centre pour un métier donné.
     *
     * Le couple est exigé, et non le seul métier : un même métier ne prépare
     * pas partout aux mêmes diplômes. Un couple qui n'est pas offert renvoie
     * une liste vide, jamais l'offre d'un autre centre.
     */
    #[Route(
        '/centres/{centre}/metiers/{metier}/certifications',
        name: 'certifications',
        requirements: ['centre' => '\d+', 'metier' => '\d+'],
        methods: ['GET']
    )]
    public function certifications(
        Centre $centre,
        Metier $metier,
        CertificationRepository $certificationRepository,
    ): JsonResponse {
        return $this->json(array_map(
            static fn (Certification $certification): array => [
                'id' => $certification->getId(),
                'libelle' => $certification->getLibelle(),
                'type' => $certification->getType(),
            ],
            $certificationRepository->findOffertesPour($centre, $metier)
        ));
    }
}
