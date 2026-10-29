<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion d'un compte désactivé par l'administrateur.
 *
 * La désactivation (PersonnelController::basculerActivation) n'avait jusque-là
 * aucun effet sur l'authentification. Les sessions déjà ouvertes sont, elles,
 * fermées par User::isEqualTo() à la requête suivante.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    /**
     * Contrôle fait après la vérification du mot de passe : sans le bon mot de
     * passe, un compte désactivé ne se distingue pas d'un identifiant inconnu.
     */
    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActif()) {
            throw new CustomUserMessageAccountStatusException(
                "Ce compte a été désactivé. Adressez-vous à l'administrateur de la plateforme."
            );
        }
    }
}
