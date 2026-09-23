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
use Doctrine\ORM\EntityManagerInterface;

/**
 * Étude du dossier et décision de recevabilité, toutes deux rendues par le
 * conseiller VAE (module M4, étape 2 de la spécification).
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
     */
    public function etudier(Candidature $candidature, EtudeDto $dto, User $auteur): Candidature
    {
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

        // Le conseiller qui se prononce prend le dossier en charge s'il n'était
        // affecté à personne : la charge affichée à l'agent d'accueil reste juste.
        if ($candidature->getConseiller() === null) {
            $candidature->setConseiller($auteur);
        }

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
     * Enregistre la décision de recevabilité et applique la transition
     * officielle correspondante. Impossible tant que le dossier n'est pas
     * INSCRIT (paiement des frais de dossier confirmé).
     */
    public function enregistrerRecevabilite(Candidature $candidature, RecevabiliteDto $dto, User $auteur): Candidature
    {
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
