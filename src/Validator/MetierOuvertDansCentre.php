<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Le métier visé doit être ouvert dans le centre choisi, et encore actif.
 *
 * Contrainte de classe : elle porte sur le couple (centre, métier) et non sur
 * un champ isolé. Elle double le filtrage de la liste déroulante, qu'un client
 * peut contourner en postant un autre identifiant (règles R3.2 et R3.3).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class MetierOuvertDansCentre extends Constraint
{
    public string $messageFerme = 'Ce métier n\'est pas ouvert dans le centre sélectionné.';
    public string $messageInactif = 'Ce métier n\'accueille plus de nouvelles candidatures.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
