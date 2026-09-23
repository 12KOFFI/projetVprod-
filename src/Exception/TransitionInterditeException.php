<?php

namespace App\Exception;

use App\Enum\StatutCandidature;

/**
 * Levée lorsqu'un changement de statut ne fait pas partie des transitions
 * autorisées par la machine à états, ou lorsqu'un pré-requis métier
 * (paiement, décision préalable) n'est pas satisfait.
 */
class TransitionInterditeException extends VaeException
{
    public static function entre(StatutCandidature $depuis, StatutCandidature $vers): self
    {
        return new self(sprintf(
            'Le dossier ne peut pas passer de « %s » à « %s ».',
            $depuis->libelle(),
            $vers->libelle()
        ));
    }

    public static function prerequisManquant(string $raison): self
    {
        return new self($raison);
    }
}
