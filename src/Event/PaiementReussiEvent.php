<?php

namespace App\Event;

use App\Entity\Paiement;
use App\Enum\TypeFrais;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Émis lorsqu'un règlement passe à l'état abouti.
 *
 * C'est par cet événement que le paiement provoque l'avancement du dossier :
 * la passerelle ne connaît que le règlement, jamais la candidature, et n'a donc
 * pas à décider d'une transition de statut (règle métier R5.7).
 */
class PaiementReussiEvent extends Event
{
    public function __construct(
        private readonly Paiement $paiement,
    ) {
    }

    public function getPaiement(): Paiement
    {
        return $this->paiement;
    }

    public function getType(): TypeFrais
    {
        return $this->paiement->getType();
    }

    public function getCandidature(): \App\Entity\Candidature
    {
        return $this->paiement->getCandidature();
    }
}
