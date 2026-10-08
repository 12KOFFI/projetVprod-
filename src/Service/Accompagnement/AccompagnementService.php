<?php

namespace App\Service\Accompagnement;

use App\Entity\Candidature;
use App\Entity\PreuveLivret;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\TypeFrais;
use App\Exception\AccompagnementException;
use App\Repository\PaiementRepository;
use App\Security\Role;
use App\Service\Candidature\CandidatureStatusResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Accompagnement du candidat (étape 5 du parcours, facultative).
 *
 * 1. Après l'éligibilité, le candidat choisit un accompagnateur de son centre
 *    ET de son métier (choisir()).
 * 2. Il règle les frais d'accompagnement ; le paiement confirmé déclenche
 *    l'affectation (affecterApresPaiement(), appelé par PaiementSubscriber).
 * 3. Candidat et accompagnateur constituent ensemble le livret de preuves.
 */
class AccompagnementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CandidatureStatusResolver $resolver,
        private readonly PaiementRepository $paiementRepository,
        private readonly LoggerInterface $logger,
        #[Autowire('%dir_media%')]
        private readonly string $dossierPieces,
    ) {
    }

    /**
     * Accompagnateurs pouvant suivre ce dossier, du moins chargé au plus chargé.
     *
     * @return array<int, array{accompagnateur: User, charge: int}>
     */
    public function accompagnateursDisponibles(Candidature $candidature): array
    {
        if ($candidature->getCentre() === null || $candidature->getMetier() === null) {
            return [];
        }

        $lignes = $this->entityManager->createQueryBuilder()
            ->select('u AS accompagnateur', 'COUNT(c.id) AS charge')
            ->from(User::class, 'u')
            ->leftJoin(Candidature::class, 'c', 'WITH', 'c.accompagnateur = u')
            ->andWhere('u.roles LIKE :role')
            ->andWhere('u.centre = :centre')
            ->andWhere('u.metier = :metier')
            ->setParameter('role', '%' . Role::ACCOMPAGNATEUR . '%')
            ->setParameter('centre', $candidature->getCentre())
            ->setParameter('metier', $candidature->getMetier())
            ->groupBy('u.id')
            ->orderBy('charge', 'ASC')
            ->addOrderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $l) => ['accompagnateur' => $l['accompagnateur'], 'charge' => (int) $l['charge']], $lignes);
    }

    /** L'accompagnement ne s'ouvre qu'à un candidat éligible encore sans accompagnateur. */
    public function peutChoisir(Candidature $candidature): bool
    {
        return $this->resolver->resolve($candidature) === StatutCandidature::ELIGIBLE
            && $candidature->getAccompagnateur() === null
            && !$this->paiementRepository->existeReussi($candidature, TypeFrais::ACCOMPAGNEMENT);
    }

    /**
     * Enregistre le choix du candidat. L'accompagnateur n'est pas encore
     * affecté : il ne voit le dossier qu'après le paiement des frais.
     */
    public function choisir(Candidature $candidature, User $accompagnateur): void
    {
        if (!$this->peutChoisir($candidature)) {
            throw AccompagnementException::choixFerme();
        }

        $autorise = array_filter(
            $this->accompagnateursDisponibles($candidature),
            static fn (array $l) => $l['accompagnateur']->getId() === $accompagnateur->getId()
        );

        // L'identifiant vient d'un formulaire : il doit désigner un
        // accompagnateur du centre ET du métier du dossier.
        if ($autorise === []) {
            throw AccompagnementException::accompagnateurNonAutorise();
        }

        $candidature->setAccompagnateurSouhaite($accompagnateur);
        $this->entityManager->flush();
    }

    /**
     * Affectation consécutive au paiement des frais d'accompagnement.
     * Idempotente : sans effet si l'accompagnateur est déjà affecté.
     */
    public function affecterApresPaiement(Candidature $candidature): bool
    {
        $souhaite = $candidature->getAccompagnateurSouhaite();

        if ($souhaite === null || $candidature->getAccompagnateur() !== null) {
            return false;
        }

        $candidature->setAccompagnateur($souhaite);
        $this->entityManager->flush();

        $this->logger->info('Accompagnateur affecté après paiement', [
            'candidature' => $candidature->getNumero(),
            'accompagnateur' => $souhaite->getUserIdentifier(),
        ]);

        return true;
    }

    /**
     * Dépose une preuve. Le fichier est déjà validé par PreuveLivretType
     * (type et taille) ; il est enregistré sous un nom aléatoire.
     */
    public function deposerPreuve(Candidature $candidature, UploadedFile $fichier, string $titre, ?string $description, User $auteur): PreuveLivret
    {
        $extension = $fichier->guessExtension() ?: 'bin';
        $nom = bin2hex(random_bytes(16)) . '.' . $extension;
        $taille = (int) $fichier->getSize();
        $nomOriginal = mb_substr($fichier->getClientOriginalName(), 0, 255);

        $fichier->move($this->dossierLivret($candidature), $nom);

        $preuve = (new PreuveLivret())
            ->setCandidature($candidature)
            ->setTitre(trim($titre))
            ->setDescription($description !== null && trim($description) !== '' ? trim($description) : null)
            ->setFichier($nom)
            ->setNomOriginal($nomOriginal)
            ->setTaille($taille)
            ->setDeposePar($auteur);

        $this->entityManager->persist($preuve);
        $this->entityManager->flush();

        return $preuve;
    }

    public function supprimerPreuve(PreuveLivret $preuve): void
    {
        $chemin = $this->cheminPreuve($preuve);
        $this->entityManager->remove($preuve);
        $this->entityManager->flush();

        (new Filesystem())->remove($chemin);
    }

    public function cheminPreuve(PreuveLivret $preuve): string
    {
        return $this->dossierLivret($preuve->getCandidature()) . \DIRECTORY_SEPARATOR . basename((string) $preuve->getFichier());
    }

    private function dossierLivret(Candidature $candidature): string
    {
        return rtrim($this->dossierPieces, '/\\') . \DIRECTORY_SEPARATOR . $candidature->getNumero() . \DIRECTORY_SEPARATOR . 'livret';
    }
}
