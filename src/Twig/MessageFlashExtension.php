<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Affichage des messages flash.
 *
 * Ils étaient rendus avec « |raw », alors que plusieurs reprennent une saisie
 * de l'utilisateur (numéro recherché, nom, message d'exception) : une XSS
 * réfléchie. Le message est désormais entièrement échappé ; seule la mise en
 * valeur <strong class="font-mono"> des mots de passe et numéros générés est
 * rétablie, sans attribut libre possible.
 */
class MessageFlashExtension extends AbstractExtension
{
    private const BALISES_ADMISES = [
        '&lt;strong class=&quot;font-mono&quot;&gt;' => '<strong class="font-mono">',
        '&lt;strong&gt;' => '<strong>',
        '&lt;/strong&gt;' => '</strong>',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('message_flash', [$this, 'messageFlash'], ['is_safe' => ['html']]),
        ];
    }

    public function messageFlash(mixed $message): string
    {
        $echappe = htmlspecialchars((string) $message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return strtr($echappe, self::BALISES_ADMISES);
    }
}
