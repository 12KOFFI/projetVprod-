<?php

namespace App\Command;

use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Entity\Certification;
use App\Entity\Metier;
use App\Exception\SuppressionInterditeException;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\CertificationRepository;
use App\Repository\MetierRepository;
use App\Service\Referentiel\ReferentielGuard;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charge le référentiel des certifications depuis le fichier officiel
 * « LISTE DES METIERS ET DIPLOMES VISES PAR ETABLISSEMENT.xlsx ».
 *
 * Le fichier est hiérarchique : l'établissement n'est écrit que sur sa première
 * ligne, le métier sur la première de son groupe. Les valeurs sont donc
 * reportées de ligne en ligne.
 *
 * Deux garde-fous tiennent la qualité du référentiel :
 *
 * 1. AUCUN MÉTIER N'EST CRÉÉ. Le fichier désigne parfois un même métier sous
 *    deux libellés selon l'établissement qui a rempli la ligne — « Couture » à
 *    Korhogo, « Couturier » au LPMMS. Importer tel quel dédoublerait le
 *    référentiel. Les variantes sont donc ramenées au métier déjà en base
 *    (table ALIAS_METIERS), et une ligne dont le métier reste introuvable est
 *    signalée puis ignorée.
 *
 * 2. LES LIBELLÉS DE CERTIFICATION SONT NORMALISÉS, pour que « CAP Coupe
 *    Couture » et « CAP Coupe-Couture » ne produisent pas deux entrées.
 */
#[AsCommand(
    name: 'app:certifications:importer',
    description: 'Importe les certifications et l\'offre par couple centre/métier depuis le fichier Excel officiel.',
)]
class ImporterCertificationsCommand extends Command
{
    private const FICHIER_PAR_DEFAUT = 'LISTE DES METIERS ET DIPLOMES VISES PAR ETABLISSEMENT.xlsx';

    /**
     * Libellés du fichier ramenés au métier déjà présent en base.
     *
     * À gauche le libellé tel qu'écrit dans l'Excel, à droite celui qui fait
     * foi. Rien n'est créé : si la cible n'existe pas, la ligne est rejetée.
     *
     * @var array<string, string>
     */
    private const ALIAS_METIERS = [
        'Couture' => 'Couturier',
        'Coiffure' => 'Coiffeur(euse)',
        'Constructeur Métallique' => 'Constructeur Métallique (Soudure)',
        'Installateur Sanitaire (Plombier)' => 'Plombier Sanitaire',
        'Tisserand-Teinturier' => 'Tisserand-Teinturier (Textile traditionnel)',
        'Tapisserie d’ameublement' => 'Tapissier-Décorateur',
    ];

    /**
     * Coquilles et variantes de ponctuation relevées dans le fichier.
     *
     * @var array<string, string>
     */
    private const CORRECTIONS_CERTIFICATIONS = [
        'CAP Coupe Couture' => 'CAP Coupe-Couture',
        'CAP Mécanique d’unsinage' => 'CAP Mécanique d\'usinage',
        'CAP Mécanique d\'unsinage' => 'CAP Mécanique d\'usinage',
    ];

    /**
     * Nombre de places donné aux offres créées par l'alignement.
     *
     * Le fichier officiel ne porte pas cette information : la valeur n'est
     * qu'un point de départ, à ajuster centre par centre dans Paramètres.
     */
    private const PLACES_PAR_DEFAUT = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CentreRepository $centres,
        private readonly MetierRepository $metiers,
        private readonly CentreMetierRepository $offres,
        private readonly CertificationRepository $certifications,
        private readonly ReferentielGuard $guard,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::OPTIONAL, 'Chemin du fichier Excel', self::FICHIER_PAR_DEFAUT)
            ->addOption('simuler', null, InputOption::VALUE_NONE, 'Analyse le fichier sans rien écrire en base')
            ->addOption(
                'aligner',
                null,
                InputOption::VALUE_NONE,
                'Aligne l\'offre sur le fichier : crée les couples (centre, métier) manquants et SUPPRIME ceux qui n\'y figurent pas'
            )
            ->addOption(
                'places',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre de places des offres créées par --aligner',
                (string) self::PLACES_PAR_DEFAUT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('simuler');

        $chemin = (string) $input->getArgument('fichier');
        if (!is_file($chemin)) {
            $chemin = $this->projectDir . '/' . $chemin;
        }

        if (!is_file($chemin)) {
            $io->error(sprintf('Fichier introuvable : %s', $chemin));

            return Command::FAILURE;
        }

        $io->title('Import des certifications VAE');
        if ($simulation) {
            $io->warning('Mode simulation : aucune écriture en base.');
        }

        $lignes = $this->lireFichier($chemin);
        $io->text(sprintf('%d ligne(s) exploitable(s) dans le fichier.', count($lignes)));

        $anomalies = [];

        if ((bool) $input->getOption('aligner')) {
            $this->aligner($io, $lignes, (int) $input->getOption('places'), $simulation, $anomalies);
        }

        $certifsCreees = 0;
        $liensCrees = 0;
        $dejaLies = 0;

        foreach ($lignes as $ligne) {
            $metier = $this->resoudreMetier($ligne['metier']);

            if ($metier === null) {
                $anomalies[] = sprintf('Métier inconnu en base : « %s » (ligne %d)', $ligne['metier'], $ligne['numero']);
                continue;
            }

            $centre = $this->centres->findOneBy(['nom' => $ligne['centre']]);

            if ($centre === null) {
                $anomalies[] = sprintf('Centre inconnu en base : « %s » (ligne %d)', $ligne['centre'], $ligne['numero']);
                continue;
            }

            $offre = $this->offres->findOneBy(['centre' => $centre, 'metier' => $metier]);

            if ($offre === null) {
                $anomalies[] = sprintf(
                    'Aucune offre (centre, métier) pour « %s » / « %s » (ligne %d)',
                    $ligne['centre'],
                    $metier->getLibelle(),
                    $ligne['numero']
                );
                continue;
            }

            $libelle = $this->normaliserCertification($ligne['certification']);
            $certification = $this->certifications->findOneByLibelle($libelle);

            if ($certification === null) {
                $certification = (new Certification())
                    ->setLibelle($libelle)
                    ->setMetier($metier);

                if (!$simulation) {
                    $this->entityManager->persist($certification);
                    // Le libellé porte un index UNIQUE : un flush immédiat évite
                    // qu'une seconde ligne du même fichier tente de le recréer.
                    $this->entityManager->flush();
                }

                ++$certifsCreees;
            }

            if ($offre->getCertifications()->contains($certification)) {
                ++$dejaLies;
                continue;
            }

            $offre->addCertification($certification);
            ++$liensCrees;
        }

        if (!$simulation) {
            $this->entityManager->flush();
        }

        $io->section('Résultat');
        $io->listing([
            sprintf('Certifications créées : %d', $certifsCreees),
            sprintf('Liens (centre, métier) → certification créés : %d', $liensCrees),
            sprintf('Liens déjà présents, ignorés : %d', $dejaLies),
            sprintf('Anomalies : %d', count($anomalies)),
        ]);

        if ($anomalies !== []) {
            $io->section('Anomalies');
            $io->listing(array_slice($anomalies, 0, 40));

            if (count($anomalies) > 40) {
                $io->text(sprintf('… et %d autre(s).', count($anomalies) - 40));
            }
        }

        $io->success($simulation ? 'Simulation terminée.' : 'Import terminé.');

        return Command::SUCCESS;
    }

    /**
     * Met l'offre en conformité avec le fichier : crée les couples (centre,
     * métier) qu'il décrit et retire ceux qu'il ne mentionne pas.
     *
     * La suppression passe par ReferentielGuard, comme l'écran
     * d'administration : un couple déjà porteur de candidatures n'est pas
     * supprimé mais signalé, l'offre devant alors être fermée en ramenant ses
     * places à zéro plutôt qu'en effaçant l'historique.
     *
     * @param list<array{numero: int, centre: string, metier: string, certification: string}> $lignes
     * @param list<string>                                                                    $anomalies
     */
    private function aligner(SymfonyStyle $io, array $lignes, int $places, bool $simulation, array &$anomalies): void
    {
        $io->section('Alignement de l\'offre sur le fichier');

        if ($places < 1) {
            $places = self::PLACES_PAR_DEFAUT;
        }

        // Couples décrits par le fichier, dédoublonnés et résolus sur les
        // entités réelles : une variante de libellé ne doit pas créer un couple
        // supplémentaire.
        $attendus = [];
        foreach ($lignes as $ligne) {
            $metier = $this->resoudreMetier($ligne['metier']);
            $centre = $this->centres->findOneBy(['nom' => $ligne['centre']]);

            if ($metier === null || $centre === null) {
                continue;
            }

            $attendus[$centre->getId() . ':' . $metier->getId()] = ['centre' => $centre, 'metier' => $metier];
        }

        $crees = 0;
        foreach ($attendus as $couple) {
            if ($this->offres->findOneBy(['centre' => $couple['centre'], 'metier' => $couple['metier']]) !== null) {
                continue;
            }

            if (!$simulation) {
                $offre = (new CentreMetier())
                    ->setCentre($couple['centre'])
                    ->setMetier($couple['metier'])
                    ->setNbrplace($places);

                $this->entityManager->persist($offre);
            }

            ++$crees;
        }

        if (!$simulation) {
            $this->entityManager->flush();
        }

        $supprimes = 0;
        $conserves = 0;
        foreach ($this->offres->findAll() as $offre) {
            $cle = $offre->getCentre()?->getId() . ':' . $offre->getMetier()?->getId();

            if (array_key_exists($cle, $attendus)) {
                continue;
            }

            try {
                $this->guard->verifierSuppressionCentreMetier($offre);
            } catch (SuppressionInterditeException $e) {
                $anomalies[] = sprintf(
                    'Offre conservée — %s / %s : %s',
                    (string) $offre->getCentre()?->getNom(),
                    (string) $offre->getMetier()?->getLibelle(),
                    $e->getMessage()
                );
                ++$conserves;
                continue;
            }

            if (!$simulation) {
                $this->entityManager->remove($offre);
            }

            ++$supprimes;
        }

        if (!$simulation) {
            $this->entityManager->flush();
        }

        $io->listing([
            sprintf('Offres créées (%d place(s) par défaut, à ajuster) : %d', $places, $crees),
            sprintf('Offres supprimées, absentes du fichier : %d', $supprimes),
            sprintf('Offres conservées malgré leur absence, car porteuses de candidatures : %d', $conserves),
        ]);
    }

    /**
     * Lit le fichier en reportant établissement, localité et métier, que les
     * cellules fusionnées ne répètent pas.
     *
     * @return list<array{numero: int, centre: string, metier: string, certification: string}>
     */
    private function lireFichier(string $chemin): array
    {
        $lecteur = IOFactory::createReaderForFile($chemin);
        $lecteur->setReadDataOnly(true);
        $feuille = $lecteur->load($chemin)->getSheet(0);

        $lignes = [];
        $centre = '';
        $metier = '';

        // La ligne 1 est vide, la ligne 2 porte l'en-tête : la lecture commence en 3.
        for ($i = 3; $i <= $feuille->getHighestRow(); ++$i) {
            $cellule = static fn (string $colonne): string => trim((string) $feuille->getCell($colonne . $i)->getValue());

            if ($cellule('B') !== '') {
                $centre = $cellule('B');
            }

            if ($cellule('D') !== '') {
                $metier = $cellule('D');
            }

            $certification = $cellule('E');

            if ($certification === '' || $centre === '' || $metier === '') {
                continue;
            }

            $lignes[] = [
                'numero' => $i,
                'centre' => $centre,
                'metier' => $metier,
                'certification' => $certification,
            ];
        }

        return $lignes;
    }

    private function resoudreMetier(string $libelle): ?\App\Entity\Metier
    {
        $cible = self::ALIAS_METIERS[$libelle] ?? $libelle;

        $metier = $this->metiers->findOneBy(['libelle' => $cible]);

        if ($metier !== null) {
            return $metier;
        }

        // Dernier recours : comparaison insensible à la casse, aux accents et à
        // la ponctuation, pour absorber un tiret cadratin ou une apostrophe
        // typographique sans élargir la liste d'alias.
        $normalise = $this->normaliser($cible);

        foreach ($this->metiers->findAll() as $candidat) {
            if ($this->normaliser((string) $candidat->getLibelle()) === $normalise) {
                return $candidat;
            }
        }

        return null;
    }

    private function normaliserCertification(string $libelle): string
    {
        return self::CORRECTIONS_CERTIFICATIONS[$libelle] ?? $libelle;
    }

    private function normaliser(string $valeur): string
    {
        $valeur = strtr($valeur, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ï' => 'i',
            'û' => 'u', 'ù' => 'u', 'ç' => 'c',
            '–' => '-', '—' => '-', '’' => "'",
        ]);

        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($valeur)) ?? '';
    }
}
