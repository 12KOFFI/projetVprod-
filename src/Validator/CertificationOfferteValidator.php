<?php

namespace App\Validator;

use App\Dto\CandidatureDepotDto;
use App\Entity\Certification;
use App\Referentiel\ProfilProfessionnel;
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

            return;
        }

        // CQP de 5 à 6 ans, CAP à partir de 7 ans — ou CQP si le centre ne
        // prépare aucun CAP pour ce métier. En dessous de 5 ans, le champ des
        // années porte déjà l'erreur.
        $attendu = ProfilProfessionnel::typeRetenu(
            $value->nbAnneesExperience,
            array_map(static fn (Certification $certification) => $certification->getType(), $offertes)
        );

        if ($attendu !== null && $value->certification->getType() !== $attendu) {
            $this->context->buildViolation($constraint->messageType)
                ->setParameter('{{ annees }}', (string) $value->nbAnneesExperience)
                ->setParameter('{{ type }}', $attendu)
                ->atPath('certification')
                ->addViolation();
        }
    }
}
