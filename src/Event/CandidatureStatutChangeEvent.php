<?php

namespace App\Event;

use App\Entity\Candidature;
use App\Entity\User;
use App\Enum\StatutCandidature;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Émis après chaque changement de statut appliqué avec succès.
 *
 * C'est le point d'accroche des effets de bord (notifications, création du
 * paiement débloqué, affectation d'un accompagnateur). Les abonnés ne doivent
 * jamais rappeler TransitionCandidature de façon récursive sur la même
 * candidature dans le même cycle.
 */
class CandidatureStatutChangeEvent extends Event
{
    public function __construct(
        private readonly Candidature $candidature,
        private readonly ?StatutCandidature $statutAvant,
        private readonly StatutCandidature $statutApres,
        private readonly ?User $auteur = null,
        private readonly ?string $motif = null,
    ) {
    }

    public function getCandidature(): Candidature
    {
        return $this->candidature;
    }

    public function getStatutAvant(): ?StatutCandidature
    {
        return $this->statutAvant;
    }

    public function getStatutApres(): StatutCandidature
    {
        return $this->statutApres;
    }

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }
}
