<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Déplace les pièces déposées avant la protection des pièces : de
 * public/media/{numéro}/ (servi tel quel par le serveur web) vers le dossier
 * privé stockage/pieces/{numéro}/.
 *
 * Sans risque à relancer : un fichier déjà présent à destination est laissé
 * en place, et l'original n'est supprimé qu'après une copie réussie.
 */
#[AsCommand(name: 'app:pieces:migrer', description: 'Déplace les pièces justificatives de public/media vers le stockage privé.')]
class MigrerPiecesCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/media')]
        private readonly string $ancienDossier,
        #[Autowire('%dir_media%')]
        private readonly string $nouveauDossier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('simulation', null, InputOption::VALUE_NONE, 'Affiche ce qui serait déplacé, sans rien modifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('simulation');
        $fs = new Filesystem();

        if (!is_dir($this->ancienDossier)) {
            $io->success('Aucune pièce à déplacer : public/media n\'existe pas.');

            return Command::SUCCESS;
        }

        $deplaces = 0;
        $ignores = 0;
        // Listes établies avant toute écriture : on ne modifie pas un dossier
        // pendant qu'on le parcourt.
        foreach (glob(rtrim($this->ancienDossier, '/\\') . '/*', \GLOB_ONLYDIR) ?: [] as $dossier) {
            $numero = basename($dossier);
            foreach (array_filter(glob($dossier . '/*') ?: [], 'is_file') as $fichier) {
                $cible = rtrim($this->nouveauDossier, '/\\') . \DIRECTORY_SEPARATOR . $numero
                    . \DIRECTORY_SEPARATOR . basename($fichier);
                if (is_file($cible)) {
                    ++$ignores;
                    continue;
                }
                if (!$simulation) {
                    $fs->copy($fichier, $cible);
                    $fs->remove($fichier);
                }
                ++$deplaces;
            }
            if (!$simulation && (glob($dossier . '/*') ?: []) === []) {
                $fs->remove($dossier);
            }
        }

        $io->success(sprintf(
            '%s %d pièce(s) ; %d déjà présente(s) à destination.',
            $simulation ? 'À déplacer :' : 'Déplacées :',
            $deplaces,
            $ignores
        ));

        return Command::SUCCESS;
    }
}
