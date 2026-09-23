<?php

namespace App\Security\Voter;

use App\Entity\Candidature;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\TypeFrais;
use App\Repository\PaiementRepository;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorisation centralisée sur un dossier de candidature.
 *
 * Ce voter est la seule source de vérité des droits sur une candidature :
 * les contrôleurs l'interrogent via denyAccessUnlessGranted() et les templates
 * via is_granted(). Le filtrage des LISTES relève, lui, de
 * CandidatureRepository::appliquerPerimetre().
 *
 * @extends Voter<string, Candidature>
 */
class CandidatureVoter extends Voter
{
    public const VIEW                     = 'CANDIDATURE_VIEW';
    public const EDIT                     = 'CANDIDATURE_EDIT';
    public const EVALUATE_RECEVABILITE    = 'CANDIDATURE_EVALUATE_RECEVABILITE';
    public const EVALUATE_ADMISSIBILITE   = 'CANDIDATURE_EVALUATE_ADMISSIBILITE';
    public const ACCOMPAGNER              = 'CANDIDATURE_ACCOMPAGNER';
    public const PAY                      = 'CANDIDATURE_PAY';
    public const DELETE                   = 'CANDIDATURE_DELETE';
    public const PRINT_FICHE              = 'CANDIDATURE_PRINT_FICHE';

    private const ATTRIBUTS = [
        self::VIEW,
        self::EDIT,
        self::EVALUATE_RECEVABILITE,
        self::EVALUATE_ADMISSIBILITE,
        self::ACCOMPAGNER,
        self::PAY,
        self::DELETE,
        self::PRINT_FICHE,
    ];

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly PaiementRepository $paiementRepository,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTS, true) && $subject instanceof Candidature;
    }

    /**
     * @param Candidature $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // L'administrateur a un périmètre national sur tout, sauf le paiement :
        // seul le candidat règle ses propres frais.
        if ($attribute !== self::PAY && $this->authorization->isGranted(Role::ADMIN)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW                   => $this->peutVoir($subject, $user),
            self::EDIT                   => $this->peutModifier($subject, $user),
            self::EVALUATE_RECEVABILITE  => $this->peutEvaluerRecevabilite($subject, $user),
            self::EVALUATE_ADMISSIBILITE => $this->peutEvaluerAdmissibilite($subject, $user),
            self::ACCOMPAGNER            => $this->peutAccompagner($subject, $user),
            self::PAY                    => $this->peutPayer($subject, $user),
            self::DELETE                 => false, // réservé à l'administrateur, traité plus haut
            self::PRINT_FICHE            => $this->peutImprimerFiche($subject, $user),
            default                      => false,
        };
    }

    private function peutVoir(Candidature $candidature, User $user): bool
    {
        if ($this->estLeCandidat($candidature, $user)) {
            return true;
        }

        if ($this->estDuMemeCentre($candidature, $user)
            && ($this->aRole($user, Role::CONSEILLER) || $this->aRole($user, Role::AGENT_ACCUEIL))) {
            return true;
        }

        return $this->estSonAccompagnateur($candidature, $user);
    }

    /**
     * Le dossier n'est modifiable que tant que son étude reste à rendre : une
     * étude acceptée le fige, même s'il reste préinscrit jusqu'au paiement.
     */
    private function peutModifier(Candidature $candidature, User $user): bool
    {
        if (!$this->resolver->estEnAttenteEtude($candidature)) {
            return false;
        }

        if ($this->estLeCandidat($candidature, $user)) {
            return true;
        }

        return $this->aRole($user, Role::AGENT_ACCUEIL) && $this->estDuMemeCentre($candidature, $user);
    }

    private function peutEvaluerRecevabilite(Candidature $candidature, User $user): bool
    {
        return $this->aRole($user, Role::CONSEILLER) && $this->estDuMemeCentre($candidature, $user);
    }

    /**
     * L'évaluation devant jury exige le règlement préalable des frais d'examen
     * (specifications_vae.txt, étape 6 et section 4).
     */
    private function peutEvaluerAdmissibilite(Candidature $candidature, User $user): bool
    {
        if (!$this->aRole($user, Role::CONSEILLER) || !$this->estDuMemeCentre($candidature, $user)) {
            return false;
        }

        return $this->paiementRepository->existeReussi($candidature, TypeFrais::EXAMEN);
    }

    private function peutAccompagner(Candidature $candidature, User $user): bool
    {
        return $this->estSonAccompagnateur($candidature, $user);
    }

    private function peutPayer(Candidature $candidature, User $user): bool
    {
        return $this->estLeCandidat($candidature, $user);
    }

    private function peutImprimerFiche(Candidature $candidature, User $user): bool
    {
        if (!$this->peutVoir($candidature, $user)) {
            return false;
        }

        // La fiche d'inscription n'est émise qu'une fois l'inscription acquise
        // (frais de dossier réglés) : tout statut au-delà de PREINSCRIT.
        return $this->resolver->resolve($candidature) !== StatutCandidature::PREINSCRIT;
    }

    private function estLeCandidat(Candidature $candidature, User $user): bool
    {
        return $candidature->getUser() !== null
            && $candidature->getUser()->getId() === $user->getId();
    }

    private function estSonAccompagnateur(Candidature $candidature, User $user): bool
    {
        return $candidature->getAccompagnateur() !== null
            && $candidature->getAccompagnateur()->getId() === $user->getId();
    }

    /**
     * Un membre du personnel sans centre rattaché ne peut accéder à aucun dossier.
     */
    private function estDuMemeCentre(Candidature $candidature, User $user): bool
    {
        return $user->getCentre() !== null
            && $candidature->getCentre() !== null
            && $user->getCentre()->getId() === $candidature->getCentre()->getId();
    }

    private function aRole(User $user, string $role): bool
    {
        return in_array($role, $user->getRoles(), true);
    }
}
