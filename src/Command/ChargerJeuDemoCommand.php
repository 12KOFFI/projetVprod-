<?php

namespace App\Command;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Entity\Metier;
use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutPaiement;
use App\Enum\StatutRecevabilite;
use App\Enum\TypeFrais;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\LocaliteRepository;
use App\Repository\MetierRepository;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\NumeroVaeGenerator;
use App\Service\Candidature\OrientationDiplomeCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Peuple la base d'un parcours complet de démonstration.
 *
 * Là où app:comptes:demo se limite à un compte par rôle, cette commande crée un
 * second centre, le personnel qui va avec et des candidatures réparties sur
 * toutes les étapes du parcours : chaque écran a ainsi de quoi s'afficher, y
 * compris ceux des étapes que les modules M7 et M8 n'alimentent pas encore.
 *
 * Les décisions sont posées directement, sans passer par
 * TransitionCandidature : il s'agit de fabriquer un état de départ, pas de
 * rejouer un parcours. Le statut de chaque dossier en découle, calculé par
 * CandidatureStatusResolver, et la commande vérifie qu'il est celui visé. L'historique est écrit à la main pour que les écrans de
 * suivi restent cohérents.
 */
#[AsCommand(
    name: 'app:jeu:demo',
    description: 'Crée un jeu de données de démonstration couvrant tout le parcours.',
)]
class ChargerJeuDemoCommand extends Command
{
    private const MOT_DE_PASSE = 'MotDePasse2026!';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly CentreRepository $centres,
        private readonly CentreMetierRepository $offres,
        private readonly MetierRepository $metiers,
        private readonly LocaliteRepository $localites,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly OrientationDiplomeCalculator $orientation,
        private readonly CandidatureStatusResolver $resolver,
        private readonly NumeroVaeGenerator $numeros,
        private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Autorise l\'exécution hors développement.');
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Supprime les candidatures existantes avant de charger.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Ces comptes partagent un mot de passe connu : ils n'ont rien à faire
        // sur un environnement de production.
        if ($this->environnement !== 'dev' && !$input->getOption('force')) {
            $io->error(sprintf(
                'Commande réservée au développement (environnement : %s). Utilisez --force pour passer outre.',
                $this->environnement
            ));

            return Command::FAILURE;
        }

        if ($this->metiers->count([]) === 0) {
            $io->error('Référentiel vide. Exécutez d\'abord app:referentiel:load.');

            return Command::FAILURE;
        }

        if ($input->getOption('purger')) {
            $this->purger($io);
        }

        $io->title('Jeu de démonstration VAE');

        $centres = $this->centresDemo($io);
        $personnel = $this->personnelDemo($io, $centres);
        $this->candidaturesDemo($io, $centres, $personnel);

        $this->entityManager->flush();

        $this->afficherAcces($io, $personnel);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, Centre>
     */
    private function centresDemo(SymfonyStyle $io): array
    {
        $definitions = [
            'cocody' => ['Centre de formation professionnelle de Cocody', 'etablissement', 'Abidjan – Cocody'],
            'bouake' => ['Centre de formation professionnelle de Bouaké', 'etablissement', 'Bouaké'],
        ];

        $centres = [];

        foreach ($definitions as $cle => [$nom, $type, $localite]) {
            $centre = $this->centres->findOneBy(['nom' => $nom]);

            if ($centre === null) {
                $centre = new Centre();
                $centre->setNom($nom);
                $centre->setType($type);
                $centre->setLocalite($this->localites->findOneBy(['libelle' => $localite]));

                $this->entityManager->persist($centre);
                $this->entityManager->flush();
            }

            // L'offre de chaque centre est posée par app:referentiel:load, qui
            // la différencie d'un centre à l'autre. On se contente de vérifier
            // qu'elle existe : la rouvrir en entier ici effacerait cette
            // différence, et avec elle la possibilité d'éprouver la règle R3.2.
            if ($this->offres->countParCentre($centre) === 0) {
                foreach ($this->metiers->findBy(['statut' => 'actif']) as $metier) {
                    $offre = new CentreMetier();
                    $offre->setCentre($centre);
                    $offre->setMetier($metier);
                    $offre->setNbrplace(25);

                    $this->entityManager->persist($offre);
                }
            }

            $centres[$cle] = $centre;
        }

        $this->entityManager->flush();
        $io->text(sprintf('Centres : <info>%d</info> avec leur offre de métiers.', count($centres)));

        return $centres;
    }

    /**
     * @param array<string, Centre> $centres
     *
     * @return array<string, User>
     */
    private function personnelDemo(SymfonyStyle $io, array $centres): array
    {
        $coiffure = $this->metiers->findOneBy(['libelle' => 'Coiffeur(euse)']);
        $maconnerie = $this->metiers->findOneBy(['libelle' => 'Maçon']);

        $definitions = [
            'admin'          => ['admin2@vae.test', Role::ADMIN, null, null, 'DIABATE', 'Salimata'],
            'agent'          => ['agent@vae.test', Role::AGENT_ACCUEIL, $centres['cocody'], null, 'KONE', 'Awa'],
            'agent_bouake'   => ['agent.bouake@vae.test', Role::AGENT_ACCUEIL, $centres['bouake'], null, 'YAO', 'Christelle'],
            'conseiller'     => ['conseiller@vae.test', Role::CONSEILLER, $centres['cocody'], null, 'BAMBA', 'Ibrahim'],
            'conseiller2'    => ['conseiller2@vae.test', Role::CONSEILLER, $centres['cocody'], null, 'OUATTARA', 'Mariam'],
            'conseiller_bke' => ['conseiller.bouake@vae.test', Role::CONSEILLER, $centres['bouake'], null, 'COULIBALY', 'Seydou'],
            'accomp_coif'    => ['accompagnateur@vae.test', Role::ACCOMPAGNATEUR, $centres['cocody'], $coiffure, 'DIALLO', 'Fatoumata'],
            'accomp_maco'    => ['accompagnateur2@vae.test', Role::ACCOMPAGNATEUR, $centres['cocody'], $maconnerie, 'SANGARE', 'Moussa'],
        ];

        $personnel = [];

        foreach ($definitions as $cle => [$email, $role, $centre, $metier, $nom, $prenoms]) {
            $personnel[$cle] = $this->creerCompte($email, [$role], $centre, $metier, $nom, $prenoms);
        }

        $this->entityManager->flush();
        $io->text(sprintf('Personnel : <info>%d</info> compte(s).', count($personnel)));

        return $personnel;
    }

    /**
     * @param array<string, Centre> $centres
     * @param array<string, User>   $personnel
     */
    private function candidaturesDemo(SymfonyStyle $io, array $centres, array $personnel): void
    {
        // Un dossier par étape du parcours, afin que chaque écran ait de la
        // matière à afficher, y compris ceux des modules à venir.
        $definitions = [
            ['SORO', 'Adama', 'M', 'Coiffeur(euse)', 'cocody', StatutCandidature::PREINSCRIT, 6],
            ['KOFFI', 'Akissi', 'F', 'Couturier', 'cocody', StatutCandidature::PREINSCRIT, 3],
            ['TOURE', 'Bakary', 'M', 'Maçon', 'cocody', StatutCandidature::PREINSCRIT, 8],
            ['GNAMIEN', 'Rosine', 'F', 'Coiffeur(euse)', 'cocody', StatutCandidature::INSCRIT, 5],
            ['ZONGO', 'Issa', 'M', 'Constructeur Métallique (Soudure)', 'cocody', StatutCandidature::DOSSIER_NON_RECEVABLE, 2],
            ['ADJOUA', 'Marie', 'F', 'Cuisinier-Restaurateur', 'cocody', StatutCandidature::DOSSIER_RECEVABLE, 7],
            ['BROU', 'Konan', 'M', 'Électricien Bâtiment', 'cocody', StatutCandidature::DOSSIER_RECEVABLE, 9],
            ['KEITA', 'Aissata', 'F', 'Coiffeur(euse)', 'cocody', StatutCandidature::ELIGIBLE, 6],
            ['DOSSO', 'Karim', 'M', 'Maçon', 'cocody', StatutCandidature::ELIGIBLE, 11],
            ['N\'GUESSAN', 'Affoué', 'F', 'Couturier', 'cocody', StatutCandidature::NON_ELIGIBLE, 4],
            ['SILUE', 'Drissa', 'M', 'Mécanicien Automobile', 'bouake', StatutCandidature::PREINSCRIT, 10],
            ['TANOH', 'Estelle', 'F', 'Cuisinier-Restaurateur', 'bouake', StatutCandidature::PREINSCRIT, 5],
            ['KOUADIO', 'Michel', 'M', 'Plombier Sanitaire', 'bouake', StatutCandidature::INSCRIT, 12],
            ['BAMBA', 'Salif', 'M', 'Carreleur', 'bouake', StatutCandidature::ADMISSIBLE, 8],
            ['YEO', 'Nadège', 'F', 'Coiffeur(euse)', 'bouake', StatutCandidature::ADMIS_DEFINITIF, 9],
        ];

        // Dossier resté préinscrit après une étude refusée : le résultat est
        // enregistré sans transition de statut.
        $etudesRefusees = ['KOFFI'];

        // Dossiers restés préinscrits après une étude acceptée : ils attendent
        // le paiement des frais de dossier pour passer à INSCRIT.
        $etudesAcceptees = ['TOURE', 'TANOH'];

        $sequence = 1;
        $lignes = [];

        foreach ($definitions as [$nom, $prenoms, $sexe, $libelleMetier, $cleCentre, $statut, $annees]) {
            $email = $this->emailCandidat($nom, $prenoms);
            $candidat = $this->creerCompte($email, [Role::CANDIDAT], null, null, $nom, $prenoms, $sexe);

            $this->entityManager->flush();

            $numero = $this->numeros->composer((int) date('Y'), $sequence);
            $centre = $centres[$cleCentre];
            $metier = $this->metiers->findOneBy(['libelle' => $libelleMetier]);
            $etudeRefusee = in_array($nom, $etudesRefusees, true);
            $etudeAcceptee = in_array($nom, $etudesAcceptees, true);

            $candidature = new Candidature();
            $candidature->setNumero($numero);
            $candidature->setUser($candidat);
            $candidature->setCentre($centre);
            $candidature->setMetier($metier);
            $candidature->setNbAnneesExperience($annees);
            $candidature->setSituationPro('ARTISAN A SON COMPTE');
            $candidature->setNomEntreprise(sprintf('Atelier %s', ucfirst(strtolower($nom))));
            $candidature->setLieuExercice((string) $centre->getLocalite()?->getLibelle());
            $candidature->setCreation(new \DateTime(sprintf('-%d days', 50 - $sequence)));

            $candidature->setDiplomedemande($this->orientation->calculer($annees));

            $this->completerInstruction($candidature, $statut, $etudeRefusee, $etudeAcceptee, $personnel);

            $this->entityManager->persist($candidature);
            $this->entityManager->flush();

            $this->creerPaiements($candidature, $statut, $candidat);

            // Le statut n'est pas écrit : il doit découler des décisions et
            // paiements posés ci-dessus. Tout écart trahit une donnée de démo
            // incohérente.
            $obtenu = $this->resolver->resolve($candidature);
            if ($obtenu !== $statut) {
                throw new \LogicException(sprintf('Dossier %s : statut attendu « %s », obtenu « %s ».', $numero, $statut->libelle(), $obtenu->libelle()));
            }

            $this->ecrireHistorique($candidature, $statut, $etudeRefusee, $etudeAcceptee);

            $lignes[] = [$numero, $nom . ' ' . $prenoms, $libelleMetier, $statut->libelle(), $email];
            ++$sequence;
        }

        $this->entityManager->flush();

        $io->newLine();
        $io->text(sprintf('Candidatures : <info>%d</info> réparties sur le parcours.', count($lignes)));
        $io->table(['Numéro VAE', 'Candidat', 'Métier', 'Statut', 'Identifiant'], $lignes);
    }

    /**
     * Renseigne les décisions (étude, recevabilité, éligibilité, admissibilité,
     * admission) dont découle le statut visé.
     *
     * @param array<string, User> $personnel
     */
    private function completerInstruction(
        Candidature $candidature,
        StatutCandidature $statut,
        bool $etudeRefusee,
        bool $etudeAcceptee,
        array $personnel,
    ): void {
        $conseiller = $candidature->getCentre()?->getNom() === 'Centre de formation professionnelle de Bouaké'
            ? $personnel['conseiller_bke']
            : $personnel['conseiller'];

        if ($etudeRefusee) {
            $candidature->setConseiller($conseiller);
            $candidature->setEtuStatut(StatutEtude::REFUSE->value);
            $candidature->setEtuDate(new \DateTime('-25 days'));
            $candidature->setEtuCom('Expérience professionnelle insuffisamment justifiée.');

            return;
        }

        if ($statut === StatutCandidature::PREINSCRIT && !$etudeAcceptee) {
            return;
        }

        // Tout dossier sorti de PREINSCRIT a reçu une étude acceptée ; un
        // préinscrit accepté attend seulement le paiement des frais de dossier.
        $candidature->setConseiller($conseiller);
        $candidature->setEtuStatut(StatutEtude::ACCEPTE->value);
        $candidature->setEtuDate(new \DateTime('-25 days'));
        $candidature->setEtuCom('Dossier complet, expérience avérée.');

        // Au-delà d'INSCRIT, une décision de recevabilité a été enregistrée.
        if ($statut === StatutCandidature::DOSSIER_NON_RECEVABLE) {
            $candidature->setRecStatut(StatutRecevabilite::NON_RECEVABLE->value);
            $candidature->setRecDate(new \DateTime('-16 days'));
        } elseif ($statut->value >= StatutCandidature::DOSSIER_RECEVABLE->value) {
            $candidature->setRecStatut(StatutRecevabilite::RECEVABLE->value);
            $candidature->setRecDate(new \DateTime('-16 days'));
        }

        // Décisions des étapes suivantes : chaque étape franchie garde la
        // sienne, comme TransitionCandidature les aurait enregistrées.
        $positif = CandidatureStatusResolver::DECISION_POSITIVE;
        $negatif = CandidatureStatusResolver::DECISION_NEGATIVE;

        if ($statut->value >= StatutCandidature::NON_ELIGIBLE->value) {
            $candidature->setEligStatut($statut === StatutCandidature::NON_ELIGIBLE ? $negatif : $positif);
        }

        if ($statut->value >= StatutCandidature::NON_ADMISSIBLE->value) {
            $candidature->setResultat($statut === StatutCandidature::NON_ADMISSIBLE ? $negatif : $positif);
        }

        if ($statut->value >= StatutCandidature::NON_ADMIS_DEFINITIF->value) {
            $candidature->setAdmis($statut === StatutCandidature::NON_ADMIS_DEFINITIF ? $negatif : $positif);
        }

        if ($statut->value >= StatutCandidature::NON_ADMISSIBLE->value) {
            $candidature->setEntdate(new \DateTime('-10 days'));
            $candidature->setEntlieu((string) $candidature->getCentre()?->getNom());
        }
    }

    /**
     * Crée les règlements déjà acquittés à ce stade du parcours.
     */
    private function creerPaiements(Candidature $candidature, StatutCandidature $statut, User $candidat): void
    {
        // Les frais de dossier sont réglés dès le statut INSCRIT : c'est ce
        // paiement confirmé qui y fait passer le dossier.
        if ($statut->value < StatutCandidature::INSCRIT->value) {
            return;
        }

        $this->creerPaiement($candidature, $candidat, TypeFrais::DOSSIER, '10000.00', 20);

        // L'évaluation devant jury suppose les frais d'examen réglés.
        if (in_array($statut, [StatutCandidature::ADMISSIBLE, StatutCandidature::ADMIS_DEFINITIF], true)) {
            $this->creerPaiement($candidature, $candidat, TypeFrais::EXAMEN, '15000.00', 12);
        }
    }

    private function creerPaiement(
        Candidature $candidature,
        User $candidat,
        TypeFrais $type,
        string $montant,
        int $joursAvant,
    ): void {
        $paiement = new Paiement();
        $paiement->setCandidature($candidature);
        $paiement->setType($type);
        $paiement->setMontant($montant);
        $paiement->setStatut(StatutPaiement::REUSSI);
        $paiement->setUser($candidat);
        $paiement->setMoyenPaiement(\App\Enum\MoyenPaiement::MOBILE_MONEY);
        $paiement->setReferencePaiement(sprintf(
            '%s-%s-01',
            (string) $candidature->getNumero(),
            strtoupper(substr($type->value, 0, 3))
        ));
        $paiement->setIdentifiantExterne('SIM-' . strtoupper(bin2hex(random_bytes(4))));
        $paiement->setDateInitiation(new \DateTime(sprintf('-%d days', $joursAvant)));
        $paiement->setDatePaiement(new \DateTime(sprintf('-%d days', $joursAvant)));
        $paiement->setTentatives(1);

        $this->entityManager->persist($paiement);
    }

    /**
     * Écrit un historique plausible, pour que la timeline du candidat et
     * l'historique du conseiller ne soient pas vides.
     *
     * L'auteur est nul pour la transition consécutive au paiement : comme dans
     * PaiementSubscriber, elle est déclenchée par le système.
     */
    private function ecrireHistorique(Candidature $candidature, StatutCandidature $statut, bool $etudeRefusee, bool $etudeAcceptee): void
    {
        $etapes = [[null, StatutCandidature::PREINSCRIT, 'Dépôt du dossier de candidature', 35, 'candidat']];

        if ($etudeRefusee) {
            $etapes[] = [StatutCandidature::PREINSCRIT, StatutCandidature::PREINSCRIT, 'Étude du dossier : Refusé', 25, 'conseiller'];
        }

        // L'étude acceptée est une décision journalisée sans changement de
        // statut, comme le fait RecevabiliteService::etudier().
        $etudeAccepteeJournal = [
            [StatutCandidature::PREINSCRIT, StatutCandidature::PREINSCRIT, 'Étude du dossier : Accepté', 25, 'conseiller'],
        ];

        if ($etudeAcceptee) {
            $etapes = array_merge($etapes, $etudeAccepteeJournal);
        }

        $versInscrit = array_merge($etudeAccepteeJournal, [
            [StatutCandidature::PREINSCRIT, StatutCandidature::INSCRIT, 'Règlement des frais de dossier', 20, 'systeme'],
        ]);

        $versRecevable = array_merge($versInscrit, [
            [StatutCandidature::INSCRIT, StatutCandidature::DOSSIER_RECEVABLE, 'Recevabilité : Recevable', 16, 'conseiller'],
        ]);

        $chemins = [
            StatutCandidature::INSCRIT->value => $versInscrit,
            StatutCandidature::DOSSIER_RECEVABLE->value => $versRecevable,
            StatutCandidature::DOSSIER_NON_RECEVABLE->value => array_merge($versInscrit, [
                [StatutCandidature::INSCRIT, StatutCandidature::DOSSIER_NON_RECEVABLE, 'Recevabilité : Non recevable', 16, 'conseiller'],
            ]),
        ];

        foreach ([StatutCandidature::ELIGIBLE, StatutCandidature::NON_ELIGIBLE] as $cible) {
            $chemins[$cible->value] = array_merge($versRecevable, [
                [StatutCandidature::DOSSIER_RECEVABLE, $cible, 'Import jury central - éligibilité', 13, 'conseiller'],
            ]);
        }

        $versAdmissible = array_merge($versRecevable, [
            [StatutCandidature::DOSSIER_RECEVABLE, StatutCandidature::ELIGIBLE, 'Import jury central - éligibilité', 13, 'conseiller'],
            [StatutCandidature::ELIGIBLE, StatutCandidature::ADMISSIBLE, 'Évaluation devant le jury du centre', 9, 'conseiller'],
        ]);

        $chemins[StatutCandidature::ADMISSIBLE->value] = $versAdmissible;
        $chemins[StatutCandidature::ADMIS_DEFINITIF->value] = array_merge($versAdmissible, [
            [StatutCandidature::ADMISSIBLE, StatutCandidature::ADMIS_DEFINITIF, 'Import jury central - admission définitive', 4, 'conseiller'],
        ]);

        foreach ($chemins[$statut->value] ?? [] as $etape) {
            $etapes[] = $etape;
        }

        $auteurs = [
            'candidat' => $candidature->getUser()?->getId(),
            'conseiller' => $candidature->getConseiller()?->getId(),
            'systeme' => null,
        ];

        foreach ($etapes as [$avant, $apres, $motif, $joursAvant, $auteur]) {
            $this->entityManager->getConnection()->insert('historique_statut', [
                'candidature_id' => $candidature->getId(),
                'statut_avant' => $avant?->value,
                'statut_apres' => $apres->value,
                'auteur_id' => $auteurs[$auteur],
                'motif' => $motif,
                'adresse_ip' => '127.0.0.1',
                'creation' => (new \DateTime(sprintf('-%d days', $joursAvant)))->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function creerCompte(
        string $email,
        array $roles,
        ?Centre $centre,
        ?Metier $metier,
        string $nom,
        string $prenoms,
        ?string $sexe = null,
    ): User {
        $utilisateur = $this->users->findOneBy(['email' => $email]);

        if ($utilisateur === null) {
            $utilisateur = new User();
            $utilisateur->setEmail($email);
            $utilisateur->setContact($this->contactLibre());
            $utilisateur->setNationalite("COTE D'IVOIRE");
            $utilisateur->setDatenaissance(new \DateTime('1990-01-15'));
            $utilisateur->setLieunaissance('Abidjan');
            $utilisateur->setResidence('Abidjan');
        }

        $utilisateur->setRoles($roles);
        $utilisateur->setNom($nom);
        $utilisateur->setPrenoms($prenoms);
        $utilisateur->setCentre($centre);
        $utilisateur->setMetier($metier);
        $utilisateur->setActif(true);
        $utilisateur->setDoitChangerMotDePasse(false);
        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        if ($sexe !== null) {
            $utilisateur->setSexe($sexe);
        }

        $this->entityManager->persist($utilisateur);

        return $utilisateur;
    }

    private function emailCandidat(string $nom, string $prenoms): string
    {
        $base = strtolower(sprintf('%s.%s', $this->sansAccent($prenoms), $this->sansAccent($nom)));
        $base = preg_replace('/[^a-z.]/', '', $base) ?? $base;

        return $base . '@vae.test';
    }

    private function sansAccent(string $valeur): string
    {
        return strtr($valeur, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o',
            'î' => 'i', 'ï' => 'i', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', 'É' => 'E',
            'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ô' => 'O', 'Î' => 'I', 'Ç' => 'C',
            '\'' => '', ' ' => '',
        ]);
    }

    private function contactLibre(): string
    {
        do {
            $contact = '07' . str_pad((string) random_int(0, 99999999), 8, '0', \STR_PAD_LEFT);
        } while ($this->users->findOneBy(['contact' => $contact]) !== null);

        return $contact;
    }

    private function purger(SymfonyStyle $io): void
    {
        $connexion = $this->entityManager->getConnection();

        foreach (['transaction_paiement', 'paiement', 'historique_statut', 'candidature'] as $table) {
            $connexion->executeStatement('DELETE FROM ' . $table);
        }

        $io->warning('Candidatures, règlements et historiques supprimés.');
    }

    /**
     * @param array<string, User> $personnel
     */
    private function afficherAcces(SymfonyStyle $io, array $personnel): void
    {
        $io->section('Accès de démonstration');

        $lignes = [];

        foreach ($personnel as $utilisateur) {
            $lignes[] = [
                (string) $utilisateur->getEmail(),
                Role::libelle((string) Role::principal($utilisateur)),
                $utilisateur->getCentre()?->getNom() ?? '—',
                $utilisateur->getMetier()?->getLibelle() ?? '—',
            ];
        }

        $io->table(['Identifiant', 'Rôle', 'Centre', 'Métier'], $lignes);
        $io->success(sprintf('Mot de passe commun à tous les comptes : %s', self::MOT_DE_PASSE));
        $io->note('Les candidats se connectent avec l\'identifiant indiqué dans le tableau des candidatures.');
    }
}
