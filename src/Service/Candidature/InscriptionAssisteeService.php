<?php

namespace App\Service\Candidature;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Exception\DepotCandidatureException;
use App\Repository\UserRepository;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Inscription d'un candidat réalisée par un agent d'accueil (F3.6, F3.7).
 *
 * L'agent crée le compte puis dépose le dossier en une seule passe. La
 * traçabilité de l'agent est conservée sur la candidature : c'est ce qui
 * distingue une inscription assistée d'un dépôt autonome (spec 5.2).
 */
class InscriptionAssisteeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly CandidatureService $candidatureService,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Crée le compte candidat puis dépose son dossier.
     *
     * Le mot de passe est choisi par l'agent avec le candidat, qui le connaît
     * donc déjà : aucun changement n'est imposé à la première connexion, le
     * candidat reste libre de le modifier depuis son profil.
     */
    public function inscrire(User $candidat, CandidatureDepotDto $dto, User $agent, string $motDePasse): Candidature
    {
        $centre = $this->centreDeLAgent($agent);

        // Le centre du dossier est celui de l'agent, quoi qu'ait pu contenir la
        // requête : un agent n'inscrit que dans son propre centre (R3.7).
        $dto->centre = $centre;

        $candidat->setRoles([Role::CANDIDAT]);
        $candidat->setPassword($this->passwordHasher->hashPassword($candidat, $motDePasse));
        $candidat->setDoitChangerMotDePasse(false);

        $this->entityManager->persist($candidat);
        $this->entityManager->flush();

        return $this->candidatureService->deposer($dto, $candidat, $agent, $agent);
    }

    /**
     * Dépose un dossier pour un candidat déjà inscrit, retrouvé par son
     * identifiant de connexion (F3.7).
     */
    public function deposerPourCandidatExistant(User $candidat, CandidatureDepotDto $dto, User $agent): Candidature
    {
        $dto->centre = $this->centreDeLAgent($agent);

        return $this->candidatureService->deposer($dto, $candidat, $agent, $agent);
    }

    /**
     * Retrouve un candidat déjà inscrit à partir de son email ou de son contact.
     */
    public function retrouverCandidat(string $identifiant): ?User
    {
        $candidat = $this->userRepository->findOneByIdentifiant($identifiant);

        if ($candidat === null || Role::principal($candidat) !== Role::CANDIDAT) {
            return null;
        }

        return $candidat;
    }

    private function centreDeLAgent(User $agent): \App\Entity\Centre
    {
        $centre = $agent->getCentre();

        if ($centre === null) {
            throw DepotCandidatureException::agentSansCentre();
        }

        return $centre;
    }
}
