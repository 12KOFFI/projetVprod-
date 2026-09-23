<?php

namespace App\Controller\Admin;

use App\Entity\User;
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
 * L'écran reste volontairement synthétique — compteurs, répartitions et
 * entonnoir. Les ventilations détaillées vivent dans l'écran Indicateurs.
 */
#[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StatistiqueService $statistiques,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $parStatut = [];
        foreach ($this->statistiques->parStatut($admin) as $ligne) {
            // Les statuts sans effectif alourdiraient la légende du graphique
            // sans rien apprendre : ils sont écartés du rendu circulaire.
            if ($ligne['effectif'] > 0) {
                $parStatut[$ligne['statut']->libelle()] = $ligne['effectif'];
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'synthese' => $this->statistiques->synthese($admin),
            'par_statut' => $parStatut,
            'par_sexe' => $this->statistiques->parSexe($admin),
            'par_centre' => $this->statistiques->parCentre($admin, 5),
            'entonnoir' => $this->statistiques->entonnoir(),
        ]);
    }
}
