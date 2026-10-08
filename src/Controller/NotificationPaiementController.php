<?php

namespace App\Controller;

use App\Service\Paiement\PaiementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Notification serveur à serveur du fournisseur de paiement (webhook), quel
 * que soit le fournisseur actif : chacun y lit son propre en-tête de signature.
 *
 * Route publique (le fournisseur n'a pas de session) mais jamais crue sur
 * parole :
 *   1. la signature HMAC et l'horodatage sont contrôlés par la passerelle ;
 *   2. le statut annoncé est ignoré : PaiementService::confirmer() redemande
 *      l'état au fournisseur ;
 *   3. la confirmation est idempotente : une notification rejouée ne
 *      redéclenche ni l'événement de paiement ni le changement de statut.
 * Avec la démo, aucune notification n'est acceptée.
 */
class NotificationPaiementController extends AbstractController
{
    #[Route('/paiement/notification', name: 'app_paiement_notification', methods: ['POST'])]
    public function __invoke(Request $request, PaiementService $paiementService): Response
    {
        try {
            // Tous les en-têtes, noms en minuscules, première valeur de chacun.
            $entetes = array_map(static fn (array $valeurs) => (string) ($valeurs[0] ?? ''), $request->headers->all());
            $paiement = $paiementService->traiterNotification($request->getContent(), $entetes);
        } catch (\Throwable) {
            // Le fournisseur renverra la notification : 503 l'y invite.
            return new JsonResponse(['recu' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($paiement === null) {
            // Volontairement laconique : rien n'est dit sur la raison du refus.
            return new JsonResponse(['recu' => false], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['recu' => true, 'statut' => $paiement->getStatut()->value]);
    }
}
