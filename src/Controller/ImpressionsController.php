<?php

namespace App\Controller;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\TypeFrais;
use App\Repository\CandidatureRepository;
use App\Repository\CentreRepository;
use App\Repository\PaiementRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Candidature\CandidatureExporter;
use App\Service\Candidature\NumeroVaeGenerator;
use App\Service\Impression\CatalogueImpressions;
use App\Service\Impression\FicheInscriptionAssets;
use App\Service\Paiement\PaiementService;
use App\Service\PdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Centre d'édition partagé par tous les rôles.
 *
 * Un seul écran et un seul gabarit : ce sont le catalogue (quelles cartes) et
 * le périmètre (quelles données) qui varient, jamais la présentation. Le
 * candidat y retrouve sa fiche et ses reçus, le personnel les listes de son
 * périmètre.
 */
#[Route('/impressions', name: 'app_impressions')]
class ImpressionsController extends AbstractController
{
    public function __construct(
        private readonly CatalogueImpressions $catalogue,
        private readonly CandidatureRepository $candidatures,
        private readonly CentreRepository $centres,
        private readonly CandidatureExporter $exporter,
        private readonly PaiementService $paiements,
        private readonly PaiementRepository $paiementsRepository,
        private readonly PdfGenerator $pdf,
        private readonly FicheInscriptionAssets $ficheAssets,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $utilisateur */
        $utilisateur = $this->getUser();

        $numeroSaisi = trim((string) $request->query->get('numero', ''));
        $fiche = null;

        if ($numeroSaisi !== '' && $this->catalogue->peutRechercherParNumero($utilisateur)) {
            $fiche = $this->rechercherFiche($numeroSaisi);
        }

        $candidature = $this->candidatureDuCandidat($utilisateur);

        return $this->render('impressions/index.html.twig', [
            'listes' => $this->catalogue->listesPour($utilisateur),
            'recherche_par_numero' => $this->catalogue->peutRechercherParNumero($utilisateur),
            'choisit_le_centre' => $this->catalogue->peutChoisirLeCentre($utilisateur),
            'statistiques' => $this->catalogue->peutImprimerLesStatistiques($utilisateur),
            'centres' => $this->catalogue->peutChoisirLeCentre($utilisateur) ? $this->centres->findTousTries() : [],
            'centre_impose' => $utilisateur->getCentre(),
            'numero' => $numeroSaisi,
            'fiche' => $fiche,
            // Volet candidat : son propre dossier, sans aucune saisie.
            'ma_candidature' => $candidature,
            'ma_fiche_disponible' => $candidature !== null
                && $this->isGranted(CandidatureVoter::PRINT_FICHE, $candidature),
            'mes_recus' => $candidature !== null ? $this->recus($candidature) : [],
        ]);
    }

    /**
     * Export d'une liste de dossiers, en PDF ou en Excel.
     */
    #[Route('/liste/{liste}', name: '_liste', methods: ['GET'])]
    public function liste(Request $request, string $liste): Response
    {
        /** @var User $utilisateur */
        $utilisateur = $this->getUser();

        // Le catalogue est rejoué ici : une URL forgée ne doit pas produire une
        // liste que l'écran n'offrait pas à ce rôle.
        if (!$this->catalogue->peutExporter($utilisateur, $liste)) {
            throw $this->createAccessDeniedException("Cette édition n'est pas accessible à votre profil.");
        }

        $definition = CatalogueImpressions::LISTES[$liste];
        $centre = $this->centreDeLExport($request, $utilisateur);

        // Un membre du personnel sans centre n'a aucun périmètre : produire un
        // export national serait une fuite.
        if ($centre === null && !$this->catalogue->peutChoisirLeCentre($utilisateur)) {
            $this->addFlash('error', "Votre compte n'est rattaché à aucun centre : aucune édition n'est possible.");

            return $this->redirectToRoute('app_impressions');
        }

        $candidatures = $this->candidatures->findParStatuts($definition['statuts'], $centre);

        if ($candidatures === []) {
            $this->addFlash('info', sprintf('Aucun dossier à éditer pour « %s » sur ce périmètre.', $definition['libelle']));

            return $this->redirectToRoute('app_impressions');
        }

        $horodatage = (new \DateTime())->format('Ymd-Hi');

        return $request->query->get('format') === 'excel'
            ? $this->exporter->versExcel($candidatures, sprintf('%s-%s.xlsx', $liste, $horodatage))
            : $this->exporter->versPdf($candidatures, $definition['libelle'], $centre?->getNom() ?? 'Tous les centres');
    }

    /**
     * Fiche d'inscription d'un dossier recherché par son numéro.
     */
    #[Route('/fiche/{numero}', name: '_fiche', methods: ['GET'])]
    public function fiche(string $numero): Response
    {
        /** @var User $utilisateur */
        $utilisateur = $this->getUser();

        if (!$this->catalogue->peutRechercherParNumero($utilisateur)) {
            throw $this->createAccessDeniedException("Cette édition n'est pas accessible à votre profil.");
        }

        $candidature = $this->candidatures->findOneByNumeroAvecRelations(
            NumeroVaeGenerator::normaliser($numero)
        );

        if ($candidature === null) {
            throw $this->createNotFoundException('Aucun dossier ne porte ce numéro.');
        }

        // C'est ce voter, et non le catalogue, qui empêche un agent d'imprimer
        // la fiche d'un dossier relevant d'un autre centre.
        $this->denyAccessUnlessGranted(CandidatureVoter::PRINT_FICHE, $candidature);

        return $this->pdf->stream('print/fiche_inscription.html.twig', [
            'candidature' => $candidature,
            'candidat' => $candidature->getUser(),
            'paiement' => $this->paiementsRepository->findParTypePourCandidature($candidature, TypeFrais::DOSSIER),
            'edite_le' => new \DateTime(),
            'logo' => $this->ficheAssets->logo(),
            'photo_candidat' => $this->ficheAssets->photoCandidat($candidature),
        ]);
    }

    /**
     * Recherche affichée à l'écran : les messages d'erreur y sont explicites,
     * là où la route d'édition se contente d'un 404.
     */
    private function rechercherFiche(string $numeroSaisi): ?Candidature
    {
        $numero = NumeroVaeGenerator::normaliser($numeroSaisi);

        if (!NumeroVaeGenerator::estValide($numero)) {
            $this->addFlash('error', sprintf("Le numéro « %s » n'est pas un numéro VAE valide.", $numeroSaisi));

            return null;
        }

        $candidature = $this->candidatures->findOneByNumeroAvecRelations($numero);

        if ($candidature === null) {
            $this->addFlash('error', sprintf("Aucun dossier ne correspond au numéro « %s ».", $numero));

            return null;
        }

        if (!$this->isGranted(CandidatureVoter::VIEW, $candidature)) {
            // Message volontairement identique au précédent : confirmer
            // l'existence d'un dossier hors périmètre serait déjà une fuite.
            $this->addFlash('error', sprintf("Aucun dossier ne correspond au numéro « %s ».", $numero));

            return null;
        }

        return $candidature;
    }

    private function centreDeLExport(Request $request, User $utilisateur): ?Centre
    {
        if (!$this->catalogue->peutChoisirLeCentre($utilisateur)) {
            // Périmètre imposé par le compte, jamais lu dans la requête.
            return $utilisateur->getCentre();
        }

        $id = $request->query->getInt('centre');

        return $id > 0 ? $this->centres->find($id) : null;
    }

    private function candidatureDuCandidat(User $utilisateur): ?Candidature
    {
        if ($this->catalogue->peutRechercherParNumero($utilisateur)) {
            return null;
        }

        return $this->candidatures->findDernierePourCandidat($utilisateur);
    }

    /**
     * @return list<array{type: \App\Enum\TypeFrais, paiement: \App\Entity\Paiement, montant: string}>
     */
    private function recus(Candidature $candidature): array
    {
        return array_values(array_filter(
            $this->paiements->tableauDeBord($candidature),
            static fn (array $ligne): bool => $ligne['regle'] && $ligne['paiement'] !== null
        ));
    }
}
