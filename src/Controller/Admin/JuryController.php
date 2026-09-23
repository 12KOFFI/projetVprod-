<?php

namespace App\Controller\Admin;

use App\Entity\ImportJury;
use App\Entity\User;
use App\Enum\TypeImportJury;
use App\Exception\ImportInvalideException;
use App\Repository\ImportJuryRepository;
use App\Service\Jury\ImportJuryService;
use App\Service\Jury\ModeleImportGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import des décisions du jury central (écrans E6.3 à E6.6).
 *
 * Les décisions d'éligibilité et d'admission définitive n'arrivent pas par le
 * parcours applicatif mais par fichier : c'est l'unique porte d'entrée, et elle
 * est réservée à l'administration.
 */
#[Route('/admin/jury', name: 'app_admin_jury_')]
#[IsGranted('ROLE_ADMIN')]
class JuryController extends AbstractController
{
    /** Règle métier R6.9. */
    private const TAILLE_MAX_OCTETS = 5 * 1024 * 1024;

    private const EXTENSIONS_ADMISES = ['xlsx', 'xls'];

    public function __construct(
        private readonly ImportJuryRepository $imports,
        private readonly ImportJuryService $importService,
        private readonly bool $importsActifs,
    ) {
    }

    /**
     * Refuse l'accès quand les imports sont fermés (app.jury.imports_actifs).
     *
     * Le masquage du menu ne suffirait pas : l'URL resterait atteignable. Un
     * 404 plutôt qu'un 403 — la fonctionnalité n'est pas interdite à ce rôle,
     * elle n'est simplement pas ouverte en ce moment.
     */
    private function garantirImportsOuverts(): void
    {
        if (!$this->importsActifs) {
            throw $this->createNotFoundException("Les imports du jury central ne sont pas ouverts actuellement.");
        }
    }

    #[Route('/eligibilite', name: 'eligibilite', methods: ['GET', 'POST'])]
    public function eligibilite(Request $request): Response
    {
        $this->garantirImportsOuverts();

        return $this->ecranImport($request, TypeImportJury::ELIGIBILITE);
    }

    #[Route('/admission', name: 'admission', methods: ['GET', 'POST'])]
    public function admission(Request $request): Response
    {
        $this->garantirImportsOuverts();

        return $this->ecranImport($request, TypeImportJury::ADMISSION);
    }

    #[Route('/modele/{type}', name: 'modele', methods: ['GET'])]
    public function modele(string $type, ModeleImportGenerator $generateur): Response
    {
        $this->garantirImportsOuverts();

        $typeImport = TypeImportJury::tryFrom($type);

        if ($typeImport === null) {
            throw $this->createNotFoundException('Modèle inconnu.');
        }

        return $generateur->generer($typeImport);
    }

    #[Route('/import/{id}/rapport', name: 'rapport', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function rapport(ImportJury $import): Response
    {
        $this->garantirImportsOuverts();

        return $this->render('admin/jury/rapport.html.twig', [
            'import' => $import,
            'anomalies' => $import->getLignesEnAnomalie(),
        ]);
    }

    private function ecranImport(Request $request, TypeImportJury $type): Response
    {
        if ($request->isMethod('POST')) {
            $reponse = $this->traiterDepot($request, $type);

            if ($reponse !== null) {
                return $reponse;
            }
        }

        return $this->render('admin/jury/import.html.twig', [
            'type' => $type,
            'historique' => $this->imports->findParType($type),
            'taille_max_mo' => (int) (self::TAILLE_MAX_OCTETS / 1024 / 1024),
        ]);
    }

    /**
     * @return Response|null la réponse à renvoyer, ou null pour réafficher l'écran
     */
    private function traiterDepot(Request $request, TypeImportJury $type): ?Response
    {
        if (!$this->isCsrfTokenValid('import_' . $type->value, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez recommencer.');

            return null;
        }

        $fichier = $request->files->get('fichier');

        if (!$fichier instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier n\'a été transmis.');

            return null;
        }

        // Contrôles de forme avant toute lecture : ils protègent la mémoire du
        // serveur d'un fichier hors gabarit (V6.1 et R6.9).
        $erreur = $this->verifierFichier($fichier);

        if ($erreur !== null) {
            $this->addFlash('error', $erreur);

            return null;
        }

        /** @var User $auteur */
        $auteur = $this->getUser();

        try {
            $import = $this->importService->importer($fichier, $type, $auteur);
        } catch (ImportInvalideException $exception) {
            // Le fichier est inexploitable ou trop fautif : le message métier
            // est rendu tel quel, l'écran reste affiché pour un nouvel essai.
            $this->addFlash('error', $exception->getMessage());

            return null;
        }

        $this->addFlash('success', sprintf(
            'Import terminé : %d décision(s) appliquée(s), %d ligne(s) ignorée(s), %d en erreur.',
            $import->getLignesTraitees(),
            $import->getLignesIgnorees(),
            $import->getLignesErreur()
        ));

        return $this->redirectToRoute('app_admin_jury_rapport', ['id' => $import->getId()]);
    }

    private function verifierFichier(UploadedFile $fichier): ?string
    {
        if (!$fichier->isValid()) {
            return 'Le transfert du fichier a échoué. Vérifiez sa taille et réessayez.';
        }

        if ($fichier->getSize() > self::TAILLE_MAX_OCTETS) {
            return sprintf(
                'Le fichier dépasse %d Mo. Scindez-le en plusieurs imports.',
                (int) (self::TAILLE_MAX_OCTETS / 1024 / 1024)
            );
        }

        $extension = strtolower((string) $fichier->getClientOriginalExtension());

        if (!in_array($extension, self::EXTENSIONS_ADMISES, true)) {
            return 'Seuls les classeurs Excel (.xlsx ou .xls) sont acceptés.';
        }

        return null;
    }
}
