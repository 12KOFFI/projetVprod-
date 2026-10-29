<?php

namespace App\Service\Statistique;

use App\Entity\Centre;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Repository\CandidatureRepository;
use App\Service\Jury\SuiviRecevabiliteService;

/**
 * Point d'agrégation unique des écrans de pilotage (tableau de bord et
 * Indicateurs), tous rôles confondus.
 *
 * Le service reçoit TOUJOURS l'utilisateur courant et le transmet au
 * repository, qui applique le périmètre : un conseiller obtient son centre, un
 * accompagnateur ses candidats, un administrateur le national. Aucun contrôleur
 * ne construit de requête lui-même (règles D.1 et D.2 de final.txt).
 *
 * Le centre passé en second argument n'est PAS un périmètre de sécurité mais un
 * filtre d'affichage, offert à l'administration seule.
 */
class StatistiqueService
{
    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly SuiviRecevabiliteService $suivi,
    ) {
    }

    /**
     * Compteurs de tête : combien de dossiers ont FRANCHI chaque étape clé.
     *
     * Le décompte est cumulatif, et non par statut exact : un dossier admis
     * définitivement a nécessairement été inscrit, recevable puis éligible, et
     * doit donc compter dans chacun de ces indicateurs. Un dossier déclaré non
     * recevable (40) s'arrête en revanche avant le seuil « recevable » (41),
     * ce que la numérotation de l'énumération traduit directement.
     *
     * @return array{total: int, inscrits: int, recevables: int, eligibles: int, admis: int}
     */
    public function synthese(User $user, ?Centre $centre = null): array
    {
        $effectifs = $this->candidatures->compterParStatut($user, $centre);

        $cumul = static function (int $seuil) use ($effectifs): int {
            $total = 0;
            foreach ($effectifs as $statut => $effectif) {
                if ($statut >= $seuil) {
                    $total += $effectif;
                }
            }

            return $total;
        };

        return [
            'total' => array_sum($effectifs),
            'inscrits' => $cumul(StatutCandidature::INSCRIT->value),
            'recevables' => $cumul(StatutCandidature::DOSSIER_RECEVABLE->value),
            'eligibles' => $cumul(StatutCandidature::ELIGIBLE->value),
            'admis' => $effectifs[StatutCandidature::ADMIS_DEFINITIF->value] ?? 0,
        ];
    }

    /**
     * Effectif exact de chaque statut, statuts vides compris : la répartition
     * doit montrer les étapes où aucun dossier ne se trouve.
     *
     * @return list<array{statut: StatutCandidature, effectif: int}>
     */
    public function parStatut(User $user, ?Centre $centre = null): array
    {
        $effectifs = $this->candidatures->compterParStatut($user, $centre);
        $repartition = [];

        foreach (StatutCandidature::cases() as $statut) {
            $repartition[] = [
                'statut' => $statut,
                'effectif' => $effectifs[$statut->value] ?? 0,
            ];
        }

        return $repartition;
    }

    /**
     * @return array<string, int> sexe => effectif
     */
    public function parSexe(User $user, ?Centre $centre = null): array
    {
        return $this->candidatures->effectifsParSexe($user, $centre);
    }

    /**
     * @return array<string, int> libellé du métier => effectif
     */
    public function parMetier(User $user, ?Centre $centre = null): array
    {
        return $this->candidatures->effectifsParMetier($user, $centre);
    }

    /**
     * Ventilation par centre, tronquée aux plus gros effectifs pour le tableau
     * de bord. Passer null en limite pour obtenir la liste complète.
     *
     * @return array<string, int> nom du centre => effectif
     */
    public function parCentre(User $user, ?int $limite = null): array
    {
        $effectifs = $this->candidatures->effectifsParCentre($user);

        return $limite === null ? $effectifs : array_slice($effectifs, 0, $limite, true);
    }

    /**
     * @return array<string, int> libellé de la direction régionale => effectif
     */
    public function parDirectionRegionale(User $user): array
    {
        return $this->candidatures->effectifsParDirectionRegionale($user);
    }

    /**
     * Entonnoir du parcours. Délégué à SuiviRecevabiliteService, qui reste la
     * source de ce calcul : il n'est pas réécrit ici.
     *
     * @return list<array{statut: StatutCandidature, effectif: int}>
     */
    public function entonnoir(): array
    {
        return $this->suivi->entonnoir();
    }

    /**
     * Tableau croisé centre × statut, réservé à l'administration.
     *
     * @return array{centres: list<array{centre: string, localite: ?string, effectifs: array<int, int>, total: int}>, statuts: list<StatutCandidature>, totaux: array<int, int>, total_general: int}
     */
    public function tableauCroise(): array
    {
        return $this->suivi->tableauCroise();
    }

    /**
     * Le parcours VAE, décision par décision : une seule table de chiffres
     * pour tout l'écran de pilotage.
     *
     * Chaque étape porte trois nombres, chacun affiché une seule fois :
     *   - `en_attente` : dossiers arrêtés devant cette décision aujourd'hui,
     *     et l'acteur qui doit la rendre ;
     *   - `franchie` : dossiers que cette décision a laissés passer, quelle
     *     que soit la suite de leur parcours (cumul) ;
     *   - `refuse` : décisions défavorables rendues à cette étape, ou null
     *     quand l'étape n'en comporte pas (le règlement des frais de dossier
     *     ne se refuse pas : le candidat paie ou il attend).
     *
     * `taux` est le taux d'acceptation de l'étape : franchie / (franchie +
     * refusé), donc toujours rapporté aux décisions RENDUES. Les dossiers
     * encore en attente n'entrent jamais dans ce calcul, sans quoi une file
     * d'attente ferait chuter un taux de décision.
     *
     * @return array{
     *     deposes: int,
     *     en_cours: int,
     *     admis: int,
     *     arretes: int,
     *     etapes: list<array{cle: string, libelle: string, acteur: string, en_attente: int, franchie: int, refuse: ?int, taux: ?int}>
     * }
     */
    public function parcours(User $user): array
    {
        $parStatut = $this->candidatures->compterParStatut($user);
        $etude = $this->candidatures->compterPreinscritsParEtude($user);
        $cumul = $this->cumul($parStatut);
        $n = static fn (StatutCandidature $statut): int => $parStatut[$statut->value] ?? 0;

        // Une étude acceptée se lit sur les préinscrits qui attendent leur
        // paiement PLUS tous les dossiers inscrits ou au-delà : on ne s'inscrit
        // qu'après une étude acceptée.
        $acceptees = $etude[CandidatureRepository::ETUDE_ACCEPTEE] + $cumul(StatutCandidature::INSCRIT);

        $etapes = [
            [
                'cle' => 'preinscrits',
                'libelle' => 'Étude du dossier',
                'acteur' => 'Conseiller VAE',
                'en_attente' => $etude[CandidatureRepository::ETUDE_A_RENDRE],
                'franchie' => $acceptees,
                'refuse' => $etude[CandidatureRepository::ETUDE_REFUSEE],
            ],
            [
                'cle' => 'acceptes',
                'libelle' => 'Frais de dossier',
                'acteur' => 'Candidat',
                'en_attente' => $etude[CandidatureRepository::ETUDE_ACCEPTEE],
                'franchie' => $cumul(StatutCandidature::INSCRIT),
                'refuse' => null,
            ],
            [
                'cle' => 'inscrits',
                'libelle' => 'Recevabilité',
                'acteur' => 'Jury local',
                'en_attente' => $n(StatutCandidature::INSCRIT),
                'franchie' => $cumul(StatutCandidature::DOSSIER_RECEVABLE),
                'refuse' => $n(StatutCandidature::DOSSIER_NON_RECEVABLE),
            ],
            [
                'cle' => 'recevables',
                'libelle' => 'Éligibilité',
                'acteur' => 'Jury',
                'en_attente' => $n(StatutCandidature::DOSSIER_RECEVABLE),
                'franchie' => $cumul(StatutCandidature::ELIGIBLE),
                'refuse' => $n(StatutCandidature::NON_ELIGIBLE),
            ],
            [
                'cle' => 'eligibles',
                'libelle' => 'Admissibilité',
                'acteur' => 'Jury',
                'en_attente' => $n(StatutCandidature::ELIGIBLE),
                'franchie' => $cumul(StatutCandidature::ADMISSIBLE),
                'refuse' => $n(StatutCandidature::NON_ADMISSIBLE),
            ],
            [
                'cle' => 'admissibles',
                'libelle' => 'Admission définitive',
                'acteur' => 'Jury',
                'en_attente' => $n(StatutCandidature::ADMISSIBLE),
                'franchie' => $n(StatutCandidature::ADMIS_DEFINITIF),
                'refuse' => $n(StatutCandidature::NON_ADMIS_DEFINITIF),
            ],
        ];

        $etapes = array_map(fn (array $etape): array => $etape + [
            // Sans décision défavorable possible, un taux d'acceptation de
            // 100 % ne voudrait rien dire : l'étape n'en affiche pas.
            'taux' => $etape['refuse'] === null
                ? null
                : $this->pourcentage($etape['franchie'], $etape['franchie'] + $etape['refuse']),
        ], $etapes);

        return [
            'deposes' => array_sum($parStatut),
            'en_cours' => array_sum(array_column($etapes, 'en_attente')),
            'admis' => $n(StatutCandidature::ADMIS_DEFINITIF),
            'arretes' => array_sum(array_map(static fn (?int $refuse): int => $refuse ?? 0, array_column($etapes, 'refuse'))),
            'etapes' => $etapes,
        ];
    }

    /**
     * Dépôts par semaine (du lundi au dimanche) sur les dernières semaines,
     * semaine en cours comprise, semaines sans dépôt incluses.
     *
     * @return array<string, int> « semaine du jj/mm » => nombre de dépôts
     */
    public function depotsParSemaine(User $user, int $semaines = 12): array
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $lundi = $aujourdhui->modify(sprintf('-%d days', (int) $aujourdhui->format('N') - 1));
        $debut = $lundi->modify(sprintf('-%d weeks', $semaines - 1));

        $effectifs = [];
        for ($i = 0; $i < $semaines; ++$i) {
            $effectifs[$debut->modify(sprintf('+%d weeks', $i))->format('Y-m-d')] = 0;
        }

        foreach ($this->candidatures->findDatesDeDepotDepuis($user, $debut) as $date) {
            $jour = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
            $lundiDuDepot = $jour->modify(sprintf('-%d days', (int) $jour->format('N') - 1))->format('Y-m-d');
            if (isset($effectifs[$lundiDuDepot])) {
                ++$effectifs[$lundiDuDepot];
            }
        }

        $parLibelle = [];
        foreach ($effectifs as $semaine => $effectif) {
            $parLibelle[(new \DateTimeImmutable($semaine))->format('d/m')] = $effectif;
        }

        return $parLibelle;
    }

    /**
     * Effectif des dossiers ayant atteint au moins un statut, d'après la
     * numérotation de l'énumération (voir synthese()).
     *
     * @param array<int, int> $parStatut
     *
     * @return callable(StatutCandidature): int
     */
    private function cumul(array $parStatut): callable
    {
        return static function (StatutCandidature $seuil) use ($parStatut): int {
            $total = 0;
            foreach ($parStatut as $statut => $effectif) {
                if ($statut >= $seuil->value) {
                    $total += $effectif;
                }
            }

            return $total;
        };
    }

    private function pourcentage(int $partie, int $tout): ?int
    {
        return $tout > 0 ? (int) round($partie * 100 / $tout) : null;
    }
}
