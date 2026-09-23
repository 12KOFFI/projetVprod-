<?php

namespace App\Validator;

use App\Entity\User;
use App\Security\Role;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class RattachementUtilisateurValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RattachementUtilisateur) {
            return;
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof User) {
            throw new UnexpectedValueException($value, User::class);
        }

        $roles = $value->getRoles();

        foreach ($roles as $role) {
            if (Role::exigeCentre($role) && $value->getCentre() === null) {
                $this->context->buildViolation($constraint->messageCentreManquant)
                    ->setParameter('{{ role }}', Role::libelle($role))
                    ->atPath('centre')
                    ->addViolation();
            }

            if (Role::exigeMetier($role) && $value->getMetier() === null) {
                $this->context->buildViolation($constraint->messageMetierManquant)
                    ->atPath('metier')
                    ->addViolation();
            }
        }
    }
}
