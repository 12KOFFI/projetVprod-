<?php

namespace App\Repository;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\Metier;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\StatutRecevabilite;
use App\Security\Role;
use App\Service\Candidature\CandidatureStatusResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Candidature>
 *
 * Ce repository porte TOUS les filtres de périmètre (centre, candidat,
 * accompagnateur). Aucun contrôleur ni template ne doit refaire ce filtrage :
 * une donnée hors périmètre ne doit jamais atteindre la couche de présentation.
 *
 * Le statut global n'étant pas stocké, tout filtre, comptage ou regroupement
 * par statut passe par l'expression DQL de CandidatureStatusResolver
 * (statut()), jamais par une règle réécrite ici.
 */
class CandidatureRepository extends ServiceEntityRepository
{
    /**
     * Préinscrits dont l'étude reste à rendre (jamais étudiés ou refusés).
     */
    public const ETUDE_A_RENDRE = 'a_rendre';

    /**
     * Préinscrits dont l'étude est acceptée, en attente des frais de dossier.
     */
    public const ETUDE_ACCEPTEE = 'acceptee';

    public function __construct(
        ManagerRegistry $registry,
        private readonly CandidatureStatusResolver $resolver,
    ) {
        parent::__construct($registry, Candidature::class);
    }

    /** Statut global calculé, en DQL, pour l'alias donné. */
    private function statut(string $alias = 'c'): string
    {
        return $this->resolver->expressionDql($alias);
    }

    public function save(Candidature $candidature, bool $flush = false): void
    {
        $this->getEntityManager()->persist($candidature);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByNumero(string $numero): ?Candidature
    {
        return $this->findOneBy(['numero' => $numero]);
    }

    /**
     * Constructeur de requête déjà restreint au périmètre autorisé de
     * l'utilisateur connecté. Point d'entrée unique des listes.
     */
    public function createQueryBuilderPourUtilisateur(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->orderBy('c.creation', 'DESC');

        return $this->appliquerPerimetre($qb, $user);
    }

    /**
     * Restreint une requête au périmètre de données du rôle principal
     * de l'utilisateur (règle métier R9.1).
     */
    public function appliquerPerimetre(QueryBuilder $qb, User $user): QueryBuilder
    {
        $alias = $qb->getRootAliases()[0];

        switch (Role::principal($user)) {
            case Role::ADMIN:
                // Périmètre national : aucune restriction.
                break;

            case Role::CONSEILLER:
            case Role::AGENT_ACCUEIL:
                // Un membre du personnel sans centre ne voit aucune donnée.
                $qb->andWhere($alias . '.centre = :perimetreCentre')
                   ->setParameter('perimetreCentre', $user->getCentre());
                break;

            case Role::ACCOMPAGNATEUR:
                $qb->andWhere($alias . '.accompagnateur = :perimetreAccompagnateur')
                   ->setParameter('perimetreAccompagnateur', $user);
                break;

            case Role::CANDIDAT:
                $qb->andWhere($alias . '.user = :perimetreCandidat')
                   ->setParameter('perimetreCandidat', $user);
                break;

            default:
                // Rôle non reconnu : aucun résultat, jamais tous les résultats.
                $qb->andWhere('1 = 0');
        }

        return $qb;
    }

    /**
     * Dossier en cours d'un candidat, au sens de la règle « une seule
     * candidature active » : les démarches closes par un refus n'en font pas
     * partie, puisqu'elles n'empêchent pas un nouveau dépôt.
     *
     * Pour AFFICHER le dossier d'un candidat, utiliser findDernierePourCandidat() :
     * un candidat refusé doit continuer à voir sa démarche et le motif du refus.
     */
    public function findActivePourCandidat(User $candidat): ?Candidature
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->andWhere('c.user = :candidat')
            ->andWhere($this->resolver->conditionDql('c', $this->statutsTerminauxNegatifs(), exclure: true))
            ->setParameter('candidat', $candidat)
            ->orderBy('c.creation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dernier dossier déposé par un candidat, quel qu'en soit le sort.
     *
     * C'est ce que son espace personnel présente : un candidat déclaré non
     * recevable ou non éligible doit pouvoir consulter sa démarche et le motif
     * de la décision, et non se voir annoncer qu'il n'a jamais rien déposé.
     */
    public function findDernierePourCandidat(User $candidat): ?Candidature
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->andWhere('c.user = :candidat')
            ->setParameter('candidat', $candidat)
            ->orderBy('c.creation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Liste paginée des dossiers d'un centre (écran E3.9).
     *
     * Le périmètre reste porté par le repository : le contrôleur transmet le
     * centre de l'agent, jamais un identifiant reçu de la requête.
     */
    public function queryListeDuCentre(Centre $centre, ?string $recherche = null, ?int $statut = null): \Doctrine\ORM\Query
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->andWhere('c.centre = :centre')
            ->setParameter('centre', $centre)
            ->orderBy('c.creation', 'DESC');

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('c.numero LIKE :recherche OR cand.nom LIKE :recherche OR cand.prenoms LIKE :recherche OR cand.contact LIKE :recherche')
               ->setParameter('recherche', '%' . $recherche . '%');
        }

        if ($statut !== null) {
            $qb->andWhere($this->statut() . ' = :statut')->setParameter('statut', $statut);
        }

        return $qb->getQuery();
    }

    /**
     * Liste paginée sans restriction de centre, pour l'écran Candidatures de
     * l'administration — le seul rôle dont le périmètre est national.
     *
     * Le centre est ici un FILTRE d'affichage facultatif, non un périmètre de
     * sécurité : c'est bien pourquoi cette méthode est réservée à l'admin. Pour
     * tout autre rôle, passer par createQueryBuilderPourUtilisateur().
     *
     * @param list<StatutCandidature>|null $statuts
     * @param self::ETUDE_*|null           $etude   restreint aux préinscrits selon l'étude
     */
    public function queryListeNationale(
        ?Centre $centre = null,
        ?string $recherche = null,
        ?array $statuts = null,
        ?string $etude = null,
    ): \Doctrine\ORM\Query {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->orderBy('c.creation', 'DESC');

        if ($centre !== null) {
            $qb->andWhere('c.centre = :centre')->setParameter('centre', $centre);
        }

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('c.numero LIKE :recherche OR cand.nom LIKE :recherche OR cand.prenoms LIKE :recherche OR cand.contact LIKE :recherche')
               ->setParameter('recherche', '%' . $recherche . '%');
        }

        if ($statuts !== null && $statuts !== []) {
            $qb->andWhere($this->resolver->conditionDql('c', $statuts));
        }

        if ($etude !== null) {
            $this->filtrerEtude($qb, $etude);
        }

        return $qb->getQuery();
    }

    /**
     * Restreint aux préinscrits selon l'état de leur étude : l'étude ne portant
     * pas de statut propre, c'est le couple (PREINSCRIT calculé, etu_statut) qui
     * distingue « à étudier » de « accepté, en attente de paiement ».
     */
    private function filtrerEtude(QueryBuilder $qb, string $etude): void
    {
        $qb->andWhere($this->statut() . ' = :preinscrit')
           ->setParameter('preinscrit', StatutCandidature::PREINSCRIT->value)
           ->setParameter('etudeAcceptee', StatutEtude::ACCEPTE->value);

        $qb->andWhere($etude === self::ETUDE_ACCEPTEE
            ? 'c.etuStatut = :etudeAcceptee'
            : '(c.etuStatut IS NULL OR c.etuStatut != :etudeAcceptee)');
    }

    /**
     * Dossiers du centre pour l'espace conseiller (écran E4.2).
     *
     * Le centre provient du compte connecté, jamais de la requête : le
     * périmètre reste porté par le repository (règle métier R4.2).
     */
    public function queryListeConseiller(
        Centre $centre,
        ?string $recherche = null,
        ?int $statut = null,
        ?User $conseiller = null,
        ?bool $nonAffectees = null,
        ?int $etuStatut = null,
        ?string $etude = null,
    ): \Doctrine\ORM\Query {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('c.conseiller', 'cons')->addSelect('cons')
            ->andWhere('c.centre = :centre')
            ->setParameter('centre', $centre)
            ->orderBy('c.creation', 'ASC');

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('c.numero LIKE :recherche OR cand.nom LIKE :recherche OR cand.prenoms LIKE :recherche OR cand.contact LIKE :recherche')
               ->setParameter('recherche', '%' . $recherche . '%');
        }

        if ($statut !== null) {
            $qb->andWhere($this->statut() . ' = :statut')->setParameter('statut', $statut);
        }

        if ($etuStatut !== null) {
            $qb->andWhere('c.etuStatut = :etuStatut')->setParameter('etuStatut', $etuStatut);
        }

        if ($conseiller !== null) {
            $qb->andWhere('c.conseiller = :conseiller')->setParameter('conseiller', $conseiller);
        }

        if ($nonAffectees === true) {
            $qb->andWhere('c.conseiller IS NULL');
        }

        if ($etude !== null) {
            $this->filtrerEtude($qb, $etude);
        }

        return $qb->getQuery();
    }

    /**
     * Dossiers INSCRIT dont ce conseiller a la charge, en attente ou déjà
     * décidés en recevabilité (écran E4.6, « mes analyses »).
     *
     * @return Candidature[]
     */
    public function findInscritesDuConseiller(User $conseiller, ?int $recStatut = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->andWhere('c.conseiller = :conseiller')
            ->andWhere($this->statut() . ' = :statut')
            ->setParameter('conseiller', $conseiller)
            ->setParameter('statut', StatutCandidature::INSCRIT->value)
            ->orderBy('c.creation', 'ASC');

        if ($recStatut === 0) {
            $qb->andWhere('c.recStatut IS NULL');
        } elseif ($recStatut !== null) {
            $qb->andWhere('c.recStatut = :recStatut')->setParameter('recStatut', $recStatut);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Dossiers INSCRIT du centre, tous conseillers confondus (écran Impressions,
     * section « candidats inscrits »).
     *
     * @return Candidature[]
     */
    public function findInscritesDuCentre(Centre $centre): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->andWhere('c.centre = :centre')
            ->andWhere($this->statut() . ' = :statut')
            ->setParameter('centre', $centre)
            ->setParameter('statut', StatutCandidature::INSCRIT->value)
            ->orderBy('c.creation', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche par numéro avec les relations nécessaires à l'impression de la
     * fiche d'inscription (écran Impressions du conseiller).
     */
    public function findOneByNumeroAvecRelations(string $numero): ?Candidature
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('m.filiere', 'f')->addSelect('f')
            ->andWhere('c.numero = :numero')
            ->setParameter('numero', $numero)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Candidats du centre déclarés recevables par le conseiller
     * (écran Impressions du conseiller).
     *
     * @return Candidature[]
     */
    public function findRecevablesDuCentre(Centre $centre): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->andWhere('c.centre = :centre')
            ->andWhere('c.recStatut = :recevable')
            ->setParameter('centre', $centre)
            ->setParameter('recevable', StatutRecevabilite::RECEVABLE->value)
            ->orderBy('c.recDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Répartition par sexe du candidat, pour l'écran Indicateurs.
     *
     * Comme compterParStatut(), cette méthode applique TOUJOURS le périmètre de
     * l'utilisateur : sans cela, un accompagnateur verrait les effectifs de tout
     * son centre au lieu de ses seuls candidats. Le centre n'est qu'un filtre
     * d'affichage supplémentaire, offert à l'administration.
     *
     * @return array<string, int> sexe => effectif
     */
    public function effectifsParSexe(User $user, ?Centre $centre = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('cand.sexe AS sexe, COUNT(c.id) AS effectif')
            ->join('c.user', 'cand')
            ->groupBy('cand.sexe');

        $this->appliquerPerimetre($qb, $user);

        if ($centre !== null) {
            $qb->andWhere('c.centre = :centre')->setParameter('centre', $centre);
        }

        $resultats = [];
        foreach ($qb->getQuery()->getScalarResult() as $ligne) {
            $resultats[(string) ($ligne['sexe'] ?? 'Non renseigné')] = (int) $ligne['effectif'];
        }

        return $resultats;
    }

    /**
     * Répartition par métier, dans le périmètre de l'utilisateur.
     *
     * @return array<string, int> libellé du métier => effectif
     */
    public function effectifsParMetier(User $user, ?Centre $centre = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('m.libelle AS metier, COUNT(c.id) AS effectif')
            ->join('c.metier', 'm')
            ->groupBy('m.id')
            ->orderBy('effectif', 'DESC');

        $this->appliquerPerimetre($qb, $user);

        if ($centre !== null) {
            $qb->andWhere('c.centre = :centre')->setParameter('centre', $centre);
        }

        $resultats = [];
        foreach ($qb->getQuery()->getScalarResult() as $ligne) {
            $resultats[(string) $ligne['metier']] = (int) $ligne['effectif'];
        }

        return $resultats;
    }

    /**
     * Effectifs par direction régionale.
     *
     * La direction régionale n'est pas portée par la candidature : elle se
     * rejoint par centre → localité → direction régionale. Ventilation pensée
     * pour l'administration ; le périmètre reste appliqué, un conseiller n'en
     * obtiendrait donc qu'une seule ligne.
     *
     * @return array<string, int> libellé de la direction régionale => effectif
     */
    public function effectifsParDirectionRegionale(User $user): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('dr.libelle AS region, COUNT(c.id) AS effectif')
            ->join('c.centre', 'ce')
            ->join('ce.localite', 'l')
            ->join('l.directionRegionale', 'dr')
            ->groupBy('dr.id')
            ->orderBy('effectif', 'DESC');

        $this->appliquerPerimetre($qb, $user);

        $resultats = [];
        foreach ($qb->getQuery()->getScalarResult() as $ligne) {
            $resultats[(string) $ligne['region']] = (int) $ligne['effectif'];
        }

        return $resultats;
    }

    /**
     * Effectifs par centre, dans le périmètre de l'utilisateur.
     *
     * @return array<string, int> nom du centre => effectif
     */
    public function effectifsParCentre(User $user): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('ce.nom AS centre, COUNT(c.id) AS effectif')
            ->join('c.centre', 'ce')
            ->groupBy('ce.id')
            ->orderBy('effectif', 'DESC');

        $this->appliquerPerimetre($qb, $user);

        $resultats = [];
        foreach ($qb->getQuery()->getScalarResult() as $ligne) {
            $resultats[(string) $ligne['centre']] = (int) $ligne['effectif'];
        }

        return $resultats;
    }


    /**
     * Compteurs du tableau de bord conseiller (F4.1).
     *
     * @return array{a_traiter: int, attente_paiement: int, inscrits: int, recevables: int, non_recevables: int, non_affectees: int}
     */
    public function compteursConseiller(Centre $centre, User $conseiller): array
    {
        $parStatut = [];

        $lignes = $this->createQueryBuilder('c')
            ->select($this->statut() . ' AS statut, COUNT(c.id) AS effectif')
            ->andWhere('c.centre = :centre')
            ->setParameter('centre', $centre)
            ->groupBy('statut')
            ->getQuery()
            ->getScalarResult();

        foreach ($lignes as $ligne) {
            $parStatut[(int) $ligne['statut']] = (int) $ligne['effectif'];
        }

        $attentePaiement = $this->compterPreinscritsSelonEtude($centre, self::ETUDE_ACCEPTEE);

        return [
            'a_traiter' => ($parStatut[StatutCandidature::PREINSCRIT->value] ?? 0) - $attentePaiement,
            'attente_paiement' => $attentePaiement,
            'inscrits' => $parStatut[StatutCandidature::INSCRIT->value] ?? 0,
            'recevables' => $parStatut[StatutCandidature::DOSSIER_RECEVABLE->value] ?? 0,
            'non_recevables' => $parStatut[StatutCandidature::DOSSIER_NON_RECEVABLE->value] ?? 0,
            'non_affectees' => $this->compterNonAffectees($centre),
        ];
    }

    private function compterPreinscritsSelonEtude(Centre $centre, string $etude): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.centre = :centre')
            ->setParameter('centre', $centre);

        $this->filtrerEtude($qb, $etude);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Dossiers du centre qu'aucun conseiller n'a encore pris en charge :
     * ils alimentent l'auto-affectation (F4.9).
     */
    public function compterNonAffectees(Centre $centre): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.centre = :centre')
            ->andWhere('c.conseiller IS NULL')
            ->andWhere($this->resolver->conditionDql('c', $this->statutsTerminaux(), exclure: true))
            ->setParameter('centre', $centre)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Effectifs par centre et par statut, pour le tableau croisé national
     * de l'administration (écran E6.1).
     *
     * @return list<array{centre: string, localite: ?string, statut: int, effectif: int}>
     */
    public function effectifsParCentreEtStatut(): array
    {
        return $this->createQueryBuilder('c')
            ->select('ce.nom AS centre, l.libelle AS localite, ' . $this->statut() . ' AS statut, COUNT(c.id) AS effectif')
            ->join('c.centre', 'ce')
            ->leftJoin('ce.localite', 'l')
            ->groupBy('ce.id')
            ->addGroupBy('statut')
            ->orderBy('ce.nom', 'ASC')
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Effectifs nationaux par statut, sans restriction de périmètre.
     *
     * Réservé aux écrans d'administration : les listes de rôle passent par
     * compterParStatut(), qui applique le périmètre de l'utilisateur.
     *
     * @return array<int, int> valeur du statut => effectif
     */
    public function effectifsParStatut(): array
    {
        $resultats = [];

        $lignes = $this->createQueryBuilder('c')
            ->select($this->statut() . ' AS statut, COUNT(c.id) AS effectif')
            ->groupBy('statut')
            ->getQuery()
            ->getScalarResult();

        foreach ($lignes as $ligne) {
            $resultats[(int) $ligne['statut']] = (int) $ligne['effectif'];
        }

        return $resultats;
    }

    /**
     * Dossiers ayant franchi un statut donné, pour les listes nationales
     * (écran E6.2) et leurs exports.
     *
     * @param list<StatutCandidature> $statuts
     *
     * @return Candidature[]
     */
    public function findParStatuts(array $statuts, ?Centre $centre = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'cand')->addSelect('cand')
            ->leftJoin('c.metier', 'm')->addSelect('m')
            ->leftJoin('c.centre', 'ce')->addSelect('ce')
            ->andWhere($this->resolver->conditionDql('c', $statuts))
            ->orderBy('ce.nom', 'ASC')
            ->addOrderBy('c.numero', 'ASC');

        if ($centre !== null) {
            $qb->andWhere('c.centre = :centre')->setParameter('centre', $centre);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Dossiers déposés dans un centre depuis le début de la journée :
     * indicateur du tableau de bord de l'agent d'accueil (F9.2).
     */
    public function compterDeposesAujourdhui(Centre $centre): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.centre = :centre')
            ->andWhere('c.creation >= :debut')
            ->setParameter('centre', $centre)
            ->setParameter('debut', new \DateTime('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Candidature[]
     */
    public function findPourCandidat(User $candidat): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :candidat')
            ->setParameter('candidat', $candidat)
            ->orderBy('c.creation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Une candidature est « active » tant qu'elle n'a pas atteint un état
     * terminal négatif. Sert à la règle « une seule candidature par candidat ».
     */
    public function compterActivesPourCandidat(User $candidat): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :candidat')
            ->andWhere($this->resolver->conditionDql('c', $this->statutsTerminauxNegatifs(), exclure: true))
            ->setParameter('candidat', $candidat)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de dossiers non terminés dont ce conseiller a la charge.
     * Indicateur consulté par l'agent d'accueil pour orienter les candidats
     * vers un conseiller peu chargé (spec 5.2).
     */
    public function compterEnChargePourConseiller(User $conseiller): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.conseiller = :conseiller')
            ->andWhere($this->resolver->conditionDql('c', $this->statutsTerminaux(), exclure: true))
            ->setParameter('conseiller', $conseiller)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Répartition des candidatures par statut, dans le périmètre de l'utilisateur.
     *
     * @return array<int, int> valeur du statut => effectif
     */
    public function compterParStatut(User $user, ?Centre $centre = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select($this->statut() . ' AS statut, COUNT(c.id) AS effectif')
            ->groupBy('statut');

        $this->appliquerPerimetre($qb, $user);

        if ($centre !== null) {
            $qb->andWhere('c.centre = :centre')->setParameter('centre', $centre);
        }

        $resultats = [];
        foreach ($qb->getQuery()->getScalarResult() as $ligne) {
            $resultats[(int) $ligne['statut']] = (int) $ligne['effectif'];
        }

        return $resultats;
    }

    /**
     * @return list<StatutCandidature>
     */
    private function statutsTerminaux(): array
    {
        return array_values(array_filter(
            StatutCandidature::cases(),
            static fn (StatutCandidature $s): bool => $s->estTerminal()
        ));
    }

    /**
     * États terminaux excluant l'admission définitive : un candidat admis
     * ne doit pas pouvoir redéposer un dossier au titre d'un « dossier clos ».
     *
     * @return list<StatutCandidature>
     */
    private function statutsTerminauxNegatifs(): array
    {
        return [
            StatutCandidature::DOSSIER_NON_RECEVABLE,
            StatutCandidature::NON_ELIGIBLE,
            StatutCandidature::NON_ADMISSIBLE,
            StatutCandidature::NON_ADMIS_DEFINITIF,
        ];
    }

    /**
     * Dépendance bloquante de R2.4 et R2.5 : un métier ou un centre déjà
     * référencé par un dossier ne peut pas être supprimé, sous peine de rendre
     * les candidatures existantes illisibles.
     */
    public function countParMetier(Metier $metier): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.metier = :metier')
            ->setParameter('metier', $metier)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countParCentre(Centre $centre): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.centre = :centre')
            ->setParameter('centre', $centre)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dépendance bloquante à la suppression d'un compte : un dossier référence
     * son candidat, son créateur et les agents qui l'ont traité.
     */
    public function countParUtilisateur(User $utilisateur): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :u OR c.userUpdate = :u OR c.agentAccueil = :u OR c.conseiller = :u OR c.accompagnateur = :u')
            ->setParameter('u', $utilisateur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dossiers déjà déposés sur un couple centre/métier : bloque la fermeture
     * brutale d'une ligne d'offre (écran E2.6).
     */
    public function countParCoupleCentreMetier(Centre $centre, Metier $metier): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.centre = :centre')
            ->andWhere('c.metier = :metier')
            ->setParameter('centre', $centre)
            ->setParameter('metier', $metier)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
