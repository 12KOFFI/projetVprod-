<?php

namespace App\Service\Jury;

use App\Enum\TypeImportJury;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Produit les modèles de fichiers remis aux jurys (F6.3 et F6.5).
 *
 * Le modèle sert de contrat : le service d'import contrôle l'en-tête, il faut
 * donc que les deux se lisent dans la même énumération. Une ligne d'exemple est
 * fournie et une feuille d'instructions explique les valeurs attendues, la
 * plupart des erreurs d'import venant d'un fichier rempli de travers.
 */
class ModeleImportGenerator
{
    public function generer(TypeImportJury $type): Response
    {
        $classeur = new Spreadsheet();

        $this->remplirFeuilleSaisie($classeur, $type);
        $this->ajouterInstructions($classeur, $type);

        $classeur->setActiveSheetIndex(0);

        $reponse = new Response($this->rendre($classeur), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        $reponse->headers->set(
            'Content-Disposition',
            $reponse->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                sprintf('modele-%s-vae.xlsx', $type->value)
            )
        );

        return $reponse;
    }

    private function remplirFeuilleSaisie(Spreadsheet $classeur, TypeImportJury $type): void
    {
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle($type->feuille());

        $colonnes = $type->colonnes();
        $obligatoires = $type->colonnesObligatoires();

        foreach ($colonnes as $index => $intitule) {
            $lettre = Coordinate::stringFromColumnIndex($index + 1);

            $feuille->setCellValue($lettre . '1', $intitule);
            $feuille->getColumnDimension($lettre)->setWidth(in_array($intitule, ['OBSERVATION', 'MENTION'], true) ? 32 : 18);

            $style = $feuille->getStyle($lettre . '1');
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(in_array($intitule, $obligatoires, true) ? 'FF4F46E5' : 'FF94A3B8');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        foreach ($this->exemples($type) as $rang => $exemple) {
            foreach (array_values($exemple) as $index => $valeur) {
                $feuille->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($index + 1) . ($rang + 2),
                    $valeur,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
        }

        $derniere = Coordinate::stringFromColumnIndex(count($colonnes));
        $feuille->getStyle('A1:' . $derniere . (count($this->exemples($type)) + 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $feuille->freezePane('A2');
    }

    /**
     * Lignes d'exemple, à remplacer par les données réelles du jury.
     *
     * @return list<array<string, string>>
     */
    private function exemples(TypeImportJury $type): array
    {
        $decisions = array_keys($type->decisions());

        return match ($type) {
            TypeImportJury::ELIGIBILITE => [
                ['VAE26001', 'KOUASSI', 'Yao', $decisions[0], '15/03/2026', 'Dossier complet'],
                ['VAE26002', 'TRAORE', 'Aminata', $decisions[1], '15/03/2026', 'Expérience insuffisante'],
            ],
            TypeImportJury::ADMISSION => [
                ['VAE26001', 'KOUASSI', 'Yao', $decisions[0], '20/06/2026', 'Bien', 'Livret complet'],
                ['VAE26002', 'TRAORE', 'Aminata', $decisions[1], '20/06/2026', '', 'Épreuve non concluante'],
            ],
        };
    }

    private function ajouterInstructions(Spreadsheet $classeur, TypeImportJury $type): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle('Instructions');
        $feuille->getColumnDimension('A')->setWidth(22);
        $feuille->getColumnDimension('B')->setWidth(90);

        $lignes = [
            ['Modèle', sprintf('Import « %s » — plateforme VAE Côte d\'Ivoire (DAIP)', $type->libelle())],
            ['Feuille de saisie', sprintf('Renseignez la feuille « %s ». Ne renommez pas ses colonnes.', $type->feuille())],
            ['Lignes d\'exemple', 'Les deux lignes fournies sont des exemples : remplacez-les par vos données.'],
            ['NUMERO_VAE', 'Obligatoire. Format VAE{AA}{NNN}, par exemple VAE26001. C\'est lui qui identifie le dossier.'],
            ['NOM / PRENOMS', 'Obligatoires. Ils sont comparés au dossier désigné par le numéro VAE : si l\'identité ne correspond pas, la ligne est refusée. C\'est ce qui empêche une erreur de numéro de modifier le dossier d\'un autre candidat. L\'ordre des deux colonnes et un prénom manquant sont tolérés.'],
            ['DECISION', sprintf('Obligatoire. Valeurs admises : %s.', implode(' ou ', array_keys($type->decisions())))],
            ['DATE_JURY', 'Facultatif. Format JJ/MM/AAAA.'],
        ];

        if ($type === TypeImportJury::ADMISSION) {
            $lignes[] = ['MENTION', 'Facultatif. Mention attribuée par le jury.'];
        }

        $lignes[] = ['OBSERVATION', 'Facultatif. Reporté dans l\'historique du dossier.'];
        $lignes[] = ['Statut requis', sprintf(
            'Seuls les dossiers au statut « %s » sont traités ; les autres sont signalés dans le rapport.',
            $type->statutRequis()->libelle()
        )];
        $lignes[] = ['Contrôle qualité', 'Si plus de la moitié des lignes sont en erreur, l\'import entier est annulé.'];

        foreach ($lignes as $index => [$titre, $texte]) {
            $rang = $index + 1;
            $feuille->setCellValue('A' . $rang, $titre);
            $feuille->setCellValue('B' . $rang, $texte);
            $feuille->getStyle('A' . $rang)->getFont()->setBold(true);
            $feuille->getStyle('B' . $rang)->getAlignment()->setWrapText(true);
        }
    }

    private function rendre(Spreadsheet $classeur): string
    {
        $ecrivain = new Xlsx($classeur);

        ob_start();

        try {
            $ecrivain->save('php://output');

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $classeur->disconnectWorksheets();
        }
    }
}
