<?php

namespace App\Exception;

/**
 * La passerelle de paiement n'a pas pu être jointe ou a répondu de façon
 * inexploitable. Le détail technique est journalisé, jamais affiché.
 */
class PasserelleIndisponibleException extends VaeException
{
    public static function injoignable(): self
    {
        return new self(
            'Le service de paiement est momentanément indisponible. '
            . 'Veuillez réessayer dans quelques instants.'
        );
    }
}
