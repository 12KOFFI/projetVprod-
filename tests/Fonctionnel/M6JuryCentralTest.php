<?php

namespace App\Tests\Fonctionnel;

use App\Entity\Candidature;
use App\Entity\ImportJury;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutRecevabilite;
use App\Enum\TypeImportJury;
use App\Exception\ImportInvalideException;
use App\Security\Role;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Jury\ImportJuryService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * M6 — décisions du jury central importées par fichier Excel.
 *
 * L'import est l'unique porte d'entrée de l'éligibilité et de l'admission
 * définitive : ces deux décisions n'ont aucun écran de saisie. Les tests
 * fabriquent de vrais classeurs, seule manière d'exercer la lecture.
 */
final class M6JuryCentralTest extends SocleFonctionnel
{
    /** @var list<string> */
    private array $fichiersTemporaires = [];

    /** @var list<string> */
    private array $importsAvant = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importsAvant = $this->fichiersImportes();
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
        $this->fichiersTemporaires = [];

        // Le service conserve chaque fichier importé dans var/imports : les
        // tests ne doivent pas y laisser de dépôt.
        foreach (array_diff($this->fichiersImportes(), $this->importsAvant) as $chemin) {
            @unlink($chemin);
        }

        parent::tearDown();
    }

    public function testSeulLAdministrateurAtteintLEcranDImport(): void
    {
        $referentiel = $this->atelier->referentiel();
        $administrateur = $this->atelier->utilisateur('admin', Role::ADMIN);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);

        $this->client->loginUser($administrateur);
        $this->client->request('GET', '/admin/jury/eligibilite');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/jury/admission');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($conseiller);
        $this->client->request('GET', '/admin/jury/eligibilite');
        self::assertResponseRedirects('/tableau-de-bord');
    }

    public function testLeModeleExcelEstTelechargeable(): void
    {
        $administrateur = $this->atelier->utilisateur('admin', Role::ADMIN);

        $this->client->loginUser($administrateur);
        $this->client->request('GET', '/admin/jury/modele/eligibilite');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'spreadsheet',
            (string) $this->client->getResponse()->headers->get('Content-Type')
        );
    }

    /**
     * Import nominal : un dossier recevable devient éligible.
     */
    public function testUnImportNominalRendLesDossiersEligibles(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'ELIGIBLE', '01/09/2026', 'RAS'],
        ]);

        self::assertSame(1, $import->getLignesTraitees());
        self::assertSame(0, $import->getLignesErreur());

        $this->entityManager->clear();
        self::assertSame(StatutCandidature::ELIGIBLE, $this->statutDe((string) $dossier->getNumero()));
    }

    /**
     * La décision négative passe par le même chemin.
     */
    public function testUnImportPeutRendreUnDossierNonEligible(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'NON_ELIGIBLE', '01/09/2026', 'Dossier insuffisant'],
        ]);

        $this->entityManager->clear();
        self::assertSame(StatutCandidature::NON_ELIGIBLE, $this->statutDe((string) $dossier->getNumero()));
    }

    /**
     * R6.3 : un numéro inconnu met la ligne en erreur sans interrompre l'import.
     */
    public function testUnNumeroInconnuMetLaLigneEnErreur(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'ELIGIBLE', '01/09/2026', ''],
            ['VAE00000', 'INCONNU', 'Personne', 'ELIGIBLE', '01/09/2026', ''],
        ]);

        self::assertSame(1, $import->getLignesTraitees());
        self::assertSame(1, $import->getLignesErreur());
        self::assertStringContainsString('Aucun dossier', $this->motifs($import));
    }

    /**
     * R6.5 : une décision non reconnue met la ligne en erreur.
     */
    public function testUneDecisionInconnueMetLaLigneEnErreur(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'PEUT_ETRE', '01/09/2026', ''],
            [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'ELIGIBLE', '01/09/2026', ''],
        ]);

        self::assertSame(1, $import->getLignesErreur());
        self::assertStringContainsString('non reconnue', $this->motifs($import));
    }

    /**
     * R6.4 : une identité incohérente avec le dossier refuse la ligne. C'est le
     * seul garde-fou contre une faute de frappe sur le numéro VAE, qui
     * toucherait un dossier réel et au bon statut.
     */
    public function testUneIdentiteIncoherenteRefuseLaLigne(): void
    {
        [$auteur, $dossier] = $this->dossierRecevable();
        [$temoin, $candidatTemoin] = $this->candidatRecevable('temoin', 'DIALLO', 'Sekou');

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), 'AUTREPERSONNE', 'Inconnue', 'ELIGIBLE', '01/09/2026', ''],
            $this->ligneValide($temoin, $candidatTemoin),
        ]);

        self::assertSame(1, $import->getLignesErreur());
        self::assertStringContainsString('Identité du fichier', $this->motifs($import));

        $this->entityManager->clear();
        self::assertSame(
            StatutCandidature::DOSSIER_RECEVABLE,
            $this->statutDe((string) $dossier->getNumero()),
            'Le dossier ne doit pas avoir bougé.'
        );
    }

    /**
     * L'inversion des colonnes NOM et PRENOMS reste la même personne : le
     * contrôle compare des ensembles de mots, pas des chaînes.
     */
    public function testLInversionDuNomEtDesPrenomsResteAcceptee(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), $candidat->getPrenoms(), $candidat->getNom(), 'ELIGIBLE', '01/09/2026', ''],
        ]);

        self::assertSame(1, $import->getLignesTraitees(), $this->motifs($import));
    }

    /**
     * NOM et PRENOMS sont obligatoires : sans eux, le contrôle d'identité ne
     * vaut rien.
     */
    public function testLeNomEtLesPrenomsSontObligatoires(): void
    {
        [$auteur, $dossier] = $this->dossierRecevable();
        [$temoin, $candidatTemoin] = $this->candidatRecevable('temoin', 'DIALLO', 'Sekou');

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), '', '', 'ELIGIBLE', '01/09/2026', ''],
            $this->ligneValide($temoin, $candidatTemoin),
        ]);

        self::assertSame(1, $import->getLignesErreur());
        self::assertStringContainsString('obligatoires', $this->motifs($import));
    }

    /**
     * R6.1 et R6.2 : le dossier doit être au statut attendu par cet import.
     * Un dossier seulement inscrit n'est pas encore passé en recevabilité.
     */
    public function testUnStatutIncompatibleMetLaLigneEnErreur(): void
    {
        $referentiel = $this->referentiel();
        $auteur = $this->atelier->utilisateur('admin', Role::ADMIN);
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT, nom: 'KOUAME', prenoms: 'Ama');

        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            fraisDossierRegles: true
        );

        [$temoin, $candidatTemoin] = $this->candidatRecevable('temoin', 'DIALLO', 'Sekou');

        $import = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
            [$dossier->getNumero(), 'KOUAME', 'Ama', 'ELIGIBLE', '01/09/2026', ''],
            $this->ligneValide($temoin, $candidatTemoin),
        ]);

        self::assertSame(1, $import->getLignesErreur());
        self::assertStringContainsString('incompatible', $this->motifs($import));
    }

    /**
     * R6.8 : une décision déjà rendue n'est pas reprise par un second import,
     * elle est ignorée — ce qui rend l'import rejouable sans dégât.
     */
    public function testUneDecisionDejaRendueEstIgnoree(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        $ligne = [[$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'ELIGIBLE', '01/09/2026', '']];

        $this->importer(TypeImportJury::ELIGIBILITE, $auteur, $ligne);
        $this->entityManager->clear();

        $second = $this->importer(TypeImportJury::ELIGIBILITE, $auteur, $ligne);

        self::assertSame(0, $second->getLignesTraitees());
        self::assertSame(1, $second->getLignesIgnorees());
        self::assertStringContainsString('déjà rendue', $this->motifs($second));
    }

    /**
     * R6.6 : au-delà de 50 % d'erreurs, RIEN n'est appliqué — même les lignes
     * valides du fichier sont remises en arrière.
     */
    public function testAuDelaDuSeuilDErreursRienNEstApplique(): void
    {
        [$auteur, $dossier, $candidat] = $this->dossierRecevable();

        try {
            $this->importer(TypeImportJury::ELIGIBILITE, $auteur, [
                [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'ELIGIBLE', '01/09/2026', ''],
                ['VAE00001', 'INCONNU', 'Un', 'ELIGIBLE', '01/09/2026', ''],
                ['VAE00002', 'INCONNU', 'Deux', 'ELIGIBLE', '01/09/2026', ''],
            ]);

            self::fail('Un import au-delà du seuil doit être refusé.');
        } catch (ImportInvalideException) {
            // Attendu : la trace est écrite, mais les décisions sont annulées.
        }

        $this->entityManager->clear();

        self::assertSame(
            StatutCandidature::DOSSIER_RECEVABLE,
            $this->statutDe((string) $dossier->getNumero()),
            'La ligne valide doit avoir été remise en arrière avec les autres.'
        );
    }

    /**
     * L'admission définitive suit le même mécanisme, mais exige un dossier
     * admissible.
     */
    public function testLAdmissionDefinitiveExigeUnDossierAdmissible(): void
    {
        $referentiel = $this->atelier->referentiel();
        $auteur = $this->atelier->utilisateur('admin', Role::ADMIN);
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT, nom: 'TRAORE', prenoms: 'Sita');

        $admissible = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            rec: StatutRecevabilite::RECEVABLE->value,
            elig: CandidatureStatusResolver::DECISION_POSITIVE,
            resultat: CandidatureStatusResolver::DECISION_POSITIVE,
            fraisDossierRegles: true
        );

        self::assertSame(StatutCandidature::ADMISSIBLE, $this->statutDe((string) $admissible->getNumero()));

        $import = $this->importer(TypeImportJury::ADMISSION, $auteur, [
            [$admissible->getNumero(), 'TRAORE', 'Sita', 'ADMIS', '15/11/2026', 'Bien', 'RAS'],
        ]);

        self::assertSame(1, $import->getLignesTraitees(), $this->motifs($import));

        $this->entityManager->clear();
        self::assertSame(StatutCandidature::ADMIS_DEFINITIF, $this->statutDe((string) $admissible->getNumero()));
    }

    /**
     * V6.2 : un en-tête non conforme rejette le fichier avant toute écriture.
     */
    public function testUnEnteteNonConformeRejetteLeFichier(): void
    {
        $auteur = $this->atelier->utilisateur('admin', Role::ADMIN);

        $chemin = $this->classeur(
            ['NUMERO', 'IDENTITE', 'AVIS', 'DATE'],
            [['VAE26001', 'X', 'ELIGIBLE', '01/09/2026']]
        );

        $this->expectException(ImportInvalideException::class);

        static::getContainer()->get(ImportJuryService::class)->importer(
            new UploadedFile($chemin, 'mauvais.xlsx', null, null, true),
            TypeImportJury::ELIGIBILITE,
            $auteur
        );
    }

    /** @var array{centre: \App\Entity\Centre, centreAutre: \App\Entity\Centre, metier: \App\Entity\Metier}|null */
    private ?array $referentielCache = null;

    /**
     * @return array{centre: \App\Entity\Centre, centreAutre: \App\Entity\Centre, metier: \App\Entity\Metier}
     */
    private function referentiel(): array
    {
        return $this->referentielCache ??= $this->atelier->referentiel();
    }

    /**
     * Prépare un dossier arrivé jusqu'à la recevabilité, seul point d'entrée
     * de l'import d'éligibilité.
     *
     * @return array{User, Candidature, User}
     */
    private function dossierRecevable(): array
    {
        $auteur = $this->atelier->utilisateur('admin', Role::ADMIN);
        [$dossier, $candidat] = $this->candidatRecevable('candidat', 'YAO', 'Marie Claire');

        return [$auteur, $dossier, $candidat];
    }

    /**
     * Un second dossier valide, pour que les tests d'erreur restent sous le
     * seuil d'annulation : une ligne fautive seule ferait 100 % d'erreurs et
     * annulerait l'import au lieu de signaler la ligne.
     *
     * @return array{Candidature, User}
     */
    private function candidatRecevable(string $cle, string $nom, string $prenoms): array
    {
        $referentiel = $this->referentiel();
        $candidat = $this->atelier->utilisateur($cle, Role::CANDIDAT, nom: $nom, prenoms: $prenoms);

        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            rec: StatutRecevabilite::RECEVABLE->value,
            fraisDossierRegles: true
        );

        self::assertSame(StatutCandidature::DOSSIER_RECEVABLE, $this->statutDe((string) $dossier->getNumero()));

        return [$dossier, $candidat];
    }

    /**
     * Ligne valide, à joindre aux tests d'erreur pour rester sous le seuil.
     *
     * @return list<string|null>
     */
    private function ligneValide(Candidature $dossier, User $candidat): array
    {
        return [$dossier->getNumero(), $candidat->getNom(), $candidat->getPrenoms(), 'ELIGIBLE', '01/09/2026', ''];
    }

    /**
     * @param list<list<string|null>> $lignes
     */
    private function importer(TypeImportJury $type, User $auteur, array $lignes): ImportJury
    {
        $chemin = $this->classeur($type->colonnes(), $lignes);

        return static::getContainer()->get(ImportJuryService::class)->importer(
            new UploadedFile($chemin, 'jury.xlsx', null, null, true),
            $type,
            $auteur
        );
    }

    /**
     * @param list<string>            $entete
     * @param list<list<string|null>> $lignes
     */
    private function classeur(array $entete, array $lignes): string
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->fromArray($entete, null, 'A1');

        $rang = 2;
        foreach ($lignes as $ligne) {
            $feuille->fromArray($ligne, null, 'A' . $rang);
            ++$rang;
        }

        $chemin = sys_get_temp_dir() . '/' . AtelierVae::PREFIXE . '-jury-' . uniqid() . '.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $classeur->disconnectWorksheets();

        $this->fichiersTemporaires[] = $chemin;

        return $chemin;
    }

    private function statutDe(string $numero): StatutCandidature
    {
        $dossier = $this->entityManager->getRepository(Candidature::class)->findOneBy(['numero' => $numero]);

        self::assertNotNull($dossier, sprintf('Le dossier %s devrait exister.', $numero));

        return static::getContainer()->get(CandidatureStatusResolver::class)->resolve($dossier);
    }

    private function motifs(ImportJury $import): string
    {
        return implode(' | ', array_map(
            static fn (array $ligne): string => (string) ($ligne['motif'] ?? ''),
            $import->getRapport() ?? []
        ));
    }

    /**
     * @return list<string>
     */
    private function fichiersImportes(): array
    {
        $repertoire = \dirname(__DIR__, 2) . '/var/imports';

        if (!is_dir($repertoire)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $nom): string => $repertoire . \DIRECTORY_SEPARATOR . $nom,
                array_diff(scandir($repertoire) ?: [], ['.', '..'])
            ),
            'is_file'
        ));
    }
}
