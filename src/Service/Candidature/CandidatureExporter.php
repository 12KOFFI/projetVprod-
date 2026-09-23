<?php

namespace App\Service\Candidature;

use App\Entity\Candidature;
use App\Service\PdfGenerator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Exports de listes de candidatures, en Excel et en PDF.
 *
 * Service unique et partagé : M4 le crée pour ses propres listes, M9 le
 * réutilisera pour les exports de pilotage, afin de ne pas dupliquer la mise en
 * forme (point d'attention technique du module M4).
 *
 * SpreadsheetGenerator n'est volontairement pas employé ici : il appelle
 * Response::send() puis exit(), ce qui court-circuite le noyau HTTP — aucun
 * écouteur de réponse ne s'exécute et la réponse échappe aux tests. Son tableau
 * d'index de colonnes s'arrête par ailleurs à « AC ».
 */
class CandidatureExporter
{
    /** @var list<string> */
    private const COLONNES = [
        'Numéro VAE',
        'Nom',
        'Prénoms',
        'Contact',
        'Métier',
        'Centre',
        'Expérience (ans)',
        'Diplôme visé',
        'Statut',
        'Étude du dossier',
        'Date de l\'étude',
        'Recevabilité',
        'Date de recevabilité',
        'Déposé le',
    ];

    public function __construct(
        private readonly PdfGenerator $pdfGenerator,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    /**
     * @param Candidature[] $candidatures
     */
    public function versExcel(array $candidatures, string $nomFichier): Response
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Candidatures');

        $derniereColonne = Coordinate::stringFromColumnIndex(count(self::COLONNES));
        $derniereLigne = count($candidatures) + 1;

        foreach (self::COLONNES as $index => $intitule) {
            $colonne = Coordinate::stringFromColumnIndex($index + 1);
            $feuille->setCellValue($colonne . '1', $intitule);
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        foreach ($candidatures as $ligne => $candidature) {
            foreach ($this->enLigne($candidature) as $index => $valeur) {
                $feuille->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . ($ligne + 2), $valeur);
            }
        }

        $feuille->getStyle('A1:' . $derniereColonne . '1')->getFont()->setBold(true);
        $feuille->getStyle('A1:' . $derniereColonne . $derniereLigne)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $contenu = $this->rendre($classeur);

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
     * @param Candidature[] $candidatures
     */
    public function versPdf(array $candidatures, string $titre, string $sousTitre): Response
    {
        return $this->pdfGenerator->stream('print/liste.html.twig', [
            'candidatures' => $candidatures,
            'titre' => $titre,
            'sous_titre' => $sousTitre,
            'edite_le' => new \DateTime(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function enLigne(Candidature $candidature): array
    {
        $candidat = $candidature->getUser();
        $etude = $candidature->getResultatEtude();
        $recevabilite = $candidature->getResultatRecevabilite();

        return [
            (string) $candidature->getNumero(),
            (string) $candidat?->getNom(),
            (string) $candidat?->getPrenoms(),
            (string) $candidat?->getContact(),
            (string) $candidature->getMetier()?->getLibelle(),
            (string) $candidature->getCentre()?->getNom(),
            (string) $candidature->getNbAnneesExperience(),
            (string) $candidature->getDiplomedemande(),
            $this->resolver->resolve($candidature)->libelle(),
            $etude?->libelle() ?? '',
            $this->date($candidature->getEtuDate()),
            $recevabilite?->libelle() ?? '',
            $this->date($candidature->getRecDate()),
            $this->date($candidature->getCreation()),
        ];
    }

    private function date(?\DateTimeInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }

    /**
     * PhpSpreadsheet n'écrit que vers un flux : on capture la sortie pour en
     * faire une réponse HTTP ordinaire plutôt qu'un envoi direct.
     */
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
