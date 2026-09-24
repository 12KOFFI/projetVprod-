<?php

namespace App\Tests\Service\Candidature;

use App\Entity\Candidature;
use App\Entity\HistoriqueStatut;
use App\Enum\StatutCandidature as S;
use App\Exception\TransitionInterditeException;
use App\Repository\HistoriqueStatutRepository;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\TransitionCandidature;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class TransitionCandidatureTest extends TestCase
{
    private const NEGATIF = 1;
    private const POSITIF = 2;

    /** @var list<HistoriqueStatut> */
    private array $journal = [];
    private bool $inscriptionDejaJournalisee = false;
    private TransitionCandidature $transition;

    protected function setUp(): void
    {
        $this->journal = [];
        $this->inscriptionDejaJournalisee = false;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entite): void {
            if ($entite instanceof HistoriqueStatut) {
                $this->journal[] = $entite;
            }
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $historiques = $this->createMock(HistoriqueStatutRepository::class);
        $historiques->method('aJournaliseArrivee')->willReturnCallback(fn (): bool => $this->inscriptionDejaJournalisee);

        $this->transition = new TransitionCandidature(
            $entityManager,
            $dispatcher,
            new RequestStack(),
            new NullLogger(),
            new CandidatureStatusResolver(),
            $historiques,
        );
    }

    /**
     * @dataProvider decisions
     *
     * @param callable(Candidature): ?int $lecture
     */
    public function testChaqueDecisionEcritSaColonne(Candidature $candidature, S $cible, callable $lecture, int $attendu): void
    {
        $this->transition->appliquer($candidature, $cible);

        self::assertSame($attendu, $lecture($candidature));
        self::assertSame($cible, (new CandidatureStatusResolver())->resolve($candidature));
        self::assertCount(1, $this->journal);
        self::assertSame($cible, $this->journal[0]->getStatutApres());
    }

    /** @return iterable<string, array{Candidature, S, callable(Candidature): ?int, int}> */
    public static function decisions(): iterable
    {
        $inscrit = static fn (): Candidature => FabriqueCandidature::avec(etu: self::POSITIF, fraisDossierRegles: true);
        $recevable = static fn (): Candidature => FabriqueCandidature::avec(etu: self::POSITIF, rec: self::POSITIF, fraisDossierRegles: true);
        $eligible = static fn (): Candidature => FabriqueCandidature::avec(etu: self::POSITIF, rec: self::POSITIF, elig: self::POSITIF, fraisDossierRegles: true);
        $admissible = static fn (): Candidature => FabriqueCandidature::avec(etu: self::POSITIF, rec: self::POSITIF, elig: self::POSITIF, resultat: self::POSITIF, fraisDossierRegles: true);

        $rec = static fn (Candidature $c): ?int => $c->getRecStatut();
        $elig = static fn (Candidature $c): ?int => $c->getEligStatut();
        $resultat = static fn (Candidature $c): ?int => $c->getResultat();
        $admis = static fn (Candidature $c): ?int => $c->getAdmis();

        yield 'recevable' => [$inscrit(), S::DOSSIER_RECEVABLE, $rec, self::POSITIF];
        yield 'non recevable' => [$inscrit(), S::DOSSIER_NON_RECEVABLE, $rec, self::NEGATIF];
        yield 'éligible' => [$recevable(), S::ELIGIBLE, $elig, self::POSITIF];
        yield 'non éligible' => [$recevable(), S::NON_ELIGIBLE, $elig, self::NEGATIF];
        yield 'admissible' => [$eligible(), S::ADMISSIBLE, $resultat, self::POSITIF];
        yield 'non admissible' => [$eligible(), S::NON_ADMISSIBLE, $resultat, self::NEGATIF];
        yield 'admis définitif' => [$admissible(), S::ADMIS_DEFINITIF, $admis, self::POSITIF];
        yield 'non admis définitif' => [$admissible(), S::NON_ADMIS_DEFINITIF, $admis, self::NEGATIF];
    }

    public function testLAdmissionNeTouchePasAResultat(): void
    {
        foreach ([S::ADMIS_DEFINITIF, S::NON_ADMIS_DEFINITIF] as $cible) {
            $candidature = FabriqueCandidature::avec(etu: self::POSITIF, rec: self::POSITIF, elig: self::POSITIF, resultat: self::POSITIF, fraisDossierRegles: true);

            $this->transition->appliquer($candidature, $cible);

            // resultat reste une décision d'admissibilité : jamais 3 ni 4.
            self::assertSame(self::POSITIF, $candidature->getResultat());
        }
    }

    public function testUneDecisionHorsEtapeEstRefusee(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF, fraisDossierRegles: true);

        $this->expectException(TransitionInterditeException::class);

        try {
            $this->transition->appliquer($candidature, S::ELIGIBLE);
        } finally {
            self::assertNull($candidature->getEligStatut());
            self::assertSame([], $this->journal);
        }
    }

    public function testLInscriptionNeSeDecidePas(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF);

        self::assertFalse($this->transition->peutAppliquer($candidature, S::INSCRIT));

        $this->expectException(TransitionInterditeException::class);
        $this->transition->appliquer($candidature, S::INSCRIT);
    }

    public function testReappliquerLaMemeDecisionNeProduitRien(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF, rec: self::POSITIF, fraisDossierRegles: true);

        $this->transition->appliquer($candidature, S::DOSSIER_RECEVABLE);

        self::assertSame([], $this->journal);
    }

    public function testConstaterInscriptionJournaliseUneSeuleFois(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF, fraisDossierRegles: true);

        self::assertTrue($this->transition->constaterInscription($candidature));
        self::assertCount(1, $this->journal);
        self::assertSame(S::PREINSCRIT, $this->journal[0]->getStatutAvant());
        self::assertSame(S::INSCRIT, $this->journal[0]->getStatutApres());

        $this->inscriptionDejaJournalisee = true;
        self::assertFalse($this->transition->constaterInscription($candidature));
        self::assertCount(1, $this->journal);
    }

    public function testConstaterInscriptionSansReglementNeJournaliseRien(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::POSITIF);

        self::assertFalse($this->transition->constaterInscription($candidature));
        self::assertSame([], $this->journal);
    }
}
