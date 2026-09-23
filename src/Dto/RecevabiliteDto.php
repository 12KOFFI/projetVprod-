<?php

namespace App\Dto;

use App\Entity\Candidature;
use App\Enum\StatutRecevabilite;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie de la décision de recevabilité par le conseiller VAE (E4.5),
 * ouverte uniquement une fois le dossier INSCRIT. Aucun commentaire n'est
 * requis pour cette décision.
 */
class RecevabiliteDto
{
    #[Assert\NotNull(message: 'Le résultat de recevabilité est obligatoire.')]
    #[Assert\Choice(
        callback: [StatutRecevabilite::class, 'cases'],
        message: 'Ce résultat n\'est pas reconnu.',
    )]
    public ?StatutRecevabilite $statut = null;

    public static function depuisCandidature(Candidature $candidature): self
    {
        $dto = new self();
        $dto->statut = $candidature->getResultatRecevabilite();

        return $dto;
    }
}
