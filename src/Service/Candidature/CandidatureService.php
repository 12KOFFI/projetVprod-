<?php

namespace App\Service\Candidature;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Exception\DepotCandidatureException;
use App\Repository\CandidatureRepository;
use App\Repository\CentreMetierRepository;
use App\Security\Role;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Dépôt et mise à jour d'un dossier de candidature (module M3).
 *
 * Toutes les règles du dépôt sont concentrées ici : le contrôleur se limite à
 * convertir la requête en DTO puis à rendre la réponse. Aucun statut n'est
 * écrit : le statut global est calculé par CandidatureStatusResolver.
 */
class CandidatureService
{
    /** Verrou MySQL nommé qui sérialise l'attribution des numéros d'inscription. */
    private const VERROU_NUMERO = 'vae_attribution_numero';

    /** Attente maximale du verrou, en secondes, avant d'abandonner le dépôt. */
    private const ATTENTE_VERROU_SECONDES = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CandidatureRepository $candidatureRepository,
        private readonly CentreMetierRepository $centreMetierRepository,
        private readonly NumeroVaeGenerator $numeroGenerator,
        private readonly TransitionCandidature $transition,
        private readonly FileUploader $fileUploader,
        private readonly string $dossierMedia,
    ) {
    }

    /**
     * Dépose un dossier pour le compte d'un candidat.
     *
     * @param User      $candidat     propriétaire du dossier
     * @param User      $auteur       utilisateur connecté (candidat lui-même ou agent)
     * @param User|null $agentAccueil agent ayant réalisé l'inscription assistée
     */
    public function deposer(
        CandidatureDepotDto $dto,
        User $candidat,
        User $auteur,
        ?User $agentAccueil = null,
    ): Candidature {
        $this->garantirCandidatEligible($candidat);
        $this->garantirOffreOuverte($dto);

        $candidature = new Candidature();
        $candidature->setUser($candidat);
        $candidature->setAgentAccueil($agentAccueil);

        $this->appliquerDonnees($candidature, $dto);

        $this->persisterAvecNumero($candidature);

        // Les fichiers ne sont déplacés qu'une fois le numéro acquis : c'est lui
        // qui nomme le répertoire de destination. En cas d'échec de l'upload, le
        // dossier est retiré pour ne pas laisser de candidature sans pièces.
        try {
            $this->enregistrerDocuments($candidature, $dto->documents);
            $this->entityManager->flush();
        } catch (\Throwable $erreur) {
            $this->annulerDepot($candidature);

            throw $erreur;
        }

        // Journalise l'entrée dans le parcours : sans aucune décision, la
        // candidature est préinscrite dès sa création. La ligne d'audit est
        // écrite explicitement.
        $this->transition->journaliserDepot($candidature, $auteur);

        return $candidature;
    }

    /**
     * Met à jour un dossier encore modifiable (règle métier R3.10).
     *
     * L'autorisation elle-même relève de CandidatureVoter::EDIT : ce service
     * suppose le contrôle déjà effectué par le contrôleur appelant.
     */
    public function mettreAJour(Candidature $candidature, CandidatureDepotDto $dto, User $auteur): Candidature
    {
        $this->garantirOffreOuverte($dto);

        $this->appliquerDonnees($candidature, $dto);
        $candidature->setUserUpdate($auteur);

        $this->enregistrerDocuments($candidature, $dto->documents);
        $this->entityManager->flush();

        return $candidature;
    }

    /**
     * Pièces obligatoires manquantes, pour un dossier donné.
     *
     * Utilisé au dépôt comme à la modification : lors d'une modification, un
     * fichier déjà transmis satisfait l'exigence sans nouvel envoi.
     *
     * @param array<string, UploadedFile|null> $documents
     *
     * @return array<string, string> champ => libellé
     */
    public function documentsManquants(array $documents, ?Candidature $candidature = null): array
    {
        $manquants = [];

        foreach (CandidatureDepotDto::documentsObligatoires() as $champ => $libelle) {
            if (($documents[$champ] ?? null) instanceof UploadedFile) {
                continue;
            }

            $dejaTransmis = $candidature !== null
                && !in_array($this->lireDocument($candidature, $champ), [null, ''], true);

            if (!$dejaTransmis) {
                $manquants[$champ] = $libelle;
            }
        }

        return $manquants;
    }

    /**
     * Un membre du personnel ne dépose pas de dossier pour lui-même (R3.12), et
     * un candidat ne suit qu'une démarche à la fois (R3.1). Cette vérification
     * est refaite à la soumission, l'affichage du formulaire ne suffisant pas.
     */
    public function garantirCandidatEligible(User $candidat): void
    {
        if (Role::principal($candidat) !== Role::CANDIDAT) {
            throw DepotCandidatureException::personnelNonAutorise();
        }

        if ($this->candidatureRepository->compterActivesPourCandidat($candidat) > 0) {
            throw DepotCandidatureException::candidatureDejaOuverte();
        }
    }

    public function peutDeposer(User $candidat): bool
    {
        return Role::principal($candidat) === Role::CANDIDAT
            && $this->candidatureRepository->compterActivesPourCandidat($candidat) === 0;
    }

    private function garantirOffreOuverte(CandidatureDepotDto $dto): void
    {
        if ($dto->centre === null || $dto->metier === null) {
            return;
        }

        if ($dto->metier->getStatut() !== 'actif') {
            throw DepotCandidatureException::metierInactif((string) $dto->metier->getLibelle());
        }

        if (!$this->centreMetierRepository->metierOuvertDansCentre($dto->centre, $dto->metier)) {
            throw DepotCandidatureException::metierFermeDansCentre(
                (string) $dto->metier->getLibelle(),
                (string) $dto->centre->getNom()
            );
        }
    }

    private function appliquerDonnees(Candidature $candidature, CandidatureDepotDto $dto): void
    {
        $annees = (int) $dto->nbAnneesExperience;

        $candidature->setCentre($dto->centre);
        $candidature->setMetier($dto->metier);
        $candidature->setNbAnneesExperience($annees);
        $candidature->setSituationPro($dto->situationPro);
        $candidature->setDiplome($dto->diplome);
        $candidature->setTitrepro($dto->titrepro);
        $candidature->setNomEntreprise($dto->nomEntreprise);
        $candidature->setRefentreprise($dto->refentreprise);
        $candidature->setLieuExercice($dto->lieuExercice);
        $candidature->setFonction($dto->fonction);
        $candidature->setRefContrat($dto->refContrat);
        $candidature->setDirectionService($dto->directionService);
        $candidature->setContactemployeur($dto->contactemployeur);
        $candidature->setLangue($dto->langue);
        $candidature->setPreciserlangue($dto->preciserlangue);

        // Le diplôme visé est choisi par le candidat, pas déduit de son
        // expérience : celle-ci n'est qu'une indication affichée à titre de
        // repère dans le formulaire.
        //
        // Le libellé de la certification est recopié plutôt que référencé : le
        // dossier doit garder trace du diplôme tel qu'il a été proposé le jour
        // du dépôt, même si le référentiel évolue ensuite.
        $candidature->setDiplomedemande($dto->certification?->getLibelle());
    }

    /**
     * Attribue un numéro puis insère la candidature.
     *
     * Le numéro est une propriété de la candidature, unique en base. Pour que
     * deux dépôts simultanés ne calculent pas la même séquence, le calcul et
     * l'insertion se font sous un verrou MySQL nommé (GET_LOCK) : le second
     * dépôt attend que le premier soit enregistré, puis lit la séquence à jour
     * (règle métier R3.5). Le verrou est libéré dans tous les cas, et MySQL le
     * libère aussi de lui-même si la connexion est coupée.
     */
    private function persisterAvecNumero(Candidature $candidature): void
    {
        $connexion = $this->entityManager->getConnection();

        $obtenu = $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [self::VERROU_NUMERO, self::ATTENTE_VERROU_SECONDES]);

        if ((int) $obtenu !== 1) {
            throw DepotCandidatureException::numeroIndisponible();
        }

        try {
            $candidature->setNumero($this->numeroGenerator->suivant($candidature->getCreation()));
            $this->entityManager->persist($candidature);
            $this->entityManager->flush();
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [self::VERROU_NUMERO]);
        }
    }

    /**
     * @param array<string, UploadedFile|null> $documents
     */
    private function enregistrerDocuments(Candidature $candidature, array $documents): void
    {
        $repertoire = $this->repertoireDe($candidature);

        foreach ($documents as $champ => $fichier) {
            if (!$fichier instanceof UploadedFile) {
                continue;
            }

            if (!$this->estUnChampDocument($candidature, $champ)) {
                continue;
            }

            $nom = $this->fileUploader->uploadWithName(
                $fichier,
                $repertoire,
                $champ,
                $this->lireDocument($candidature, $champ)
            );

            $candidature->{'set' . ucfirst($champ)}($nom);
        }
    }

    /**
     * Retire une candidature dont le dépôt n'a pas abouti.
     *
     * La réservation du numéro n'est volontairement pas libérée : un numéro
     * d'inscription a pu être communiqué ou imprimé, le réattribuer à un autre
     * dossier créerait une ambiguïté bien plus coûteuse qu'un trou dans la
     * séquence.
     */
    private function annulerDepot(Candidature $candidature): void
    {
        $repertoire = $this->repertoireDe($candidature);

        $this->entityManager->remove($candidature);
        $this->entityManager->flush();

        // Sans ce nettoyage, un échec en cours d'upload laisserait des fichiers
        // rattachés à un numéro qui n'existe plus.
        $this->fileUploader->remove($repertoire);
    }

    private function repertoireDe(Candidature $candidature): string
    {
        return rtrim($this->dossierMedia, '/\\') . \DIRECTORY_SEPARATOR . $candidature->getNumero();
    }

    private function lireDocument(Candidature $candidature, string $champ): ?string
    {
        $getter = 'get' . ucfirst($champ);

        return method_exists($candidature, $getter) ? $candidature->{$getter}() : null;
    }

    private function estUnChampDocument(Candidature $candidature, string $champ): bool
    {
        return in_array($champ, ['fphoto', 'fpiece', 'fextrait', 'fexperiencepro', 'fcmu'], true)
            && method_exists($candidature, 'set' . ucfirst($champ));
    }
}
