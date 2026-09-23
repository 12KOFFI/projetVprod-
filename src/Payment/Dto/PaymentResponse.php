<?php

namespace App\Payment\Dto;

use App\Enum\StatutPaiement;

/**
 * Réponse d'une passerelle de paiement, normalisée.
 *
 * Chaque passerelle traduit son propre format vers cet objet : la logique
 * métier ne connaît que celui-ci, ce qui permet de remplacer la simulation par
 * la passerelle réelle sans la toucher.
 *
 * @param array<string, mixed> $reponseBrute réponse d'origine, conservée pour l'audit
 */
final class PaymentResponse
{
    public function __construct(
        public readonly bool $succes,
        public readonly StatutPaiement $statut,
        public readonly string $referenceTransaction,
        public readonly ?string $identifiantExterne = null,
        public readonly ?string $urlRedirection = null,
        public readonly ?string $codeErreur = null,
        public readonly ?string $messageErreur = null,
        public readonly array $reponseBrute = [],
        public readonly ?\DateTimeImmutable $horodatage = null,
    ) {
    }

    public function estReussi(): bool
    {
        return $this->statut === StatutPaiement::REUSSI;
    }

    public function estEnAttente(): bool
    {
        return $this->statut === StatutPaiement::EN_ATTENTE;
    }
}
