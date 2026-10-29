<?php

namespace App\EventSubscriber;

use Knp\Component\Pager\Event\BeforeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ferme le tri et le filtrage « libres » de KnpPaginator.
 *
 * Par défaut, toute liste paginée accepte ?sort=alias.champ et
 * ?filterField=alias.champ&filterValue=motif* sur N'IMPORTE QUEL champ des
 * entités jointes, y compris ceux qui ne sont jamais affichés : un conseiller
 * pouvait ainsi deviner, caractère par caractère, l'empreinte du mot de passe
 * des candidats (cand.password) en observant le nombre de résultats.
 *
 * Aucun écran de l'application n'utilise ces paramètres (les filtres passent
 * par les repositories) : ils sont retirés de la requête avant que Knp ne les
 * lise. Une liste qui voudrait un jour un tri cliquable le déclarera avec
 * l'option sortFieldAllowList.
 */
class PaginationSansTriLibreSubscriber implements EventSubscriberInterface
{
    /** Noms par défaut de knp_paginator.default_options (non redéfinis dans config/). */
    private const PARAMETRES = ['sort', 'direction', 'filterField', 'filterValue'];

    public static function getSubscribedEvents(): array
    {
        return ['knp_pager.before' => ['retirerParametres', 100]];
    }

    public function retirerParametres(BeforeEvent $event): void
    {
        $requete = $event->getRequest();

        foreach (self::PARAMETRES as $parametre) {
            $requete->query->remove($parametre);
        }
    }
}
