<?php

namespace App\Service\Referentiel;

use App\Entity\User;
use App\Exception\SuppressionInterditeException;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Service\UtilService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création et mise à jour des comptes du personnel (F2.7).
 *
 * Le mot de passe initial est généré ici et retourné une seule fois à
 * l'appelant pour communication hors bande ; il n'est jamais stocké en clair et
 * son changement est imposé à la première connexion (règle métier R2.9).
 */
class GestionCompte
{
    private const LONGUEUR_MOT_DE_PASSE_INITIAL = 12;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UtilService $utilService,
    ) {
    }

    /**
     * Finalise un compte du personnel : rôle unique, rattachements cohérents,
     * mot de passe initial aléatoire.
     *
     * @return string le mot de passe en clair, à communiquer hors bande
     */
    public function creerPersonnel(User $utilisateur, string $role): string
    {
        $this->normaliser($utilisateur, $role);

        $motDePasse = $this->utilService->randomString(self::LONGUEUR_MOT_DE_PASSE_INITIAL, false);

        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $motDePasse));
        $utilisateur->setDoitChangerMotDePasse(true);
        $utilisateur->setActif(true);

        $this->userRepository->save($utilisateur, true);

        return $motDePasse;
    }

    /**
     * Met à jour un compte existant sans toucher au mot de passe.
     */
    public function mettreAJourPersonnel(User $utilisateur, string $role): void
    {
        $this->normaliser($utilisateur, $role);

        $this->userRepository->save($utilisateur, true);
    }

    /**
     * Réinitialise le mot de passe et réimpose son changement.
     *
     * @return string le nouveau mot de passe en clair
     */
    public function reinitialiserMotDePasse(User $utilisateur): string
    {
        $motDePasse = $this->utilService->randomString(self::LONGUEUR_MOT_DE_PASSE_INITIAL, false);

        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $motDePasse));
        $utilisateur->setDoitChangerMotDePasse(true);

        $this->userRepository->save($utilisateur, true);

        return $motDePasse;
    }

    /**
     * Bascule l'accès d'un compte. Un administrateur ne peut pas se désactiver
     * lui-même : il se priverait du seul écran capable de le réactiver.
     */
    public function basculerActivation(User $utilisateur, ?User $auteur): void
    {
        if ($auteur !== null && $auteur->getId() === $utilisateur->getId()) {
            throw SuppressionInterditeException::avecMotif(
                'Vous ne pouvez pas désactiver votre propre compte.'
            );
        }

        $utilisateur->setActif(!$utilisateur->isActif());

        $this->userRepository->save($utilisateur, true);
    }

    /**
     * Un compte du personnel porte exactement un rôle métier, et ses
     * rattachements sont purgés de ce qui ne correspond pas à ce rôle : sans
     * cela un ancien accompagnateur promu conseiller garderait un métier de
     * rattachement devenu trompeur (V1.2 et V1.3 de M1).
     */
    private function normaliser(User $utilisateur, string $role): void
    {
        $utilisateur->setRoles([$role]);

        if ($role === Role::ADMIN) {
            $utilisateur->setCentre(null);
        }

        if (!Role::exigeMetier($role)) {
            $utilisateur->setMetier(null);
        }

        $email = $utilisateur->getEmail();

        if ($email !== null) {
            $utilisateur->setEmail(mb_strtolower(trim($email)));
        }
    }
}
