<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add(
                'error',
                "Vous n'avez pas accès à cette page."
            );
        }

        // L'aiguilleur renvoie chaque utilisateur vers l'espace de son rôle ;
        // un visiteur non authentifié y sera à son tour redirigé vers la
        // page de connexion par le pare-feu.
        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }
}
