<?php

namespace App\Validator;

use App\Dto\CandidatureDepotDto;
use App\Repository\CertificationRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class CertificationOfferteValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CertificationRepository $certifications,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CertificationOfferte || $value === null) {
            return;
        }

        if (!$value instanceof CandidatureDepotDto) {
            throw new UnexpectedValueException($value, CandidatureDepotDto::class);
        }

        // Chaque champ porte déjà sa contrainte NotNull : ne pas empiler un
        // second message sur un formulaire encore incomplet.
        if ($value->centre === null || $value->metier === null || $value->certification === null) {
            return;
        }

        if (!$value->certification->isActif()) {
            $this->context->buildViolation($constraint->messageInactive)
                ->atPath('certification')
                ->addViolation();

            return;
        }

        $offertes = $this->certifications->findOffertesPour($value->centre, $value->metier);

        if (!in_array($value->certification, $offertes, true)) {
            $this->context->buildViolation($constraint->messageNonOfferte)
                ->atPath('certification')
                ->addViolation();
        }
    }
}
