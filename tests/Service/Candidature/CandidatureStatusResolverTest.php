<?php

namespace App\Tests\Service\Candidature;

use App\Entity\Candidature;
use App\Enum\StatutCandidature as S;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use App\Service\Candidature\CandidatureStatusResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CandidatureStatusResolverTest extends TestCase
{
    // Conventions du projet (StatutEtude, StatutRecevabilite, colonnes 1/2).
    private const EN_ATTENTE = 0;
    private const NEGATIF = 1;
    private const POSITIF = 2;

    private CandidatureStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CandidatureStatusResolver();
    }

    /**
     * @dataProvider casElementaires
     * @dataProvider combinaisons
     */
    public function testResolve(Candidature $candidature, S $attendu): void
    {
        self::assertSame($attendu, $this->resolver->resolve($candidature));
    }

    /** @return iterable<string, array{Candidature, S}> */
    public static function casElementaires(): iterable
    {
        yield 'étude non rendue' => [FabriqueCandidature::avec(), S::PREINSCRIT];
        yield 'étude refusée' => [FabriqueCandidature::avec(etu: self::NEGATIF), S::PREINSCRIT];
        yield 'étude acceptée, frais non réglés' => [FabriqueCandidature::avec(etu: self::POSITIF), S::PREINSCRIT];
        yield 'étude acceptée, frais réglés' => [FabriqueCandidature::avec(etu: self::POSITIF, fraisDossierRegles: true), S::INSCRIT];
        yield 'non recevable' => [FabriqueCandidature::avec(etu: self::POSITIF, rec: self::NEGATIF, fraisDossierRegles: true), S::DOSSIER_NON_RECEVABLE];
        yield 'recevable' => [FabriqueCandidature::avec(etu: self::POSITIF, rec: self::POSITIF, fraisDossierRegles: true), S::DOSSIER_RECEVABLE];
        yield 'non éligible' => [FabriqueCandidature::avec(elig: self::NEGATIF), S::NON_ELIGIBLE];
        yield 'éligible' => [FabriqueCandidature::avec(elig: self::POSITIF), S::ELIGIBLE];
        yield 'non admissible' => [FabriqueCandidature::avec(resultat: self::NEGATIF), S::NON_ADMISSIBLE];
        yield 'admissible' => [FabriqueCandidature::avec(resultat: self::POSITIF), S::ADMISSIBLE];
        yield 'non admis' => [FabriqueCandidature::avec(admis: self::NEGATIF), S::NON_ADMIS_DEFINITIF];
        yield 'admis' => [FabriqueCandidature::avec(admis: self::POSITIF), S::ADMIS_DEFINITIF];
    }

    /** @return iterable<string, array{Candidature, S}> */
    public static function combinaisons(): iterable
    {
        $parcours = static fn (?int $resultat = null, ?int $admis = null): Candidature => FabriqueCandidature::avec(
            etu: self::POSITIF,
            rec: self::POSITIF,
            elig: self::POSITIF,
            resultat: $resultat,
            admis: $admis,
            fraisDossierRegles: true,
        );

        yield 'éligible, admissibilité non décidée' => [$parcours(), S::ELIGIBLE];
        yield 'admissible, admission non décidée' => [$parcours(resultat: self::POSITIF), S::ADMISSIBLE];
        yield 'admis définitif' => [$parcours(resultat: self::POSITIF, admis: self::POSITIF), S::ADMIS_DEFINITIF];
        yield 'non admis définitif' => [$parcours(resultat: self::POSITIF, admis: self::NEGATIF), S::NON_ADMIS_DEFINITIF];

        // Une étape plus avancée l'emporte toujours sur les précédentes.
        yield 'éligibilité sans recevabilité saisie' => [FabriqueCandidature::avec(elig: self::POSITIF, fraisDossierRegles: true), S::ELIGIBLE];
        yield 'recevabilité en attente (0), frais réglés' => [FabriqueCandidature::avec(etu: self::POSITIF, rec: self::EN_ATTENTE, fraisDossierRegles: true), S::INSCRIT];
        yield 'étude en attente (0), frais réglés' => [FabriqueCandidature::avec(etu: self::EN_ATTENTE, fraisDossierRegles: true), S::PREINSCRIT];
        yield 'étude refusée, frais réglés' => [FabriqueCandidature::avec(etu: self::NEGATIF, fraisDossierRegles: true), S::PREINSCRIT];
    }

    public function testUnReglementNonAbouti(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF);
        FabriqueCandidature::ajouterPaiement($candidature, TypeFrais::DOSSIER, StatutPaiement::EN_ATTENTE);
        FabriqueCandidature::ajouterPaiement($candidature, TypeFrais::DOSSIER, StatutPaiement::ECHOUE);

        self::assertSame(S::PREINSCRIT, $this->resolver->resolve($candidature));
    }

    public function testSeulsLesFraisDeDossierInscrivent(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF);
        FabriqueCandidature::ajouterPaiement($candidature, TypeFrais::EXAMEN, StatutPaiement::REUSSI);

        self::assertSame(S::PREINSCRIT, $this->resolver->resolve($candidature));
    }

    public function testResolveNeModifieRien(): void
    {
        $candidature = FabriqueCandidature::avec(
            etu: self::POSITIF,
            rec: self::POSITIF,
            elig: self::POSITIF,
            fraisDossierRegles: true,
        );
        $avant = $this->instantane($candidature);

        $this->resolver->resolve($candidature);
        $this->resolver->estEnAttenteEtude($candidature);
        $this->resolver->estAccepteeEnAttentePaiement($candidature);

        self::assertSame($avant, $this->instantane($candidature));
    }

    public function testPredicatsEtude(): void
    {
        $aEtudier = FabriqueCandidature::avec();
        $refusee = FabriqueCandidature::avec(etu: self::NEGATIF);
        $accepteeNonPayee = FabriqueCandidature::avec(etu: self::POSITIF);
        $inscrite = FabriqueCandidature::avec(etu: self::POSITIF, fraisDossierRegles: true);

        self::assertTrue($this->resolver->estEnAttenteEtude($aEtudier));
        self::assertTrue($this->resolver->estEnAttenteEtude($refusee));
        self::assertFalse($this->resolver->estEnAttenteEtude($accepteeNonPayee));
        self::assertFalse($this->resolver->estEnAttenteEtude($inscrite));

        self::assertTrue($this->resolver->estAccepteeEnAttentePaiement($accepteeNonPayee));
        self::assertFalse($this->resolver->estAccepteeEnAttentePaiement($inscrite));
        self::assertFalse($this->resolver->estAccepteeEnAttentePaiement($refusee));
    }

    /** @return array<string, mixed> */
    private function instantane(Candidature $candidature): array
    {
        return [
            'etu' => $candidature->getEtuStatut(),
            'rec' => $candidature->getRecStatut(),
            'elig' => $candidature->getEligStatut(),
            'resultat' => $candidature->getResultat(),
            'admis' => $candidature->getAdmis(),
            'paiements' => array_map(
                static fn ($p): array => [$p->getTypeFrais(), $p->getStatutPaiement()],
                $candidature->getPaiements()->toArray()
            ),
        ];
    }
}
