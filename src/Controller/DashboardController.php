<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\Role;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aiguillage post-connexion : chaque rôle est renvoyé vers son propre espace.
 *
 * Les espaces métier sont livrés par les modules suivants ; tant qu'une route
 * d'espace n'existe pas encore, l'utilisateur reçoit une page d'attente plutôt
 * qu'une erreur de routage.
 */
class DashboardController extends AbstractController
{
    #[Route('/tableau-de-bord', name: 'app_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(RouterInterface $router): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $role = Role::principal($user);

        if ($role === null) {
            $this->addFlash('error', "Votre compte n'a aucun rôle reconnu. Contactez l'administrateur.");

            return $this->redirectToRoute('app_logout');
        }

        $route = Role::routeEspace($role);

        if ($route !== null && $this->routeExiste($router, $route)) {
            return $this->redirectToRoute($route);
        }

        // L'espace de ce rôle n'est pas encore livré : on affiche un accueil
        // neutre qui rappelle l'identité et le rattachement de l'utilisateur.
        return $this->render('dashboard/attente.html.twig', [
            'role_libelle' => Role::libelle($role),
        ]);
    }

    private function routeExiste(RouterInterface $router, string $nom): bool
    {
        return $router->getRouteCollection()->get($nom) !== null;
    }
}
