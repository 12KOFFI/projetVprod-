<?php

namespace App\Dto;

use App\Entity\Candidature;
use App\Enum\StatutEtude;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Saisie de l'étude du dossier par le conseiller VAE (écran E4.4).
 *
 * Le DTO ne porte que le résultat de l'étude et son motif : le statut officiel
 * de la candidature en découle par TransitionCandidature (via RecevabiliteService)
 * et n'est jamais soumis par le formulaire.
 */
class EtudeDto
{
    #[Assert\NotNull(message: 'Le résultat de l\'étude est obligatoire.')]
    #[Assert\Choice(
        callback: [StatutEtude::class, 'cases'],
        message: 'Ce résultat n\'est pas reconnu.',
    )]
    public ?StatutEtude $statut = null;

    public ?string $commentaire = null;

    public static function depuisCandidature(Candidature $candidature): self
    {
        $dto = new self();
        $dto->statut = $candidature->getResultatEtude();
        $dto->commentaire = $candidature->getEtuCom();

        return $dto;
    }

    /**
     * Un résultat défavorable doit être motivé : le candidat reçoit ce motif, et
     * l'administration doit pouvoir justifier le refus (règle métier R4.4).
     */
    #[Assert\Callback]
    public function validerMotif(ExecutionContextInterface $context): void
    {
        if ($this->statut?->exigeCommentaire() !== true) {
            return;
        }

        if (trim((string) $this->commentaire) === '') {
            $context->buildViolation('Un résultat défavorable doit être motivé.')
                ->atPath('commentaire')
                ->addViolation();
        }
    }
}
