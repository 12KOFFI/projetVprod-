<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité HTTP ajoutés à toutes les réponses de l'application.
 *
 * Posés ici plutôt que dans le .htaccess : ils ne dépendent ni de mod_headers
 * ni de ce que l'hébergeur mutualisé autorise dans les fichiers .htaccess.
 *
 *   - X-Frame-Options : empêche d'afficher la plateforme dans un cadre d'un
 *     autre site (clickjacking sur les boutons de décision, de paiement…) ;
 *   - X-Content-Type-Options : le navigateur respecte le type annoncé et
 *     n'interprète pas un fichier déposé comme du HTML ou du script ;
 *   - Referrer-Policy : les adresses internes (numéros de dossier, recherches)
 *     ne sont pas transmises aux sites externes.
 *
 * Pas de Content-Security-Policy pour l'instant : les gabarits contiennent des
 * scripts en ligne qu'une politique stricte bloquerait.
 */
class EntetesSecuriteSubscriber implements EventSubscriberInterface
{
    private const ENTETES = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['ajouterEntetes', -10]];
    }

    public function ajouterEntetes(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $entetes = $event->getResponse()->headers;

        foreach (self::ENTETES as $nom => $valeur) {
            if (!$entetes->has($nom)) {
                $entetes->set($nom, $valeur);
            }
        }
    }
}
