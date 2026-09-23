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
}
