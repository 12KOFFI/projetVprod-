<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * La certification visée doit être préparée par le centre pour ce métier.
 *
 * Contrainte de classe : elle porte sur le triplet (centre, métier,
 * certification), qu'aucun champ pris isolément ne peut vérifier. Elle double
 * le filtrage de la liste déroulante, qu'un client peut contourner en postant
 * un autre identifiant — la liste étant peuplée en JavaScript, c'est ici que la
 * règle est réellement tenue.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class CertificationOfferte extends Constraint
{
    public string $messageNonOfferte = 'Ce diplôme n\'est pas préparé par le centre sélectionné pour ce métier.';
    public string $messageInactive = 'Ce diplôme n\'est plus proposé.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
