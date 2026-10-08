<?php

namespace App\Twig;

use App\Referentiel\Telephone;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre « telephone » : affiche un numéro enregistré au format international
 * de façon lisible (« +225 07 08 09 10 11 »). Les anciens numéros de dix
 * chiffres sans indicatif sont présentés comme ivoiriens.
 */
class TelephoneExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('telephone', Telephone::formater(...)),
        ];
    }
}
