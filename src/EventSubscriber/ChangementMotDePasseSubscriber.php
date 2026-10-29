<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Règle R2.9 : un compte dont le mot de passe a été fixé par l'administrateur
 * (création ou réinitialisation, mot de passe affiché à l'écran puis transmis
 * hors bande) doit en changer avant toute autre action.
 *
 * Le drapeau User::$doitChangerMotDePasse était posé mais jamais appliqué :
 * tant qu'il est levé, seule la page « Mon profil » (où le nouveau mot de
 * passe se saisit) et la déconnexion restent accessibles.
 */
class ChangementMotDePasseSubscriber implements EventSubscriberInterface
{
    private const ROUTES_PERMISES = ['app_profil_modifier', 'app_logout', 'app_login'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (priorité 8), qui a chargé l'utilisateur.
        return [KernelEvents::REQUEST => ['exigerChangement', 0]];
    }

    public function exigerChangement(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof User || !$utilisateur->doitChangerMotDePasse()) {
            return;
        }

        $requete = $event->getRequest();
        $route = (string) $requete->attributes->get('_route', '');

        // Routes techniques (barre de débogage, pages d'erreur) et écrans permis.
        if ($route === '' || str_starts_with($route, '_') || in_array($route, self::ROUTES_PERMISES, true)) {
            return;
        }

        if ($requete->hasSession()) {
            $requete->getSession()->getFlashBag()->add(
                'info',
                "Votre mot de passe a été fixé par l'administrateur : choisissez-en un nouveau pour continuer."
            );
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_profil_modifier')));
    }
}
