<?php

namespace App\Command;

use App\Entity\Centre;
use App\Entity\User;
use App\Repository\CentreRepository;
use App\Repository\UserRepository;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un jeu de comptes de démonstration, un par rôle métier.
 *
 * Complète app:referentiel:load, qui amorce les données de référence mais ne
 * crée aucun utilisateur : sans comptes, aucun parcours n'est vérifiable sur
 * une base neuve (problème P6 de final.txt). La commande est idempotente et
 * refuse de s'exécuter hors environnement de développement.
 */
#[AsCommand(
    name: 'app:comptes:demo',
    description: 'Crée les comptes de démonstration (agent, conseiller, candidats).',
)]
class ChargerComptesDemoCommand extends Command
{
    private const MOT_DE_PASSE = 'MotDePasse2026!';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly CentreRepository $centreRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Autorise l\'exécution hors environnement de développement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Ces comptes partagent un mot de passe connu : ils n'ont rien à faire
        // sur un environnement de production.
        if ($this->environnement !== 'dev' && !$input->getOption('force')) {
            $io->error(sprintf(
                'Commande réservée au développement (environnement courant : %s). Utilisez --force pour passer outre.',
                $this->environnement
            ));

            return Command::FAILURE;
        }

        $centre = $this->centreRepository->findOneBy([]);

        if ($centre === null) {
            $io->error('Aucun centre en base. Exécutez d\'abord app:referentiel:load.');

            return Command::FAILURE;
        }

        $io->title('Comptes de démonstration');
        $io->text(sprintf('Centre de rattachement : <info>%s</info>', (string) $centre->getNom()));

        $lignes = [];

        foreach ($this->definitions($centre) as $definition) {
            $lignes[] = $this->creerOuMettreAJour($definition);
        }

        $this->entityManager->flush();

        $io->table(['Adresse', 'Rôle', 'Centre', 'État'], $lignes);
        $io->success(sprintf('Mot de passe commun : %s', self::MOT_DE_PASSE));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{email: string, role: string, centre: ?Centre, nom: string, prenoms: string}>
     */
    private function definitions(Centre $centre): array
    {
        return [
            ['email' => 'agent@vae.test', 'role' => Role::AGENT_ACCUEIL, 'centre' => $centre, 'nom' => 'KONE', 'prenoms' => 'Awa'],
            ['email' => 'conseiller@vae.test', 'role' => Role::CONSEILLER, 'centre' => $centre, 'nom' => 'BAMBA', 'prenoms' => 'Ibrahim'],
            ['email' => 'candidat@vae.test', 'role' => Role::CANDIDAT, 'centre' => null, 'nom' => 'KOUASSI', 'prenoms' => 'Yao'],
            ['email' => 'candidat2@vae.test', 'role' => Role::CANDIDAT, 'centre' => null, 'nom' => 'TRAORE', 'prenoms' => 'Aminata'],
        ];
    }

    /**
     * @param array{email: string, role: string, centre: ?Centre, nom: string, prenoms: string} $definition
     *
     * @return list<string>
     */
    private function creerOuMettreAJour(array $definition): array
    {
        $utilisateur = $this->userRepository->findOneBy(['email' => $definition['email']]);
        $etat = $utilisateur === null ? 'créé' : 'mis à jour';

        if ($utilisateur === null) {
            $utilisateur = new User();
            $utilisateur->setEmail($definition['email']);
            $utilisateur->setContact($this->contactLibre());
        }

        $utilisateur->setRoles([$definition['role']]);
        $utilisateur->setNom($definition['nom']);
        $utilisateur->setPrenoms($definition['prenoms']);
        $utilisateur->setCentre($definition['centre']);
        $utilisateur->setActif(true);
        $utilisateur->setDoitChangerMotDePasse(false);
        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $this->entityManager->persist($utilisateur);

        return [
            $definition['email'],
            Role::libelle($definition['role']),
            $definition['centre']?->getNom() ?? '—',
            $etat,
        ];
    }

    /**
     * Le contact sert d'identifiant de connexion alternatif : il doit rester
     * unique, même pour des comptes de démonstration.
     */
    private function contactLibre(): string
    {
        do {
            $contact = '07' . str_pad((string) random_int(0, 99999999), 8, '0', \STR_PAD_LEFT);
        } while ($this->userRepository->findOneBy(['contact' => $contact]) !== null);

        return $contact;
    }
}
