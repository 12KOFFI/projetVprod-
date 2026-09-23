<?php

namespace App\EventSubscriber;

use App\Exception\VaeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Convertit les exceptions métier en retour utilisateur lisible.
 *
 * Les exceptions techniques ne sont pas interceptées : elles suivent le
 * traitement standard de Symfony (page d'erreur en production, trace en
 * développement). Aucun détail technique n'est exposé à l'utilisateur.
 */
class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof VaeException) {
            return;
        }

        $request = $event->getRequest();

        $this->logger->warning('Règle métier non satisfaite', [
            'message' => $exception->getMessage(),
            'route'   => $request->attributes->get('_route'),
        ]);

        if ($this->attendDuJson($request)) {
            $event->setResponse(new JsonResponse(
                ['erreur' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY
            ));

            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('error', $exception->getMessage());
        }

        $event->setResponse(new RedirectResponse($this->cibleDeRepli($request)));
    }

    private function attendDuJson(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function cibleDeRepli(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $referer = $request->headers->get('referer');

        // On ne renvoie vers le référent que s'il est interne, pour éviter
        // toute redirection ouverte.
        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $referer;
        }

        return $this->urlGenerator->generate('app_dashboard');
    }
}
