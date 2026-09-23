<?php

namespace App\Exception;

/**
 * Exception métier de la plateforme VAE.
 *
 * Ces exceptions portent un message destiné à l'utilisateur final : elles sont
 * interceptées par App\EventSubscriber\ExceptionSubscriber, qui les convertit
 * en message flash. Elles ne doivent jamais contenir de détail technique
 * (requête SQL, trace, chemin de fichier).
 */
abstract class VaeException extends \RuntimeException
{
}
