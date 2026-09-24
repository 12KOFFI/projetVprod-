<?php

namespace App\Tests\Fonctionnel;

use App\Dto\EtudeDto;
use App\Dto\RecevabiliteDto;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutRecevabilite;
use App\Enum\TypeFrais;
use App\Exception\RecevabiliteException;
use App\Repository\HistoriqueStatutRepository;
use App\Security\Role;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\RecevabiliteService;

/**
 * M4 — étude du dossier puis décision de recevabilité, toutes deux rendues par
 * le conseiller VAE.
 *
 * Le parcours réel compte DEUX décisions distinctes : l'étude, qui ne change
 * aucun statut, puis la recevabilité, qui n'est ouverte qu'une fois les frais
 * de dossier réglés.
 */
final class M4EtudeRecevabiliteTest extends SocleFonctionnel
{
    public function testLeConseillerAtteintSonEspaceEtSesListes(): void
    {
        $referentiel = $this->atelier->referentiel();
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);

        $this->client->loginUser($conseiller);

        foreach (['/conseiller', '/conseiller/candidatures', '/conseiller/mes-analyses'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('%s doit répondre au conseiller.', $url));
        }
    }

    /**
     * R4.2 : un conseiller n'accède qu'aux dossiers de SON centre.
     */
    public function testUnConseillerNAccedePasAuDossierDUnAutreCentre(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseillerAilleurs = $this->atelier->utilisateur('conseiller-b', Role::CONSEILLER, $referentiel['centreAutre']);

        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $this->client->loginUser($conseillerAilleurs);
        $this->client->request('GET', sprintf('/conseiller/candidature/%d', (int) $dossier->getId()));

        self::assertResponseRedirects('/tableau-de-bord');
    }

    public function testLeConseillerDuCentreOuvreLeDossier(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);

        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $this->client->loginUser($conseiller);
        $this->client->request('GET', sprintf('/conseiller/candidature/%d', (int) $dossier->getId()));

        self::assertResponseIsSuccessful();
    }

    /**
     * Une étude acceptée n'avance PAS le statut : le dossier reste préinscrit
     * jusqu'au règlement des frais de dossier. C'est l'écart majeur du module
     * par rapport au schéma d'origine.
     */
    public function testUneEtudeAccepteeNeFaitPasAvancerLeStatut(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $dto = new EtudeDto();
        $dto->statut = StatutEtude::ACCEPTE;
        $dto->commentaire = 'Dossier complet.';

        $this->service()->etudier($dossier, $dto, $conseiller);

        self::assertSame(StatutEtude::ACCEPTE->value, $dossier->getEtuStatut());
        self::assertSame(StatutCandidature::PREINSCRIT, $this->resolver()->resolve($dossier));
        self::assertNotNull($dossier->getEtuDate());

        /** @var HistoriqueStatutRepository $historiques */
        $historiques = static::getContainer()->get(HistoriqueStatutRepository::class);
        self::assertNotEmpty(
            $historiques->findPourCandidature($dossier),
            'L\'étude doit être journalisée même sans changement de statut.'
        );
    }

    /**
     * R4.4 : le refus exige un commentaire.
     */
    public function testUnRefusSansCommentaireEstRejete(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $dto = new EtudeDto();
        $dto->statut = StatutEtude::REFUSE;
        $dto->commentaire = '   ';

        $this->expectException(RecevabiliteException::class);
        $this->service()->etudier($dossier, $dto, $conseiller);
    }

    /**
     * Un refus motivé laisse le dossier préinscrit, donc réétudiable.
     */
    public function testUnRefusMotiveLaisseLeDossierReetudiable(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $dto = new EtudeDto();
        $dto->statut = StatutEtude::REFUSE;
        $dto->commentaire = 'Expérience insuffisamment justifiée.';

        $this->service()->etudier($dossier, $dto, $conseiller);

        self::assertSame(StatutCandidature::PREINSCRIT, $this->resolver()->resolve($dossier));
        self::assertTrue(
            $this->resolver()->estEnAttenteEtude($dossier),
            'Un dossier refusé doit rester réétudiable.'
        );
    }

    /**
     * R4.1 : le conseiller qui rend l'étude prend le dossier en charge s'il
     * n'était affecté à personne.
     */
    public function testRendreLEtudePrendLeDossierEnCharge(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        self::assertNull($dossier->getConseiller());

        $dto = new EtudeDto();
        $dto->statut = StatutEtude::ACCEPTE;
        $dto->commentaire = 'Complet.';
        $this->service()->etudier($dossier, $dto, $conseiller);

        self::assertSame($conseiller->getId(), $dossier->getConseiller()?->getId());
    }

    /**
     * Un dossier dont l'étude est acceptée ne se réétudie pas.
     */
    public function testUnDossierDejaAccepteNeSeReetudiePas(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value
        );

        $dto = new EtudeDto();
        $dto->statut = StatutEtude::REFUSE;
        $dto->commentaire = 'Revirement.';

        $this->expectException(RecevabiliteException::class);
        $this->service()->etudier($dossier, $dto, $conseiller);
    }

    /**
     * R4.1 : un dossier dont l'étude est rendue ne se réaffecte plus.
     */
    public function testUnDossierEtudieNeSeReaffectePlus(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value
        );

        $this->expectException(RecevabiliteException::class);
        $this->service()->affecter($dossier, $conseiller);
    }

    /**
     * La recevabilité est fermée tant que le dossier n'est pas INSCRIT, donc
     * tant que les frais de dossier ne sont pas réglés.
     */
    public function testLaRecevabiliteEstFermeeAvantLePaiement(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value
        );

        $dto = new RecevabiliteDto();
        $dto->statut = StatutRecevabilite::RECEVABLE;

        $this->expectException(RecevabiliteException::class);
        $this->service()->enregistrerRecevabilite($dossier, $dto, $conseiller);
    }

    /**
     * Parcours complet : étude acceptée, frais réglés — le dossier devient
     * INSCRIT — puis décision de recevabilité.
     */
    public function testLeParcoursCompletMeneDuDepotALaRecevabilite(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        self::assertSame(StatutCandidature::PREINSCRIT, $this->resolver()->resolve($dossier));

        $etude = new EtudeDto();
        $etude->statut = StatutEtude::ACCEPTE;
        $etude->commentaire = 'Complet.';
        $this->service()->etudier($dossier, $etude, $conseiller);

        $this->atelier->paiementReussi($dossier, TypeFrais::DOSSIER);
        $this->entityManager->refresh($dossier);

        self::assertSame(
            StatutCandidature::INSCRIT,
            $this->resolver()->resolve($dossier),
            'Les frais de dossier réglés font passer le dossier à INSCRIT.'
        );

        $recevabilite = new RecevabiliteDto();
        $recevabilite->statut = StatutRecevabilite::RECEVABLE;
        $this->service()->enregistrerRecevabilite($dossier, $recevabilite, $conseiller);

        self::assertSame(StatutCandidature::DOSSIER_RECEVABLE, $this->resolver()->resolve($dossier));
        self::assertNotNull($dossier->getRecDate());
    }

    /**
     * La décision négative existe aussi, et clôt le parcours.
     */
    public function testUnDossierPeutEtreDeclareNonRecevable(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            fraisDossierRegles: true
        );

        $dto = new RecevabiliteDto();
        $dto->statut = StatutRecevabilite::NON_RECEVABLE;
        $this->service()->enregistrerRecevabilite($dossier, $dto, $conseiller);

        $statut = $this->resolver()->resolve($dossier);
        self::assertSame(StatutCandidature::DOSSIER_NON_RECEVABLE, $statut);
        self::assertTrue($statut->estTerminal());
    }

    private function service(): RecevabiliteService
    {
        return static::getContainer()->get(RecevabiliteService::class);
    }

    private function resolver(): CandidatureStatusResolver
    {
        return static::getContainer()->get(CandidatureStatusResolver::class);
    }
}
