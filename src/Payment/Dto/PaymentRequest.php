<?php

namespace App\Payment\Dto;

use App\Enum\MoyenPaiement;

/**
 * Demande de règlement transmise à la passerelle.
 *
 * Objet immuable : une demande transmise ne doit plus pouvoir être altérée, ce
 * qui garantit que le montant envoyé à la passerelle est bien celui qui a été
 * calculé côté serveur.
 *
 * @param array<string, scalar|null> $metadonnees
 */
final class PaymentRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly string $montant,
        public readonly MoyenPaiement $moyenPaiement,
        public readonly string $description,
        public readonly string $candidatNom,
        public readonly ?string $candidatContact = null,
        public readonly ?string $urlRetour = null,
        public readonly ?string $urlCallback = null,
        public readonly array $metadonnees = [],
        public readonly string $devise = 'XOF',
    ) {
    }
}
