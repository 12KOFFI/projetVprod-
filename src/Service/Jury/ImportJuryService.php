<?php

namespace App\Service\Jury;

use App\Entity\Candidature;
use App\Entity\ImportJury;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\StatutImport;
use App\Enum\TypeImportJury;
use App\Exception\ImportInvalideException;
use App\Repository\CandidatureRepository;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\TransitionCandidature;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import des décisions du jury central (module M6).
 *
 * Le traitement est transactionnel par lot : les lignes valides sont
 * appliquées, sauf si le taux d'erreur dépasse le seuil, auquel cas l'import
 * entier est remis en arrière (règle métier R6.6). Cela suppose une
 * transaction explicite — un simple flush ne permettrait pas d'annuler.
 */
class ImportJuryService
{
    /** Au-delà, le fichier est réputé mauvais et rien n'est appliqué (R6.6). */
    private const SEUIL_ERREUR_POURCENT = 50;

    /** Les entités sont vidées par paquets pour contenir la mémoire. */
    private const TAILLE_LOT = 100;

    public const ISSUE_TRAITEE = 'traitee';
    public const ISSUE_IGNOREE = 'ignoree';
    public const ISSUE_ERREUR  = 'erreur';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CandidatureRepository $candidatureRepository,
        private readonly TransitionCandidature $transition,
        private readonly CandidatureStatusResolver $resolver,
        private readonly LoggerInterface $logger,
        private readonly string $dossierImports,
    ) {
    }

    /**
     * Lit le fichier, applique les décisions et retourne la trace de l'import.
     *
     * @throws ImportInvalideException si le fichier est inexploitable
     */
    public function importer(UploadedFile $fichier, TypeImportJury $type, User $auteur): ImportJury
    {
        $nomOrigine = $fichier->getClientOriginalName();
        $chemin = $this->conserver($fichier, $type);

        // La lecture précède toute écriture : un fichier non conforme est
        // rejeté sans qu'aucune trace d'import ne soit créée (V6.2).
        $lignes = $this->lireLignes($chemin, $type);

        $motif = sprintf(
            'Import jury central du %s - fichier %s',
            (new \DateTime())->format('d/m/Y'),
            $nomOrigine
        );

        $rapport = [];
        $compteurs = [self::ISSUE_TRAITEE => 0, self::ISSUE_IGNOREE => 0, self::ISSUE_ERREUR => 0];
        $annule = false;
        $pourcentageErreur = 0;

        // La trace de l'import n'est écrite qu'une fois l'issue connue : créée
        // avant la transaction, elle resterait « en cours » après un rollback ;
        // créée dedans, elle disparaîtrait avec lui.
        $this->entityManager->getConnection()->beginTransaction();

        try {
            foreach ($lignes as $index => $ligne) {
                $resultat = $this->traiterLigne($ligne, $type, $auteur, $motif);

                $rapport[] = $resultat;
                ++$compteurs[$resultat['issue']];

                // Flush par paquets : sur un fichier volumineux, tout accumuler
                // jusqu'au bout épuiserait la mémoire.
                if (($index + 1) % self::TAILLE_LOT === 0) {
                    $this->entityManager->flush();
                }
            }

            $this->entityManager->flush();

            $pourcentageErreur = $this->pourcentageErreur($compteurs[self::ISSUE_ERREUR], count($lignes));
            $annule = $pourcentageErreur > self::SEUIL_ERREUR_POURCENT;

            if ($annule) {
                $this->entityManager->getConnection()->rollBack();
                // Les entités portent un état que la base vient de rejeter :
                // les détacher évite de le réécrire avec la trace.
                $this->entityManager->clear();
            } else {
                $this->entityManager->getConnection()->commit();
            }
        } catch (\Throwable $exception) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
            }

            $this->logger->error('Import du jury central interrompu', [
                'fichier' => $nomOrigine,
                'type' => $type->value,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $import = $this->enregistrerTrace(
            $type,
            $nomOrigine,
            $chemin,
            $auteur,
            count($lignes),
            $rapport,
            $compteurs,
            $annule
        );

        if ($annule) {
            $this->logger->warning('Import du jury central annulé : trop d\'erreurs', [
                'fichier' => $nomOrigine,
                'erreurs' => $compteurs[self::ISSUE_ERREUR],
                'total' => count($lignes),
            ]);

            throw ImportInvalideException::tropDErreurs($pourcentageErreur, self::SEUIL_ERREUR_POURCENT);
        }

        $this->logger->info('Import du jury central terminé', [
            'fichier' => $nomOrigine,
            'type' => $type->value,
            'traitees' => $compteurs[self::ISSUE_TRAITEE],
            'ignorees' => $compteurs[self::ISSUE_IGNOREE],
            'erreurs' => $compteurs[self::ISSUE_ERREUR],
        ]);

        return $import;
    }

    /**
     * Enregistre la trace de l'import, abouti ou annulé.
     *
     * @param list<array<string, mixed>> $rapport
     * @param array<string, int>         $compteurs
     */
    private function enregistrerTrace(
        TypeImportJury $type,
        string $nomOrigine,
        string $chemin,
        User $auteur,
        int $lignesTotal,
        array $rapport,
        array $compteurs,
        bool $annule,
    ): ImportJury {
        // Après un rollback, l'unité de travail a été vidée : l'auteur doit
        // être réattaché, sans quoi Doctrine le prendrait pour un compte neuf.
        $auteurAttache = $this->entityManager->getReference(User::class, $auteur->getId());

        $import = (new ImportJury())
            ->setType($type)
            ->setNomFichier($nomOrigine)
            ->setCheminFichier($chemin)
            ->setAuteur($auteurAttache)
            ->setLignesTotal($lignesTotal)
            ->setRapport($rapport)
            // Un import annulé n'a rien appliqué, quel que soit le décompte
            // provisoire atteint avant le rollback.
            ->setLignesTraitees($annule ? 0 : $compteurs[self::ISSUE_TRAITEE])
            ->setLignesIgnorees($compteurs[self::ISSUE_IGNOREE])
            ->setLignesErreur($compteurs[self::ISSUE_ERREUR])
            ->setStatut($annule ? StatutImport::ANNULE : StatutImport::TERMINE);

        $this->entityManager->persist($import);
        $this->entityManager->flush();

        return $import;
    }

    /**
     * Applique la décision d'une ligne, ou explique pourquoi elle ne l'est pas.
     *
     * @param array<string, string> $ligne
     *
     * @return array<string, mixed>
     */
    private function traiterLigne(array $ligne, TypeImportJury $type, User $auteur, string $motif): array
    {
        $numero = trim($ligne['NUMERO_VAE'] ?? '');
        $decisionBrute = strtoupper(trim($ligne['DECISION'] ?? ''));

        $base = [
            'numero' => $ligne['ligne_excel'] ?? null,
            'numero_vae' => $numero,
            'decision' => $decisionBrute,
        ];

        if ($numero === '') {
            return $base + ['issue' => self::ISSUE_ERREUR, 'motif' => 'Numéro VAE absent.'];
        }

        if ($decisionBrute === '') {
            return $base + ['issue' => self::ISSUE_ERREUR, 'motif' => 'Décision absente.'];
        }

        // L'identité est le seul recours contre une faute de frappe sur le
        // numéro : celle-ci tombe sur un dossier réel, au bon statut, que rien
        // d'autre ne distingue du dossier visé. Le contrôle ne vaut donc que si
        // les deux colonnes sont renseignées.
        if (trim($ligne['NOM'] ?? '') === '' || trim($ligne['PRENOMS'] ?? '') === '') {
            return $base + [
                'issue' => self::ISSUE_ERREUR,
                'motif' => 'Nom et prénoms obligatoires : ils vérifient que le numéro VAE désigne le bon candidat.',
            ];
        }

        $decisions = $type->decisions();

        // R6.5 : une décision non reconnue met la ligne en erreur.
        if (!array_key_exists($decisionBrute, $decisions)) {
            return $base + [
                'issue' => self::ISSUE_ERREUR,
                'motif' => sprintf(
                    'Décision « %s » non reconnue. Valeurs admises : %s.',
                    $decisionBrute,
                    implode(' ou ', array_keys($decisions))
                ),
            ];
        }

        $candidature = $this->candidatureRepository->findOneByNumero($numero);

        // R6.3 : un numéro inconnu met la ligne en erreur sans interrompre.
        if ($candidature === null) {
            return $base + ['issue' => self::ISSUE_ERREUR, 'motif' => 'Aucun dossier ne porte ce numéro.'];
        }

        $base['candidat'] = $candidature->getUser()?->getNomComplet();

        $statut = $this->resolver->resolve($candidature);

        // R6.8 : une décision déjà rendue n'est pas reprise par un second import.
        if (in_array($statut, $type->statutsDejaDecides(), true)) {
            return $base + [
                'issue' => self::ISSUE_IGNOREE,
                'motif' => sprintf(
                    'Décision déjà rendue pour ce dossier (%s).',
                    $statut->libelle()
                ),
            ];
        }

        // R6.1 et R6.2 : le dossier doit être au statut attendu par cet import.
        if ($statut !== $type->statutRequis()) {
            return $base + [
                'issue' => self::ISSUE_ERREUR,
                'motif' => sprintf(
                    'Statut « %s » incompatible : ce dossier doit être « %s ».',
                    $statut->libelle(),
                    $type->statutRequis()->libelle()
                ),
            ];
        }

        // R6.4 : une identité qui ne correspond pas au dossier bloque la ligne.
        // Une faute de frappe sur le numéro VAE atteint un dossier réel, au bon
        // statut, et lui appliquerait la décision d'un autre candidat sans que
        // rien ne le signale ; l'identité est le seul élément qui la révèle.
        $ecartIdentite = $this->controlerIdentite($candidature, $ligne);

        if ($ecartIdentite !== null) {
            return $base + ['issue' => self::ISSUE_ERREUR, 'motif' => $ecartIdentite];
        }

        $this->transition->appliquer(
            $candidature,
            $decisions[$decisionBrute],
            $auteur,
            $motif,
            $this->commentaire($ligne, $type),
            // Le flush est piloté par le lot, pas par chaque transition.
            false
        );

        return $base + [
            'issue' => self::ISSUE_TRAITEE,
            'motif' => sprintf('Statut porté à « %s ».', $decisions[$decisionBrute]->libelle()),
        ];
    }

    /**
     * @param array<string, string> $ligne
     */
    private function controlerIdentite(Candidature $candidature, array $ligne): ?string
    {
        $candidat = $candidature->getUser();

        // Dossier sans compte candidat : l'identité n'est pas vérifiable. Le
        // cas est anormal mais indépendant de l'import, qui n'a pas à le
        // transformer en refus.
        if ($candidat === null) {
            return null;
        }

        $attendu = $this->motsIdentite($candidat->getNom() . ' ' . $candidat->getPrenoms());
        $fourni = $this->motsIdentite(($ligne['NOM'] ?? '') . ' ' . ($ligne['PRENOMS'] ?? ''));

        if ($attendu === [] || $fourni === []) {
            return null;
        }

        // Comparaison par ensembles de mots plutôt que chaîne à chaîne : le
        // fichier peut inverser les colonnes NOM et PRENOMS, ou ne porter qu'un
        // prénom là où le dossier en compte plusieurs, sans désigner quelqu'un
        // d'autre. Un ensemble inclus dans l'autre reste donc la même personne,
        // alors que deux candidats distincts n'ont ni le même nom ni les mêmes
        // prénoms.
        $communs = array_values(array_intersect($attendu, $fourni));
        sort($communs);

        if ($communs === $attendu || $communs === $fourni) {
            return null;
        }

        return sprintf(
            'Identité du fichier (« %s ») différente de celle du dossier %s (« %s ») : vérifiez le numéro VAE.',
            trim(($ligne['NOM'] ?? '') . ' ' . ($ligne['PRENOMS'] ?? '')),
            (string) $candidature->getNumero(),
            $candidat->getNomComplet()
        );
    }

    /**
     * Mots composant une identité, normalisés, dédoublonnés et triés, pour
     * permettre une comparaison indépendante de l'ordre de saisie.
     *
     * @return list<string>
     */
    private function motsIdentite(string $valeur): array
    {
        $mots = array_unique(array_filter(explode(' ', $this->normaliser($valeur))));
        sort($mots);

        return $mots;
    }

    /**
     * @param array<string, string> $ligne
     */
    private function commentaire(array $ligne, TypeImportJury $type): ?string
    {
        $morceaux = [];

        if (($ligne['MENTION'] ?? '') !== '') {
            $morceaux[] = 'Mention : ' . $ligne['MENTION'];
        }

        if (($ligne['OBSERVATION'] ?? '') !== '') {
            $morceaux[] = $ligne['OBSERVATION'];
        }

        if (($ligne['DATE_JURY'] ?? '') !== '') {
            $morceaux[] = 'Jury du ' . $ligne['DATE_JURY'];
        }

        return $morceaux === [] ? null : implode(' — ', $morceaux);
    }

    /**
     * Lit les lignes exploitables du classeur.
     *
     * @return list<array<string, string>>
     *
     * @throws ImportInvalideException
     */
    private function lireLignes(string $chemin, TypeImportJury $type): array
    {
        try {
            $lecteur = IOFactory::createReaderForFile($chemin);
            // Les formules et la mise en forme ne servent à rien ici, et leur
            // lecture coûte cher en mémoire sur les gros fichiers.
            $lecteur->setReadDataOnly(true);
            $classeur = $lecteur->load($chemin);
        } catch (\Throwable $exception) {
            $this->logger->warning('Classeur illisible', ['chemin' => $chemin, 'erreur' => $exception->getMessage()]);

            throw ImportInvalideException::illisible();
        }

        $feuille = $this->feuilleAttendue($classeur, $type);
        $colonnes = $type->colonnes();
        $lignes = $feuille->toArray(null, true, false, false);

        if ($lignes === []) {
            throw ImportInvalideException::fichierVide();
        }

        $this->verifierEntete(array_shift($lignes), $colonnes);

        $resultat = [];

        foreach ($lignes as $index => $cellules) {
            $valeurs = [];

            foreach ($colonnes as $rang => $intitule) {
                $valeurs[$intitule] = $this->valeurCellule($cellules[$rang] ?? null);
            }

            // Une ligne dont toutes les cellules sont vides provient du
            // remplissage du tableur : elle n'est pas une anomalie.
            if (implode('', $valeurs) === '') {
                continue;
            }

            // Le rang affiché doit correspondre à ce que l'utilisateur voit
            // dans son tableur : + 2 pour l'en-tête et l'indexation à zéro.
            $valeurs['ligne_excel'] = $index + 2;
            $resultat[] = $valeurs;
        }

        if ($resultat === []) {
            throw ImportInvalideException::fichierVide();
        }

        return $resultat;
    }

    private function feuilleAttendue(Spreadsheet $classeur, TypeImportJury $type): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $feuille = $classeur->getSheetByName($type->feuille());

        if ($feuille !== null) {
            return $feuille;
        }

        // Tolérance : un classeur d'une seule feuille est accepté même si son
        // onglet a été renommé, l'en-tête restant contrôlé juste après.
        if ($classeur->getSheetCount() === 1) {
            return $classeur->getSheet(0);
        }

        throw ImportInvalideException::feuilleAbsente($type);
    }

    /**
     * @param array<int, mixed> $entete
     * @param list<string>      $attendues
     */
    private function verifierEntete(array $entete, array $attendues): void
    {
        $trouvees = [];

        foreach ($entete as $cellule) {
            $valeur = strtoupper($this->valeurCellule($cellule));

            if ($valeur !== '') {
                $trouvees[] = $valeur;
            }
        }

        // Les colonnes facultatives peuvent manquer : seules celles du modèle,
        // dans l'ordre, doivent se retrouver au début de l'en-tête.
        $obligatoires = array_slice($attendues, 0, 4);

        if (array_slice($trouvees, 0, count($obligatoires)) !== $obligatoires) {
            throw ImportInvalideException::enteteNonConforme($attendues, $trouvees);
        }
    }

    private function valeurCellule(mixed $valeur): string
    {
        if ($valeur instanceof \DateTimeInterface) {
            return $valeur->format('d/m/Y');
        }

        return trim((string) $valeur);
    }

    private function normaliser(string $valeur): string
    {
        $valeur = mb_strtoupper(trim($valeur));
        $valeur = preg_replace('/\s+/', ' ', $valeur) ?? $valeur;

        // Les accents varient d'une saisie à l'autre : les ignorer évite de
        // refuser des identités pourtant identiques.
        return strtr($valeur, [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
            'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U',
            'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C',
        ]);
    }

    private function pourcentageErreur(int $erreurs, int $total): int
    {
        return $total === 0 ? 0 : (int) round($erreurs * 100 / $total);
    }

    /**
     * Conserve le fichier soumis pour l'audit (règle métier R6.10).
     */
    private function conserver(UploadedFile $fichier, TypeImportJury $type): string
    {
        $repertoire = rtrim($this->dossierImports, '/\\');

        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw ImportInvalideException::stockageImpossible();
        }

        $nom = sprintf(
            '%s-%s-%s.%s',
            $type->value,
            (new \DateTime())->format('Ymd-His'),
            bin2hex(random_bytes(3)),
            $fichier->guessExtension() ?? 'xlsx'
        );

        try {
            $fichier->move($repertoire, $nom);
        } catch (\Throwable $exception) {
            $this->logger->error('Conservation du fichier impossible', ['erreur' => $exception->getMessage()]);

            throw ImportInvalideException::stockageImpossible();
        }

        return $repertoire . \DIRECTORY_SEPARATOR . $nom;
    }
}
