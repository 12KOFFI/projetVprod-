<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\CandidatureRepository;
use App\Service\Statistique\StatistiqueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord national de l'administration.
 *
 * Cette route est celle que Role::routeEspace() désigne pour ROLE_ADMIN : tant
 * qu'elle n'existait pas, DashboardController repliait l'administrateur sur
 * l'écran d'attente à chaque connexion.
 *
 * L'écran tient en une seule table de chiffres : le parcours VAE décision par
 * décision, où chaque nombre n'apparaît qu'une fois (en attente aujourd'hui,
 * franchi, refusé). Viennent ensuite les motifs des derniers refus et les
 * dépôts par semaine. Les ventilations (centre, sexe, métier, direction
 * régionale) vivent dans l'écran Indicateurs, pas ici.
 */
#[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StatistiqueService $statistiques,
        private readonly CandidatureRepository $candidatures,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();

        return $this->render('admin/dashboard.html.twig', [
            'parcours' => $this->statistiques->parcours($admin),
            'derniers_refus' => $this->candidatures->findDerniersRefusEtude($admin, 5),
            'depots_par_semaine' => $this->statistiques->depotsParSemaine($admin, 12),
        ]);
    }
}
