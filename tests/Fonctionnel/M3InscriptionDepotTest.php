<?php

namespace App\Tests\Fonctionnel;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Exception\DepotCandidatureException;
use App\Repository\CandidatureRepository;
use App\Repository\HistoriqueStatutRepository;
use App\Security\Role;
use App\Service\Candidature\CandidatureService;
use App\Service\Candidature\CandidatureStatusResolver;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * M3 — inscription en ligne et dépôt du dossier de candidature.
 *
 * Le dépôt réel est exercé par le service, seul chemin qu'aucun formulaire
 * posté ne peut contourner, et les écrans sont exercés en HTTP.
 */
final class M3InscriptionDepotTest extends SocleFonctionnel
{
    /** @var list<string> */
    private array $fichiersTemporaires = [];

    /** Numéros réellement déposés : leurs pièces vivent sur le disque. */
    private array $numerosDeposes = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
        $this->fichiersTemporaires = [];

        // Le dépôt copie les pièces dans public/media/{numero} : la base n'est
        // pas le seul endroit que les tests salissent.
        foreach ($this->numerosDeposes as $numero) {
            $this->supprimerRepertoire(sprintf('%s/public/media/%s', $this->racineProjet(), $numero));
        }
        $this->numerosDeposes = [];

        parent::tearDown();
    }

    private function racineProjet(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function supprimerRepertoire(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }

        foreach (scandir($chemin) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }

            $cible = $chemin . \DIRECTORY_SEPARATOR . $entree;
            is_dir($cible) ? $this->supprimerRepertoire($cible) : @unlink($cible);
        }

        @rmdir($chemin);
    }

    public function testLaPageDInscriptionEstAccessibleSansCompte(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('form')->count(), 'La page doit porter un formulaire.');
    }

    /**
     * Le candidat atteint son espace et le formulaire de dépôt tant qu'il n'a
     * pas de dossier en cours.
     */
    public function testLeCandidatAtteintSonEspaceEtLeFormulaireDeDepot(): void
    {
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $this->client->loginUser($candidat);

        $this->client->request('GET', '/espace-candidat');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/espace-candidat/candidature/nouvelle');
        self::assertResponseIsSuccessful();
    }

    /**
     * R3.1 : une seule candidature active par candidat. La garde est posée à
     * l'affichage du formulaire, qui redirige au lieu de s'ouvrir.
     */
    public function testUnCandidatDejaEngageNePeutPasRouvrirLeFormulaire(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $this->client->loginUser($candidat);
        $this->client->request('GET', '/espace-candidat/candidature/nouvelle');

        self::assertResponseRedirects('/espace-candidat');
    }

    /**
     * La même règle, côté service : c'est elle qui fait foi, le formulaire
     * n'étant qu'un confort.
     */
    public function testLeServiceRefuseUnSecondDossier(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        /** @var CandidatureService $service */
        $service = static::getContainer()->get(CandidatureService::class);

        self::assertFalse($service->peutDeposer($candidat));

        $this->expectException(DepotCandidatureException::class);
        $service->garantirCandidatEligible($candidat);
    }

    /**
     * R3.12 : un membre du personnel ne dépose pas de dossier pour lui-même.
     */
    public function testUnMembreDuPersonnelNeDeposePasPourLuiMeme(): void
    {
        $referentiel = $this->atelier->referentiel();
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);

        /** @var CandidatureService $service */
        $service = static::getContainer()->get(CandidatureService::class);

        self::assertFalse($service->peutDeposer($conseiller));
    }

    /**
     * Dépôt nominal : le dossier reçoit un numéro au format court, naît
     * PREINSCRIT et laisse une ligne d'audit (R1.3, R3.6).
     */
    public function testUnDepotNominalCreeUnDossierPreinscritNumerote(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);

        $candidature = $this->deposer($candidat, $referentiel);

        self::assertMatchesRegularExpression(
            '/^VAE\d{2}\d{3}$/',
            (string) $candidature->getNumero(),
            'Le numéro doit suivre le format court VAE{YY}{NNN}.'
        );

        /** @var CandidatureStatusResolver $resolver */
        $resolver = static::getContainer()->get(CandidatureStatusResolver::class);
        self::assertSame(StatutCandidature::PREINSCRIT, $resolver->resolve($candidature));

        self::assertSame($candidat->getId(), $candidature->getUser()?->getId());
        self::assertSame($referentiel['centre']->getId(), $candidature->getCentre()?->getId());

        /** @var HistoriqueStatutRepository $historiques */
        $historiques = static::getContainer()->get(HistoriqueStatutRepository::class);
        $lignes = $historiques->findPourCandidature($candidature);

        self::assertNotEmpty($lignes, 'Le dépôt doit être journalisé.');
        self::assertSame(StatutCandidature::PREINSCRIT, $lignes[0]->getStatutApres());
    }

    /**
     * R3.5 : deux dépôts successifs ne partagent jamais un numéro.
     */
    public function testDeuxDepotsRecoiventDesNumerosDistincts(): void
    {
        $referentiel = $this->atelier->referentiel();

        $premier = $this->deposer($this->atelier->utilisateur('candidat', Role::CANDIDAT), $referentiel);
        $second = $this->deposer($this->atelier->utilisateur('candidat2', Role::CANDIDAT), $referentiel);

        self::assertNotSame((string) $premier->getNumero(), (string) $second->getNumero());
    }

    /**
     * R3.2 : le couple (centre, métier) doit exister dans l'offre du centre.
     * Le second centre de l'atelier n'ouvre pas ce métier.
     */
    public function testUnMetierFermeDansLeCentreEstRefuse(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);

        $this->expectException(DepotCandidatureException::class);

        $this->deposer($candidat, [
            'centre' => $referentiel['centreAutre'],
            'metier' => $referentiel['metier'],
        ]);
    }

    /**
     * R3.8 : les pièces obligatoires sont exigées. Le code en compte cinq —
     * la photo d'identité en fait désormais partie, contrairement à ce que
     * décrit final.txt.
     */
    public function testLesPiecesObligatoiresSontExigees(): void
    {
        /** @var CandidatureService $service */
        $service = static::getContainer()->get(CandidatureService::class);

        $manquants = $service->documentsManquants([]);

        self::assertSame(
            array_keys(CandidatureDepotDto::documentsObligatoires()),
            array_keys($manquants)
        );
        self::assertArrayHasKey('fphoto', $manquants, 'La photo est obligatoire dans le code actuel.');

        $complet = [];
        foreach (array_keys(CandidatureDepotDto::documentsObligatoires()) as $champ) {
            $complet[$champ] = $this->fichier($champ);
        }

        self::assertSame([], $service->documentsManquants($complet));
    }

    /**
     * Le dossier déposé est visible par son candidat, et par lui seul.
     */
    public function testLaPageDeSuccesNEstVisibleQueParSonCandidat(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $intrus = $this->atelier->utilisateur('candidat2', Role::CANDIDAT);

        $candidature = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);
        $url = sprintf('/espace-candidat/candidature/succes/%d', (int) $candidature->getId());

        $this->client->loginUser($candidat);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($intrus);
        $this->client->request('GET', $url);
        self::assertResponseRedirects('/tableau-de-bord');
    }

    /**
     * Le dossier reste modifiable tant que l'étude n'est pas rendue, puis se
     * ferme — y compris pendant qu'il est encore PREINSCRIT.
     */
    public function testLaModificationSeFermeDesQueLEtudeEstRendue(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $candidature = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $url = sprintf('/espace-candidat/candidature/%d/modifier', (int) $candidature->getId());

        $this->client->loginUser($candidat);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $candidature->setEtuStatut(StatutEtude::ACCEPTE->value);
        $this->entityManager->flush();

        $this->client->request('GET', $url);
        self::assertResponseRedirects('/tableau-de-bord');
    }

    /**
     * @param array{centre: \App\Entity\Centre, metier: \App\Entity\Metier} $referentiel
     */
    private function deposer(\App\Entity\User $candidat, array $referentiel): Candidature
    {
        $dto = new CandidatureDepotDto();
        $dto->centre = $referentiel['centre'];
        $dto->metier = $referentiel['metier'];
        $dto->nbAnneesExperience = 9;
        $dto->situationPro = 'ARTISAN A SON COMPTE';
        $dto->nomEntreprise = 'Atelier de test';
        $dto->lieuExercice = 'Abidjan';

        foreach (array_keys(CandidatureDepotDto::documentsObligatoires()) as $champ) {
            $dto->documents[$champ] = $this->fichier($champ);
        }

        /** @var CandidatureService $service */
        $service = static::getContainer()->get(CandidatureService::class);

        $candidature = $service->deposer($dto, $candidat, $candidat);
        $this->numerosDeposes[] = (string) $candidature->getNumero();

        return $candidature;
    }

    /**
     * Fichier réel : le service déplace les pièces, un objet simulé ne
     * suffirait pas.
     */
    private function fichier(string $champ): UploadedFile
    {
        $chemin = sys_get_temp_dir() . '/' . AtelierVae::PREFIXE . '-' . $champ . '-' . uniqid() . '.pdf';
        file_put_contents($chemin, "%PDF-1.4\n% test\n");
        $this->fichiersTemporaires[] = $chemin;

        return new UploadedFile($chemin, $champ . '.pdf', 'application/pdf', null, true);
    }
}
