<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Vérifie qu'un compte du personnel est correctement rattaché :
 * un centre pour l'agent d'accueil, le conseiller et l'accompagnateur,
 * et en outre un métier pour l'accompagnateur (règle métier R1.5).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class RattachementUtilisateur extends Constraint
{
    public string $messageCentreManquant = 'Un {{ role }} doit être rattaché à un centre.';
    public string $messageMetierManquant = 'Un accompagnateur doit être rattaché à un métier.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
