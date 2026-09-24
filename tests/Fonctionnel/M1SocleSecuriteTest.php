<?php

namespace App\Tests\Fonctionnel;

use App\Entity\Candidature;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutRecevabilite;
use App\Enum\TypeFrais;
use App\Exception\TransitionInterditeException;
use App\Repository\HistoriqueStatutRepository;
use App\Security\Role;
use App\Security\Voter\CandidatureVoter;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\TransitionCandidature;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * M1 — socle technique : contrôle d'accès par préfixe de route, périmètre du
 * voter, machine à états et journal d'audit.
 *
 * Remarque tirée du code et non de la spécification : un accès refusé à un
 * utilisateur authentifié ne produit pas un 403 mais une redirection vers
 * /tableau-de-bord, AccessDeniedHandler interceptant l'exception.
 */
final class M1SocleSecuriteTest extends SocleFonctionnel
{
    /**
     * Aucun espace n'est atteignable sans authentification : le pare-feu
     * renvoie vers la page de connexion.
     *
     * @dataProvider routesProtegees
     */
    public function testUnVisiteurAnonymeEstRenvoyeVersLaConnexion(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/connexion',
            (string) $this->client->getResponse()->headers->get('Location'),
            sprintf('%s devrait renvoyer vers la connexion.', $url)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function routesProtegees(): iterable
    {
        yield 'espace candidat' => ['/espace-candidat'];
        yield 'espace conseiller' => ['/conseiller'];
        yield 'espace agent' => ['/accueil'];
        yield 'administration' => ['/admin/candidatures'];
        yield 'jury central' => ['/admin/jury/eligibilite'];
        yield 'tableau de bord' => ['/tableau-de-bord'];
    }

    /**
     * Les pages publiques restent ouvertes : sans elles, aucun candidat ne
     * pourrait entrer dans le parcours.
     *
     * @dataProvider routesPubliques
     */
    public function testLesRoutesPubliquesRestentOuvertes(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful(sprintf('%s doit rester publique.', $url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function routesPubliques(): iterable
    {
        yield 'connexion' => ['/connexion'];
        yield 'inscription' => ['/inscription'];
    }

    /**
     * security.yaml ouvre ^/resultats au public, mais aucune route ne
     * l'implémente : la consultation publique des résultats par numéro de
     * dossier reste à écrire. Le test fige l'état réel pour que l'arrivée de
     * la fonctionnalité soit un changement visible, pas un effet de bord.
     */
    public function testLaConsultationPubliqueDesResultatsNEstPasEncoreOuverte(): void
    {
        $this->client->request('GET', '/resultats');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Cloisonnement des espaces : chaque rôle est refoulé de ceux des autres.
     *
     * @dataProvider croisementsInterdits
     */
    public function testUnRoleNAccedePasALEspaceDUnAutre(string $role, string $url): void
    {
        $referentiel = $this->atelier->referentiel();
        $utilisateur = $this->atelier->utilisateur('cloison', $role, $referentiel['centre']);

        $this->client->loginUser($utilisateur);
        $this->client->request('GET', $url);

        self::assertResponseRedirects(
            '/tableau-de-bord',
            null,
            sprintf('%s ne doit pas atteindre %s.', $role, $url)
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function croisementsInterdits(): iterable
    {
        yield 'candidat vers conseiller' => [Role::CANDIDAT, '/conseiller'];
        yield 'candidat vers administration' => [Role::CANDIDAT, '/admin/candidatures'];
        yield 'conseiller vers jury central' => [Role::CONSEILLER, '/admin/jury/eligibilite'];
        yield 'conseiller vers espace candidat' => [Role::CONSEILLER, '/espace-candidat'];
        yield 'agent accueil vers conseiller' => [Role::AGENT_ACCUEIL, '/conseiller'];
        yield 'agent accueil vers indicateurs' => [Role::AGENT_ACCUEIL, '/indicateurs'];
    }

    /**
     * L'administrateur hérite de tous les rôles : il atteint les espaces
     * métier sans y être explicitement rattaché.
     */
    public function testLAdministrateurAtteintLesEspacesMetier(): void
    {
        $administrateur = $this->atelier->utilisateur('admin', Role::ADMIN);

        $this->client->loginUser($administrateur);
        $this->client->request('GET', '/admin/candidatures');

        self::assertResponseIsSuccessful();
    }

    /**
     * Périmètre du voter, énoncé cas par cas. C'est la seule source de vérité
     * des droits sur un dossier : les contrôleurs et les gabarits s'y réfèrent.
     */
    public function testLeVoterCloisonneLesDroitsSurUnDossier(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $autreCandidat = $this->atelier->utilisateur('candidat2', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);
        $conseillerAilleurs = $this->atelier->utilisateur('conseiller-b', Role::CONSEILLER, $referentiel['centreAutre']);
        $administrateur = $this->atelier->utilisateur('admin', Role::ADMIN);

        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        // Le candidat propriétaire : voit, modifie et paie.
        self::assertTrue($this->autorise($candidat, CandidatureVoter::VIEW, $dossier));
        self::assertTrue($this->autorise($candidat, CandidatureVoter::EDIT, $dossier));
        self::assertTrue($this->autorise($candidat, CandidatureVoter::PAY, $dossier));

        // Un autre candidat n'a aucun droit sur le dossier d'autrui.
        self::assertFalse($this->autorise($autreCandidat, CandidatureVoter::VIEW, $dossier));
        self::assertFalse($this->autorise($autreCandidat, CandidatureVoter::PAY, $dossier));

        // Le conseiller du centre voit et évalue ; celui d'un autre centre, non.
        self::assertTrue($this->autorise($conseiller, CandidatureVoter::VIEW, $dossier));
        self::assertTrue($this->autorise($conseiller, CandidatureVoter::EVALUATE_RECEVABILITE, $dossier));
        self::assertFalse($this->autorise($conseillerAilleurs, CandidatureVoter::VIEW, $dossier));
        self::assertFalse($this->autorise($conseillerAilleurs, CandidatureVoter::EVALUATE_RECEVABILITE, $dossier));

        // L'administrateur a tout, sauf le paiement : seul le candidat règle
        // ses propres frais.
        self::assertTrue($this->autorise($administrateur, CandidatureVoter::VIEW, $dossier));
        self::assertTrue($this->autorise($administrateur, CandidatureVoter::DELETE, $dossier));
        self::assertFalse($this->autorise($administrateur, CandidatureVoter::PAY, $dossier));
    }

    /**
     * L'évaluation devant jury exige le règlement préalable des frais d'examen.
     */
    public function testLAdmissibiliteExigeLesFraisDExamen(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);

        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            rec: StatutRecevabilite::RECEVABLE->value,
            elig: CandidatureStatusResolver::DECISION_POSITIVE,
            fraisDossierRegles: true
        );

        self::assertFalse(
            $this->autorise($conseiller, CandidatureVoter::EVALUATE_ADMISSIBILITE, $dossier),
            'Sans frais d\'examen réglés, l\'évaluation devant jury doit être refusée.'
        );

        $this->atelier->paiementReussi($dossier, TypeFrais::EXAMEN);
        $this->entityManager->refresh($dossier);

        self::assertTrue($this->autorise($conseiller, CandidatureVoter::EVALUATE_ADMISSIBILITE, $dossier));
    }

    /**
     * Un dossier dont l'étude est rendue n'est plus modifiable, même s'il reste
     * PREINSCRIT en attendant le paiement. C'est plus strict que « statut =
     * PREINSCRIT » : le voter interroge estEnAttenteEtude().
     */
    public function testUneEtudeRendueFigeLeDossier(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);

        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value
        );

        $resolver = static::getContainer()->get(CandidatureStatusResolver::class);

        self::assertSame(
            StatutCandidature::PREINSCRIT,
            $resolver->resolve($dossier),
            'Une étude acceptée ne fait pas à elle seule avancer le statut.'
        );
        self::assertFalse(
            $this->autorise($candidat, CandidatureVoter::EDIT, $dossier),
            'Le dossier doit être figé dès que l\'étude est rendue.'
        );
    }

    /**
     * La fiche d'inscription n'est éditable qu'une fois l'inscription acquise.
     */
    public function testLaFicheNEstImprimableQuUneFoisInscrit(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);

        $preinscrit = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);
        self::assertFalse($this->autorise($candidat, CandidatureVoter::PRINT_FICHE, $preinscrit));

        $autre = $this->atelier->utilisateur('candidat2', Role::CANDIDAT);
        $inscrit = $this->atelier->candidature(
            $autre,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            fraisDossierRegles: true
        );

        self::assertTrue($this->autorise($autre, CandidatureVoter::PRINT_FICHE, $inscrit));
    }

    /**
     * Une décision hors du chemin autorisé est refusée par la machine à états.
     */
    public function testUneTransitionNonAutoriseeEstRefusee(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $transition = static::getContainer()->get(TransitionCandidature::class);

        $this->expectException(TransitionInterditeException::class);

        // PREINSCRIT ne mène qu'à INSCRIT, et l'inscription ne se décide pas.
        $transition->appliquer($dossier, StatutCandidature::ELIGIBLE, $candidat, 'test');
    }

    /**
     * L'inscription n'est pas une décision : elle découle du paiement.
     */
    public function testLInscriptionNeSeDecidePas(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $dossier = $this->atelier->candidature($candidat, $referentiel['centre'], $referentiel['metier']);

        $transition = static::getContainer()->get(TransitionCandidature::class);

        self::assertFalse($transition->peutAppliquer($dossier, StatutCandidature::INSCRIT));
    }

    /**
     * Chaque décision écrit une ligne d'audit : statut avant, statut après,
     * auteur et motif (R1.3).
     */
    public function testChaqueDecisionEcritUneLigneDAudit(): void
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

        $transition = static::getContainer()->get(TransitionCandidature::class);
        $transition->appliquer(
            $dossier,
            StatutCandidature::DOSSIER_RECEVABLE,
            $conseiller,
            'Recevabilité : Recevable'
        );

        /** @var HistoriqueStatutRepository $historiques */
        $historiques = static::getContainer()->get(HistoriqueStatutRepository::class);
        $lignes = $historiques->findPourCandidature($dossier);

        self::assertNotEmpty($lignes, 'La décision doit laisser une trace.');

        $derniere = $lignes[0];
        $attendues = array_map(
            static fn ($ligne) => [$ligne->getStatutAvant(), $ligne->getStatutApres()],
            $lignes
        );

        self::assertContains(
            [StatutCandidature::INSCRIT, StatutCandidature::DOSSIER_RECEVABLE],
            $attendues,
            'L\'audit doit porter le statut avant et après la décision.'
        );
        self::assertNotNull($derniere->getCandidature());
    }

    /**
     * Rejouer la même décision ne produit ni erreur ni doublon d'audit.
     */
    public function testUneDecisionRejoueeEstSansEffet(): void
    {
        $referentiel = $this->atelier->referentiel();
        $candidat = $this->atelier->utilisateur('candidat', Role::CANDIDAT);
        $conseiller = $this->atelier->utilisateur('conseiller', Role::CONSEILLER, $referentiel['centre']);

        $dossier = $this->atelier->candidature(
            $candidat,
            $referentiel['centre'],
            $referentiel['metier'],
            etu: StatutEtude::ACCEPTE->value,
            rec: StatutRecevabilite::RECEVABLE->value,
            fraisDossierRegles: true
        );

        $transition = static::getContainer()->get(TransitionCandidature::class);
        /** @var HistoriqueStatutRepository $historiques */
        $historiques = static::getContainer()->get(HistoriqueStatutRepository::class);

        $avant = count($historiques->findPourCandidature($dossier));
        $transition->appliquer($dossier, StatutCandidature::DOSSIER_RECEVABLE, $conseiller, 'rejeu');

        self::assertCount(
            $avant,
            $historiques->findPourCandidature($dossier),
            'Réappliquer la décision déjà rendue ne doit rien journaliser.'
        );
    }

    private function autorise(object $utilisateur, string $attribut, Candidature $dossier): bool
    {
        $conteneur = static::getContainer();
        $conteneur->get('security.token_storage')->setToken(
            new UsernamePasswordToken($utilisateur, 'main', $utilisateur->getRoles())
        );

        /** @var AuthorizationCheckerInterface $checker */
        $checker = $conteneur->get('security.authorization_checker');

        return $checker->isGranted($attribut, $dossier);
    }
}
