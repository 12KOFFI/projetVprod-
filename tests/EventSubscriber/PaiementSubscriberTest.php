<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Candidature;
use App\Entity\HistoriqueStatut;
use App\Enum\StatutCandidature;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use App\Event\PaiementReussiEvent;
use App\EventSubscriber\PaiementSubscriber;
use App\Repository\HistoriqueStatutRepository;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\TransitionCandidature;
use App\Tests\Service\Candidature\FabriqueCandidature;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PaiementSubscriberTest extends TestCase
{
    private const ACCEPTE = 2;

    /** @var list<HistoriqueStatut> */
    private array $journal = [];
    private PaiementSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->journal = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entite): void {
            if ($entite instanceof HistoriqueStatut) {
                $this->journal[] = $entite;
            }
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        // Comme en base : l'inscription est « déjà journalisée » dès qu'une
        // ligne PREINSCRIT → INSCRIT a été écrite.
        $historiques = $this->createMock(HistoriqueStatutRepository::class);
        $historiques->method('aJournaliseArrivee')->willReturnCallback(
            fn (Candidature $c, StatutCandidature $s): bool => array_filter(
                $this->journal,
                static fn (HistoriqueStatut $h): bool => $h->getStatutApres() === $s && $h->getStatutAvant() !== $s
            ) !== []
        );

        $transition = new TransitionCandidature(
            $entityManager,
            $dispatcher,
            new RequestStack(),
            new NullLogger(),
            new CandidatureStatusResolver(),
            $historiques,
        );

        $this->subscriber = new PaiementSubscriber($transition, new NullLogger());
    }

    public function testLeReglementDesFraisDeDossierNeModifieAucuneDecision(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::ACCEPTE);
        $paiement = FabriqueCandidature::ajouterPaiement($candidature, TypeFrais::DOSSIER, StatutPaiement::REUSSI);

        $this->subscriber->onPaiementReussi(new PaiementReussiEvent($paiement));

        self::assertSame(self::ACCEPTE, $candidature->getEtuStatut());
        self::assertNull($candidature->getRecStatut());
        self::assertNull($candidature->getEligStatut());
        self::assertNull($candidature->getResultat());
        self::assertNull($candidature->getAdmis());
        self::assertSame(StatutCandidature::INSCRIT, (new CandidatureStatusResolver())->resolve($candidature));
    }

    public function testLInscriptionEstJournaliseeUneSeuleFois(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::ACCEPTE);
        $paiement = FabriqueCandidature::ajouterPaiement($candidature, TypeFrais::DOSSIER, StatutPaiement::REUSSI);
        $evenement = new PaiementReussiEvent($paiement);

        $this->subscriber->onPaiementReussi($evenement);
        // Webhook rejoué.
        $this->subscriber->onPaiementReussi($evenement);

        self::assertCount(1, $this->journal);
        self::assertSame(StatutCandidature::INSCRIT, $this->journal[0]->getStatutApres());
    }

    public function testLesAutresFraisNeJournalisentRien(): void
    {
        $candidature = FabriqueCandidature::avec(etu: self::ACCEPTE, rec: 2, elig: 2, fraisDossierRegles: true);

        foreach ([TypeFrais::ACCOMPAGNEMENT, TypeFrais::EXAMEN] as $type) {
            $paiement = FabriqueCandidature::ajouterPaiement($candidature, $type, StatutPaiement::REUSSI);
            $this->subscriber->onPaiementReussi(new PaiementReussiEvent($paiement));
        }

        self::assertSame([], $this->journal);
        self::assertSame(StatutCandidature::ELIGIBLE, (new CandidatureStatusResolver())->resolve($candidature));
    }
}
