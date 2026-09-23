<?php

namespace App\Command;

use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Entity\DirectionRegionale;
use App\Entity\Filiere;
use App\Entity\Localite;
use App\Entity\Metier;
use App\Entity\User;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\DirectionRegionaleRepository;
use App\Repository\FiliereRepository;
use App\Repository\LocaliteRepository;
use App\Repository\MetierRepository;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Service\Referentiel\GestionCompte;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Amorçage du référentiel sur une base vide (F2.9, réponse au problème P6).
 *
 * La commande est idempotente : chaque élément est recherché par libellé avant
 * insertion, ce qui permet de la relancer après un ajout au jeu de données sans
 * créer de doublon.
 */
#[AsCommand(
    name: 'app:referentiel:load',
    description: 'Charge un référentiel minimal (directions régionales, localités, filières, métiers, centre de test).'
)]
class ChargerReferentielCommand extends Command
{
    /**
     * Jeu d'amorçage volontairement réduit : de quoi dérouler les écrans
     * d'administration et déposer une première candidature. Le référentiel
     * réel est saisi par l'administrateur depuis les écrans E2.1 à E2.6.
     *
     * @var array<string, list<string>>
     */
    private const GEOGRAPHIE = [
        'Direction Régionale d\'Abidjan 1' => ['Cocody', 'Plateau', 'Yopougon'],
        'Direction Régionale de Bouaké'    => ['Bouaké', 'Sakassou'],
        'Direction Régionale de San-Pédro' => ['San-Pédro', 'Sassandra'],
    ];

    /**
     * Catalogue des métiers artisanaux ouverts à la VAE, par filière.
     *
     * Il couvre les corps de métier réellement exercés par les maîtres artisans
     * visés par le dispositif : c'est ce catalogue que l'administrateur affine
     * ensuite depuis les écrans E2.3 et E2.4.
     *
     * @var array<string, list<string>>
     */
    private const OFFRE = [
        'Bâtiment et travaux publics' => [
            'Maçonnerie',
            'Carrelage',
            'Plomberie sanitaire',
            'Peinture bâtiment',
            'Menuiserie bois',
            'Menuiserie aluminium',
            'Ferraillage',
            'Étanchéité',
        ],
        'Mécanique et métallurgie' => [
            'Mécanique automobile',
            'Soudure',
            'Mécanique deux-roues',
            'Tôlerie-carrosserie',
            'Chaudronnerie',
            'Usinage',
        ],
        'Électricité et électronique' => [
            'Électricité bâtiment',
            'Froid et climatisation',
            'Électricité automobile',
            'Maintenance informatique',
            'Réparation de téléphonie mobile',
            'Électronique industrielle',
        ],
        'Métiers de service' => [
            'Coiffure',
            'Couture',
            'Restauration',
            'Pâtisserie',
            'Esthétique et soins du corps',
            'Blanchisserie-pressing',
            'Hôtellerie',
        ],
        'Agriculture et agroalimentaire' => [
            'Transformation agroalimentaire',
            'Maraîchage',
            'Aviculture',
            'Pisciculture',
            'Boulangerie',
        ],
        'Artisanat d\'art' => [
            'Sculpture sur bois',
            'Vannerie',
            'Poterie et céramique',
            'Bijouterie',
            'Maroquinerie',
            'Tissage traditionnel',
        ],
    ];

    /**
     * Centres de démonstration et métiers qu'ils proposent réellement.
     *
     * L'offre est volontairement différenciée : un centre ne forme pas à tous
     * les métiers du pays, et cette différence est ce qui rend vérifiable la
     * règle « le métier doit être ouvert dans le centre choisi » (R3.2).
     *
     * @var array<string, array{localite: string, type: string, filieres: list<string>, metiers: list<string>}>
     */
    private const CENTRES = [
        'Centre de formation professionnelle de Cocody' => [
            'localite' => 'Cocody',
            'type' => 'etablissement',
            // Centre généraliste : toutes les filières urbaines.
            'filieres' => ['Bâtiment et travaux publics', 'Électricité et électronique', 'Métiers de service'],
            'metiers' => ['Mécanique automobile', 'Soudure'],
        ],
        'Centre de formation professionnelle de Bouaké' => [
            'localite' => 'Bouaké',
            'type' => 'etablissement',
            'filieres' => ['Bâtiment et travaux publics', 'Mécanique et métallurgie', 'Métiers de service'],
            'metiers' => ['Transformation agroalimentaire', 'Maraîchage', 'Boulangerie'],
        ],
        'Centre des métiers de San-Pédro' => [
            'localite' => 'San-Pédro',
            'type' => 'etablissement',
            'filieres' => ['Mécanique et métallurgie', 'Agriculture et agroalimentaire'],
            'metiers' => ['Maçonnerie', 'Électricité bâtiment', 'Restauration', 'Hôtellerie'],
        ],
        'Centre artisanal de Yopougon' => [
            'localite' => 'Yopougon',
            'type' => 'etablissement',
            'filieres' => ['Artisanat d\'art', 'Métiers de service'],
            'metiers' => ['Menuiserie bois', 'Couture'],
        ],
        'Entreprise partenaire du Plateau' => [
            'localite' => 'Plateau',
            'type' => 'entreprise',
            // Une entreprise n'accueille que les métiers qu'elle pratique.
            'filieres' => [],
            'metiers' => ['Maintenance informatique', 'Réparation de téléphonie mobile', 'Électronique industrielle'],
        ],
    ];

    /** Places ouvertes par défaut sur une ligne d'offre. */
    private const PLACES_PAR_DEFAUT = 25;

    public function __construct(
        private readonly DirectionRegionaleRepository $directionRegionaleRepository,
        private readonly LocaliteRepository $localiteRepository,
        private readonly FiliereRepository $filiereRepository,
        private readonly MetierRepository $metierRepository,
        private readonly CentreRepository $centreRepository,
        private readonly CentreMetierRepository $centreMetierRepository,
        private readonly UserRepository $userRepository,
        private readonly GestionCompte $gestionCompte,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'admin-email',
                null,
                InputOption::VALUE_REQUIRED,
                'Crée un compte administrateur avec cette adresse et affiche son mot de passe initial.'
            )
            ->addOption(
                'sans-centre-test',
                null,
                InputOption::VALUE_NONE,
                'Ne crée pas le centre de démonstration ni son offre de formation.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Chargement du référentiel VAE');

        $compteurs = [
            'directions régionales' => 0,
            'localités'             => 0,
            'filières'              => 0,
            'métiers'               => 0,
            'centres'               => 0,
            'lignes d\'offre'       => 0,
        ];

        foreach (self::GEOGRAPHIE as $libelleDr => $localites) {
            $directionRegionale = $this->directionRegionaleRepository->findOneBy(['libelle' => $libelleDr]);

            if ($directionRegionale === null) {
                $directionRegionale = (new DirectionRegionale())->setLibelle($libelleDr);
                $this->directionRegionaleRepository->save($directionRegionale, true);
                ++$compteurs['directions régionales'];
            }

            foreach ($localites as $libelleLocalite) {
                $existante = $this->localiteRepository->findOneBy([
                    'libelle' => $libelleLocalite,
                    'directionRegionale' => $directionRegionale,
                ]);

                if ($existante === null) {
                    $localite = (new Localite())
                        ->setLibelle($libelleLocalite)
                        ->setDirectionRegionale($directionRegionale);
                    $this->localiteRepository->save($localite, true);
                    ++$compteurs['localités'];
                }
            }
        }

        foreach (self::OFFRE as $libelleFiliere => $metiers) {
            $filiere = $this->filiereRepository->findOneBy(['libelle' => $libelleFiliere]);

            if ($filiere === null) {
                $filiere = (new Filiere())->setLibelle($libelleFiliere);
                $this->filiereRepository->save($filiere, true);
                ++$compteurs['filières'];
            }

            foreach ($metiers as $libelleMetier) {
                $existant = $this->metierRepository->findOneBy([
                    'libelle' => $libelleMetier,
                    'filiere' => $filiere,
                ]);

                if ($existant === null) {
                    $metier = (new Metier())
                        ->setLibelle($libelleMetier)
                        ->setFiliere($filiere)
                        ->setStatut('actif');
                    $this->metierRepository->save($metier, true);
                    ++$compteurs['métiers'];
                }
            }
        }

        if (!$input->getOption('sans-centre-test')) {
            [$centresCrees, $offresCreees] = $this->chargerCentreDeTest();
            $compteurs['centres'] += $centresCrees;
            $compteurs['lignes d\'offre'] += $offresCreees;
        }

        $io->table(
            ['Élément', 'Créés'],
            array_map(
                static fn (string $libelle, int $nombre): array => [$libelle, $nombre],
                array_keys($compteurs),
                $compteurs
            )
        );

        $email = $input->getOption('admin-email');

        if (is_string($email) && $email !== '') {
            $this->creerAdministrateur($io, $email);
        }

        $io->success('Référentiel chargé. Les écrans /admin permettent de le compléter.');

        return Command::SUCCESS;
    }

    /**
     * Centres de démonstration et leur offre de formation.
     *
     * Sans au moins un centre proposant un métier, aucune candidature n'est
     * possible sur une base fraîche. L'offre suit la table CENTRES : elle est
     * différenciée d'un centre à l'autre, un centre ne formant pas à tous les
     * métiers du pays.
     *
     * @return array{0: int, 1: int} nombre de centres puis de lignes d'offre créés
     */
    private function chargerCentreDeTest(): array
    {
        $centresCrees = 0;
        $offresCreees = 0;

        foreach (self::CENTRES as $nom => $definition) {
            $centre = $this->centreRepository->findOneBy(['nom' => $nom]);

            if ($centre === null) {
                $localite = $this->localiteRepository->findOneBy(['libelle' => $definition['localite']]);

                // Une localité absente signale un référentiel géographique
                // incomplet : on saute le centre plutôt que de le rattacher
                // arbitrairement ailleurs.
                if ($localite === null) {
                    continue;
                }

                $centre = (new Centre())
                    ->setNom($nom)
                    ->setType($definition['type'])
                    ->setLocalite($localite);
                $this->centreRepository->save($centre, true);
                ++$centresCrees;
            }

            foreach ($this->metiersDuCentre($definition) as $metier) {
                if ($this->centreMetierRepository->findCouple($centre, $metier) !== null) {
                    continue;
                }

                $offre = (new CentreMetier())
                    ->setCentre($centre)
                    ->setMetier($metier)
                    ->setNbrplace(self::PLACES_PAR_DEFAUT);
                $this->centreMetierRepository->save($offre, true);
                ++$offresCreees;
            }
        }

        return [$centresCrees, $offresCreees];
    }

    /**
     * Métiers réellement proposés par un centre : toutes les filières qu'il
     * couvre, plus les métiers isolés qu'il accueille à titre particulier.
     *
     * @param array{filieres: list<string>, metiers: list<string>} $definition
     *
     * @return list<Metier>
     */
    private function metiersDuCentre(array $definition): array
    {
        $metiers = [];

        foreach ($definition['filieres'] as $libelleFiliere) {
            $filiere = $this->filiereRepository->findOneBy(['libelle' => $libelleFiliere]);

            if ($filiere === null) {
                continue;
            }

            foreach ($this->metierRepository->findBy(['filiere' => $filiere, 'statut' => 'actif']) as $metier) {
                $metiers[(int) $metier->getId()] = $metier;
            }
        }

        foreach ($definition['metiers'] as $libelleMetier) {
            $metier = $this->metierRepository->findOneBy(['libelle' => $libelleMetier, 'statut' => 'actif']);

            if ($metier !== null) {
                $metiers[(int) $metier->getId()] = $metier;
            }
        }

        return array_values($metiers);
    }

    /**
     * Le mot de passe est généré par GestionCompte et affiché une seule fois :
     * il n'est jamais écrit dans un fichier ni journalisé (R2.9).
     */
    private function creerAdministrateur(SymfonyStyle $io, string $email): void
    {
        $email = mb_strtolower(trim($email));

        if ($this->userRepository->emailDejaUtilise($email)) {
            $io->warning(sprintf('Un compte existe déjà pour %s : aucun administrateur créé.', $email));

            return;
        }

        $administrateur = (new User())
            ->setEmail($email)
            ->setNom('Administrateur')
            ->setPrenoms('Plateforme');

        $motDePasse = $this->gestionCompte->creerPersonnel($administrateur, Role::ADMIN);

        $io->section('Compte administrateur');
        $io->definitionList(
            ['Identifiant' => $email],
            ['Mot de passe initial' => $motDePasse]
        );
        $io->comment('Notez ce mot de passe : il ne sera plus affiché. Son changement sera demandé à la première connexion.');
    }
}
