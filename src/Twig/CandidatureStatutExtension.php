<?php

namespace App\Twig;

use App\Service\Candidature\CandidatureStatusResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose aux gabarits le statut global calculé par CandidatureStatusResolver.
 *
 * Aucune règle ici : chaque fonction délègue au Resolver, seule source du
 * statut global. Un gabarit ne doit jamais le reconstituer lui-même à partir
 * des colonnes de décision.
 */
class CandidatureStatutExtension extends AbstractExtension
{
    public function __construct(
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('statut_candidature', $this->resolver->resolve(...)),
            new TwigFunction('candidature_en_attente_etude', $this->resolver->estEnAttenteEtude(...)),
            new TwigFunction('candidature_en_attente_paiement', $this->resolver->estAccepteeEnAttentePaiement(...)),
        ];
    }
}
