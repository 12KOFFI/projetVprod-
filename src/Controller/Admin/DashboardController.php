<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Statistique\PilotageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord national de l'administration : le pilotage de la campagne.
 *
 * Cette route est celle que Role::routeEspace() désigne pour ROLE_ADMIN : tant
 * qu'elle n'existait pas, DashboardController repliait l'administrateur sur
 * l'écran d'attente à chaque connexion.
 *
 * Lecture seule et sans filtre : tous les établissements. L'avancement du
 * parcours et ses répartitions, les dépôts par semaine, les points d'attention,
 * le classement des centres et le tableau centre × étape viennent d'un seul
 * calcul (PilotageService::tableauDeBord). Les filtres vivent sur l'écran
 * Indicateurs.
 */
#[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    public function __construct(private readonly PilotageService $pilotage)
    {
    }

    public function __invoke(): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();

        return $this->render('admin/dashboard.html.twig', $this->pilotage->tableauDeBord($admin));
    }
}
