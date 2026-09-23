<?php

namespace App\Service\Jury;

use App\Enum\StatutCandidature;
use App\Repository\CandidatureRepository;

/**
 * Suivi centralisé du processus de recevabilité, centre par centre (étape 3).
 *
 * Les effectifs sont agrégés par la base en une seule requête : compter en PHP
 * supposerait d'hydrater toutes les candidatures du pays.
 */
class SuiviRecevabiliteService
{
    public function __construct(
        private readonly CandidatureRepository $candidatureRepository,
    ) {
    }

    /**
     * Tableau croisé centre × statut (écran E6.1).
     *
     * @return array{
     *     centres: list<array{centre: string, localite: ?string, effectifs: array<int, int>, total: int}>,
     *     statuts: list<StatutCandidature>,
     *     totaux: array<int, int>,
     *     total_general: int
     * }
     */
    public function tableauCroise(): array
    {
        $statuts = $this->statutsSuivis();
        $lignes = [];
        $totaux = array_fill_keys(array_map(static fn (StatutCandidature $s): int => $s->value, $statuts), 0);
        $totalGeneral = 0;

        foreach ($this->candidatureRepository->effectifsParCentreEtStatut() as $ligne) {
            $centre = (string) $ligne['centre'];

            if (!isset($lignes[$centre])) {
                $lignes[$centre] = [
                    'centre' => $centre,
                    'localite' => $ligne['localite'] ?? null,
                    'effectifs' => array_fill_keys(array_keys($totaux), 0),
                    'total' => 0,
                ];
            }

            $statut = (int) $ligne['statut'];
            $effectif = (int) $ligne['effectif'];

            $lignes[$centre]['total'] += $effectif;
            $totalGeneral += $effectif;

            // Un statut hors du suivi (dossier non encore instruit, par
            // exemple) compte dans le total du centre sans avoir sa colonne.
            if (array_key_exists($statut, $lignes[$centre]['effectifs'])) {
                $lignes[$centre]['effectifs'][$statut] += $effectif;
                $totaux[$statut] += $effectif;
            }
        }

        ksort($lignes);

        return [
            'centres' => array_values($lignes),
            'statuts' => $statuts,
            'totaux' => $totaux,
            'total_general' => $totalGeneral,
        ];
    }

    /**
     * Entonnoir du parcours : combien de dossiers ont franchi chaque étape.
     *
     * @return list<array{statut: StatutCandidature, effectif: int}>
     */
    public function entonnoir(): array
    {
        $parStatut = $this->candidatureRepository->effectifsParStatut();
        $entonnoir = [];

        foreach ($this->statutsSuivis() as $statut) {
            $entonnoir[] = [
                'statut' => $statut,
                'effectif' => $parStatut[$statut->value] ?? 0,
            ];
        }

        return $entonnoir;
    }

    /**
     * Étapes présentées dans le suivi : les états transitoires n'y figurent
     * pas, l'administration suivant des décisions, non des files d'attente.
     *
     * @return list<StatutCandidature>
     */
    private function statutsSuivis(): array
    {
        return [
            StatutCandidature::INSCRIT,
            StatutCandidature::DOSSIER_RECEVABLE,
            StatutCandidature::DOSSIER_NON_RECEVABLE,
            StatutCandidature::ELIGIBLE,
            StatutCandidature::NON_ELIGIBLE,
            StatutCandidature::ADMISSIBLE,
            StatutCandidature::ADMIS_DEFINITIF,
        ];
    }
}
