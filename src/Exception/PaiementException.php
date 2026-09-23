<?php

namespace App\Exception;

use App\Enum\TypeFrais;

/**
 * Règle métier du paiement non satisfaite (module M5).
 *
 * Les messages sont destinés au candidat : ils n'exposent jamais le détail
 * technique renvoyé par la passerelle (règle de sécurité S5).
 */
class PaiementException extends VaeException
{
    public static function nonDebloque(TypeFrais $type): self
    {
        return new self(sprintf(
            'Les %s ne peuvent pas encore être réglés : votre dossier n\'a pas atteint l\'étape correspondante.',
            mb_strtolower($type->libelle())
        ));
    }

    public static function dejaRegle(TypeFrais $type): self
    {
        return new self(sprintf('Les %s ont déjà été réglés.', mb_strtolower($type->libelle())));
    }

    public static function moyenNonSupporte(string $moyen): self
    {
        return new self(sprintf('Le moyen de paiement « %s » n\'est pas accepté.', $moyen));
    }

    public static function introuvable(): self
    {
        return new self('Ce règlement est introuvable.');
    }

    public static function tarifAbsent(TypeFrais $type): self
    {
        return new self(sprintf(
            'Le tarif des %s n\'est pas configuré. Contactez l\'administrateur.',
            mb_strtolower($type->libelle())
        ));
    }

    public static function remboursementImpossible(): self
    {
        return new self('Seul un règlement abouti peut être remboursé.');
    }
}
