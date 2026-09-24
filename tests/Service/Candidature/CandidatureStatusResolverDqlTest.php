<?php

namespace App\Tests\Service\Candidature;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Repository\CandidatureRepository;
use App\Service\Candidature\CandidatureStatusResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Concordance entre les deux énoncés de la même règle :
 * CandidatureStatusResolver::resolve() (PHP) et expressionDql() (base).
 *
 * Toutes les combinaisons de décisions et de règlements sont écrites dans la
 * base de test ; chaque dossier doit recevoir le même statut des deux côtés.
 */
final class CandidatureStatusResolverDqlTest extends KernelTestCase
{
    private const PREFIXE = 'TSTRES';

    private EntityManagerInterface $entityManager;
    private Connection $connexion;
    private CandidatureStatusResolver $resolver;
    private int $centreId;
    private int $metierId;

    protected function setUp(): void
    {
        self::bootKernel();
        $conteneur = static::getContainer();

        $this->entityManager = $conteneur->get(EntityManagerInterface::class);
        $this->connexion = $this->entityManager->getConnection();
        $this->resolver = $conteneur->get(CandidatureStatusResolver::class);

        $this->nettoyer();
        $this->preparerReferentiel();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testPhpEtDqlDonnentLeMemeStatut(): void
    {
        $decisions = [null, 0, 1, 2];
        $juryDecisions = [null, 1, 2];
        $reglements = [
            'aucun' => [],
            'dossier réussi' => [['dossier', 'reussi']],
            'dossier en attente' => [['dossier', 'en_attente']],
            'examen réussi seul' => [['examen', 'reussi']],
        ];

        $sequence = 0;
        foreach ($decisions as $etu) {
            foreach ($decisions as $rec) {
                foreach ($juryDecisions as $elig) {
                    foreach ($juryDecisions as $resultat) {
                        foreach ($juryDecisions as $admis) {
                            foreach ($reglements as $paiements) {
                                $this->inserer(++$sequence, $etu, $rec, $elig, $resultat, $admis, $paiements);
                            }
                        }
                    }
                }
            }
        }

        $parDql = [];
        $lignes = $this->entityManager->createQuery(sprintf(
            'SELECT c.numero AS numero, %s AS statut FROM %s c WHERE c.numero LIKE :prefixe',
            $this->resolver->expressionDql('c'),
            Candidature::class
        ))->setParameter('prefixe', self::PREFIXE . '%')->getScalarResult();

        foreach ($lignes as $ligne) {
            $parDql[$ligne['numero']] = (int) $ligne['statut'];
        }

        $this->entityManager->clear();
        $candidatures = $this->entityManager->getRepository(Candidature::class)->createQueryBuilder('c')
            ->andWhere('c.numero LIKE :prefixe')
            ->setParameter('prefixe', self::PREFIXE . '%')
            ->getQuery()
            ->getResult();

        self::assertCount($sequence, $candidatures);
        self::assertCount($sequence, $parDql);

        $ecarts = [];
        foreach ($candidatures as $candidature) {
            $php = $this->resolver->resolve($candidature)->value;
            if ($php !== $parDql[$candidature->getNumero()]) {
                $ecarts[] = sprintf('%s : PHP %d, DQL %d', $candidature->getNumero(), $php, $parDql[$candidature->getNumero()]);
            }
        }

        self::assertSame([], $ecarts, 'resolve() et expressionDql() divergent.');

        // conditionDql() : même règle, sous forme d'appartenance à un ensemble.
        $ensemble = [StatutCandidature::INSCRIT, StatutCandidature::ELIGIBLE, StatutCandidature::NON_ADMIS_DEFINITIF];
        $attendus = [];
        foreach ($candidatures as $candidature) {
            $attendus[$candidature->getNumero()] = in_array($this->resolver->resolve($candidature), $ensemble, true);
        }

        foreach ([false, true] as $exclure) {
            $numeros = array_column($this->entityManager->createQuery(sprintf(
                'SELECT c.numero AS numero FROM %s c WHERE c.numero LIKE :prefixe AND %s',
                Candidature::class,
                $this->resolver->conditionDql('c', $ensemble, $exclure)
            ))->setParameter('prefixe', self::PREFIXE . '%')->getScalarResult(), 'numero');

            $attenduPourCeSens = array_keys(array_filter($attendus, static fn (bool $dedans): bool => $dedans !== $exclure));
            sort($numeros);
            sort($attenduPourCeSens);

            self::assertSame($attenduPourCeSens, $numeros, sprintf('conditionDql(exclure: %s) diverge de resolve().', var_export($exclure, true)));
        }
    }

    /**
     * Chaque requête du repository qui filtre, compte ou regroupe par statut
     * doit rester exécutable : l'expression y est injectée dans des WHERE,
     * IN, NOT IN et GROUP BY.
     */
    public function testLesRequetesDuRepositoryUtilisantLeStatutSExecutent(): void
    {
        /** @var CandidatureRepository $repository */
        $repository = $this->entityManager->getRepository(Candidature::class);
        $centre = $this->entityManager->getReference(Centre::class, 0);
        $utilisateur = $this->entityManager->getReference(User::class, 0);

        $this->inserer(1, 2, null, null, null, null, [['dossier', 'reussi']]);
        $this->inserer(2, 2, 2, 2, null, null, [['dossier', 'reussi']]);

        $parStatut = $repository->effectifsParStatut();
        self::assertGreaterThanOrEqual(1, $parStatut[StatutCandidature::INSCRIT->value] ?? 0);
        self::assertGreaterThanOrEqual(1, $parStatut[StatutCandidature::ELIGIBLE->value] ?? 0);

        self::assertContains(
            self::PREFIXE . '000002',
            array_map(static fn (Candidature $c): ?string => $c->getNumero(), $repository->findParStatuts([StatutCandidature::ELIGIBLE]))
        );

        $repository->effectifsParCentreEtStatut();
        $repository->compteursConseiller($centre, $utilisateur);
        $repository->compterNonAffectees($centre);
        $repository->compterEnChargePourConseiller($utilisateur);
        $repository->compterActivesPourCandidat($utilisateur);
        $repository->findActivePourCandidat($utilisateur);
        $repository->findInscritesDuCentre($centre);
        $repository->findInscritesDuConseiller($utilisateur);
        $repository->queryListeDuCentre($centre, null, StatutCandidature::INSCRIT->value)->getResult();
        $repository->queryListeNationale(null, null, [StatutCandidature::INSCRIT, StatutCandidature::ELIGIBLE], CandidatureRepository::ETUDE_ACCEPTEE)->getResult();
        $repository->queryListeConseiller($centre, null, StatutCandidature::PREINSCRIT->value, null, null, null, CandidatureRepository::ETUDE_A_RENDRE)->getResult();

        self::addToAssertionCount(1);
    }

    /**
     * @param list<array{string, string}> $paiements type de frais, statut
     */
    private function inserer(int $sequence, ?int $etu, ?int $rec, ?int $elig, ?int $resultat, ?int $admis, array $paiements): void
    {
        $this->connexion->insert('candidature', [
            'numero' => sprintf('%s%06d', self::PREFIXE, $sequence),
            'centre_id' => $this->centreId,
            'metier_id' => $this->metierId,
            'etu_statut' => $etu,
            'rec_statut' => $rec,
            'elig_statut' => $elig,
            'resultat' => $resultat,
            'admis' => $admis,
        ]);
        $id = (int) $this->connexion->lastInsertId();

        foreach ($paiements as [$type, $statut]) {
            $this->connexion->insert('paiement', [
                'candidature_id' => $id,
                'type_frais' => $type,
                'statut_paiement' => $statut,
                'montant' => '10000.00',
            ]);
        }
    }

    /**
     * Centre et métier réels, que candidature référence.
     *
     * Les colonnes centre_id et metier_id portent une contrainte de clé
     * étrangère : un identifiant fictif, toléré tant que la table était en
     * MyISAM, est désormais rejeté par la base.
     */
    private function preparerReferentiel(): void
    {
        $this->connexion->insert('direction_regionale', ['libelle' => self::PREFIXE . ' direction']);

        $this->connexion->insert('localite', [
            'libelle' => self::PREFIXE . ' localite',
            'direction_regionale_id' => (int) $this->connexion->lastInsertId(),
        ]);

        $this->connexion->insert('centre', [
            'nom' => self::PREFIXE . ' centre',
            'type' => 'etablissement',
            'localite_id' => (int) $this->connexion->lastInsertId(),
        ]);
        $this->centreId = (int) $this->connexion->lastInsertId();

        $this->connexion->insert('filiere', ['libelle' => self::PREFIXE . ' filiere']);

        $this->connexion->insert('metier', [
            'libelle' => self::PREFIXE . ' metier',
            'filiere_id' => (int) $this->connexion->lastInsertId(),
        ]);
        $this->metierId = (int) $this->connexion->lastInsertId();
    }

    private function nettoyer(): void
    {
        $this->connexion->executeStatement(
            'DELETE p FROM paiement p JOIN candidature c ON c.id = p.candidature_id WHERE c.numero LIKE ?',
            [self::PREFIXE . '%']
        );
        $this->connexion->executeStatement('DELETE FROM candidature WHERE numero LIKE ?', [self::PREFIXE . '%']);

        // Après les candidatures qui les référencent, et dans l'ordre inverse
        // des dépendances : les contraintes refusent toute autre séquence.
        $this->connexion->executeStatement('DELETE FROM metier WHERE libelle LIKE ?', [self::PREFIXE . '%']);
        $this->connexion->executeStatement('DELETE FROM filiere WHERE libelle LIKE ?', [self::PREFIXE . '%']);
        $this->connexion->executeStatement('DELETE FROM centre WHERE nom LIKE ?', [self::PREFIXE . '%']);
        $this->connexion->executeStatement('DELETE FROM localite WHERE libelle LIKE ?', [self::PREFIXE . '%']);
        $this->connexion->executeStatement('DELETE FROM direction_regionale WHERE libelle LIKE ?', [self::PREFIXE . '%']);
    }
}
