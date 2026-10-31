<?php

namespace App\Service\Candidature;

use App\Dto\EtudeDto;
use App\Dto\RecevabiliteDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutRecevabilite;
use App\Exception\RecevabiliteException;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Étude du dossier, rendue par le conseiller VAE, puis décision de
 * recevabilité, prononcée par le jury local du centre (module M4, étape 2 de
 * la spécification).
 *
 * L'étude (`etuStatut`) ne fait pas avancer le statut global : elle est
 * enregistrée ici puis journalisée. La recevabilité est une décision : elle
 * passe par TransitionCandidature::appliquer(), qui écrit `recStatut`.
 */
class RecevabiliteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransitionCandidature $transition,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    /**
     * Prend en charge un dossier non encore affecté, ou réaffecte un dossier
     * dont l'étude n'est pas encore rendue (règle métier R4.1).
     */
    public function affecter(Candidature $candidature, User $conseiller): void
    {
        if ($this->etudeRendue($candidature)) {
            throw RecevabiliteException::etudeDejaRendue((string) $candidature->getNumero());
        }

        $candidature->setConseiller($conseiller);
        $this->entityManager->flush();
    }

    /**
     * Enregistre le résultat de l'étude du dossier.
     *
     * Aucun résultat ne change le statut officiel : le dossier reste PREINSCRIT.
     * ACCEPTE ouvre le paiement des frais de dossier, dont la confirmation fera
     * passer le dossier à INSCRIT (PaiementSubscriber). REFUSE le laisse
     * modifiable et réétudiable. Dans les deux cas, la décision est tracée dans
     * l'historique. Un dossier déjà accepté ne se réétudie pas.
     *
     * Le justificatif d'expérience professionnelle est joint par le conseiller,
     * jamais par le candidat : il doit figurer au dossier pour que l'étude soit
     * rendue, quel qu'en soit le résultat. Le contrôleur l'enregistre (avec les
     * éventuelles corrections du dossier) avant d'appeler cette méthode.
     */
    public function etudier(Candidature $candidature, EtudeDto $dto, User $auteur): Candidature
    {
        // Seul un conseiller VAE étudie un dossier, jamais l'administrateur
        // (rôle stocké sur le compte, hors hiérarchie des rôles).
        if (!in_array(Role::CONSEILLER, $auteur->getRoles(), true)) {
            throw RecevabiliteException::etudeReserveeAuConseiller();
        }

        if (!$this->resolver->estEnAttenteEtude($candidature)) {
            throw RecevabiliteException::etudeImpossible((string) $candidature->getNumero());
        }

        $statut = $dto->statut;

        if ($statut === null || $statut === StatutEtude::EN_ATTENTE) {
            throw RecevabiliteException::resultatEtudeManquant();
        }

        if ($statut->exigeCommentaire() && trim((string) $dto->commentaire) === '') {
            throw RecevabiliteException::motifObligatoire();
        }

        if (in_array($candidature->getFexperiencepro(), [null, ''], true)) {
            throw RecevabiliteException::justificatifExperienceManquant();
        }

        // Le dossier est rattaché au conseiller qui rend l'étude, même si un
        // collègue l'avait pris en charge avant lui : c'est l'auteur réel de
        // l'analyse qui figure dans les listes et dans « Mes dossiers ».
        $candidature->setConseiller($auteur);

        $candidature->setEtuStatut($statut->value);
        $candidature->setEtuDate(new \DateTime());
        $candidature->setEtuCom($dto->commentaire);
        $candidature->setUserUpdate($auteur);

        $this->transition->journaliserDecision(
            $candidature,
            $auteur,
            sprintf('Étude du dossier : %s', $statut->libelle()),
            $dto->commentaire
        );

        return $candidature;
    }

    /**
     * Enregistre la décision de recevabilité du jury local et applique la
     * transition officielle correspondante. Impossible tant que le dossier
     * n'est pas INSCRIT (paiement des frais de dossier confirmé), et définitive
     * une fois prononcée.
     */
    public function enregistrerRecevabilite(Candidature $candidature, RecevabiliteDto $dto, User $auteur): Candidature
    {
        if ($candidature->getRecStatut() !== null) {
            throw RecevabiliteException::recevabiliteDejaPrononcee((string) $candidature->getNumero());
        }

        if ($this->resolver->resolve($candidature) !== StatutCandidature::INSCRIT) {
            throw RecevabiliteException::recevabiliteImpossible((string) $candidature->getNumero());
        }

        $statut = $dto->statut;

        if ($statut === null || $statut === StatutRecevabilite::EN_ATTENTE) {
            throw RecevabiliteException::resultatRecevabiliteManquant();
        }

        $candidature->setRecDate(new \DateTime());
        $candidature->setUserUpdate($auteur);

        $this->transition->appliquer(
            $candidature,
            $statut->statutOfficiel(),
            $auteur,
            sprintf('Recevabilité : %s', $statut->libelle())
        );

        return $candidature;
    }

    public function etudeRendue(Candidature $candidature): bool
    {
        return $candidature->getEtuStatut() !== null && $candidature->getEtuStatut() !== StatutEtude::EN_ATTENTE->value;
    }
}
