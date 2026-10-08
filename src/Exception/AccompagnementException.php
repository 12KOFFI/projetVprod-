<?php

namespace App\Exception;

/**
 * Règle métier de l'accompagnement non satisfaite (étape 5). Messages
 * destinés à l'utilisateur, convertis par ExceptionSubscriber.
 */
class AccompagnementException extends VaeException
{
    public static function choixFerme(): self
    {
        return new self("Le choix d'un accompagnateur n'est possible qu'après la décision d'éligibilité, et une seule fois.");
    }

    public static function accompagnateurNonAutorise(): self
    {
        return new self("Cet accompagnateur ne suit pas votre métier dans votre centre.");
    }

    public static function livretFerme(): self
    {
        return new self("Le livret de preuves s'ouvre une fois votre accompagnateur affecté.");
    }
}
