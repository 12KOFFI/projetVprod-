<?php

namespace App\Validator;

use App\Dto\CandidatureDepotDto;
use App\Repository\CentreMetierRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class MetierOuvertDansCentreValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CentreMetierRepository $centreMetierRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MetierOuvertDansCentre || $value === null) {
            return;
        }

        if (!$value instanceof CandidatureDepotDto) {
            throw new UnexpectedValueException($value, CandidatureDepotDto::class);
        }

        // Les deux champs portent déjà leur propre contrainte NotNull : inutile
        // d'empiler un second message sur un formulaire incomplet.
        if ($value->centre === null || $value->metier === null) {
            return;
        }

        if ($value->metier->getStatut() !== 'actif') {
            $this->context->buildViolation($constraint->messageInactif)
                ->atPath('metier')
                ->addViolation();

            return;
        }

        if (!$this->centreMetierRepository->metierOuvertDansCentre($value->centre, $value->metier)) {
            $this->context->buildViolation($constraint->messageFerme)
                ->atPath('metier')
                ->addViolation();
        }
    }
}
