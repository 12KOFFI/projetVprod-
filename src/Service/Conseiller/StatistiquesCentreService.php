<?php

namespace App\Service\Conseiller;

use App\Entity\Centre;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Repository\CandidatureRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Statistiques du centre pour l'écran Impressions du conseiller.
 *
 * Restreintes systématiquement au centre passé en paramètre — jamais au
 * périmètre national — puisqu'un conseiller ne doit voir que les données de
 * son propre centre (règle métier R9.1).
 */
class StatistiquesCentreService
{
    public function __construct(
        private readonly CandidatureRepository $candidatureRepository,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     par_statut: array<int, array{libelle: string, effectif: int}>,
     *     par_sexe: array<string, int>,
     *     par_metier: array<string, int>,
     * }
     */
    public function tableauDeBord(Centre $centre, User $conseiller): array
    {
        $effectifsParStatut = $this->candidatureRepository->compterParStatut($conseiller, $centre);

        $parStatut = [];
        foreach (StatutCandidature::cases() as $statut) {
            $parStatut[$statut->value] = [
                'libelle' => $statut->libelle(),
                'effectif' => $effectifsParStatut[$statut->value] ?? 0,
            ];
        }

        return [
            'total' => $this->candidatureRepository->countParCentre($centre),
            'par_statut' => $parStatut,
            'par_sexe' => $this->candidatureRepository->effectifsParSexe($conseiller, $centre),
            'par_metier' => $this->candidatureRepository->effectifsParMetier($conseiller, $centre),
        ];
    }

    /**
     * @param array{total: int, par_statut: array<int, array{libelle: string, effectif: int}>, par_sexe: array<string, int>, par_metier: array<string, int>} $stats
     */
    public function versExcel(array $stats, Centre $centre, string $nomFichier): Response
    {
        $classeur = new Spreadsheet();

        $this->feuilleEffectifs($classeur->getActiveSheet(), 'Par statut', 'Statut', array_map(
            static fn (array $ligne): array => [$ligne['libelle'], $ligne['effectif']],
            $stats['par_statut']
        ));

        $feuilleSexe = $classeur->createSheet();
        $this->feuilleEffectifs($feuilleSexe, 'Par sexe', 'Sexe', $this->versLignes($stats['par_sexe']));

        $feuilleMetier = $classeur->createSheet();
        $this->feuilleEffectifs($feuilleMetier, 'Par métier', 'Métier', $this->versLignes($stats['par_metier']));

        $classeur->setActiveSheetIndex(0);

        $ecrivain = new Xlsx($classeur);
        ob_start();
        try {
            $ecrivain->save('php://output');
            $contenu = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $classeur->disconnectWorksheets();
        }

        $reponse = new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
        $reponse->headers->set(
            'Content-Disposition',
            $reponse->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nomFichier)
        );

        return $reponse;
    }

    /**
     * @param array<string, int> $effectifs
     * @return list<array{0: string, 1: int}>
     */
    private function versLignes(array $effectifs): array
    {
        $lignes = [];
        foreach ($effectifs as $libelle => $effectif) {
            $lignes[] = [$libelle, $effectif];
        }

        return $lignes;
    }

    /**
     * @param list<array{0: string, 1: int}> $lignes
     */
    private function feuilleEffectifs(Worksheet $feuille, string $titre, string $intituleColonne, array $lignes): void
    {
        $feuille->setTitle($titre);
        $feuille->setCellValue('A1', $intituleColonne);
        $feuille->setCellValue('B1', 'Effectif');
        $feuille->getColumnDimension('A')->setAutoSize(true);
        $feuille->getColumnDimension('B')->setAutoSize(true);

        foreach ($lignes as $index => $ligne) {
            $feuille->setCellValue('A' . ($index + 2), $ligne[0]);
            $feuille->setCellValue('B' . ($index + 2), $ligne[1]);
        }

        $derniereLigne = count($lignes) + 1;
        $feuille->getStyle('A1:B1')->getFont()->setBold(true);
        $feuille->getStyle('A1:B' . $derniereLigne)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
